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
 * Returns the counters of the running exporter.
 *
 * The browser cannot reach the exporter port reliably - it listens on the
 * PBX, not on the admin interface - so the PBX asks the exporter itself and
 * passes the answer through.
 *
 * @package Modules\ModulePrometheus\Lib\RestAPI\Status\Actions
 */
class GetExporterInfoAction
{
    private const PROBE_TIMEOUT = 3;

    /**
     * @param array<string, mixed> $data Request data (unused)
     * @return PBXApiResult
     */
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $address = PrometheusMain::getListenAddress();
        $port = PrometheusMain::getListenPort();
        $scheme = PrometheusMain::isTlsEnabled() ? 'tls' : 'tcp';

        // A wildcard listener is reached through the loopback interface.
        $host = in_array($address, ['0.0.0.0', '::', '*', ''], true) ? '127.0.0.1' : $address;
        $endpoint = PrometheusMain::buildEndpoint($scheme, $host, $port);

        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client($endpoint, $errno, $errstr, self::PROBE_TIMEOUT);
        if ($connection === false) {
            $result->success = false;
            $result->messages['error'][] = "Cannot connect to {$endpoint}: {$errstr} ({$errno})";
            return $result;
        }

        stream_set_timeout($connection, self::PROBE_TIMEOUT);
        $token = PrometheusMain::getAuthToken();
        $request = "GET /-/info HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n";
        if ($token !== '') {
            $request .= "Authorization: Bearer {$token}\r\n";
        }
        $request .= "\r\n";
        @fwrite($connection, $request);

        $raw = '';
        while (!feof($connection)) {
            $chunk = @fread($connection, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > 65536) {
                break;
            }
        }
        @fclose($connection);

        $body = $raw;
        $separator = strpos($raw, "\r\n\r\n");
        if ($separator !== false) {
            $body = substr($raw, $separator + 4);
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            $result->success = false;
            $result->messages['error'][] = 'The exporter did not return a readable answer';
            return $result;
        }

        $result->success = true;
        $result->data = $decoded;

        return $result;
    }
}
