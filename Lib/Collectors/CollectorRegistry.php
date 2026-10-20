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

/**
 * Runs every collector for one scrape and publishes the self metrics.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class CollectorRegistry
{
    /** A textfile snippet larger than this is rejected. */
    public const TEXTFILE_MAX_BYTES = 1048576;

    /** @var AbstractCollector[] */
    private array $collectors = [];

    /** @var array<string, string> Provider id => display name */
    private array $trunkNames = [];

    private float $scrapeStart = 0.0;

    /** @var string[] Names of the collectors that were skipped by the deadline */
    private array $timedOut = [];

    public function add(AbstractCollector $collector): void
    {
        $this->collectors[] = $collector;
    }

    public function setTrunkNames(array $names): void
    {
        $this->trunkNames = $names;
        foreach ($this->collectors as $collector) {
            if ($collector instanceof CallStatsCollector) {
                $collector->setTrunkNames($names);
            }
        }
    }

    /**
     * @return array{body:string, ok:bool, failed:string[], families:int, duration:float, timedOut:bool}
     */
    public function run(float $deadline = 0.0): array
    {
        $this->scrapeStart = microtime(true);
        $this->timedOut = [];
        $writer = new MetricsWriter();

        $success = [];
        $duration = [];
        $failed = [];

        foreach ($this->collectors as $collector) {
            if ($deadline > 0 && microtime(true) >= $deadline) {
                // The budget is spent: the rest of the groups is skipped and
                // the scrape is reported as failed, so Prometheus sees it.
                $this->timedOut[] = $collector->name();
                $failed[] = $collector->name();
                $success[] = [['collector' => $collector->name()], 0];
                $duration[] = [['collector' => $collector->name()], 0.0];
                continue;
            }
            $collector->run($writer);
            $name = $collector->name();
            $success[] = [['collector' => $name], $collector->isSuccessful() ? 1 : 0];
            $duration[] = [['collector' => $name], round($collector->lastDuration(), 6)];
            if (!$collector->isSuccessful()) {
                $failed[] = $name;
            }
        }

        $this->emitSelfMetrics($writer, $success, $duration);

        $body = $writer->render();

        return [
            'body' => $body,
            'ok' => $failed === [],
            'failed' => $failed,
            'families' => $writer->familyCount(),
            'duration' => round(microtime(true) - $this->scrapeStart, 6),
            'timedOut' => $this->timedOut !== [],
        ];
    }

    /**
     * @param array<int, array{0: array<string, string>, 1: int|float}> $success
     * @param array<int, array{0: array<string, string>, 1: int|float}> $duration
     */
    private function emitSelfMetrics(MetricsWriter $w, array $success, array $duration): void
    {
        $w->metric(
            'mikopbx_exporter_collector_success',
            'Whether the last collection of the metric group succeeded',
            'gauge',
            $success
        );
        $w->metric(
            'mikopbx_exporter_collector_duration_seconds',
            'Wall clock time each collector spent during the last scrape',
            'gauge',
            $duration
        );
        $w->metric(
            'mikopbx_exporter_scrape_duration_seconds',
            'Duration of the current scrape in seconds',
            'gauge',
            [[[], round(microtime(true) - $this->scrapeStart, 6)]]
        );
        $w->metric(
            'mikopbx_exporter_scrape_families',
            'Number of metric families published by the last scrape',
            'gauge',
            [[[], $w->familyCount()]]
        );
    }

    /**
     * Merges textfile collector output into an already rendered payload.
     *
     * Any *.prom file dropped into $directory is published verbatim, which
     * lets cron scripts add metrics without touching the module. A file that
     * is too large or does not look like an exposition document is skipped:
     * a broken snippet would otherwise corrupt the whole payload.
     *
     * @return string
     */
    public static function appendTextfiles(string $body, string $directory): string
    {
        if (!is_dir($directory)) {
            return $body;
        }
        foreach (glob($directory . '/*.prom') ?: [] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            try {
                $size = filesize($file);
                $content = $size === false ? false : file_get_contents($file);
            } catch (\Throwable $e) {
                $content = false;
            }
            if ($content === false || $size === false || $size > self::TEXTFILE_MAX_BYTES) {
                continue;
            }
            if (trim($content) === '') {
                continue;
            }
            if (!self::looksLikeExposition($content)) {
                continue;
            }
            // A file without a trailing newline would corrupt the next header
            $body .= $content . (str_ends_with($content, "\n") ? '' : "\n");
        }
        return $body;
    }

    /**
     * A textfile snippet has to start with a comment or a metric name.
     */
    private static function looksLikeExposition(string $content): bool
    {
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                return true;
            }

            return (bool)preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{.*\})?\s+\S+/', $line);
        }

        return false;
    }
}
