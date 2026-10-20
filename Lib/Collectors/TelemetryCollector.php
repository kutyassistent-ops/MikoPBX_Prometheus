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

use Modules\ModulePrometheus\Lib\ApiHelper;

/**
 * Telephony metrics taken from the MikoPBX REST API v3.
 *
 * Every method here throws when the endpoint is unreachable, so
 * collector_success reports the loss instead of publishing an empty result.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class TelemetryCollector extends AbstractCollector
{
    /** SIP peer states treated as online, lowercased for comparison. */
    private const SIP_RESPONSIVE_STATES = ['ok', 'available', 'avail'];

    /** Provider states treated as up. */
    private const TRUNK_UP_STATES = ['OK', 'REGISTERED'];

    /** Numbers shorter than this are treated as internal extensions. */
    private const INNER_NUMBER_MAX_LEN = 6;

    public function name(): string
    {
        return 'telemetry';
    }

    public function collect(MetricsWriter $w): void
    {
        $this->collectCallMetrics($w);
        $this->collectSipMetrics($w);
        $this->collectTrunkStatusMetrics($w);
    }

    private function collectCallMetrics(MetricsWriter $w): void
    {
        $data = ApiHelper::callApi('/pbxcore/api/v3/pbx-status:getActiveCalls');
        if ($data === null) {
            throw new \RuntimeException('pbx-status:getActiveCalls is unavailable');
        }

        $total = 0;
        $byDirection = ['incoming' => 0, 'outgoing' => 0, 'internal' => 0];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $total++;
            $byDirection[$this->classifyDirection($row)]++;
        }

        $w->metric(
            'mikopbx_calls_active',
            'Active calls reported by the MikoPBX API',
            'gauge',
            [[[], $total]]
        );

        $samples = [];
        foreach ($byDirection as $direction => $count) {
            $samples[] = [['direction' => $direction], $count];
        }
        $w->metric('mikopbx_calls_active_by_direction', 'Active calls by direction', 'gauge', $samples);
    }

    /**
     * Classifies a call as internal/incoming/outgoing by number lengths,
     * following the logic of the original Zabbix module.
     */
    private function classifyDirection(array $row): string
    {
        $src = (string)($row['src_num'] ?? '');
        $dst = (string)($row['dst_num'] ?? '');
        if (strlen($src) < self::INNER_NUMBER_MAX_LEN && strlen($dst) < self::INNER_NUMBER_MAX_LEN) {
            return 'internal';
        }
        return strlen($src) >= self::INNER_NUMBER_MAX_LEN ? 'incoming' : 'outgoing';
    }

    private function collectSipMetrics(MetricsWriter $w): void
    {
        $data = ApiHelper::callApi('/pbxcore/api/v3/sip:getStatuses');
        if ($data === null) {
            throw new \RuntimeException('sip:getStatuses is unavailable');
        }

        $total = 0;
        $online = 0;
        $byState = [];
        foreach ($data as $peer) {
            if (!is_array($peer)) {
                continue;
            }
            $total++;
            // The API changed case between releases, so the state bucket is
            // always the lowercased value and the online test is
            // case-insensitive - a hardcoded exact match silently reported
            // every peer as offline before
            $rawStatus = strtolower((string)($peer['status'] ?? ''));
            $bucket = $rawStatus === '' ? 'unknown' : $rawStatus;
            $byState[$bucket] = ($byState[$bucket] ?? 0) + 1;
            if (in_array($rawStatus, self::SIP_RESPONSIVE_STATES, true)) {
                $online++;
            }
        }

        $w->metric('mikopbx_sip_peers_total', 'Total SIP peers configured', 'gauge', [[[], $total]]);
        $w->metric('mikopbx_sip_peers_online', 'SIP peers in a responsive state', 'gauge', [[[], $online]]);
        $w->metric(
            'mikopbx_sip_peers_online_ratio',
            'Share of SIP peers in a responsive state (0..1)',
            'gauge',
            [[[], $total > 0 ? round($online / $total, 6) : 0.0]]
        );

        $stateSamples = [];
        foreach ($byState as $state => $count) {
            $stateSamples[] = [['state' => $state], $count];
        }
        $w->metric('mikopbx_sip_peers_by_state', 'SIP peers grouped by reported status', 'gauge', $stateSamples);
    }

    private function collectTrunkStatusMetrics(MetricsWriter $w): void
    {
        $providers = ApiHelper::getAllProviderStatuses();
        // An empty list is ambiguous: either the API failed or there really
        // are no trunks. Silent [] would make trunk_up report "all fine"
        // while the data source is down, so fail the collector instead.
        if ($providers === []) {
            throw new \RuntimeException('sip-providers:getStatuses is unavailable');
        }

        $upSamples = $infoSamples = $channelSamples = [];
        $stateCounts = ['up' => 0, 'down' => 0, 'disabled' => 0, 'unknown' => 0];

        foreach ($providers as $provider) {
            $id = (string)($provider['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = (string)($provider['description'] ?? ($provider['username'] ?? $id));

            $state = strtoupper((string)($provider['state'] ?? ''));
            $isUp = in_array($state, self::TRUNK_UP_STATES, true);

            if ($isUp) {
                $bucket = 'up';
            } elseif ($state === 'OFF') {
                $bucket = 'disabled';
            } elseif ($state === '') {
                $bucket = 'unknown';
            } else {
                $bucket = 'down';
            }
            $stateCounts[$bucket]++;

            $upSamples[] = [['trunk' => $id, 'name' => $name], $isUp ? 1 : 0];
            $infoSamples[] = [
                ['trunk' => $id, 'name' => $name, 'state' => strtolower($state !== '' ? $state : 'unknown')],
                1,
            ];

            // Channel capacity, when the API exposes it: allows warning
            // about saturation instead of only about failures
            $limit = (int)($provider['maxChannels'] ?? ($provider['channels'] ?? 0));
            $used = (int)($provider['activeChannels'] ?? 0);
            if ($limit > 0) {
                $channelSamples[] = [
                    ['trunk' => $id, 'name' => $name],
                    round(min(1.0, $used / $limit), 6),
                ];
            }
        }

        $w->metric(
            'mikopbx_trunk_up',
            'Trunk state as a number (1 = registered or OK, 0 = anything else)',
            'gauge',
            $upSamples
        );
        $w->metric(
            'mikopbx_trunk_state_info',
            'Trunk state as an info metric (value is always 1)',
            'gauge',
            $infoSamples
        );

        $countSamples = [];
        foreach ($stateCounts as $state => $count) {
            $countSamples[] = [['state' => $state], $count];
        }
        $w->metric(
            'mikopbx_sip_trunks',
            'Number of SIP/IAX trunks by aggregated state',
            'gauge',
            $countSamples
        );
        $w->metric(
            'mikopbx_trunk_channel_utilization_ratio',
            'Used channels divided by the configured limit (0..1)',
            'gauge',
            $channelSamples
        );
    }
}