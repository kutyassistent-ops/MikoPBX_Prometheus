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

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\System;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModulePrometheus\Models\ModulePrometheus;
use Phalcon\Di\Injectable;

/**
 * Class PrometheusMain
 *
 * Main class for managing the Prometheus exporter module.
 *
 * @package Lib
 */
class PrometheusMain extends Injectable
{
    /**
     * Process title of the exporter worker (must match the title set by
     * Lib/WorkerPrometheusExporter.php).
     */
    public const SERVICE_EXPORTER = 'ModulePrometheusExporter';

    public const DEFAULT_LISTEN_ADDRESS = '0.0.0.0';
    public const DEFAULT_LISTEN_PORT = 9713;

    /** Rendered payloads are reused for this many seconds by default (0 = always fresh). */
    public const DEFAULT_SCRAPE_CACHE_TTL = 0;

    /** Advertised scrape budget, mirrored from the Prometheus scrape_timeout. */
    public const DEFAULT_SCRAPE_TIMEOUT = 10;

    /** How many scrapes may be collected at the same time. */
    public const DEFAULT_MAX_CONCURRENT_SCRAPES = 8;

    /** Authorization is opt-in: a fresh install never requires a token. */
    public const AUTH_DISABLED = '0';
    public const AUTH_ENABLED = '1';

    private const MODULE_UNIQUE_ID = 'ModulePrometheus';

    private const BIN_SCRIPTS = [
        'prometheus-exporter',
        'prometheus-safe-script.sh',
        'prometheus-stats-cache.sh',
    ];

    /**
     * @var array Directories used by the module
     */
    public array $dirs;

    /**
     * @var array Module settings
     */
    public array $module_settings = [];

    /**
     * Cached instance. The constructor creates directories and queries the
     * database, and the static getters below are called several times per
     * request - the status panel polls every 10 seconds from the browser.
     * Settings only change through a module restart, so a per-process cache
     * is safe. restartService() drops it explicitly.
     */
    private static ?self $instance = null;

    private static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Drops the cached instance so the next getter re-reads the database.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * PrometheusMain constructor.
     * Initializes module directories, checks module status and loads settings.
     */
    public function __construct()
    {
        $this->dirs = $this->getModuleDirs();
        $this->loadSettings();
    }

    /**
     * (Re)reads the settings from the database and applies the defaults.
     *
     * The worker calls refreshSettings() before every request, so a rotated
     * bearer token or a changed source address list takes effect without a
     * restart of the exporter.
     */
    private function loadSettings(): void
    {
        $this->module_settings = [];

        if (PbxExtensionUtils::isEnabled(self::MODULE_UNIQUE_ID)) {
            $settings = ModulePrometheus::findFirst();
            if ($settings !== null) {
                $this->module_settings = $settings->toArray();
            }
        }

        if (empty($this->module_settings['listenAddress'])) {
            $this->module_settings['listenAddress'] = self::DEFAULT_LISTEN_ADDRESS;
        }
        if (empty($this->module_settings['listenPort'])) {
            $this->module_settings['listenPort'] = self::DEFAULT_LISTEN_PORT;
        }
        // Authorization is off unless it is explicitly switched on. An
        // empty token alone used to be the only signal, so a token left in
        // the database from an earlier version would silently start
        // rejecting scrapes after an upgrade.
        if (!isset($this->module_settings['authEnabled'])) {
            $this->module_settings['authEnabled'] = self::AUTH_DISABLED;
        }
        if (!isset($this->module_settings['authToken'])) {
            $this->module_settings['authToken'] = '';
        }
        if (!isset($this->module_settings['scrapeCacheTtl'])) {
            $this->module_settings['scrapeCacheTtl'] = self::DEFAULT_SCRAPE_CACHE_TTL;
        }
        if (!isset($this->module_settings['scrapeTimeout'])) {
            $this->module_settings['scrapeTimeout'] = self::DEFAULT_SCRAPE_TIMEOUT;
        }
        if (!isset($this->module_settings['tlsEnabled'])) {
            $this->module_settings['tlsEnabled'] = '0';
        }
        if (!isset($this->module_settings['tlsCert'])) {
            $this->module_settings['tlsCert'] = '';
        }
        if (!isset($this->module_settings['tlsKey'])) {
            $this->module_settings['tlsKey'] = '';
        }
        if (!isset($this->module_settings['allowedSourceIp'])) {
            $this->module_settings['allowedSourceIp'] = '';
        }
        if (!isset($this->module_settings['publishChannelQuality'])) {
            $this->module_settings['publishChannelQuality'] = '0';
        }
        if (!isset($this->module_settings['maxConcurrentScrapes'])) {
            $this->module_settings['maxConcurrentScrapes'] = (string)self::DEFAULT_MAX_CONCURRENT_SCRAPES;
        }
    }

    /**
     * Re-reads the settings of the running process.
     *
     * The instance is kept, only the values are replaced, so the
     * directories are not touched again.
     */
    public static function refreshSettings(): void
    {
        self::instance()->loadSettings();
    }

    /**
     * Whether the exporter serves HTTPS instead of plain HTTP.
     */
    public static function isTlsEnabled(): bool
    {
        $main = self::instance();
        return (string)($main->module_settings['tlsEnabled'] ?? '0') === '1';
    }

    /**
     * Absolute path to the TLS certificate chain.
     */
    public static function getTlsCertificate(): string
    {
        $main = self::instance();
        return trim((string)($main->module_settings['tlsCert'] ?? ''));
    }

    /**
     * Absolute path to the private key.
     */
    public static function getTlsKey(): string
    {
        $main = self::instance();
        return trim((string)($main->module_settings['tlsKey'] ?? ''));
    }

    /**
     * How long a rendered payload may be reused before the next scrape
     * recomputes it. 0 means every request collects fresh data.
     */
    public static function getScrapeCacheTtl(): int
    {
        $main = self::instance();
        $ttl = (int)($main->module_settings['scrapeCacheTtl'] ?? 0);
        return ($ttl >= 0 && $ttl <= 300) ? $ttl : self::DEFAULT_SCRAPE_CACHE_TTL;
    }

    /**
     * Advertised budget for a single scrape. Published as a metric so
     * Grafana can warn before Prometheus starts timing out.
     */
    public static function getScrapeTimeout(): int
    {
        $main = self::instance();
        $timeout = (int)($main->module_settings['scrapeTimeout'] ?? 0);
        return ($timeout >= 1 && $timeout <= 120) ? $timeout : self::DEFAULT_SCRAPE_TIMEOUT;
    }

    /**
     * How many scrapes may be collected at the same time.
     */
    public static function getMaxConcurrentScrapes(): int
    {
        $main = self::instance();
        $limit = (int)($main->module_settings['maxConcurrentScrapes'] ?? 0);
        return ($limit >= 1 && $limit <= 64) ? $limit : self::DEFAULT_MAX_CONCURRENT_SCRAPES;
    }

    /**
     * Whether the per channel RTP quality family is published.
     */
    public static function isChannelQualityEnabled(): bool
    {
        $main = self::instance();
        return (string)($main->module_settings['publishChannelQuality'] ?? '0') === '1';
    }

    /**
     * List of allowed source addresses (plain IPs and CIDR networks).
     *
     * @return string[]
     */
    public static function getAllowedSourceIps(): array
    {
        $main = self::instance();
        $raw  = (string)($main->module_settings['allowedSourceIp'] ?? '');
        $list = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Checks a peer address against the allow list.
     *
     * An empty allow list means "any source" - the firewall rule of the
     * module stays the only restriction in that case.
     */
    public static function isSourceAllowed(string $peerAddress): bool
    {
        $allowed = self::getAllowedSourceIps();
        if ($allowed === []) {
            return true;
        }
        $ip = self::extractIp($peerAddress);
        if ($ip === '') {
            return false;
        }
        foreach ($allowed as $rule) {
            if (self::ipMatches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips the port from a "ip:port" peer address.
     */
    private static function extractIp(string $peerAddress): string
    {
        $peerAddress = trim($peerAddress);
        if ($peerAddress === '') {
            return '';
        }
        // IPv6 with brackets: [::1]:1234
        if (str_starts_with($peerAddress, '[')) {
            $end = strpos($peerAddress, ']');
            return $end === false ? $peerAddress : substr($peerAddress, 1, $end - 1);
        }
        // Bare IPv6 has several colons and no port
        if (substr_count($peerAddress, ':') > 1) {
            return $peerAddress;
        }
        $pos = strrpos($peerAddress, ':');
        if ($pos === false) {
            return $peerAddress;
        }

        return substr($peerAddress, 0, $pos);
    }

    /**
     * Matches an address against a plain IP or a CIDR network.
     */
    private static function ipMatches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }
        [$network, $bits] = explode('/', $rule, 2);
        $bits = (int)$bits;
        if ($bits < 0 || $bits > 128) {
            return false;
        }
        $ipBinary = @inet_pton($ip);
        $netBinary = @inet_pton(trim($network));
        if ($ipBinary === false || $netBinary === false || strlen($ipBinary) !== strlen($netBinary)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($bytes > 0 && strncmp($ipBinary, $netBinary, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return ($ipBinary[$bytes] & $mask) === ($netBinary[$bytes] & $mask);
    }

    /**
     * Builds a socket endpoint for the given scheme, address and port.
     *
     * IPv6 literals have to be wrapped in brackets, otherwise the port
     * separator becomes ambiguous and the connection always fails.
     */
    public static function buildEndpoint(string $scheme, string $address, int $port): string
    {
        $address = trim($address);
        if (str_contains($address, ':') && !str_starts_with($address, '[')) {
            $address = '[' . $address . ']';
        }

        return sprintf('%s://%s:%d', $scheme, $address, $port);
    }

    /**
     * Normalises a listen address for use in a socket endpoint.
     *
     * Accepts a bare host, a bracketed IPv6 literal, or anything the
     * operator already typed with a scheme. Returns null when the result
     * cannot be used in a stream_socket_server() target.
     */
    public static function normalizeListenAddress(string $address): ?string
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        // Strip a scheme if the operator pasted one
        if (preg_match('#^[a-z0-9+.-]+://#i', $address) === 1) {
            $address = preg_replace('#^[a-z0-9+.-]+://#i', '', $address) ?? '';
        }
        // Strip a trailing :port if one was typed in
        if (substr_count($address, ':') === 1) {
            $address = preg_replace('/:\d+$/', '', $address) ?? $address;
        }
        $address = trim($address, '[]');
        return $address === '' ? null : $address;
    }

    /**
     * Prepares and returns the directories required by the module.
     */
    private function getModuleDirs(): array
    {
        $moduleDir = PbxExtensionUtils::getModuleDir(self::MODULE_UNIQUE_ID);

        $binDir = $moduleDir . '/bin';
        Util::mwMkdir($binDir);

        $logDir = System::getLogDir() . '/' . self::MODULE_UNIQUE_ID;
        Util::mwMkdir($logDir);

        return [
            'moduleDir' => $moduleDir,
            'logDir' => $logDir,
            'binDir' => $binDir,
        ];
    }

    /**
     * Absolute path to the module bin directory, holding the cron scripts
     * and the disabled marker.
     */
    public static function getBinDir(): string
    {
        return self::instance()->dirs['binDir'];
    }

    /**
     * Returns the configured listen port (falls back to the default).
     */
    public static function getListenPort(): int
    {
        $main = self::instance();
        $port = (int)($main->module_settings['listenPort'] ?? 0);
        return ($port >= 1 && $port <= 65535) ? $port : self::DEFAULT_LISTEN_PORT;
    }

    /**
     * Returns the configured listen address (falls back to the default).
     */
    public static function getListenAddress(): string
    {
        $main = self::instance();
        $address = (string)($main->module_settings['listenAddress'] ?? '');
        return self::normalizeListenAddress($address) ?? self::DEFAULT_LISTEN_ADDRESS;
    }

    /**
     * Returns the optional bearer token (empty string = no authorization).
     */
    public static function isAuthEnabled(): bool
    {
        $main = self::instance();
        return (string)($main->module_settings['authEnabled'] ?? self::AUTH_DISABLED) === self::AUTH_ENABLED;
    }

    /**
     * Returns the bearer token, or an empty string when authorization is
     * disabled. The exporter treats an empty token as "no authorization",
     * so a stale token can never re-enable it on its own.
     */
    public static function getAuthToken(): string
    {
        if (!self::isAuthEnabled()) {
            return '';
        }
        $main = self::instance();
        return trim((string)($main->module_settings['authToken'] ?? ''));
    }

    /**
     * Returns the module version from module.json ('dev' for raw sources).
     */
    public static function getVersion(): string
    {
        $main = self::instance();
        if (!empty($main->module_settings['__version'])) {
            return (string)$main->module_settings['__version'];
        }
        $moduleDir = $main->dirs['moduleDir'];
        $moduleJson = '';
        $manifest = $moduleDir . '/module.json';
        if (is_file($manifest) && is_readable($manifest)) {
            try {
                $read = file_get_contents($manifest);
                $moduleJson = $read === false ? '' : $read;
            } catch (\Throwable $e) {
                $moduleJson = '';
            }
        }
        if ($moduleJson !== '') {
            $data = json_decode($moduleJson, true);
            $version = $data['version'] ?? '';
            if (is_string($version) && $version !== '' && $version[0] !== '%') {
                // Cache it: render() asks for the version on every scrape
                $main->module_settings['__version'] = $version;
                return $version;
            }
        }
        return 'dev';
    }

    /**
     * Whether the exporter worker process is running.
     */
    public static function isRunning(): bool
    {
        return !empty(Processes::getPidOfProcess(self::SERVICE_EXPORTER));
    }

    /**
     * Checks that an address can actually be bound.
     *
     * Called by the settings form before saving, so a typo in the listen
     * address is reported instead of leaving the exporter silently down
     * after a restart.
     */
    public static function isValidListenAddress(string $address): bool
    {
        $address = self::normalizeListenAddress($address) ?? '';
        if ($address === '') {
            return false;
        }
        // Port 0 asks the kernel for a free one, so the probe never collides
        // with a real service - the point is only to validate the host part.
        $handle = @stream_socket_server(self::buildEndpoint('tcp', $address, 0), $errno, $errstr);
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        return true;
    }

    /**
     * Checks that a list of source addresses is syntactically usable.
     *
     * @return string|null error message or null when the list is fine
     */
    public static function validateSourceIps(string $raw): ?string
    {
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (str_contains($item, '/')) {
                [$network, $bits] = explode('/', $item, 2);
                if (!ctype_digit($bits) || (int)$bits > 128) {
                    return "Invalid CIDR mask in '$item'";
                }
                if (@inet_pton(trim($network)) === false) {
                    return "Invalid network address in '$item'";
                }
                continue;
            }
            if (@inet_pton($item) === false) {
                return "Invalid IP address '$item'";
            }
        }

        return null;
    }

    /**
     * Starts the exporter if not already running.
     * Uses 'start' action so a second call is a no-op when the worker is up.
     */
    public static function startService(): void
    {
        $main = self::instance();
        self::ensureExecutable($main->dirs['binDir']);
        $path = $main->dirs['binDir'] . '/prometheus-exporter';
        if (!file_exists($path)) {
            return;
        }
        Processes::processWorker($path, 'start', self::SERVICE_EXPORTER, 'start');
    }

    /**
     * Stops the exporter worker.
     */
    public static function stopService(): void
    {
        $main = self::instance();
        $path = $main->dirs['binDir'] . '/prometheus-exporter';
        Processes::processWorker($path, '', self::SERVICE_EXPORTER, 'stop');
    }

    /**
     * Restarts the exporter (used when configuration has changed).
     */
    public static function restartService(): void
    {
        // The settings were just persisted by WorkerModelsEvents. Drop the
        // cache first, otherwise the worker would be started with the old
        // port/token read before the change.
        self::resetInstance();
        $main = self::instance();
        self::ensureExecutable($main->dirs['binDir']);
        $path = $main->dirs['binDir'] . '/prometheus-exporter';
        if (!file_exists($path)) {
            return;
        }
        Processes::processWorker($path, 'start', self::SERVICE_EXPORTER, 'restart');
    }

    /**
     * Makes bin scripts executable. Module archives built on Windows or
     * unpacked by tools that lose the executable bit would otherwise
     * produce "permission denied" on start.
     */
    private static function ensureExecutable(string $binDir): void
    {
        foreach (self::BIN_SCRIPTS as $script) {
            $path = $binDir . '/' . $script;
            if (file_exists($path)) {
                @chmod($path, 0755);
            }
        }
    }
}
