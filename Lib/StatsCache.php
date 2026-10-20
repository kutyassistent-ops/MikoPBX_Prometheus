<?php
/*
 * ModulePrometheus - Prometheus exporter for MikoPBX
 * Copyright (C) 2026 Community
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModulePrometheus\Lib;

require_once 'Globals.php';
require_once __DIR__ . '/ApiHelper.php';
require_once __DIR__ . '/Collectors/MetricsWriter.php';

/**
 * Background CDR statistics collector for the Prometheus module.
 *
 * Runs via cron every 5 minutes, collects trunk call statistics from the
 * REST API and writes pre-computed values to flat cache files. The exporter
 * worker reads these files during scrapes, so polling never triggers the
 * heavy per-trunk API requests directly.
 *
 * Cache file format:
 *   trunkCalls_{providerId}_{period}_{direction}_{metric}
 *   missed_{period}_{extension}
 *   trunkState_{providerId}
 *
 * Field names in the CDR API have moved between releases, so every optional
 * field is looked up through a list of candidates and is simply skipped when
 * none of them is present. The base metrics stay mandatory.
 *
 * @package Modules\ModulePrometheus\Lib
 */
class StatsCache
{
    private const PERIODS = ['hour', 'day'];
    private const DIRECTIONS = ['incoming', 'outgoing', 'all'];
    private const METRICS = ['totalCalls', 'answeredCalls', 'totalDuration', 'totalBillsec'];
    private const PROVIDER_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** A answered call shorter than this counts as "short". */
    private const SHORT_CALL_SECONDS = 5;

    /** An answered call longer than this counts as "long". */
    private const LONG_CALL_SECONDS = 600;

    /**
     * Candidate CDR field names per derived metric. The first one present in
     * a row wins.
     */
    private const FIELD_ALIASES = [
        'ring' => ['avgRing', 'averageRing', 'ringTime', 'avgRingTime'],
        'hold' => ['avgHold', 'averageHold', 'holdTime', 'avgTalk'],
        'short' => ['shortCalls', 'callsShort', 'shortCallCount'],
        'long' => ['longCalls', 'callsLong', 'longCallCount'],
        'disposition' => ['disposition', 'dispositionCode', 'hangupReason', 'reason'],
    ];

    /**
     * Collects CDR statistics for all providers and writes cache files.
     */
    public static function collect(): void
    {
        $cacheDir = ApiHelper::CACHE_DIR;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (!is_dir(ApiHelper::TEXTFILE_DIR)) {
            @mkdir(ApiHelper::TEXTFILE_DIR, 0755, true);
        }

        $providerIds = self::getProviderIds();
        self::cleanupOrphanedFiles($cacheDir, $providerIds);

        $states = self::collectProviderStates();

        foreach ($providerIds as $providerId) {
            foreach (self::PERIODS as $period) {
                [$dateFrom, $dateTo] = self::window($period);
                $data = ApiHelper::callApi('/pbxcore/api/v3/cdr:getStatsByProvider', [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'provider' => $providerId,
                ]);

                self::writeCacheFiles($cacheDir, $providerId, $period, $data);
            }
        }

        self::collectMissedByExtension($cacheDir);
        self::writeProviderStates($cacheDir, $states);
        self::atomicWrite($cacheDir . '/last_update', (string)time());
    }

    /**
     * @return array{0:string, 1:string} dateFrom, dateTo for the window
     */
    private static function window(string $period): array
    {
        $now = new \DateTime();
        $interval = $period === 'day' ? 'PT24H' : 'PT1H';
        $from = (clone $now)->sub(new \DateInterval($interval));
        return [
            $from->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Returns list of all provider IDs (SIP + IAX), validated for safe filenames.
     *
     * @return string[]
     */
    private static function getProviderIds(): array
    {
        $ids = [];
        foreach (ApiHelper::getAllProviderStatuses() as $provider) {
            $id = (string)($provider['id'] ?? '');
            if ($id !== '' && preg_match(self::PROVIDER_ID_PATTERN, $id) === 1) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Snapshot of every provider state, used to detect registration
     * flapping between two cron runs.
     *
     * @return array<string, string>
     */
    private static function collectProviderStates(): array
    {
        $states = [];
        foreach (ApiHelper::getAllProviderStatuses() as $provider) {
            $id = (string)($provider['id'] ?? '');
            if ($id !== '') {
                $states[$id] = strtoupper((string)($provider['state'] ?? ''));
            }
        }
        return $states;
    }

    /**
     * Compares the previous state snapshot with the current one and counts
     * transitions, which is the earliest sign of a trunk misbehaving.
     *
     * @param array<string, string> $states
     */
    private static function writeProviderStates(string $cacheDir, array $states): void
    {
        $previousFile = $cacheDir . '/trunkState_current';
        $previous = [];
        if (is_file($previousFile)) {
            foreach (explode("\n", (string)@file_get_contents($previousFile)) as $line) {
                if ($line === '') {
                    continue;
                }
                [$id, $state] = array_pad(explode('=', $line, 2), 2, '');
                if ($id !== '') {
                    $previous[$id] = $state;
                }
            }
        }

        $lines = [];
        $transitions = [];
        $registered = [];
        foreach ($states as $id => $state) {
            $lines[] = "{$id}={$state}";
            $registered[] = [['trunk' => $id], in_array($state, ['OK', 'REGISTERED'], true) ? 1 : 0];
            if (isset($previous[$id]) && $previous[$id] !== $state) {
                $transitions[] = [['trunk' => $id], 1];
            }
        }
        self::atomicWrite($previousFile, implode("\n", $lines) . "\n");
        self::writePromSnippet($cacheDir, 'trunk_state', $lines === [] ? null : [
            'mikopbx_trunk_registered' => [
                'help' => 'Whether the trunk was registered at the time of the last background collection',
                'type' => 'gauge',
                'samples' => $registered,
            ],
            'mikopbx_trunk_state_transitions_total' => [
                'help' => 'Registration state changes detected between two background collections',
                'type' => 'counter',
                'samples' => $transitions,
            ],
        ]);
    }

    /**
     * Aggregates the API response and writes one flat file per
     * direction x metric combination using atomic rename.
     *
     * @param string[]|null $data
     */
    private static function writeCacheFiles(string $cacheDir, string $providerId, string $period, ?array $data): void
    {
        foreach (self::DIRECTIONS as $direction) {
            $sums = array_fill_keys(self::METRICS, 0);
            $ringSum = 0.0;
            $holdSum = 0.0;
            $answeredForAverages = 0;
            $dispositions = [];
            $shortSum = 0;
            $longSum = 0;

            if (is_array($data)) {
                foreach ($data as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($direction !== 'all' && ($row['direction'] ?? '') !== $direction) {
                        continue;
                    }
                    foreach (self::METRICS as $metric) {
                        $sums[$metric] += (int)($row[$metric] ?? 0);
                    }

                    $answered = (int)($row['answeredCalls'] ?? 0);
                    if ($answered > 0) {
                        $answeredForAverages++;
                        $ringSum += self::firstNumeric($row, self::FIELD_ALIASES['ring']) ?? 0.0;
                        $holdSum += self::firstNumeric($row, self::FIELD_ALIASES['hold']) ?? 0.0;
                    }

                    // Dispositions and length thresholds are only meaningful
                    // on the aggregate view, and writing them per direction
                    // would only create cache files nothing ever reads.
                    if ($direction !== 'all') {
                        continue;
                    }

                    $disposition = self::firstValue($row, self::FIELD_ALIASES['disposition']);
                    if ($disposition !== null) {
                        $key = strtolower(preg_replace('/[^A-Za-z]+/', '_', $disposition) ?? 'unknown');
                        $key = trim($key, '_') ?: 'unknown';
                        $dispositions[$key] = ($dispositions[$key] ?? 0) + 1;
                    }

                    $shortSum += (int)self::firstNumeric($row, self::FIELD_ALIASES['short']);
                    $longSum += (int)self::firstNumeric($row, self::FIELD_ALIASES['long']);
                }
            }

            foreach ($sums as $metric => $value) {
                self::atomicWrite(
                    self::cachePath($cacheDir, $providerId, $period, $direction, $metric),
                    (string)$value
                );
            }

            if ($answeredForAverages > 0) {
                self::atomicWrite(
                    self::cachePath($cacheDir, $providerId, $period, $direction, 'avgRing'),
                    (string)round($ringSum / $answeredForAverages, 3)
                );
                self::atomicWrite(
                    self::cachePath($cacheDir, $providerId, $period, $direction, 'avgHold'),
                    (string)round($holdSum / $answeredForAverages, 3)
                );
            }

            if ($shortSum > 0) {
                self::atomicWrite(
                    self::cachePath($cacheDir, $providerId, $period, $direction, 'shortCalls'),
                    (string)$shortSum
                );
            }
            if ($longSum > 0) {
                self::atomicWrite(
                    self::cachePath($cacheDir, $providerId, $period, $direction, 'longCalls'),
                    (string)$longSum
                );
            }

            if ($dispositions !== []) {
                foreach ($dispositions as $disposition => $count) {
                    self::atomicWrite(
                        $cacheDir . "/trunkCalls_{$providerId}_{$period}_{$direction}_disposition_{$disposition}",
                        (string)$count
                    );
                }
            }
        }
    }

    private static function cachePath(
        string $cacheDir,
        string $providerId,
        string $period,
        string $direction,
        string $metric
    ): string {
        return sprintf('%s/trunkCalls_%s_%s_%s_%s', $cacheDir, $providerId, $period, $direction, $metric);
    }

    /**
     * Missed inbound calls per internal extension.
     *
     * The endpoint is optional: when the API does not offer it the previous
     * files are removed so stale counters do not linger forever. When the
     * endpoint exists but fails, the previous values are kept and a marker
     * is written - a metric that disappears because of a temporary API
     * outage looks like "nobody missed anything".
     */
    private static function collectMissedByExtension(string $cacheDir): void
    {
        $data = ApiHelper::callApi('/pbxcore/api/v3/cdr:getMissedCallsByExtension', [
            'dateFrom' => self::window('day')[0],
            'dateTo' => self::window('day')[1],
        ]);

        $markerFile = $cacheDir . '/missed_error';
        if (!is_array($data)) {
            // The source failed: keep the last known values and record the
            // failure so the exporter can publish it.
            self::atomicWrite($markerFile, (string)time());

            return;
        }
        if (is_file($markerFile)) {
            @unlink($markerFile);
        }

        foreach (glob($cacheDir . '/missed_*') ?: [] as $stale) {
            if (basename($stale) === 'missed_error') {
                continue;
            }
            @unlink($stale);
        }

        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $extension = (string)($row['extension'] ?? ($row['src_num'] ?? ''));
            if ($extension === '' || preg_match(self::PROVIDER_ID_PATTERN, $extension) !== 1) {
                continue;
            }
            $count = (int)($row['count'] ?? ($row['totalCalls'] ?? 0));
            if ($count <= 0) {
                continue;
            }
            self::atomicWrite($cacheDir . "/missed_day_{$extension}", (string)$count);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $candidates
     */
    private static function firstNumeric(array $row, array $candidates): ?float
    {
        foreach ($candidates as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float)$row[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $candidates
     */
    private static function firstValue(array $row, array $candidates): ?string
    {
        foreach ($candidates as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return null;
    }

    /**
     * Writes a textfile collector snippet.
     *
     * @param array<string, array{help:string, type:string, samples:array}>|null $families
     */
    private static function writePromSnippet(string $cacheDir, string $name, ?array $families): void
    {
        $path = ApiHelper::TEXTFILE_DIR . "/{$name}.prom";
        if ($families === null || $families === []) {
            @unlink($path);
            return;
        }

        $lines = '';
        foreach ($families as $metric => $family) {
            if ($family['samples'] === []) {
                continue;
            }
            $lines .= "# HELP {$metric} {$family['help']}\n";
            $lines .= "# TYPE {$metric} {$family['type']}\n";
            foreach ($family['samples'] as [$labels, $value]) {
                $lines .= $metric . Collectors\MetricsWriter::formatLabels($labels)
                    . ' ' . Collectors\MetricsWriter::formatValue($value) . "\n";
            }
        }

        self::atomicWrite($path, $lines);
    }

    /**
     * Writes content to a file atomically via temp file + rename.
     * Prevents the exporter from reading a truncated/empty file.
     */
    private static function atomicWrite(string $filename, string $content): void
    {
        $tmp = $filename . '.tmp';
        try {
            $written = file_put_contents($tmp, $content);
        } catch (\Throwable $e) {
            Util::sysLogMsg('ModulePrometheusStatsCache', "Cannot write {$filename}: " . $e->getMessage());

            return;
        }
        if ($written === false) {
            Util::sysLogMsg('ModulePrometheusStatsCache', "Cannot write {$filename}");

            return;
        }
        @rename($tmp, $filename);
    }

    /**
     * Removes cache files for providers that no longer exist.
     * Prefix matching is used so provider IDs containing underscores
     * are cleaned up correctly.
     *
     * @param string[] $activeProviderIds
     */
    private static function cleanupOrphanedFiles(string $cacheDir, array $activeProviderIds): void
    {
        $files = glob($cacheDir . '/trunkCalls_*');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            $basename = basename($file);
            $keep = false;
            foreach ($activeProviderIds as $providerId) {
                if (str_starts_with($basename, "trunkCalls_{$providerId}_")) {
                    $keep = true;
                    break;
                }
            }
            if (!$keep) {
                @unlink($file);
            }
        }
    }
}

// Run collector
StatsCache::collect();