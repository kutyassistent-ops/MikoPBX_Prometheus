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

namespace Modules\ModulePrometheus\Lib\Collectors;

use Modules\ModulePrometheus\Lib\ApiHelper;

/**
 * Per trunk call statistics read from the flat cache written by StatsCache.
 *
 * Cache files are cheap to read, so this collector never touches the CDR
 * API. The sliding window can shrink between scrapes, which is why every
 * family here is a gauge and rate()/increase() must not be applied to it.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class CallStatsCollector extends AbstractCollector
{
    /** Provider id => human readable name, filled by the trunk collector. */
    private array $trunkNames = [];

    public function name(): string
    {
        return 'call_stats';
    }

    public function collect(MetricsWriter $w): void
    {
        $this->collectCacheFreshness($w);
        $this->collectTrunkWindows($w);
        $this->collectDispositions($w);
        $this->collectAnswerTimes($w);
        $this->collectAbandonedByExtension($w);
    }

    public function setTrunkNames(array $names): void
    {
        $this->trunkNames = $names;
    }

    private function collectCacheFreshness(MetricsWriter $w): void
    {
        $cacheDir = ApiHelper::CACHE_DIR;
        $lastUpdateFile = $cacheDir . '/last_update';
        if (!is_file($lastUpdateFile)) {
            return;
        }
        $timestamp = (int)trim(self::readFile($lastUpdateFile));
        if ($timestamp <= 0) {
            return;
        }
        $w->metric(
            'mikopbx_stats_cache_last_update_timestamp_seconds',
            'Unix timestamp of the last trunk call statistics collection',
            'gauge',
            [[[], $timestamp]]
        );
        // Growing age means the cron collector stopped running
        $w->metric(
            'mikopbx_stats_cache_age_seconds',
            'Age of the statistics cache in seconds',
            'gauge',
            [[[], max(0, time() - $timestamp)]]
        );

        // The missed call source may fail on its own while the rest of the
        // cache is fresh. The marker file carries the time of the failure.
        $errorFile = $cacheDir . '/missed_error';
        if (is_file($errorFile)) {
            $failedAt = (int)trim(self::readFile($errorFile));
            $w->metric(
                'mikopbx_stats_cache_missed_error_age_seconds',
                'Seconds since the missed call statistics source failed (0 when it works)',
                'gauge',
                [[[], $failedAt > 0 ? max(0, time() - $failedAt) : 0]]
            );
        } else {
            $w->metric(
                'mikopbx_stats_cache_missed_error_age_seconds',
                'Seconds since the missed call statistics source failed (0 when it works)',
                'gauge',
                [[[], 0]]
            );
        }
    }

    private function collectTrunkWindows(MetricsWriter $w): void
    {
        $matrix = $this->readCacheMatrix('/^trunkCalls_(.+)_(hour|day)_(incoming|outgoing|all)_(.+)$/');
        if ($matrix === []) {
            return;
        }

        $calls = $answered = $duration = $billsec = $asr = $acd = $shortCalls = $longCalls = [];

        foreach ($matrix as $trunkId => $periods) {
            $trunkName = $this->trunkNames[$trunkId] ?? $trunkId;
            foreach ($periods as $period => $directions) {
                foreach ($directions as $direction => $values) {
                    $labels = [
                        'trunk' => $trunkId,
                        'name' => $trunkName,
                        'period' => $period,
                        'direction' => $direction,
                    ];
                    $totalCalls = (int)($values['totalCalls'] ?? 0);
                    $answeredCalls = (int)($values['answeredCalls'] ?? 0);
                    $totalDuration = (int)($values['totalDuration'] ?? 0);
                    $totalBillsec = (int)($values['totalBillsec'] ?? 0);

                    $calls[] = [$labels, $totalCalls];
                    $answered[] = [$labels, $answeredCalls];
                    $duration[] = [$labels, $totalDuration];
                    $billsec[] = [$labels, $totalBillsec];

                    $asr[] = [$labels, $totalCalls > 0 ? round($answeredCalls / $totalCalls, 6) : 0.0];
                    $acd[] = [$labels, $answeredCalls > 0 ? round($totalBillsec / $answeredCalls, 3) : 0.0];

                    if (isset($values['shortCalls'])) {
                        $shortCalls[] = [$labels, (int)$values['shortCalls']];
                    }
                    if (isset($values['longCalls'])) {
                        $longCalls[] = [$labels, (int)$values['longCalls']];
                    }
                }
            }
        }

        $w->metric('mikopbx_trunk_calls', 'Calls processed over the sliding period for each trunk', 'gauge', $calls);
        $w->metric('mikopbx_trunk_calls_answered', 'Answered calls over the sliding period for each trunk', 'gauge', $answered);
        $w->metric(
            'mikopbx_trunk_calls_duration_seconds',
            'Total call duration over the sliding period for each trunk',
            'gauge',
            $duration
        );
        $w->metric(
            'mikopbx_trunk_calls_billsec_seconds',
            'Total billable seconds over the sliding period for each trunk',
            'gauge',
            $billsec
        );
        $w->metric(
            'mikopbx_trunk_answer_rate_ratio',
            'Answered calls divided by total calls (0..1) over the sliding period',
            'gauge',
            $asr
        );
        $w->metric(
            'mikopbx_trunk_average_billsec_seconds',
            'Average billable seconds per answered call over the sliding period',
            'gauge',
            $acd
        );
        $w->metric(
            'mikopbx_trunk_short_calls',
            'Answered calls shorter than the short call threshold (default 5s)',
            'gauge',
            $shortCalls
        );
        $w->metric(
            'mikopbx_trunk_long_calls',
            'Answered calls longer than the long call threshold (default 600s)',
            'gauge',
            $longCalls
        );
    }

    /**
     * Calls grouped by the CDR disposition field. The field name has moved
     * between MikoPBX releases, so the cache key wins.
     */
    private function collectDispositions(MetricsWriter $w): void
    {
        $files = glob(ApiHelper::CACHE_DIR . '/trunkCalls_*_all_disposition_*');
        if (!is_array($files) || $files === []) {
            return;
        }

        $samples = [];
        $pattern = '/^trunkCalls_(.+)_(hour|day)_all_disposition_(.+)$/';
        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match($pattern, $base, $m)) {
                continue;
            }
            $samples[] = [
                [
                    'trunk' => $m[1],
                    'name' => $this->trunkNames[$m[1]] ?? $m[1],
                    'period' => $m[2],
                    'disposition' => strtolower($m[3]),
                ],
                (int)trim(self::readFile($file)),
            ];
        }

        if ($samples === []) {
            return;
        }
        $w->metric(
            'mikopbx_trunk_calls_by_disposition',
            'Calls over the sliding period grouped by CDR disposition (NO ANSWER, BUSY, CANCEL, FAILED, UNKNOWN)',
            'gauge',
            $samples
        );
    }

    /**
     * Average hold time (AHT) - answer time plus talk time.
     */
    private function collectAnswerTimes(MetricsWriter $w): void
    {
        $matrix = $this->readCacheMatrix('/^trunkCalls_(.+)_(hour|day)_(incoming|outgoing|all)_(avgRing|totalRing|avgHold|totalHold)$/');
        if ($matrix === []) {
            return;
        }

        $ringAvg = $holdAvg = [];
        foreach ($matrix as $trunkId => $periods) {
            $trunkName = $this->trunkNames[$trunkId] ?? $trunkId;
            foreach ($periods as $period => $directions) {
                foreach ($directions as $direction => $values) {
                    $labels = [
                        'trunk' => $trunkId,
                        'name' => $trunkName,
                        'period' => $period,
                        'direction' => $direction,
                    ];
                    if (isset($values['avgRing'])) {
                        $ringAvg[] = [$labels, (float)$values['avgRing']];
                    }
                    if (isset($values['avgHold'])) {
                        $holdAvg[] = [$labels, (float)$values['avgHold']];
                    }
                }
            }
        }

        if ($ringAvg !== []) {
            $w->metric(
                'mikopbx_trunk_average_ring_seconds',
                'Average ringing time per answered call over the sliding period',
                'gauge',
                $ringAvg
            );
        }
        if ($holdAvg !== []) {
            $w->metric(
                'mikopbx_trunk_average_hold_seconds',
                'Average answer hold time (ring plus talk) per answered call',
                'gauge',
                $holdAvg
            );
        }
    }

    /**
     * Missed inbound calls per extension, from the cache.
     *
     * Only the extensions that actually missed something are written, and
     * the exporter publishes exactly what it finds - no zero series for the
     * rest, which keeps the cardinality proportional to the problem size.
     */
    private function collectAbandonedByExtension(MetricsWriter $w): void
    {
        $files = glob(ApiHelper::CACHE_DIR . '/missed_*');
        if (!is_array($files) || $files === []) {
            return;
        }

        $samples = [];
        foreach ($files as $file) {
            if (!preg_match('/^missed_(hour|day)_(.+)$/', basename($file), $m)) {
                continue;
            }
            $samples[] = [
                ['period' => $m[1], 'extension' => $m[2]],
                (int)trim(self::readFile($file)),
            ];
        }

        if ($samples === []) {
            return;
        }
        $w->metric(
            'mikopbx_extension_missed_calls',
            'Missed inbound calls per extension over the sliding period',
            'gauge',
            $samples
        );
    }

    /**
     * Reads every cache file matching $pattern into a nested matrix.
     *
     * The final capture group is the metric name, so new cache entries are
     * picked up without touching this method.
     *
     * @return array<string, array<string, array<string, array<string, int>>>>
     */
    private function readCacheMatrix(string $pattern): array
    {
        $files = glob(ApiHelper::CACHE_DIR . '/trunkCalls_*');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $matrix = [];
        foreach ($files as $file) {
            if (!preg_match($pattern, basename($file), $m)) {
                continue;
            }
            $matrix[$m[1]][$m[2]][$m[3]][$m[4]] = (int)trim(self::readFile($file));
        }
        return $matrix;
    }
}