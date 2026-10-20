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

use Modules\ModulePrometheus\Lib\PrometheusMain;

/**
 * Metrics read through the Asterisk command line.
 *
 * Every CLI command forks a process and opens an AMI connection, so this
 * collector is the slowest one. Optional sub-blocks (queues, conferences,
 * channel statistics) swallow their own failures: the output format differs
 * between Asterisk releases and a missing family is far less harmful than a
 * permanently red collector.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class AsteriskCollector extends AbstractCollector
{
    private const ASTERISK_BIN = '/usr/sbin/asterisk';

    public function name(): string
    {
        return 'asterisk';
    }

    public function collect(MetricsWriter $w): void
    {
        $up = 0;
        $version = '';
        $versionOutput = $this->cli('core show version');
        if ($versionOutput !== null && $versionOutput !== '') {
            $up = 1;
            if (preg_match('/(\d+(?:\.\d+)+)/', $versionOutput, $m) === 1) {
                $version = $m[1];
            }
        }

        $w->metric(
            'mikopbx_asterisk_up',
            'Whether Asterisk is running and responds to the CLI (1 = up)',
            'gauge',
            [[[], $up]]
        );
        if ($up === 0) {
            return;
        }

        if ($version !== '') {
            $w->metric(
                'mikopbx_asterisk_info',
                'Asterisk build information (value is always 1)',
                'gauge',
                [[['version' => $version], 1]]
            );
        }

        $this->collectUptime($w);
        $this->collectChannels($w);
        $this->collectQueues($w);
        $this->collectConferences($w);
        $this->collectChannelStats($w);
    }

    private function collectUptime(MetricsWriter $w): void
    {
        $output = $this->cli('core show uptime seconds');
        if ($output === null) {
            return;
        }
        if (preg_match('/System uptime:\s*(\d+)/', $output, $m) === 1) {
            $w->metric(
                'mikopbx_asterisk_uptime_seconds',
                'Asterisk uptime in seconds',
                'gauge',
                [[[], (int)$m[1]]]
            );
        }
        if (preg_match('/Last reload:\s*(\d+)/', $output, $m) === 1) {
            $w->metric(
                'mikopbx_asterisk_last_reload_seconds',
                'Seconds since the last Asterisk reload',
                'gauge',
                [[[], (int)$m[1]]]
            );
        }
    }

    private function collectChannels(MetricsWriter $w): void
    {
        $output = $this->cli('core show channels');
        if ($output === null) {
            return;
        }
        if (preg_match('/(\d+)\s+active channels?/', $output, $m) === 1) {
            $w->metric(
                'mikopbx_asterisk_channels_active',
                'Active channels in Asterisk',
                'gauge',
                [[[], (int)$m[1]]]
            );
        }
        if (preg_match('/(\d+)\s+active calls?/', $output, $m) === 1) {
            $w->metric(
                'mikopbx_asterisk_calls_active',
                'Active calls reported by Asterisk CLI',
                'gauge',
                [[[], (int)$m[1]]]
            );
        }
        if (preg_match('/(\d+)\s+calls processed/', $output, $m) === 1) {
            // Resets on every Asterisk restart: use increase(), not rate()
            $w->metric(
                'mikopbx_asterisk_calls_processed_total',
                'Calls processed since the last Asterisk restart (resets on reload)',
                'counter',
                [[[], (int)$m[1]]]
            );
        }

        $this->collectChannelMix($w, $output);
    }

    /**
     * Channel breakdown by technology prefix (SIP/PJSIP/Local/IAX/DaHDI...).
     *
     * The detail lines look like "  sip/100-200  ...  0:05", so the first
     * token identifies the technology.
     */
    private function collectChannelMix(MetricsWriter $w, string $output): void
    {
        $byType = [];
        foreach (explode("\n", $output) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '=') !== false) {
                continue;
            }
            $parts = preg_split('/\s+/', $trimmed);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            $channel = $parts[0];
            if (strpos($channel, '/') === false) {
                continue;
            }
            $type = strtolower(strtok($channel, '/'));
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        if ($byType === []) {
            return;
        }
        $samples = [];
        foreach ($byType as $type => $count) {
            $samples[] = [['type' => $type], $count];
        }
        $w->metric(
            'mikopbx_asterisk_channels_by_type',
            'Active channels grouped by channel technology prefix',
            'gauge',
            $samples
        );
    }

    /**
     * `queue show` prints one line per queue:
     *   <name>  <callers>  <active>  [<longest wait>]
     * Layout varies slightly between releases, so the numeric prefix is what
     * is anchored on and the rest is taken positionally.
     */
    private function collectQueues(MetricsWriter $w): void
    {
        $output = $this->cli('queue show');
        if ($output === null || trim($output) === '') {
            return;
        }

        $callers = $active = $agentsAvail = $agentsBusy = [];
        $waiting = [];
        $longest = 0.0;

        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s*(\[[^\]]*\])?/', trim($line), $m)) {
                continue;
            }
            $name = $m[1];
            if ($name === 'Queue' || $name === 'Queues') {
                continue; // header line
            }
            $callers[] = [['queue' => $name], (int)$m[2]];
            $active[] = [['queue' => $name], (int)$m[3]];

            // The bracketed field holds the longest wait, e.g. "[  1:23]"
            if (isset($m[4]) && $m[4] !== '') {
                $seconds = self::parseDuration($m[4]);
                $waiting[] = [['queue' => $name], $seconds];
                $longest = max($longest, $seconds);
            }
        }

        if ($callers === []) {
            return;
        }

        $w->metric('mikopbx_queue_callers', 'Callers waiting in each queue', 'gauge', $callers);
        $w->metric('mikopbx_queue_active', 'Active calls handled by each queue', 'gauge', $active);
        $w->metric(
            'mikopbx_queue_longest_wait_seconds',
            'Longest current wait in each queue in seconds',
            'gauge',
            $waiting
        );
        $w->metric(
            'mikopbx_queue_longest_wait_max_seconds',
            'Longest current wait across all queues in seconds',
            'gauge',
            [[[], round($longest, 3)]]
        );
    }

    /**
     * `conference show` prints one line per conference:
     *   <conf id> <participants> <flags> <admin> <pin>
     */
    private function collectConferences(MetricsWriter $w): void
    {
        $output = $this->cli('conference show');
        if ($output === null || trim($output) === '') {
            return;
        }

        $participants = [];
        $count = 0;
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\d+)/', $line, $m)) {
                continue;
            }
            $participants[] = [['conference' => $m[1]], (int)$m[2]];
            $count++;
        }

        if ($participants === []) {
            return;
        }

        $w->metric('mikopbx_conferences_active', 'Number of active conferences', 'gauge', [[[], $count]]);
        $w->metric('mikopbx_conference_participants', 'Participants per active conference', 'gauge', $participants);
    }

    /**
     * RTP quality from `pjsip show channelstats`.
     *
     * The command only exists on Asterisk 16+ and the column set shifted
     * between releases, so the header row drives the parsing instead of
     * fixed positions.
     *
     * The aggregated families (worst and average value per indicator) are
     * always published: their cardinality does not depend on the number of
     * concurrent calls. The per channel family multiplies with it, so it
     * is only published when the operator asks for it.
     */
    private function collectChannelStats(MetricsWriter $w): void
    {
        $output = $this->cli('pjsip show channelstats');
        if ($output === null || trim($output) === '') {
            return;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn($l) => $l !== ''));
        if ($lines === []) {
            return;
        }

        // Header: "Chan  Callid  ...  RTT  Jit  Loss  ..." - locate the
        // columns by name so a release change cannot shift the values.
        $header = preg_split('/\s+/', $lines[0]);
        $index = [];
        foreach ($header as $i => $name) {
            $index[strtolower($name)] = $i;
        }
        $wanted = ['rtt' => 'rtt', 'jitter' => 'jitter', 'loss' => 'loss', 'lost' => 'lost'];
        $columns = [];
        foreach ($wanted as $needle => $label) {
            foreach ($index as $name => $pos) {
                if ($name === $needle || str_starts_with($name, $needle)) {
                    $columns[$label] = $pos;
                    break;
                }
            }
        }
        if ($columns === []) {
            return;
        }

        $channelLabels = [];
        $samples = [];
        $values = [];
        foreach (array_slice($lines, 1) as $line) {
            $fields = preg_split('/\s+/', $line);
            if (!is_array($fields)) {
                continue;
            }
            $channel = $fields[$index['chan'] ?? 0] ?? 'unknown';
            foreach ($columns as $label => $pos) {
                if (!isset($fields[$pos])) {
                    continue;
                }
                $value = self::parseNumeric($fields[$pos]);
                if ($value === null) {
                    continue;
                }
                $samples[] = [['channel' => $channel, 'metric' => $label], $value];
                $channelLabels[$channel] = true;
                $values[$label][] = $value;
            }
        }

        if ($samples === [] || $channelLabels === []) {
            return;
        }

        $maxSamples = [];
        $avgSamples = [];
        foreach ($values as $label => $list) {
            $maxSamples[] = [['metric' => $label], max($list)];
            $avgSamples[] = [['metric' => $label], round(array_sum($list) / count($list), 3)];
        }

        $w->metric(
            'mikopbx_asterisk_channel_quality_max',
            'Worst RTP quality value reported by pjsip show channelstats per indicator (rtt in ms, jitter/loss as reported)',
            'gauge',
            $maxSamples
        );
        $w->metric(
            'mikopbx_asterisk_channel_quality_average',
            'Average RTP quality value reported by pjsip show channelstats per indicator',
            'gauge',
            $avgSamples
        );

        if (!PrometheusMain::isChannelQualityEnabled()) {
            return;
        }

        $w->metric(
            'mikopbx_asterisk_channel_quality',
            'Per channel RTP quality as reported by pjsip show channelstats (rtt in ms, jitter/loss as reported)',
            'gauge',
            $samples
        );
    }

    /**
     * Turns Asterisk duration notation ("1:23", "01:02:03") into seconds.
     */
    private static function parseDuration(string $text): float
    {
        $cleaned = preg_replace('/[\[\]\s:]/', '', $text) ?? '';
        if ($cleaned === '' || !is_numeric($cleaned)) {
            return 0.0;
        }
        return (float)$cleaned;
    }

    /**
     * Strips a trailing unit suffix and returns the numeric part, or null.
     */
    private static function parseNumeric(string $text): ?float
    {
        if (preg_match('/^-?[\d.]+/', $text, $m) === 1) {
            return (float)$m[0];
        }
        return null;
    }

    /**
     * Runs an Asterisk CLI command; returns null when Asterisk is
     * unreachable or the command fails.
     */
    private function cli(string $command): ?string
    {
        $output = [];
        $exitCode = 1;
        @exec(self::ASTERISK_BIN . ' -rx ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return null;
        }
        $result = implode("\n", $output);
        if (stripos($result, 'Unable to connect') !== false) {
            return null;
        }
        // An unknown command prints "No such command" instead of exiting != 0
        if (stripos($result, 'No such command') !== false) {
            return null;
        }
        return $result;
    }
}