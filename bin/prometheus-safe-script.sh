#!/bin/sh

# Health check for the Prometheus exporter worker.
# Called by cron every minute: (re)starts the worker when it is down.

scriptDir=$(/bin/busybox dirname "$0")

# The cron job is generated for the module as a whole and keeps running
# even after the operator disables it in the web interface. Without this
# guard it would resurrect an exporter the user deliberately turned off.
if [ -f "$scriptDir/disabled" ]; then
    exit 0
fi

# The bracket trick prevents grep from matching its own command line.
pidData=$(/bin/busybox ps | /bin/busybox grep '[M]odulePrometheusExporter')

if [ -z "$pidData" ]; then
    /bin/busybox chmod 755 "$scriptDir/prometheus-exporter" 2>/dev/null
    /bin/busybox nohup "$scriptDir/prometheus-exporter" start >/dev/null 2>&1 &
fi