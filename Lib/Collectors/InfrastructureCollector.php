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

use MikoPBX\Common\Models\PbxExtensionModules;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\Util;
use Modules\ModulePrometheus\Lib\PrometheusMain;

/**
 * Platform level metrics: databases, web server, time sync, kernel events
 * and the state of the extension modules themselves.
 *
 * Every block degrades independently. A missing MariaDB client or an
 * unreadable kernel ring buffer means fewer families, never a failed
 * collector - these are nice-to-have signals, not the core of the export.
 *
 * A block that could not run says so through
 * mikopbx_exporter_block_present and mikopbx_exporter_block_absent_reason,
 * so a silently missing family is never confused with a zero value.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class InfrastructureCollector extends AbstractCollector
{
    /** @var array<string,mixed> Counters of the exporter process */
    private array $stats = [];

    /** @var string[] Failed optional blocks with their reason */
    private array $failures = [];

    /** @var array<string,string> Blocks that did not run and the reason why */
    private array $absent = [];

    /**
     * @param array<string,mixed> $stats
     */
    public function __construct(array $stats = [])
    {
        $this->stats = $stats;
    }

    public function name(): string
    {
        return 'infrastructure';
    }

    public function collect(MetricsWriter $w): void
    {
        // Each block is optional: a missing binary, an unreadable ring
        // buffer or an unknown directory constant must cost one family,
        // not the whole collector. The 1.3.0 release lost twelve families
        // to a single undefined constant because of this.
        $this->optional('exporter_runtime', fn() => $this->collectExporterRuntime($w));
        $this->optional('database_files', fn() => $this->collectDatabaseFiles($w));
        $this->optional('mysql', fn() => $this->collectMysql($w));
        $this->optional('webserver', fn() => $this->collectWebServer($w));
        $this->optional('time_sync', fn() => $this->collectTimeSync($w));
        $this->optional('kernel_events', fn() => $this->collectKernelEvents($w));
        $this->optional('modules_state', fn() => $this->collectModulesState($w));
        $this->publishBlockState($w);
    }

    /**
     * Runs one optional block, recording failures instead of propagating.
     *
     * A block that fails is reported through its own metric so the reason
     * is visible in /metrics rather than only as success = 0 on the whole
     * collector.
     */
    private function optional(string $block, callable $fn): void
    {
        try {
            $reason = $fn();
            if (is_string($reason) && $reason !== '') {
                $this->absent[$block] = $reason;
            }
        } catch (\Throwable $e) {
            $this->failures[] = $block . ': ' . $e->getMessage();
            $this->absent[$block] = 'exception: ' . $e->getMessage();
        }
    }

    /**
     * Publishes the state of every optional block.
     *
     * The old code only reported blocks that threw. A block that quietly
     * returned nothing - a missing binary, an unreadable directory - was
     * indistinguishable from a family that simply has no data right now.
     */
    private function publishBlockState(MetricsWriter $w): void
    {
        $blocks = [
            'exporter_runtime',
            'database_files',
            'mysql',
            'webserver',
            'time_sync',
            'kernel_events',
            'modules_state',
        ];

        $present = [];
        $reasons = [];
        foreach ($blocks as $block) {
            $isPresent = !isset($this->absent[$block]);
            $present[] = [['block' => $block], $isPresent ? 1 : 0];
            if (!$isPresent) {
                $reasons[] = [['block' => $block, 'reason' => substr($this->absent[$block], 0, 200)], 1];
            }
        }

        $w->metric(
            'mikopbx_exporter_block_present',
            'Whether the optional exporter block produced data in the last scrape',
            'gauge',
            $present
        );
        $w->metric(
            'mikopbx_exporter_block_absent_reason',
            'Why an optional exporter block did not produce data',
            'gauge',
            $reasons
        );

        if ($this->failures === []) {
            return;
        }
        $samples = [];
        foreach ($this->failures as $failure) {
            [$block, $reason] = array_pad(explode(': ', $failure, 2), 2, '');
            $samples[] = [
                ['block' => $block, 'reason' => substr(trim($reason), 0, 200)],
                1,
            ];
        }
        $w->metric(
            'mikopbx_exporter_block_error_info',
            'Optional exporter blocks that failed during the last scrape (value is always 1)',
            'gauge',
            $samples
        );
    }

    private function collectExporterRuntime(MetricsWriter $w): ?string
    {
        $startedAt = (int)($this->stats['startedAt'] ?? 0);
        $w->metric(
            'mikopbx_exporter_build_info',
            'Build information about the MikoPBX Prometheus exporter',
            'gauge',
            [[['module' => 'ModulePrometheus', 'version' => PrometheusMain::getVersion()], 1]]
        );

        // The exporter answers on its own, so a scrape of this endpoint is
        // the proof that the process lives.
        $w->metric(
            'mikopbx_exporter_up',
            'Whether the exporter process is running (value is always 1 when the metrics were collected)',
            'gauge',
            [[[], 1]]
        );

        if ($startedAt > 0) {
            $w->metric(
                'mikopbx_exporter_start_time_seconds',
                'Unix time of the exporter process start',
                'gauge',
                [[[], $startedAt]]
            );
        }

        $w->metric(
            'mikopbx_exporter_scrapes_total',
            'Number of scrape requests served since the exporter started',
            'counter',
            [[[], (int)($this->stats['scrapesTotal'] ?? 0)]]
        );
        $w->metric(
            'mikopbx_exporter_scrape_failures_total',
            'Number of scrapes that ended with an error since the exporter started',
            'counter',
            [[[], (int)($this->stats['scrapeFailures'] ?? 0)]]
        );
        $w->metric(
            'mikopbx_exporter_scrape_timeouts_total',
            'Number of scrapes aborted because the scrape timeout was reached',
            'counter',
            [[[], (int)($this->stats['scrapeTimeouts'] ?? 0)]]
        );
        $w->metric(
            'mikopbx_exporter_requests_total',
            'Number of HTTP requests received since the exporter started',
            'counter',
            [[[], (int)($this->stats['requestsTotal'] ?? 0)]]
        );
        $w->metric(
            'mikopbx_exporter_auth_failures_total',
            'Number of requests rejected because of a missing or wrong bearer token',
            'counter',
            [[[], (int)($this->stats['authFailures'] ?? 0)]]
        );
        $w->metric(
            'mikopbx_exporter_active_children',
            'Number of scrape requests currently being collected',
            'gauge',
            [[[], (int)($this->stats['activeChildren'] ?? 0)]]
        );

        $responseCodes = $this->stats['responseCodes'] ?? [];
        if (is_array($responseCodes) && $responseCodes !== []) {
            $samples = [];
            foreach ($responseCodes as $code => $count) {
                $samples[] = [['code' => (string)$code], (int)$count];
            }
            $w->metric(
                'mikopbx_exporter_http_responses_total',
                'Number of HTTP responses by status code',
                'counter',
                $samples
            );
        }

        $durationSum = (float)($this->stats['requestDurationSum'] ?? 0);
        $requests = (int)($this->stats['requestsTotal'] ?? 0);
        if ($requests > 0) {
            $w->metric(
                'mikopbx_exporter_request_duration_seconds_sum',
                'Total time spent serving requests since the exporter started',
                'counter',
                [[[], round($durationSum, 6)]]
            );
            $w->metric(
                'mikopbx_exporter_request_duration_seconds_average',
                'Average time spent serving a request since the exporter started',
                'gauge',
                [[[], round($durationSum / $requests, 6)]]
            );
        }

        $w->metric(
            'mikopbx_exporter_config_info',
            'Current exporter settings (value is always 1)',
            'gauge',
            [[
                [
                    'listen_port' => (string)PrometheusMain::getListenPort(),
                    'listen_address' => PrometheusMain::getListenAddress(),
                    'auth_enabled' => PrometheusMain::isAuthEnabled() ? '1' : '0',
                    'tls_enabled' => PrometheusMain::isTlsEnabled() ? '1' : '0',
                    'scrape_cache_ttl' => (string)PrometheusMain::getScrapeCacheTtl(),
                ],
                1,
            ]]
        );

        // Advertised budget so Grafana can warn before Prometheus gives up,
        // rather than after the scrapes have already started failing
        $w->metric(
            'mikopbx_exporter_scrape_timeout_seconds',
            'Advertised budget for a single scrape, published so alerting can compare it against the actual duration',
            'gauge',
            [[[], (float)PrometheusMain::getScrapeTimeout()]]
        );

        $w->metric(
            'mikopbx_exporter_memory_bytes',
            'Memory used by the exporter process as reported by PHP',
            'gauge',
            [
                [['type' => 'current'], memory_get_usage(true)],
                [['type' => 'peak'], memory_get_peak_usage(true)],
            ]
        );

        $gcRuns = gc_status();
        if (is_array($gcRuns)) {
            $w->metric(
                'mikopbx_exporter_gc_runs_total',
                'Garbage collection runs in the exporter process',
                'counter',
                [[[], (int)($gcRuns['runs'] ?? 0)]]
            );
        }

        if (function_exists('opcache_get_status') && function_exists('opcache_get_configuration')) {
            $status = @opcache_get_status(false);
            $config = @opcache_get_configuration();
            if (is_array($status) && is_array($config)) {
                $w->metric(
                    'mikopbx_exporter_opcache_enabled',
                    'Whether OPcache is active in the exporter process',
                    'gauge',
                    [[[], (int)(!empty($status['opcache_enabled']))]]
                );
                $w->metric(
                    'mikopbx_exporter_opcache_memory_bytes',
                    'OPcache memory accounting as reported by PHP',
                    'gauge',
                    [
                        [['type' => 'used'], (int)($status['memory_usage']['used_memory'] ?? 0)],
                        [['type' => 'free'], (int)($status['memory_usage']['free_memory'] ?? 0)],
                        [['type' => 'wasted'], (int)($status['memory_usage']['wasted_memory'] ?? 0)],
                    ]
                );
                $w->metric(
                    'mikopbx_exporter_opcache_hit_ratio',
                    'OPcache cache hit ratio (0..1) as reported by the status endpoint',
                    'gauge',
                    [[
                        [],
                        isset($status['opcache_statistics'])
                            ? round((float)$status['opcache_statistics']['hit_ratio'], 6)
                            : 0.0,
                    ]]
                );
            }
        }

        return null;
    }

    /**
     * Database file sizes straight from the filesystem. This is the cheapest
     * possible source and it is the one that predicts a full disk, so it
     * stays even when the database server itself is unreachable.
     */
    private function collectDatabaseFiles(MetricsWriter $w): ?string
    {
        $dbDir = Directories::getDir(Directories::CORE_CONF_BACKUP_DIR);
        if (!is_string($dbDir) || !is_dir($dbDir)) {
            return 'database directory is not readable';
        }

        $byType = [];
        foreach (glob($dbDir . '/*.db') ?: [] as $file) {
            $size = self::fileSize($file);
            if ($size === null) {
                continue;
            }
            $type = str_contains(basename($file), 'cdr') ? 'cdr' : 'main';
            $byType[$type] = ($byType[$type] ?? 0) + $size;
        }

        if ($byType === []) {
            return 'no database files found';
        }
        $samples = [];
        foreach ($byType as $type => $size) {
            $samples[] = [['database' => $type], $size];
        }
        $w->metric(
            'mikopbx_database_size_bytes',
            'Size of the SQLite database files by purpose',
            'gauge',
            $samples
        );

        return null;
    }

    /**
     * Connection counters from the bundled client. Absent binary means the
     * whole family disappears instead of reporting zeros.
     */
    private function collectMysql(MetricsWriter $w): ?string
    {
        $client = Util::which('mysqladmin');
        if ($client === '') {
            $client = Util::which('mariadb-admin');
        }
        if ($client === '') {
            return 'mysqladmin is not installed';
        }

        $output = [];
        $rc = 1;
        @exec($client . ' status 2>/dev/null', $output, $rc);
        if ($rc !== 0 || $output === []) {
            return 'mysqladmin status returned nothing';
        }

        $samples = [];
        foreach (explode("\n", implode(' ', $output)) as $pair) {
            if (!preg_match('/(\S+)\s+(\d+)/', $pair, $m)) {
                continue;
            }
            $samples[] = [['metric' => strtolower($m[1])], (int)$m[2]];
        }
        if ($samples === []) {
            return 'mysqladmin status could not be parsed';
        }
        $w->metric(
            'mikopbx_mysql_status',
            'Counter values reported by mysqladmin status',
            'gauge',
            $samples
        );

        return null;
    }

    private function collectWebServer(MetricsWriter $w): ?string
    {
        $statusUrl = 'http://127.0.0.1:80/nginx_status';
        $output = [];
        $rc = 1;
        @exec('/bin/busybox wget -q -O - ' . escapeshellarg($statusUrl) . ' 2>/dev/null', $output, $rc);
        if ($rc !== 0 || $output === []) {
            return 'nginx status endpoint is not available';
        }

        $values = [];
        foreach ($output as $line) {
            if (preg_match('/^(\w+):\s*(\d+)/', trim($line), $m) === 1) {
                $values[strtolower($m[1])] = (int)$m[2];
            }
        }
        if ($values === []) {
            return 'nginx status endpoint returned nothing';
        }

        $samples = [];
        foreach ($values as $metric => $value) {
            $samples[] = [['metric' => $metric], $value];
        }
        $w->metric(
            'mikopbx_webserver_status',
            'Counters reported by the local nginx status endpoint',
            'gauge',
            $samples
        );

        return null;
    }

    private function collectTimeSync(MetricsWriter $w): ?string
    {
        $chronyc = Util::which('chronyc');
        if ($chronyc !== '') {
            $output = [];
            $rc = 1;
            @exec($chronyc . ' tracking 2>/dev/null', $output, $rc);
            if ($rc === 0 && $output !== []) {
                $text = implode(' ', $output);
                // "System time : 0.000000 seconds fast of NTP time"
                if (preg_match('/System time\s*:\s*(-?[\d.]+)/', $text, $m) === 1) {
                    $w->metric(
                        'mikopbx_time_sync_offset_seconds',
                        'System clock offset against NTP in seconds',
                        'gauge',
                        [[[], (float)$m[1]]]
                    );
                }
                // "Leap status     : Normal"
                if (preg_match('/Leap status\s*:\s*(\w+)/', $text, $m) === 1) {
                    $w->metric(
                        'mikopbx_time_sync_leap_status',
                        'NTP leap status as reported by chrony (1 = Normal)',
                        'gauge',
                        [[[], strtolower($m[1]) === 'normal' ? 1 : 0]]
                    );
                }
                return null;
            }
        }

        $timedatectl = Util::which('timedatectl');
        if ($timedatectl === '') {
            return 'neither chronyc nor timedatectl is installed';
        }
        $output = [];
        $rc = 1;
        @exec($timedatectl . ' show -p NTPSynchronized 2>/dev/null', $output, $rc);
        if ($rc !== 0 || $output === []) {
            return 'timedatectl returned nothing';
        }
        $w->metric(
            'mikopbx_time_sync_leap_status',
            'NTP leap status as reported by the system (1 = synchronized)',
            'gauge',
            [[[], str_contains(strtolower($output[0]), 'yes') ? 1 : 0]]
        );

        return null;
    }

    /**
     * OOM kills and kernel panics from the ring buffer. Needs privileges,
     * so an unreadable buffer just yields nothing.
     */
    private function collectKernelEvents(MetricsWriter $w): ?string
    {
        $dmesg = Util::which('dmesg');
        if ($dmesg === '') {
            return 'dmesg is not installed';
        }
        $output = [];
        $rc = 1;
        @exec($dmesg . ' 2>/dev/null', $output, $rc);
        if ($rc !== 0 || $output === []) {
            return 'kernel ring buffer is not readable';
        }

        $text = implode("\n", $output);
        $w->metric(
            'mikopbx_kernel_oom_kills_total',
            'Out of memory killer invocations visible in the kernel ring buffer since boot',
            'gauge',
            [[[], preg_match_all('/Out of memory: Killed process/i', $text)]]
        );
        $w->metric(
            'mikopbx_kernel_panics_total',
            'Kernel panics visible in the ring buffer since boot',
            'gauge',
            [[[], preg_match_all('/Kernel panic/i', $text)]]
        );

        return null;
    }

    private function collectModulesState(MetricsWriter $w): ?string
    {
        try {
            $total = PbxExtensionModules::find(['conditions' => '1=1'])->count();
            $enabled = PbxExtensionModules::find(['conditions' => 'disabled = "0"'])->count();
        } catch (\Throwable $e) {
            return 'module table is not readable';
        }

        $w->metric('mikopbx_modules_total', 'Number of installed extension modules', 'gauge', [[[], $total]]);
        $w->metric('mikopbx_modules_enabled', 'Number of enabled extension modules', 'gauge', [[[], $enabled]]);

        return null;
    }
}
