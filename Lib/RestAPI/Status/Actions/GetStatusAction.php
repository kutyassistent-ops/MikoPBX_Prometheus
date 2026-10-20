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

use MikoPBX\Core\System\Processes;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModulePrometheus\Lib\ApiHelper;
use Modules\ModulePrometheus\Lib\PrometheusMain;

/**
 * Returns Prometheus exporter service status.
 *
 * @package Modules\ModulePrometheus\Lib\RestAPI\Status\Actions
 */
class GetStatusAction
{
    /**
     * @param array<string, mixed> $data Request data (unused)
     * @return PBXApiResult
     */
    public static function main(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $pid = Processes::getPidOfProcess(PrometheusMain::SERVICE_EXPORTER);
        $running = !empty($pid);

        $result->success = true;
        $result->data = [
            'running' => $running,
            'pid' => $running ? (int)$pid : null,
            'version' => PrometheusMain::getVersion(),
            'listenAddress' => PrometheusMain::getListenAddress(),
            'listenPort' => PrometheusMain::getListenPort(),
            // The flag, not the token: an enabled flag without a token would
            // leave the exporter open, and the UI has to show that.
            'authEnabled' => PrometheusMain::isAuthEnabled(),
            'authTokenSet' => PrometheusMain::getAuthToken() !== '',
            'metricsPath' => '/metrics',
            'readyPath' => '/-/ready',
            'infoPath' => '/-/info',
            'tlsEnabled' => PrometheusMain::isTlsEnabled(),
            'scrapeCacheTtl' => PrometheusMain::getScrapeCacheTtl(),
            'scrapeTimeout' => PrometheusMain::getScrapeTimeout(),
            'maxConcurrentScrapes' => PrometheusMain::getMaxConcurrentScrapes(),
            'allowedSourceIps' => PrometheusMain::getAllowedSourceIps(),
            'channelQualityEnabled' => PrometheusMain::isChannelQualityEnabled(),
            'textfileDir' => ApiHelper::TEXTFILE_DIR,
        ];

        return $result;
    }
}
