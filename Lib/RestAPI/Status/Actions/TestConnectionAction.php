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

declare(strict_types=1);

namespace Modules\ModulePrometheus\Lib\RestAPI\Status\Actions;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModulePrometheus\Lib\PrometheusMain;

/**
 * Opens a socket to the exporter from the PBX itself.
 *
 * The interesting failure is "the exporter is up but Prometheus cannot reach
 * it", which the status endpoint cannot show: it runs on the PBX. This
 * action at least proves the listener is bound and answers, which separates
 * "exporter down" from "port blocked in the firewall".
 *
 * @package Modules\ModulePrometheus\Lib\RestAPI\Status\Actions
 */
class TestConnectionAction
{
    private const PROBE_TIMEOUT = 3;

    /**
     * @param array<string, mixed> $data Request data
     * @return PBXApiResult
     */
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $address = PrometheusMain::getListenAddress();
        $port = PrometheusMain::getListenPort();
        $scheme = PrometheusMain::isTlsEnabled() ? 'tls' : 'tcp';

        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client(
            "{$scheme}://{$address}:{$port}",
            $errno,
            $errstr,
            self::PROBE_TIMEOUT
        );

        if ($connection === false) {
            $result->success = false;
            $result->messages['error'][] = "Cannot connect to {$scheme}://{$address}:{$port}: {$errstr} ({$errno})";
            return $result;
        }

        stream_set_timeout($connection, self::PROBE_TIMEOUT);
        @fwrite($connection, "GET /-/healthy HTTP/1.1\r\nHost: {$address}\r\nConnection: close\r\n\r\n");
        $response = (string)@fread($connection, 256);
        @fclose($connection);

        $reachable = str_contains($response, ' 200 ');

        $result->success = $reachable;
        if (!$reachable) {
            $result->messages['error'][] = "Connected, but the health probe answered: " . trim(substr($response, 0, 80));
        }

        $result->data = [
            'address' => $address,
            'port' => $port,
            'scheme' => $scheme,
            'reachable' => $reachable,
        ];

        return $result;
    }
}