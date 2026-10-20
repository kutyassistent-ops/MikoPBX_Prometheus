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

namespace Modules\ModulePrometheus\Setup;

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModulePrometheus\Lib\ApiHelper;
use Modules\ModulePrometheus\Lib\PrometheusMain;

/**
 * Class PbxExtensionSetup
 * Module installer and uninstaller
 *
 * @package Modules\ModulePrometheus\Setup
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /**
     * Stops the worker and drops the statistics cache before the base class
     * deletes the module directory.
     *
     * killModuleBinProcesses() covers the update path, but a plain delete
     * from the web interface must stop the exporter on its own - otherwise
     * an orphaned process keeps the port bound after the module is gone.
     *
     * @param bool $keepSettings Keep the module database
     * @return bool
     */
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        try {
            PrometheusMain::stopService();
        } catch (\Throwable $exception) {
            // A worker that is already gone must not block the removal
            $this->messages[] = $exception->getMessage();
        }

        // A stale cache would be picked up verbatim on the next install
        $cacheDir = ApiHelper::CACHE_DIR;
        if (is_dir($cacheDir)) {
            $rm = Util::which('rm');
            Processes::mwExec($rm . ' -rf ' . escapeshellarg($cacheDir));
        }

        return parent::unInstallFiles($keepSettings);
    }
}
