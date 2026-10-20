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

use MikoPBX\Common\Models\PbxExtensionModules;
use Modules\ModulePrometheus\Lib\Collectors\AsteriskCollector;
use Modules\ModulePrometheus\Lib\Collectors\CallStatsCollector;
use Modules\ModulePrometheus\Lib\Collectors\CollectorRegistry;
use Modules\ModulePrometheus\Lib\Collectors\InfrastructureCollector;
use Modules\ModulePrometheus\Lib\Collectors\SystemCollector;
use Modules\ModulePrometheus\Lib\Collectors\TelemetryCollector;

/**
 * Facade over the collector set.
 *
 * The worker only needs render(); everything about how a family is produced
 * lives in Lib/Collectors/. Kept as a class of its own because the worker
 * bootstraps through require_once and cannot rely on autoloading.
 *
 * @package Modules\ModulePrometheus\Lib
 */
class MetricsCollector
{
    /** @var array<string,mixed> Counters of the exporter process */
    private array $exporterStats = [];

    /**
     * Feeds the worker-side counters into the exporter metrics.
     *
     * @param array<string,mixed> $stats
     */
    public function setExporterStats(array $stats): void
    {
        $this->exporterStats = $stats;
    }

    /**
     * Renders all metrics for one scrape.
     *
     * The deadline is a UNIX timestamp. When it is reached, the collectors
     * that did not run yet are skipped and the scrape is reported as failed:
     * a partially collected payload that looks complete is worse than an
     * error Prometheus can see.
     *
     * @return array{body:string, ok:bool, failed:string[], families:int, duration:float, timedOut:bool}
     */
    public function collect(float $deadline = 0.0): array
    {
        $registry = new CollectorRegistry();

        $registry->add(new SystemCollector());
        $registry->add(new AsteriskCollector());
        $registry->add(new TelemetryCollector());
        $registry->add(new CallStatsCollector());
        $registry->add(new InfrastructureCollector($this->exporterStats));
        $registry->setTrunkNames($this->resolveTrunkNames());

        $result = $registry->run($deadline);
        $result['body'] = CollectorRegistry::appendTextfiles(
            $result['body'],
            ApiHelper::TEXTFILE_DIR
        );

        return $result;
    }

    /**
     * Provider id => display name, so the trunk metrics can carry a readable
     * label without every collector querying the API again.
     *
     * @return array<string, string>
     */
    private function resolveTrunkNames(): array
    {
        $names = [];
        try {
            foreach (ApiHelper::getAllProviderStatuses() as $provider) {
                $id = (string)($provider['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $names[$id] = (string)($provider['description'] ?? ($provider['username'] ?? $id));
            }
        } catch (\Throwable) {
            return [];
        }
        return $names;
    }

    /**
     * Number of installed/enabled modules, kept here for the REST status
     * action which reports it next to the exporter state.
     *
     * @return array{total:int, enabled:int}
     */
    public static function modulesState(): array
    {
        try {
            return [
                'total' => PbxExtensionModules::find(['conditions' => '1=1'])->count(),
                'enabled' => PbxExtensionModules::find(['conditions' => 'disabled = "0"'])->count(),
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'enabled' => 0];
        }
    }
}
