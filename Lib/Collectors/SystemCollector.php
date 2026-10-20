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
 * Host metrics read straight from /proc.
 *
 * @package Modules\ModulePrometheus\Lib\Collectors
 */
class SystemCollector extends AbstractCollector
{
    /** Filesystem types included in disk metrics. */
    private const FILESYSTEM_TYPES = [
        'ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'f2fs', 'vfat', 'overlay', 'squashfs',
    ];

    /**
     * Container and virtual interfaces excluded from network metrics. They
     * churn constantly and would multiply the series count without telling
     * anything useful about the PBX.
     */
    private const IGNORED_IFACES = '/^(veth|docker|br-|virbr|tun|tap)/';

    /** Mountpoints worth exporting even when the type is unusual. */
    private const ALWAYS_INCLUDE_MOUNTS = ['/storage', '/var', '/tmp', '/usr', '/'];

    public function name(): string
    {
        return 'system';
    }

    public function collect(MetricsWriter $w): void
    {
        $this->collectUptimeAndLoad($w);
        $this->collectMemory($w);
        $this->collectCpu($w);
        $this->collectFilesystems($w);
        $this->collectNetwork($w);
        $this->collectProcesses($w);
        $this->collectFileDescriptors($w);
    }

    private function collectUptimeAndLoad(MetricsWriter $w): void
    {
        $uptimeRaw = self::readFile('/proc/uptime');
        if ($uptimeRaw !== false) {
            $parts = preg_split('/\s+/', trim($uptimeRaw));
            if (isset($parts[0])) {
                $w->metric(
                    'mikopbx_system_uptime_seconds',
                    'System uptime in seconds',
                    'gauge',
                    [[[], (float)$parts[0]]]
                );
            }
        }

        $loadRaw = self::readFile('/proc/loadavg');
        if ($loadRaw === false) {
            return;
        }
        $parts = preg_split('/\s+/', trim($loadRaw));
        foreach (['1' => 0, '5' => 1, '15' => 2] as $label => $index) {
            if (isset($parts[$index])) {
                $w->metric(
                    "mikopbx_system_load{$label}",
                    "System load average over the last {$label} minutes",
                    'gauge',
                    [[[], (float)$parts[$index]]]
                );
            }
        }
    }

    private function collectMemory(MetricsWriter $w): void
    {
        $memRaw = self::readFile('/proc/meminfo');
        if ($memRaw === false) {
            return;
        }
        $mem = [];
        foreach (explode("\n", $memRaw) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m) === 1) {
                $mem[$m[1]] = (int)$m[2] * 1024;
            }
        }

        $total = $mem['MemTotal'] ?? 0;
        $available = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
        if ($total > 0) {
            $w->metric('mikopbx_system_memory_total_bytes', 'Total RAM in bytes', 'gauge', [[[], $total]]);
            $w->metric(
                'mikopbx_system_memory_available_bytes',
                'Available RAM in bytes',
                'gauge',
                [[[], $available]]
            );
            $w->metric(
                'mikopbx_system_memory_used_bytes',
                'Used RAM in bytes (total minus available)',
                'gauge',
                [[[], max(0, $total - $available)]]
            );
        }

        $w->metric('mikopbx_system_swap_total_bytes', 'Total swap in bytes', 'gauge', [[[], $mem['SwapTotal'] ?? 0]]);
        $w->metric('mikopbx_system_swap_free_bytes', 'Free swap in bytes', 'gauge', [[[], $mem['SwapFree'] ?? 0]]);
    }

    /**
     * /proc/stat reports jiffies since boot.
     *
     * The family is published in real seconds, so the name matches the
     * value and nobody has to guess the USER_HZ divisor. The raw counter
     * stays available as a second family for dashboards that already use
     * it - it is deprecated and will be removed in a later release.
     */
    private function collectCpu(MetricsWriter $w): void
    {
        $stat = $this->readCpuStat();
        if ($stat === null) {
            return;
        }

        $userHz = self::userHz();
        $w->metric(
            'mikopbx_system_user_hz',
            'Number of jiffies per second reported by the kernel (USER_HZ)',
            'gauge',
            [[[], $userHz]]
        );

        $modes = [
            'user' => 0, 'nice' => 1, 'system' => 2, 'idle' => 3,
            'iowait' => 4, 'irq' => 5, 'softirq' => 6, 'steal' => 7,
        ];

        $seconds = [];
        $jiffies = [];
        foreach ($stat as $cpu => $fields) {
            $cpuLabel = $cpu === 'cpu' ? 'all' : substr($cpu, 3);
            foreach ($modes as $mode => $index) {
                $value = (int)($fields[$index] ?? 0);
                $jiffies[] = [['cpu' => $cpuLabel, 'mode' => $mode], $value];
                $seconds[] = [['cpu' => $cpuLabel, 'mode' => $mode], round($value / $userHz, 3)];
            }
        }

        $w->metric(
            'mikopbx_system_cpu_seconds_total',
            'CPU time spent in each mode in seconds',
            'counter',
            $seconds
        );
        $w->metric(
            'mikopbx_system_cpu_jiffies_total',
            'CPU time spent in each mode in USER_HZ units (deprecated, use mikopbx_system_cpu_seconds_total)',
            'counter',
            $jiffies
        );
    }

    /**
     * Number of jiffies per second, 100 on every usual Linux build.
     */
    private static function userHz(): int
    {
        return 100;
    }

    /**
     * @return array<string, int[]>|null
     */
    private function readCpuStat(): ?array
    {
        $raw = self::readFile('/proc/stat');
        if ($raw === false) {
            return null;
        }
        $result = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(cpu\d*)\s+([\d\s]+)$/', trim($line), $m) === 1) {
                $result[$m[1]] = array_map('intval', preg_split('/\s+/', trim($m[2])));
            }
        }
        return $result === [] ? null : $result;
    }

    private function collectFilesystems(MetricsWriter $w): void
    {
        $mountsRaw = self::readFile('/proc/mounts');
        if ($mountsRaw === false) {
            return;
        }

        $inodes = $this->readInodeUsage();
        $sizeSamples = $freeSamples = $availSamples = $usedSamples = $ratioSamples = [];
        $inodeTotal = $inodeFree = $inodeRatio = [];

        $seen = [];
        foreach (explode("\n", $mountsRaw) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3) {
                continue;
            }
            [$device, $mountpoint, $fstype] = $parts;
            $device = str_replace('\040', ' ', $device);
            $mountpoint = str_replace('\040', ' ', $mountpoint);

            $isKnownType = in_array($fstype, self::FILESYSTEM_TYPES, true);
            $isWantedMount = in_array($mountpoint, self::ALWAYS_INCLUDE_MOUNTS, true);
            // Unknown types are kept only for the mountpoints that actually
            // matter, so /storage survives whatever filesystem it is on
            if (!$isKnownType && !$isWantedMount) {
                continue;
            }

            $key = $device . '|' . $mountpoint;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $total = @disk_total_space($mountpoint);
            $stat = self::statvfs($mountpoint);
            if ($total === false || $total <= 0 || $stat === null) {
                continue;
            }

            $used = $total - $stat['free'];
            $labels = ['mountpoint' => $mountpoint, 'device' => $device, 'fstype' => $fstype];
            $sizeSamples[] = [$labels, (int)$total];
            $freeSamples[] = [$labels, $stat['free']];
            $availSamples[] = [$labels, $stat['available']];
            $usedSamples[] = [$labels, (int)$used];
            $ratioSamples[] = [$labels, round($used / $total, 6)];

            // df -i is the only portable source for inode counts
            if (isset($inodes[$mountpoint])) {
                $it = $inodes[$mountpoint]['total'];
                $ifu = $inodes[$mountpoint]['used'];
                $inodeTotal[] = [$labels, $it];
                $inodeFree[] = [$labels, $it - $ifu];
                $inodeRatio[] = [$labels, $it > 0 ? round($ifu / $it, 6) : 0.0];
            }
        }

        $w->metric('mikopbx_system_filesystem_size_bytes', 'Filesystem size in bytes', 'gauge', $sizeSamples);
        $w->metric(
            'mikopbx_system_filesystem_free_bytes',
            'Free space in bytes including the filesystem reserve',
            'gauge',
            $freeSamples
        );
        $w->metric(
            'mikopbx_system_filesystem_available_bytes',
            'Space available to this process in bytes (excludes the reserve)',
            'gauge',
            $availSamples
        );
        $w->metric('mikopbx_system_filesystem_used_bytes', 'Used space in bytes', 'gauge', $usedSamples);
        $w->metric('mikopbx_system_filesystem_used_ratio', 'Filesystem used space ratio (0..1)', 'gauge', $ratioSamples);
        $w->metric('mikopbx_system_filesystem_inodes_total', 'Total inodes on the filesystem', 'gauge', $inodeTotal);
        $w->metric('mikopbx_system_filesystem_inodes_free', 'Free inodes on the filesystem', 'gauge', $inodeFree);
        $w->metric(
            'mikopbx_system_filesystem_inodes_used_ratio',
            'Inode used ratio (0..1). Reaching 1 with free bytes left is the classic "disk full" case',
            'gauge',
            $inodeRatio
        );
    }

    /**
     * Reads f_bfree and f_bavail from statvfs.
     *
     * PHP exposes only disk_free_space(), which is f_bavail - "free for the
     * caller". The filesystem reserve (typically 5% on ext4) is not part of
     * it, so total - free overstates the used space by the whole reserve.
     *
     * @return array{free:int, available:int}|null
     */
    private static function statvfs(string $mountpoint): ?array
    {
        $out = [];
        $rc = 1;
        // %S - block size, %f - free blocks to everybody, %a - free blocks to the caller
        @exec(
            '/bin/busybox stat -f -c "%S %f %a" ' . escapeshellarg($mountpoint) . ' 2>/dev/null',
            $out,
            $rc
        );
        if ($rc !== 0 || $out === []) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($out[0]));
        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }
        return [
            'free' => (int)$parts[1] * (int)$parts[0],
            'available' => (int)$parts[2] * (int)$parts[0],
        ];
    }

    /**
     * One df -i pass for every mountpoint.
     *
     * @return array<string, array{total:int, used:int}> mountpoint => counts
     */
    private function readInodeUsage(): array
    {
        $output = [];
        $rc = 1;
        // -P forces one line per filesystem, -i asks for inodes
        @exec('/bin/busybox df -Pi 2>/dev/null', $output, $rc);
        if ($rc !== 0 || $output === []) {
            return [];
        }

        $result = [];
        foreach (array_slice($output, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 6) {
                continue;
            }
            $mountpoint = array_pop($parts);
            $used = (int)$parts[3];
            $total = $used + (int)$parts[4];
            if ($mountpoint === '') {
                continue;
            }
            $result[$mountpoint] = ['total' => $total, 'used' => $used];
        }
        return $result;
    }

    private function collectNetwork(MetricsWriter $w): void
    {
        $raw = self::readFile('/proc/net/dev');
        if ($raw === false) {
            return;
        }
        $receiveSamples = $transmitSamples = $errSamples = [];
        foreach (explode("\n", $raw) as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $iface = trim(substr($line, 0, $colonPos));
            if ($iface === '' || $iface === 'lo') {
                continue;
            }
            if (preg_match(self::IGNORED_IFACES, $iface) === 1) {
                continue;
            }
            $fields = preg_split('/\s+/', trim(substr($line, $colonPos + 1)));
            if (count($fields) < 16) {
                continue;
            }
            $receiveSamples[] = [['device' => $iface], (int)$fields[0]];
            $transmitSamples[] = [['device' => $iface], (int)$fields[8]];
            // errs + drop are adjacent at the end of the line
            $errSamples[] = [['device' => $iface, 'kind' => 'errors'], (int)$fields[2]];
            $errSamples[] = [['device' => $iface, 'kind' => 'dropped'], (int)$fields[3]];
        }

        $w->metric('mikopbx_system_network_receive_bytes_total', 'Bytes received on network interfaces', 'counter', $receiveSamples);
        $w->metric('mikopbx_system_network_transmit_bytes_total', 'Bytes transmitted on network interfaces', 'counter', $transmitSamples);
        $w->metric('mikopbx_system_network_errors_total', 'Receive errors and dropped packets per interface', 'counter', $errSamples);
    }

    private function collectProcesses(MetricsWriter $w): void
    {
        $entries = glob('/proc/[0-9]*', GLOB_ONLYDIR);
        $count = is_array($entries) ? count($entries) : 0;
        $w->metric('mikopbx_system_processes', 'Number of processes', 'gauge', [[[], $count]]);

        // Thread count: a leak there shows up long before it breaks anything
        $threads = 0;
        foreach ($entries as $dir) {
            // The process may die between the glob and the read, and a
            // failed read is a warning - in MikoPBX every warning becomes
            // an exception, so the file is only touched when it is there.
            $statusFile = $dir . '/status';
            if (!is_readable($statusFile)) {
                continue;
            }
            try {
                $status = file_get_contents($statusFile);
            } catch (\Throwable $e) {
                continue;
            }
            if ($status !== false && preg_match('/^Threads:\s*(\d+)/m', $status, $m) === 1) {
                $threads += (int)$m[1];
            }
        }
        $w->metric('mikopbx_system_threads', 'Total number of threads across all processes', 'gauge', [[[], $threads]]);
    }

    private function collectFileDescriptors(MetricsWriter $w): void
    {
        $output = [];
        $rc = 1;
        @exec('/bin/busybox cat /proc/sys/fs/file-nr 2>/dev/null', $output, $rc);
        if ($rc === 0 && $output !== []) {
            $parts = preg_split('/\s+/', trim($output[0]));
            if (is_array($parts) && count($parts) >= 3) {
                $w->metric(
                    'mikopbx_system_file_descriptors_allocated',
                    'File descriptors currently allocated system wide',
                    'gauge',
                    [[[], (int)$parts[0]]]
                );
                $w->metric(
                    'mikopbx_system_file_descriptors_free',
                    'File descriptors remaining in the system table',
                    'gauge',
                    [[[], (int)$parts[1]]]
                );
            }
        }

        // Open FDs of the exporter itself: it is a long running process and
        // a descriptor leak there is invisible until the scrape breaks
        $exporterFds = [];
        if (is_dir('/proc/self/fd')) {
            try {
                $scanned = scandir('/proc/self/fd');
                $exporterFds = is_array($scanned) ? $scanned : [];
            } catch (\Throwable $e) {
                $exporterFds = [];
            }
        }
        $openCount = max(0, count($exporterFds) - 2);
        $w->metric(
            'mikopbx_exporter_open_fds',
            'File descriptors open in the exporter process',
            'gauge',
            [[[], $openCount]]
        );
    }
}