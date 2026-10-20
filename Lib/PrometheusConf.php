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

use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModulePrometheus\Models\ModulePrometheus;

/**
 * Class PrometheusConf
 *
 * MikoPBX lifecycle hooks: starts/stops the exporter, registers the
 * firewall rule and cron tasks.
 */
class PrometheusConf extends ConfigClass
{
    /**
     * Runs after the PBX has fully started.
     */
    public function onAfterPbxStarted(): void
    {
        PrometheusMain::startService();
    }

    /**
     * Runs after the module is disabled in the web interface.
     */
    public function onAfterModuleDisable(): void
    {
        PrometheusMain::stopService();
        self::setDisabledMarker(true);
    }

    /**
     * Runs before the module is enabled in the web interface.
     *
     * Binding failures used to surface only in syslog, leaving the UI with a
     * bare "Stopped" status and no hint why.
     */
    public function onBeforeModuleEnable(): bool
    {
        $address = PrometheusMain::getListenAddress();
        $port = PrometheusMain::getListenPort();

        if (!PrometheusMain::isValidListenAddress($address)) {
            $this->messages[] = "Cannot bind to listen address '$address'";
            return false;
        }

        $handle = @stream_socket_server("tcp://{$address}:{$port}", $errno, $errstr);
        if ($handle === false) {
            $this->messages[] = "Port {$port} on {$address} is not available: {$errstr}";
            return false;
        }
        fclose($handle);

        return true;
    }

    /**
     * Runs after the module is enabled in the web interface.
     */
    public function onAfterModuleEnable(): void
    {
        self::setDisabledMarker(false);
        PrometheusMain::startService();
    }

    /**
     * Drops a marker file next to the cron scripts.
     *
     * createCronTasks() writes the jobs for the module as a whole, and they
     * survive disabling it, so the per-minute health check would otherwise
     * restart an exporter the operator switched off.
     */
    private static function setDisabledMarker(bool $disabled): void
    {
        $marker = PrometheusMain::getBinDir() . '/disabled';
        if ($disabled) {
            @touch($marker);
            return;
        }
        if (is_file($marker)) {
            @unlink($marker);
        }
    }

    /**
     * Restarts the exporter when its settings are changed in the database.
     *
     * @param mixed $data
     */
    public function modelsEventChangeData($data): void
    {
        if (($data['model'] ?? '') === ModulePrometheus::class) {
            PrometheusMain::restartService();
        }
    }

    /**
     * Firewall rule for the exporter port. Access should be limited to the
     * Prometheus server subnet in the firewall settings.
     *
     * When the module knows the allowed addresses, the rule is created for
     * them as well - otherwise the port stays open for the whole network
     * while the exporter itself only accepts the monitoring server.
     */
    public function getDefaultFirewallRules(): array
    {
        $listenPort = PrometheusMain::getListenPort();
        $allowed = PrometheusMain::getAllowedSourceIps();
        $rule = [
            'portfrom' => $listenPort,
            'portto' => $listenPort,
            'protocol' => 'tcp',
            'name' => 'PrometheusListenPort',
        ];
        if ($allowed !== []) {
            $rule['sourceIp'] = implode(',', $allowed);
        }

        return [
            'ModulePrometheus' => [
                'rules' => [$rule],
                'action' => 'allow',
                'shortName' => 'Prometheus',
            ],
        ];
    }

    /**
     * Adds crond tasks: exporter health check every minute and statistics
     * cache refresh every five minutes.
     *
     * @param $tasks
     */
    public function createCronTasks(&$tasks): void
    {
        $safeScript = $this->moduleDir . '/bin/prometheus-safe-script.sh';
        $tasks[] = "* * * * * {$safeScript} > /dev/null 2> /dev/null\n";

        $cacheScript = $this->moduleDir . '/bin/prometheus-stats-cache.sh';
        $tasks[] = "*/5 * * * * {$cacheScript} > /dev/null 2> /dev/null\n";
    }
}
