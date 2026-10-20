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

/**
 * Renders samples in the Prometheus text exposition format (version 0.0.4).
 *
 * Extracted from MetricsCollector so collectors stay independent of the
 * rendering details and can be tested on their own.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class MetricsWriter
{
    /** @var string[] */
    private array $lines = [];

    /** @var array<string, bool> Guards against a duplicate HELP/TYPE header. */
    private array $declared = [];

    /**
     * Appends one metric family (HELP/TYPE header plus samples).
     *
     * A family with an empty sample list is skipped entirely: Prometheus
     * rejects a header with no samples anyway.
     *
     * @param array<int, array{0: array<string, string>, 1: int|float}> $samples
     */
    public function metric(string $name, string $help, string $type, array $samples): void
    {
        if ($samples === []) {
            return;
        }
        if (!isset($this->declared[$name])) {
            $this->declared[$name] = true;
            $this->lines[] = "# HELP {$name} " . self::escapeHelp($help) . "\n";
            $this->lines[] = "# TYPE {$name} {$type}\n";
        }
        foreach ($samples as [$labels, $value]) {
            $this->lines[] = $name . self::formatLabels($labels) . ' ' . self::formatValue($value) . "\n";
        }
    }

    /**
     * Escapes a label set into the Prometheus exposition syntax.
     *
     * @param array<string, string> $labels
     */
    public static function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . self::escapeLabelValue((string)$value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Formats a numeric value for the exposition format.
     *
     * @param int|float $value
     */
    public static function formatValue($value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            // NaN and +/-Inf are legal in the format but every renderer and
            // most query engines handle 0 predictably instead
            if (is_nan($value) || is_infinite($value)) {
                return '0';
            }
            $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
        }
        return (string)$value;
    }

    /**
     * @return string The whole exposition payload
     */
    public function render(): string
    {
        return implode('', $this->lines);
    }

    /**
     * Number of published metric families.
     */
    public function familyCount(): int
    {
        return count($this->declared);
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /**
     * HELP text must escape backslash and newline only - a stray quote is
     * legal there and escaping it would be wrong.
     */
    private static function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }
}