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

namespace Modules\ModulePrometheus\Lib\Collectors;

use Throwable;

/**
 * Shared plumbing for collectors: a name for the success/duration labels and
 * a guarded run() so one broken collector cannot take the whole scrape down.
 *
 * Collectors that read an external source whose output format is not
 * guaranteed should catch their own errors and return without emitting a
 * family. Collectors backed by the PBX API throw, so that
 * mikopbx_exporter_collector_success reports the loss instead of silently
 * publishing an empty result.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
abstract class AbstractCollector
{
    private string $lastError = '';
    private float $lastDuration = 0.0;
    private bool $lastSuccess = false;

    /**
     * Machine name used in the collector="..." label.
     */
    abstract public function name(): string;

    /**
     * Emits the metric families this collector is responsible for.
     */
    abstract public function collect(MetricsWriter $writer): void;

    public function run(MetricsWriter $writer): void
    {
        $start = microtime(true);
        $this->lastSuccess = true;
        $this->lastError = '';
        try {
            $this->collect($writer);
        } catch (Throwable $e) {
            $this->lastSuccess = false;
            $this->lastError = $e->getMessage();
        }
        $this->lastDuration = microtime(true) - $start;
    }

    public function isSuccessful(): bool
    {
        return $this->lastSuccess;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function lastDuration(): float
    {
        return $this->lastDuration;
    }

    /**
     * Reads a file without turning a missing file into an exception.
     *
     * MikoPBX converts every warning into an exception, and the "@" operator
     * does not suppress that: a file that disappeared between the glob and
     * the read would otherwise kill the whole scrape.
     */
    protected static function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        try {
            $content = file_get_contents($path);
        } catch (\Throwable $e) {
            return '';
        }

        return $content === false ? '' : $content;
    }

    /**
     * Size of a file, or null when it cannot be read.
     */
    protected static function fileSize(string $path): ?int
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        try {
            $size = filesize($path);
        } catch (\Throwable $e) {
            return null;
        }

        return $size === false ? null : (int)$size;
    }
}