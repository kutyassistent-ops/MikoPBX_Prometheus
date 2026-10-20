#!/bin/sh

# Collects per-trunk CDR statistics for the Prometheus module and writes
# them to flat cache files. Called by cron every 5 minutes so that scrapes
# never query the heavy CDR API directly.

CACHE_DIR="/storage/usbdisk1/mikopbx/tmp/ModulePrometheus"
COLLECTOR="$(/bin/busybox dirname "$0")/../Lib/StatsCache.php"
LOCK_FILE="${CACHE_DIR}/collector.lock"

# Ensure cache directory exists
if [ ! -d "$CACHE_DIR" ]; then
    /bin/busybox mkdir -p "$CACHE_DIR"
fi

# Prevent overlapping runs via flock
exec 9>"$LOCK_FILE"
if ! /bin/busybox flock -n 9; then
    exit 0
fi

# Run collector
php -f "$COLLECTOR"
