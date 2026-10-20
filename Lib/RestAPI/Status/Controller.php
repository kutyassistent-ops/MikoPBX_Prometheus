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

use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use MikoPBX\PBXCoreREST\Attributes\{
    ApiResource,
    ApiOperation,
    ApiResponse,
    ApiDataSchema,
    HttpMapping,
    SecurityType,
    ResourceSecurity
};

/**
 * Prometheus exporter status controller (REST API v3,
 * auto-discovery via PHP 8 attributes).
 *
 * Provides exporter status and scrape config download endpoints.
 *
 * @package Modules\ModulePrometheus\Lib\RestAPI\Status
 */
#[ApiResource(
    path: '/pbxcore/api/v3/module-prometheus/status',
    tags: ['Module Prometheus - Status'],
    description: 'Prometheus exporter service status and connectivity probe',
    processor: Processor::class
)]
#[HttpMapping(
    mapping: [
        'GET' => ['getStatus', 'testConnection', 'getExporterInfo']
    ],
    resourceLevelMethods: [],
    collectionLevelMethods: ['getStatus', 'testConnection', 'getExporterInfo'],
    customMethods: ['getStatus', 'testConnection', 'getExporterInfo']
)]
#[ResourceSecurity('module-prometheus-status', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * Get Prometheus exporter service status.
     *
     * @route GET /pbxcore/api/v3/module-prometheus/status:getStatus
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'Get Prometheus exporter service status',
        description: 'Returns running state, PID, module version, listen address/port and metrics URL path',
        operationId: 'getPrometheusExporterStatus'
    )]
    #[ApiResponse(200, 'Service status retrieved')]
    #[ApiResponse(500, 'rest_response_500')]
    public function getStatus(): void {}


    /**
     * Probe the exporter listener from the PBX itself.
     *
     * @route GET /pbxcore/api/v3/module-prometheus/status:testConnection
     */
    #[ApiOperation(
        summary: 'Test exporter connectivity',
        description: 'Opens a socket to the configured listen address and port and runs the health probe',
        operationId: 'testPrometheusExporterConnection'
    )]
    #[ApiResponse(200, 'Listener reachable')]
    #[ApiResponse(500, 'Listener unreachable')]
    public function testConnection(): void {}

    /**
     * Counters of the running exporter.
     *
     * @route GET /pbxcore/api/v3/module-prometheus/status:getExporterInfo
     */
    #[ApiOperation(
        summary: 'Get exporter counters',
        description: 'Returns the request counters, the last scrape result and the current configuration of the exporter process',
        operationId: 'getPrometheusExporterInfo'
    )]
    #[ApiResponse(200, 'Exporter counters retrieved')]
    #[ApiResponse(500, 'Exporter is not reachable')]
    public function getExporterInfo(): void {}
}
