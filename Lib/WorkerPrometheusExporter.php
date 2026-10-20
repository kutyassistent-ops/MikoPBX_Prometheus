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

use Error;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;

require_once 'Globals.php';
require_once __DIR__ . '/ApiHelper.php';
require_once __DIR__ . '/PrometheusMain.php';
require_once __DIR__ . '/MetricsCollector.php';
require_once __DIR__ . '/Collectors/MetricsWriter.php';
require_once __DIR__ . '/Collectors/AbstractCollector.php';
require_once __DIR__ . '/Collectors/SystemCollector.php';
require_once __DIR__ . '/Collectors/AsteriskCollector.php';
require_once __DIR__ . '/Collectors/TelemetryCollector.php';
require_once __DIR__ . '/Collectors/CallStatsCollector.php';
require_once __DIR__ . '/Collectors/InfrastructureCollector.php';
require_once __DIR__ . '/Collectors/CollectorRegistry.php';

/**
 * Long-running worker that serves metrics in the Prometheus text
 * exposition format (version 0.0.4) over HTTP.
 *
 * Routes:
 *   GET /metrics      - metrics
 *   GET /health       - liveness probe
 *   GET /-/healthy    - liveness probe (Prometheus exporter convention)
 *   GET /-/ready      - readiness probe: 503 unless every collector ran
 *   GET /-/info       - JSON snapshot of the exporter counters
 *   GET /             - same as /health
 *
 * Each connection is handled in a forked child, so a slow or hostile client
 * cannot block the accept loop. The child reports the outcome of the request
 * back to the parent through a socket pair, which keeps the counters in one
 * place - the parent is the only process that survives a request, so the
 * numbers have to be aggregated there. When a bearer token is configured,
 * every request must carry a matching "Authorization: Bearer <token>" header;
 * the token is re-read from the database per request so rotating it does not
 * need a restart.
 */
class WorkerPrometheusExporter extends WorkerBase
{
    public const PROCESS_TITLE = 'ModulePrometheusExporter';

    private const MAX_REQUEST_BYTES = 8192;
    private const ACCEPT_TIMEOUT = 2;
    private const READ_TIMEOUT = 2;

    /** How often the parent re-reads the settings from the database. */
    private const SETTINGS_REFRESH_INTERVAL = 30;

    /** A child that runs longer than this is killed. */
    private const CHILD_TIMEOUT = 60;

    /** Routes that answer with a health or information document. */
    private const ROUTES = ['/metrics', '/health', '/-/healthy', '/-/ready', '/-/info', '/'];

    private int $startedAt = 0;
    private int $lastSettingsRefresh = 0;
    private int $requestsTotal = 0;
    private int $scrapesTotal = 0;
    private int $scrapeFailures = 0;
    private int $scrapeTimeouts = 0;
    private int $authFailures = 0;
    private int $activeChildren = 0;
    private float $requestDurationSum = 0.0;

    /** @var array<int,int> HTTP status code => number of responses */
    private array $responseCodes = [];

    /** @var array{duration:float,families:int,bytes:int,at:int,ok:bool} */
    private array $lastScrape = ['duration' => 0.0, 'families' => 0, 'bytes' => 0, 'at' => 0, 'ok' => false];

    /** @var array<int,resource> pid => socket with the report of the child */
    private array $childSockets = [];

    /** @var array<int,int> pid => start time of the child */
    private array $childStartedAt = [];

    /** @var array{body:string, ok:bool, expires:float, families:int}|null */
    private ?array $payloadCache = null;

    private string $bindAddress = '';
    private int $bindPort = 9713;
    private int $cacheTtl = 0;
    private int $scrapeTimeout = 10;
    private int $maxConcurrentScrapes = 8;

    /**
     * Worker entry point.
     *
     * @param $argv
     */
    public function start($argv): void
    {
        $this->bindAddress = PrometheusMain::getListenAddress();
        $this->bindPort = PrometheusMain::getListenPort();
        $this->cacheTtl = PrometheusMain::getScrapeCacheTtl();
        $this->scrapeTimeout = PrometheusMain::getScrapeTimeout();
        $this->maxConcurrentScrapes = PrometheusMain::getMaxConcurrentScrapes();

        $server = $this->bind();
        if ($server === null) {
            return;
        }

        Util::sysLogMsg(self::PROCESS_TITLE, "Prometheus exporter listening on {$this->bindAddress}:{$this->bindPort}");

        $this->startedAt = time();
        $stop = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void {
                $stop = true;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        $canFork = function_exists('pcntl_fork');
        if (!$canFork) {
            Util::sysLogMsg(
                self::PROCESS_TITLE . '_FORK',
                'pcntl_fork is unavailable, falling back to sequential handling'
            );
        }

        while (!$stop) {
            $connection = $this->acceptConnection($server);
            if ($connection === false || $connection === null) {
                $this->reapChildren();
                $this->refreshSettingsPeriodically();
                continue;
            }

            try {
                // The source address list is checked before the fork, so the
                // parent has to know the current settings.
                $this->refreshSettingsPeriodically();

                $this->requestsTotal++;
                $peer = (string)@stream_socket_get_name($connection, true);

                // A connection from a forbidden address is closed right here, so
                // an unwanted scraper cannot even reach the accept loop.
                if (!PrometheusMain::isSourceAllowed($peer)) {
                    $this->sendResponse($connection, 403, 'Forbidden', "forbidden\n");
                    @fclose($connection);
                    $this->countResponse(403);
                    $this->reapChildren();
                    continue;
                }

                // Too many collections in flight: answer instead of forking one
                // more child, otherwise a burst of scrapes can fork the box.
                if ($this->activeChildren >= $this->maxConcurrentScrapes) {
                    $this->sendResponse($connection, 503, 'Service Unavailable', "too many concurrent scrapes\n");
                    @fclose($connection);
                    $this->countResponse(503);
                    $this->reapChildren();
                    continue;
                }

                if ($canFork) {
                    $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                    $pid = pcntl_fork();
                    if ($pid === 0) {
                        // Child: handle one request and leave. The parent keeps
                        // the counters, the child must not touch them.
                        if (is_array($pair)) {
                            @fclose($pair[0]);
                        }
                        $report = $this->serveConnectionSafely($connection);
                        if (is_array($pair)) {
                            @fwrite($pair[1], json_encode($report) . "\n");
                            @fclose($pair[1]);
                        }
                        @fclose($connection);
                        exit(0);
                    }
                    if ($pid > 0) {
                        if (is_array($pair)) {
                            @fclose($pair[1]);
                            $this->childSockets[$pid] = $pair[0];
                            $this->childStartedAt[$pid] = time();
                        }
                        @fclose($connection);
                        $this->activeChildren++;
                        // Reap finished children without blocking
                        $this->reapChildren();
                        continue;
                    }
                    // fork() failed: handle it inline rather than dropping it
                }

                $report = $this->serveConnectionSafely($connection);
                $this->applyReport($report);
            } catch (\Throwable $e) {
                // A single broken request must not take the exporter down.
                $this->scrapeFailures++;
                Util::sysLogMsg(self::PROCESS_TITLE . '_REQUEST_ERROR', $e->getMessage());
                if (is_resource($connection)) {
                    @fclose($connection);
                }
            }
        }

        @fclose($server);
    }

    /**
     * Waits for a connection without a warning.
     *
     * stream_socket_accept() with a timeout raises a warning when the timeout
     * is reached, and MikoPBX turns every warning into an exception - even
     * under "@" - which killed the exporter every two seconds. stream_select()
     * reports the timeout by returning zero, and accept() is then called on a
     * socket that is known to be ready.
     *
     * @param resource $server
     *
     * @return resource|null
     */
    private function acceptConnection($server)
    {
        $read   = [$server];
        $write  = null;
        $except = null;
        set_error_handler(static fn() => true);
        try {
            $ready = stream_select($read, $write, $except, self::ACCEPT_TIMEOUT, 0);
        } finally {
            restore_error_handler();
        }
        if ($ready === false || $ready === 0) {
            return null;
        }

        set_error_handler(static fn() => true);
        try {
            return stream_socket_accept($server, 0);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Serves a request and always returns a report.
     *
     * The report is the only way the parent learns what happened, so a
     * failing request has to be reported as well.
     *
     * @param resource $connection
     *
     * @return array<string,mixed>
     */
    private function serveConnectionSafely($connection): array
    {
        try {
            return $this->serveConnection($connection);
        } catch (\Throwable $e) {
            Util::sysLogMsg(self::PROCESS_TITLE . '_REQUEST_ERROR', $e->getMessage());

            return [
                'code' => 500,
                'duration' => 0.0,
                'failed' => true,
                'timeout' => false,
                'unauthorized' => false,
                'scrape' => false,
            ];
        }
    }

    /**
     * Re-reads the settings of the parent process from time to time.
     *
     * The parent checks the source address list before it forks, so the
     * list has to be current here. A database query every few seconds is
     * cheap enough and keeps a change effective without a restart.
     */
    private function refreshSettingsPeriodically(): void
    {
        $now = time();
        if ($this->lastSettingsRefresh > 0 && ($now - $this->lastSettingsRefresh) < self::SETTINGS_REFRESH_INTERVAL) {
            return;
        }
        $this->lastSettingsRefresh = $now;
        try {
            PrometheusMain::refreshSettings();
        } catch (\Throwable $e) {
            // A failing settings reload must not take the exporter down.
            Util::sysLogMsg(self::PROCESS_TITLE . '_SETTINGS_ERROR', $e->getMessage());
        }
    }

    /**
     * Collects the reports of the finished children and updates the counters.
     */
    private function reapChildren(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }
        try {
            while (true) {
                $pid = pcntl_waitpid(-1, $status, WNOHANG);
                if ($pid <= 0) {
                    break;
                }
                $report = null;
                $hadSocket = isset($this->childSockets[$pid]);
                if ($hadSocket) {
                    $sock = $this->childSockets[$pid];
                    // The report is read only when it is actually there: a
                    // blocking read would freeze the whole exporter.
                    $read   = [$sock];
                    $write  = null;
                    $except = null;
                    set_error_handler(static fn() => true);
                    try {
                        $ready = stream_select($read, $write, $except, 0, 200000);
                        if ($ready > 0) {
                            $raw = (string)@fread($sock, 8192);
                            $report = json_decode(trim($raw), true);
                        }
                    } finally {
                        restore_error_handler();
                    }
                    @fclose($sock);
                    unset($this->childSockets[$pid]);
                    unset($this->childStartedAt[$pid]);
                }
                if (is_array($report)) {
                    $this->applyReport($report);
                } elseif ($hadSocket) {
                    Util::sysLogMsg(self::PROCESS_TITLE . '_REPORT', "The child {$pid} finished without a report");
                }
                if ($this->activeChildren > 0) {
                    $this->activeChildren--;
                }
            }
            $this->killStuckChildren();
        } catch (\Throwable $e) {
            // Reaping must never take the exporter down.
            Util::sysLogMsg(self::PROCESS_TITLE . '_REAP_ERROR', $e->getMessage());
        }
    }

    /**
     * Kills children that run far too long.
     *
     * A child that hangs (a client that stopped reading, a stuck collector)
     * would keep the slot occupied forever.
     */
    private function killStuckChildren(): void
    {
        if ($this->childStartedAt === []) {
            return;
        }
        $now = time();
        foreach ($this->childStartedAt as $pid => $startedAt) {
            if (($now - $startedAt) < self::CHILD_TIMEOUT) {
                continue;
            }
            Util::sysLogMsg(self::PROCESS_TITLE . '_CHILD_TIMEOUT', "Killing the stuck child {$pid}");
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            }
            if (isset($this->childSockets[$pid])) {
                @fclose($this->childSockets[$pid]);
                unset($this->childSockets[$pid]);
            }
            unset($this->childStartedAt[$pid]);
            if ($this->activeChildren > 0) {
                $this->activeChildren--;
            }
        }
    }

    /**
     * Applies the report of a finished request to the counters.
     *
     * @param array{code?:int,duration?:float,failed?:bool,timeout?:bool,unauthorized?:bool,scrape?:bool,families?:int,bytes?:int,scrapeOk?:bool} $report
     */
    private function applyReport(array $report): void
    {
        $code = (int)($report['code'] ?? 0);
        if ($code > 0) {
            $this->countResponse($code);
        }
        if (!empty($report['scrape'])) {
            // The counter lives in the parent: the child that served the
            // request is already gone when the next scrape starts.
            $this->scrapesTotal++;
        }
        $duration = (float)($report['duration'] ?? 0);
        $this->requestDurationSum += $duration;
        if (!empty($report['unauthorized'])) {
            $this->authFailures++;
        }
        if (!empty($report['timeout'])) {
            $this->scrapeTimeouts++;
        }
        if (!empty($report['failed'])) {
            $this->scrapeFailures++;
        }
        // The result of the collection is produced by the child, but the
        // status panel is served by the parent - the numbers have to
        // travel with the report.
        if (isset($report['families'])) {
            $this->lastScrape = [
                'duration' => round($duration, 6),
                'families' => (int)$report['families'],
                'bytes' => (int)($report['bytes'] ?? 0),
                'at' => time(),
                'ok' => (bool)($report['scrapeOk'] ?? false),
            ];
        }
    }

    /**
     * @param int $code
     */
    private function countResponse(int $code): void
    {
        $this->responseCodes[$code] = ($this->responseCodes[$code] ?? 0) + 1;
    }

    /**
     * Creates the listening socket, optionally with TLS.
     *
     * @return resource|null
     */
    private function bind()
    {
        $tlsEnabled = PrometheusMain::isTlsEnabled();
        $endpoint = $tlsEnabled ? 'tls' : 'tcp';
        $target = PrometheusMain::buildEndpoint($endpoint, $this->bindAddress, $this->bindPort);

        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server($target, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            Util::sysLogMsg(
                self::PROCESS_TITLE . '_BIND_ERROR',
                "Cannot bind {$target}: {$errstr} ({$errno})"
            );
            return null;
        }

        if ($tlsEnabled) {
            $cert = PrometheusMain::getTlsCertificate();
            $key = PrometheusMain::getTlsKey();
            if ($cert === '' || $key === '') {
                Util::sysLogMsg(
                    self::PROCESS_TITLE . '_TLS_ERROR',
                    'TLS is enabled but the certificate or key path is empty, falling back to plain HTTP'
                );
            } else {
                stream_context_set_option($server, $endpoint, 'local_cert', $cert);
                stream_context_set_option($server, $endpoint, 'local_pk', $key);
                stream_context_set_option($server, $endpoint, 'allow_self_signed', true);
                stream_context_set_option($server, $endpoint, 'verify_peer', false);
                Util::sysLogMsg(self::PROCESS_TITLE, "TLS enabled with $cert");
            }
        }

        return $server;
    }

    /**
     * Reads a single HTTP request, routes it and writes the response.
     *
     * @param resource $connection
     *
     * @return array{code:int,duration:float,failed:bool,timeout:bool,unauthorized:bool}
     */
    private function serveConnection($connection): array
    {
        $started = microtime(true);
        $report = [
            'code' => 0,
            'duration' => 0.0,
            'failed' => false,
            'timeout' => false,
            'unauthorized' => false,
            'scrape' => false,
        ];

        stream_set_timeout($connection, self::READ_TIMEOUT);

        $request = '';
        while (
            !feof($connection)
            && strpos($request, "\r\n\r\n") === false
            && strpos($request, "\n\n") === false
        ) {
            $chunk = fread($connection, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
            if (strlen($request) > self::MAX_REQUEST_BYTES) {
                break;
            }
        }

        $requestLine = strtok($request, "\r\n");
        if ($requestLine === false || $requestLine === '') {
            $this->sendResponse($connection, 400, 'Bad Request', "bad request\n");
            $report['code'] = 400;
            $report['duration'] = microtime(true) - $started;

            return $report;
        }

        $parts = explode(' ', $requestLine, 3);
        $method = $parts[0] ?? '';
        $path = $parts[1] ?? '';
        $queryPos = strpos($path, '?');
        if ($queryPos !== false) {
            $path = substr($path, 0, $queryPos);
        }

        // The route is checked before the method, so an unknown path is a
        // 404 for every verb - HEAD used to answer 200 for anything.
        if (!in_array($path, self::ROUTES, true)) {
            $this->sendResponse($connection, 404, 'Not Found', "not found\n");
            $report['code'] = 404;
            $report['duration'] = microtime(true) - $started;

            return $report;
        }

        if ($method === 'HEAD') {
            // Prometheus and the blackbox exporter both probe with HEAD first
            $this->sendResponse($connection, 200, 'OK', '', 'text/plain; charset=utf-8');
            $report['code'] = 200;
            $report['duration'] = microtime(true) - $started;

            return $report;
        }

        if ($method !== 'GET') {
            $this->sendResponse($connection, 405, 'Method Not Allowed', "method not allowed\n");
            $report['code'] = 405;
            $report['duration'] = microtime(true) - $started;

            return $report;
        }

        // Re-read the token and the address list per request: rotating the
        // token must not need a restart of the exporter.
        PrometheusMain::refreshSettings();
        $token = PrometheusMain::getAuthToken();
        if ($token !== '' && !$this->isAuthorized($request, $token)) {
            $this->sendResponse($connection, 401, 'Unauthorized', "unauthorized\n");
            $report['code'] = 401;
            $report['unauthorized'] = true;
            $report['duration'] = microtime(true) - $started;

            return $report;
        }

        switch ($path) {
            case '/metrics':
                $result = $this->serveMetrics($connection, $request);
                $report['code'] = $result['code'];
                $report['failed'] = $result['failed'];
                $report['timeout'] = $result['timeout'];
                $report['scrape'] = true;
                $report['families'] = $result['families'];
                $report['bytes'] = $result['bytes'];
                $report['scrapeOk'] = $result['scrapeOk'];
                break;
            case '/-/ready':
                $report['code'] = $this->serveReadiness($connection);
                break;
            case '/-/info':
                $report['code'] = $this->serveInfo($connection);
                break;
            case '/':
            case '/health':
            case '/-/healthy':
                $this->sendResponse($connection, 200, 'OK', "ok\n");
                $report['code'] = 200;
                break;
            default:
                $this->sendResponse($connection, 404, 'Not Found', "not found\n");
                $report['code'] = 404;
        }

        $report['duration'] = microtime(true) - $started;

        return $report;
    }

    /**
     * Serves /metrics.
     *
     * @param resource $connection
     *
     * @return array{code:int,failed:bool,timeout:bool}
     */
    private function serveMetrics($connection, string $request): array
    {
        $collector = new MetricsCollector();
        $collector->setExporterStats($this->exporterStats());

        $cached = $this->payloadCache;
        $now = microtime(true);
        $timedOut = false;
        $families = 0;
        if ($cached !== null && $cached['expires'] > $now) {
            $body = $cached['body'];
            $ok = $cached['ok'];
            $families = (int)($cached['families'] ?? 0);
        } else {
            $deadline = microtime(true) + $this->scrapeTimeout;
            try {
                $result = $collector->collect($deadline);
                $body = $result['body'];
                $ok = $result['ok'];
                $families = (int)$result['families'];
                $timedOut = (bool)($result['timedOut'] ?? false);
                $this->lastScrape = [
                    'duration' => round($result['duration'], 6),
                    'families' => $families,
                    'bytes' => strlen($body),
                    'at' => time(),
                    'ok' => $ok,
                ];
                $this->payloadCache = [
                    'body' => $body,
                    'ok' => $ok,
                    'families' => $families,
                    'expires' => $now + max(0, $this->cacheTtl),
                ];
            } catch (\Throwable $e) {
                Util::sysLogMsg(self::PROCESS_TITLE . '_COLLECT_ERROR', $e->getMessage());
                $this->sendResponse($connection, 500, 'Internal Server Error', "collection failed\n");

                return ['code' => 500, 'failed' => true, 'timeout' => false];
            }
        }

        // A collector that failed is reported through collector_success.
        // The payload itself is still sent: Prometheus prefers partial data
        // over no data, and /-/ready shows the problem.
        $this->sendResponse(
            $connection,
            200,
            'OK',
            $body,
            'text/plain; version=0.0.4; charset=utf-8',
            $this->acceptsGzip($request)
        );

        return [
            'code' => 200,
            'failed' => false,
            'timeout' => $timedOut,
            'families' => $families,
            'bytes' => strlen($body),
            'scrapeOk' => $ok,
        ];
    }

    /**
     * Readiness probe: 200 only when every collector produced data.
     *
     * @param resource $connection
     */
    private function serveReadiness($connection): int
    {
        $cached = $this->payloadCache;
        if ($cached === null) {
            $this->sendResponse($connection, 503, 'Service Unavailable', "not collected yet\n");

            return 503;
        }
        if (!$cached['ok']) {
            $this->sendResponse($connection, 503, 'Service Unavailable', "a collector is failing\n");

            return 503;
        }
        $this->sendResponse($connection, 200, 'OK', "ready\n");

        return 200;
    }

    /**
     * JSON snapshot of the exporter counters.
     *
     * The values are the ones the parent process knows, so they describe
     * the exporter as a whole and not only the current request.
     *
     * @param resource $connection
     */
    private function serveInfo($connection): int
    {
        $payload = [
            'version'               => PrometheusMain::getVersion(),
            'startedAt'             => $this->startedAt,
            'uptimeSeconds'         => max(0, time() - $this->startedAt),
            'requestsTotal'         => $this->requestsTotal,
            'scrapesTotal'          => $this->scrapesTotal,
            'scrapeFailures'        => $this->scrapeFailures,
            'scrapeTimeouts'        => $this->scrapeTimeouts,
            'authFailures'          => $this->authFailures,
            'activeChildren'        => $this->activeChildren,
            'responseCodes'         => $this->responseCodes,
            'requestDurationSum'    => round($this->requestDurationSum, 6),
            'maxConcurrentScrapes'  => $this->maxConcurrentScrapes,
            'scrapeCacheTtl'        => $this->cacheTtl,
            'scrapeTimeout'         => $this->scrapeTimeout,
            'listenAddress'         => $this->bindAddress,
            'listenPort'            => $this->bindPort,
            'lastScrape'            => $this->lastScrape,
            'textfileFiles'         => count(glob(ApiHelper::TEXTFILE_DIR . '/*.prom') ?: []),
        ];
        $body = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->sendResponse($connection, 200, 'OK', (string)$body, 'application/json; charset=utf-8');

        return 200;
    }

    /**
     * Counters of the exporter, handed over to the collectors.
     */
    private function exporterStats(): array
    {
        return [
            'startedAt'          => $this->startedAt,
            'requestsTotal'      => $this->requestsTotal,
            'scrapesTotal'       => $this->scrapesTotal,
            'scrapeFailures'     => $this->scrapeFailures,
            'scrapeTimeouts'     => $this->scrapeTimeouts,
            'authFailures'       => $this->authFailures,
            'activeChildren'     => $this->activeChildren,
            'responseCodes'      => $this->responseCodes,
            'requestDurationSum' => $this->requestDurationSum,
            'maxConcurrentScrapes' => $this->maxConcurrentScrapes,
            'scrapeCacheTtl'     => $this->cacheTtl,
            'scrapeTimeout'      => $this->scrapeTimeout,
            'lastScrape'         => $this->lastScrape,
        ];
    }

    private function acceptsGzip(string $request): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }
        return preg_match('/^Accept-Encoding:[^\r\n]*\bgzip\b/mi', $request) === 1;
    }

    /**
     * Validates the Authorization header against the configured token.
     */
    private function isAuthorized(string $request, string $token): bool
    {
        if (preg_match('/^Authorization:\s*Bearer\s+(.+)$/mi', $request, $m) !== 1) {
            return false;
        }
        return hash_equals($token, trim($m[1]));
    }

    /**
     * Writes a minimal HTTP/1.1 response and closes the connection body.
     *
     * @param resource $connection
     */
    private function sendResponse(
        $connection,
        int $code,
        string $reason,
        string $body,
        string $contentType = 'text/plain; charset=utf-8',
        bool $gzip = false
    ): void {
        $headers = "HTTP/1.1 {$code} {$reason}\r\n"
            . "Content-Type: {$contentType}\r\n"
            . "X-Content-Type-Options: nosniff\r\n";

        if ($gzip && $body !== '') {
            $encoded = gzencode($body, 6);
            if ($encoded !== false) {
                $body = $encoded;
                $headers .= "Content-Encoding: gzip\r\n";
            }
        }

        $headers .= 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n";
        @fwrite($connection, $headers . $body);
    }
}

// Start worker process
$workerClassname = WorkerPrometheusExporter::class;
if (isset($argv) && count($argv) > 1) {
    cli_set_process_title(WorkerPrometheusExporter::PROCESS_TITLE);
    try {
        $worker = new $workerClassname();
        $worker->start($argv);
    } catch (Error $e) {
        global $errorLogger;
        $errorLogger->captureException($e);
        Util::sysLogMsg($workerClassname . '_EXCEPTION', $e->getMessage());
    }
}
