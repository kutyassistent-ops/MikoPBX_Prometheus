# ModulePrometheus

[English](README.md) | [Русский](README.ru.md)

MikoPBX module that exposes metrics in the Prometheus text exposition
format (0.0.4) over HTTP. Prometheus pulls the endpoint directly - no
push agent like Zabbix requires.

## What it exposes

`GET http://<mikopbx>:9713/metrics`

| Group | Metrics |
|---|---|
| Exporter | `mikopbx_exporter_build_info`, `up`, `start_time_seconds`, `scrapes_total`, `scrape_failures_total`, `scrape_timeouts_total`, `requests_total`, `auth_failures_total`, `active_children`, `http_responses_total{code}`, `request_duration_seconds_{sum,average}`, `config_info`, `collector_success{collector}`, `collector_duration_seconds`, `scrape_duration_seconds`, `scrape_families`, `scrape_timeout_seconds`, `memory_bytes`, `open_fds`, `gc_runs_total`, `block_present{block}`, `block_absent_reason{block,reason}`, `block_error_info` |
| System | `mikopbx_system_uptime_seconds`, `load1/5/15`, `memory_*`, `swap_*`, `user_hz`, `cpu_seconds_total{cpu,mode}`, `cpu_jiffies_total{cpu,mode}` (deprecated), `filesystem_{size,free,available,used}_bytes{mountpoint,device,fstype}`, `filesystem_inodes_*`, `network_receive/transmit_bytes_total`, `network_errors_total`, `processes`, `threads`, `file_descriptors_*` |
| Asterisk | `mikopbx_asterisk_up`, `info{version}`, `uptime_seconds`, `last_reload_seconds`, `channels_active`, `channels_by_type`, `calls_active`, `calls_processed_total`, `queue_callers`, `queue_active`, `queue_longest_wait_seconds`, `conferences_active`, `conference_participants`, `channel_quality_max{metric}`, `channel_quality_average{metric}`, `channel_quality{channel,metric}` (opt-in) |
| Calls (API) | `mikopbx_calls_active`, `calls_active_by_direction{direction}` |
| SIP | `mikopbx_sip_peers_total`, `_online`, `_online_ratio`, `_by_state` |
| Trunks | `mikopbx_trunk_up{trunk,name}`, `trunk_state_info`, `sip_trunks{state}`, `trunk_channel_utilization_ratio`, `trunk_registered`, `trunk_state_transitions_total` |
| Call statistics | `mikopbx_trunk_calls`, `_calls_answered`, `_calls_duration_seconds`, `_calls_billsec_seconds`, `_answer_rate_ratio`, `_average_billsec_seconds`, `_average_ring_seconds`, `_average_hold_seconds`, `_calls_by_disposition`, `_short_calls`, `_long_calls`, `mikopbx_extension_missed_calls`, `mikopbx_stats_cache_age_seconds`, `mikopbx_stats_cache_missed_error_age_seconds` |
| Platform | `mikopbx_database_size_bytes`, `mysql_status`, `webserver_status`, `time_sync_*`, `kernel_oom_kills_total`, `kernel_panics_total`, `modules_total`, `modules_enabled` |

Additionally: `/health`, `/-/healthy`, `/-/ready` (503 when a collector
fails), `/-/info` (JSON counters of the exporter), `HEAD` support and gzip.

> [!warning] Authorization is off by default
> A fresh install serves metrics **without** an `Authorization` header and
> listens on `0.0.0.0`. Enable the bearer token, or restrict the allowed
> source addresses, or close the port with the module firewall rule. The
> settings page shows a warning while the metrics are open to everybody.

## Requirements

- MikoPBX 2025.1.1+
- PHP 8.4
- Prometheus 2.x+ (the monitoring server)

## Installation

1. Take `ModulePrometheus.zip` from the releases, or build it from source.
2. In the MikoPBX web interface: **Modules** -> **Installed** -> **Upload module**.
3. Enable the module. The exporter starts automatically.

> [!danger] Archive layout
> `module.json` must sit at the **root** of the zip. Inside a
> `ModulePrometheus/` folder the install fails with
> "module.json not found in the module archive". Use `build-module.ps1`.

## Configuration

Listen address (`0.0.0.0`), port (`9713`), response cache in seconds
(`0` disables it), scrape timeout (`10`), maximum parallel scrapes (`8`),
the list of allowed source addresses (empty = any), the bearer token
requirement (**off** by default) and HTTPS with certificate and key paths.

The bearer token never reaches the browser: the field stays empty and an
empty field keeps the stored token. A token can be generated with one
click. The settings page has a **Restart exporter** button and shows the
live counters of the exporter process.

Restrict access to the port with the MikoPBX firewall rule so only the
Prometheus server can reach it.

### Example prometheus.yml

```yaml
scrape_configs:
  - job_name: mikopbx
    scrape_interval: 60s
    scrape_timeout: 10s
    static_configs:
      - targets: ['192.168.1.10:9713']
    # if the token is enabled in the module:
    # bearer_token_file: '/etc/prometheus/secrets/mikopbx.token'
```

> [!danger] The CPU formula is easy to get wrong
> `mikopbx_system_cpu_seconds_total{cpu="all"}` is the **sum** of the cores,
> not the average. Since 1.4.0 the values are real seconds, so no divisor
> is needed:
>
> ```promql
> sum(rate(mikopbx_system_cpu_seconds_total{mode!="idle", cpu!="all"}[5m]))
>   / count(count by (cpu) (mikopbx_system_cpu_seconds_total{cpu!="all"}))
> ```
>
> Dashboards built for 1.3.x have to drop the `/ 100` - the old raw
> counter is still published as `mikopbx_system_cpu_jiffies_total`.

### Alerting rules

`examples/alerts.yml` ships 15 rules and 7 recording rules, ready to use.

## Architecture

- `Lib/WorkerPrometheusExporter.php` - long-running worker; binds the port
  (`stream_socket_server`, optionally TLS), forks per connection, caches the
  response, validates the token on every request.
- `Lib/Collectors/*` - `System` (/proc), `Asterisk` (CLI), `Telemetry`
  (REST API v3), `CallStats` (cache files), `Infrastructure` (platform).
  `MetricsWriter` renders the exposition format, `CollectorRegistry`
  orchestrates and times.
- `Lib/StatsCache.php` - cron CDR collector, writes the cache atomically.
- `bin/prometheus-safe-script.sh` - liveness check every minute,
  `bin/prometheus-stats-cache.sh` - cache refresh every five minutes.
- `Lib/RestAPI/Status/` - REST API v3 for the UI (status, connectivity probe).

## Current limitations

- Per-process metrics (top CPU/memory by PID) are not ported; Prometheus
  users normally run a dedicated process exporter next to the PBX.
- Queue, conference and RTP quality metrics depend on the Asterisk release.
  When the output format does not match, the parser returns nothing - check
  `mikopbx_exporter_block_present` and `mikopbx_exporter_block_absent_reason`,
  not only `collector_success`.
- The Grafana dashboard is not shipped; ready-made alert rules are.

## Development

- `bin/*.sh` must use LF line endings.
- `Messages/*.php` must start with `return [` or translations silently fail.
- `App/Module.php` is required or the router never registers the page.
- The admin templates read view variables as plain PHP variables: pass
  scalars, not arrays - `{{ x.y }}` does not reach into an array.
- Build with `build-module.ps1`: `module.json` must end up at the zip root.

## License

GPL-3.0-or-later