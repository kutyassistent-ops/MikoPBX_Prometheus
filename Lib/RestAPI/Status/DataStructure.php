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

namespace Modules\ModulePrometheus\Lib\RestAPI\Status;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

/**
 * Data structure for the Prometheus exporter Status resource.
 *
 * @package Modules\ModulePrometheus\Lib\RestAPI\Status
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'running' => [
                    'type' => 'boolean',
                    'description' => 'Whether the exporter worker process is running',
                    'example' => true,
                ],
                'pid' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Process ID of the running exporter',
                    'example' => 12345,
                ],
                'version' => [
                    'type' => 'string',
                    'description' => 'Module version',
                    'example' => '1.0.0',
                ],
                'listenAddress' => [
                    'type' => 'string',
                    'description' => 'Configured listen address',
                    'example' => '0.0.0.0',
                ],
                'listenPort' => [
                    'type' => 'integer',
                    'description' => 'Configured listen port',
                    'example' => 9713,
                ],
                'authEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether a bearer token is required for scraping',
                    'example' => false,
                ],
                'authTokenSet' => [
                    'type' => 'boolean',
                    'description' => 'Whether a bearer token is stored (the token itself is never returned)',
                    'example' => false,
                ],
                'metricsPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path that serves the metrics',
                    'example' => '/metrics',
                ],
                'readyPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path of the readiness probe',
                    'example' => '/-/ready',
                ],
                'infoPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path of the exporter counter document',
                    'example' => '/-/info',
                ],
                'tlsEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the exporter serves HTTPS',
                    'example' => false,
                ],
                'scrapeCacheTtl' => [
                    'type' => 'integer',
                    'description' => 'How long a rendered payload is reused, in seconds',
                    'example' => 0,
                ],
                'scrapeTimeout' => [
                    'type' => 'integer',
                    'description' => 'Advertised budget for a single scrape, in seconds',
                    'example' => 10,
                ],
                'maxConcurrentScrapes' => [
                    'type' => 'integer',
                    'description' => 'How many scrapes may be collected at the same time',
                    'example' => 8,
                ],
                'allowedSourceIps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Addresses allowed to scrape the exporter (empty means any)',
                    'example' => ['192.168.1.10'],
                ],
                'channelQualityEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the per channel RTP quality family is published',
                    'example' => false,
                ],
                'textfileDir' => [
                    'type' => 'string',
                    'description' => 'Directory with the textfile collector snippets',
                    'example' => '/storage/usbdisk1/mikopbx/tmp/ModulePrometheus/textfile',
                ],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [],
            'response' => [
                'running' => [
                    'type' => 'boolean',
                    'description' => 'Whether the exporter worker process is running',
                    'readOnly' => true,
                ],
                'pid' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Process ID of the running exporter',
                    'readOnly' => true,
                ],
                'version' => [
                    'type' => 'string',
                    'description' => 'Module version',
                    'readOnly' => true,
                ],
                'listenAddress' => [
                    'type' => 'string',
                    'description' => 'Configured listen address',
                    'readOnly' => true,
                ],
                'listenPort' => [
                    'type' => 'integer',
                    'description' => 'Configured listen port',
                    'readOnly' => true,
                ],
                'authEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether a bearer token is required for scraping',
                    'readOnly' => true,
                ],
                'authTokenSet' => [
                    'type' => 'boolean',
                    'description' => 'Whether a bearer token is stored',
                    'readOnly' => true,
                ],
                'metricsPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path that serves the metrics',
                    'readOnly' => true,
                ],
                'readyPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path of the readiness probe',
                    'readOnly' => true,
                ],
                'infoPath' => [
                    'type' => 'string',
                    'description' => 'HTTP path of the exporter counter document',
                    'readOnly' => true,
                ],
                'tlsEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the exporter serves HTTPS',
                    'readOnly' => true,
                ],
                'scrapeCacheTtl' => [
                    'type' => 'integer',
                    'description' => 'How long a rendered payload is reused, in seconds',
                    'readOnly' => true,
                ],
                'scrapeTimeout' => [
                    'type' => 'integer',
                    'description' => 'Advertised budget for a single scrape, in seconds',
                    'readOnly' => true,
                ],
                'maxConcurrentScrapes' => [
                    'type' => 'integer',
                    'description' => 'How many scrapes may be collected at the same time',
                    'readOnly' => true,
                ],
                'allowedSourceIps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Addresses allowed to scrape the exporter',
                    'readOnly' => true,
                ],
                'channelQualityEnabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the per channel RTP quality family is published',
                    'readOnly' => true,
                ],
                'textfileDir' => [
                    'type' => 'string',
                    'description' => 'Directory with the textfile collector snippets',
                    'readOnly' => true,
                ],
            ],
            'related' => [],
        ];
    }
}
