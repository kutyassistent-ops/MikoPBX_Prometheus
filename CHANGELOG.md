# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.3] - 2026-10-20

### Fixed
- **The exporter was restarted every few seconds.** The syslog showed a
  thousand times:
  `[SHUTDOWN-ERROR] stream_socket_accept(): Accept failed: Connection timed out`.
  MikoPBX turns every PHP warning into an exception, and the "@" operator
  does not suppress that - so the two second timeout of `stream_socket_accept()`
  killed the worker in every idle cycle. The wait is now done with
  `stream_select()` (which reports a timeout by returning zero) and the
  accept call is wrapped in an own error handler.
- **A process that died between the glob and the read killed the scrape**
  (`file_get_contents(/proc/<pid>/status): Failed to open stream`). All file
  reads of the collectors go through a safe helper that checks the file
  first.
- The request loop, the child handling and the reaping are wrapped in
  `try/catch`: a single broken request can no longer take the exporter down.
- A child that hangs (a client that stopped reading) is killed after 60
  seconds, and the report of a child is read without blocking the accept
  loop.

## [1.4.2] - 2026-10-20

### Fixed
- **The status panel showed an empty "last scrape".** The collection result
  is produced by the forked child, but the panel is served by the parent
  process, and the report only carried the response code. The report now
  carries the number of families, the payload size and the success flag, so
  `/-/info` and the web interface show the real result of the last scrape.

## [1.4.1] - 2026-10-20

### Fixed
- **Fatal error on the first scrape:** `collectExporterRuntime()` was declared
  `void` but ended with `return null;` - PHP refuses to return a value from a
  void method, and the exporter died on the first request. The method now
  declares `?string` like the other optional blocks.
- MikoPBX switched the module off automatically after that error. The fix
  has to be uploaded as a new version and the module switched on again.

## [1.4.0] - 2026-10-20

### Fixed
- **The settings page rendered empty values.** The template read the settings
  with `{{ settings.listenAddress }}`, but the admin cabinet templates read
  view variables as plain PHP variables and the dot notation does not reach
  into an array. Every field was empty while the exporter was running with
  the real configuration - and saving the form overwrote the listen
  address, the port and the TLS paths with the defaults. All values are
  passed as separate view variables now.
- **An empty token field wiped the stored token and silently disabled the
  authorization.** The worker treats an empty token as "no authorization",
  so saving the form with the (always empty) token field removed the
  protection while the interface still showed "require a bearer token".
  An empty field keeps the stored token, the token is never sent to the
  browser, and enabling the requirement without any token is refused.
- **The native form action pointed to a route that answers 401.** The page
  lives at `/admin-cabinet/module-prometheus/module-prometheus/index`, so
  the action has to carry the module prefix and the controller, exactly as
  in the official module template. The AJAX URL was corrected as well.
- **The scrape counters never grew.** The increment sat in the branch that
  runs without `pcntl_fork`, while the normal mode forks a child per
  request. The child now reports the outcome to the parent through a
  socket pair, and the parent keeps the counters.
- **`HEAD` answered 200 for every path.** The route is checked before the
  method now, so an unknown path is a 404 for every verb.
- **IPv6 listen addresses were broken.** The brackets were stripped when
  the address was normalised and never restored, so `tcp://fe80::1:9713`
  could not be parsed. The endpoint is assembled by one helper that always
  adds the brackets.
- **The missed call metrics disappeared when the source failed.** The
  collector deleted the cache files before it knew whether the API
  answered. The last known values are kept now and the failure is
  published as `mikopbx_stats_cache_missed_error_age_seconds`.
- **A broken textfile snippet could corrupt the whole payload.** Files
  larger than 1 MB and files that do not look like an exposition document
  are skipped.

### Added
- Self monitoring of the exporter, restored after the collector split in
  1.3.0 lost it: `mikopbx_exporter_up`, `_start_time_seconds`,
  `_scrapes_total`, `_scrape_failures_total`, `_config_info`, plus
  `_scrape_timeouts_total`, `_requests_total`, `_auth_failures_total`,
  `_active_children`, `_http_responses_total{code}` and the request
  duration counters.
- `mikopbx_exporter_block_present{block}` and
  `mikopbx_exporter_block_absent_reason{block,reason}`: an optional block
  that quietly returned nothing looked exactly like a family without data.
  Now every block says whether it ran and why not.
- `mikopbx_exporter_scrape_families` - how many families the last scrape
  published.
- A limit on parallel scrapes (default 8): a burst of scrapes no longer
  forks an unlimited number of children, requests above the limit get a
  503.
- A list of allowed source addresses (IP or CIDR). The exporter checks it
  on every connection and answers 403 to everybody else. The firewall rule
  of the module is created for the same addresses.
- A scrape deadline: a collection that does not fit into the configured
  timeout is aborted and the scrape is reported as failed instead of
  returning a half filled payload.
- `GET /-/info` on the exporter and `status:getExporterInfo` in the REST
  API: the counters of the running process, proxied through the PBX.
- Aggregated RTP quality (`_max` and `_average`). The per channel family
  multiplies with the number of concurrent calls and is now opt-in.
- A "Restart exporter" button, a clickable metrics URL with a copy
  button, a warning banner while the metrics are open to everybody, and
  the live counters in the status panel.
- A "Generate token" button for the bearer token (48 hex characters from
  `crypto.getRandomValues`).
- `mikopbx_system_user_hz` and `mikopbx_system_cpu_jiffies_total` next to
  the CPU family.

### Changed
- **BREAKING**: `mikopbx_system_cpu_seconds_total` now carries seconds, as
  its name promises. The raw counter stays available as
  `mikopbx_system_cpu_jiffies_total` for one release. Dashboards built
  for 1.3.x have to drop the `/ 100` from the CPU formula.
- The settings form is split into four sections (network, access, HTTPS,
  collection) and validates the numeric fields in the browser before the
  request is sent.
- The REST status endpoint reports the authorization flag and the fact
  that a token is stored separately, and the OpenAPI schema was brought
  in line with the actual answer.

### Documentation
- `Справочник метрик` is synchronized with the code: the missing self
  metrics are back, the CPU family and the new families are described,
  and the deprecation of the jiffies counter is stated.
- `Настройка модуля` documents the new settings, the token rules, the
  source address list and the restart button.
- `Алертинг и Grafana` has the corrected CPU formula and rules for the
  new failure modes; `examples/alerts.yml` was extended accordingly.
- `Архитектура` describes the socket pair, the limits and the deadline.
- README (both languages) lists the current metric set.

## [1.3.1] - 2026-10-19

### Fixed
- The `infrastructure` collector failed as a whole and produced 3 of its 15
  families. It referenced `Directories::CORE_DB_DIR`, a constant that does not
  exist in Core; the resulting `Error` was caught by the collector runner and
  every block after it was skipped. Corrected to `CORE_CONF_BACKUP_DIR`.
- Optional blocks inside a collector are now isolated individually. One
  missing binary or unreadable directory must cost one metric family, not the
  whole collector, which is what the class documentation always claimed.
- `mikopbx_exporter_build_info` was lost during the collector split in 1.3.0,
  so the exporter version was visible only in the web interface.

### Added
- `mikopbx_exporter_block_error_info{block, reason}` publishes the text of a
  failed optional block. Previously a silently missing family had no visible
  cause anywhere: `lastError` stayed inside the collector process.

### Documentation
- The CPU formula printed in the docs was wrong and produced a negative busy
  ratio. `mikopbx_system_cpu_seconds_total{cpu="all"}` is the sum of all cores,
  and values are in USER_HZ, so neither `cpu!="all"` nor a division by 100 was
  enough. Corrected everywhere.
- Documented that `collector_success = 1` means "no exception", not "metrics
  are present". A collector over an empty cache reports success.

## [1.3.0] - 2026-10-19

### Removed
- The "Download scrape config" button and the `status:downloadScrapeConfig`
  REST endpoint. Prometheus scrape configuration is a property of the
  Prometheus server, not of the PBX module, and maintaining a second copy of
  it in the module only produced drift. The example configuration stays in
  `docs/Подключение Prometheus.md`.

### Fixed
- `sip:getStatuses` states were compared case-sensitively against
  `['OK', 'Available']`. A release that returns `ok` or `Avail` reported every
  peer as offline. Comparison is now case-insensitive.
- The cron health check kept restarting the exporter after the module was
  disabled in the web interface, because `createCronTasks()` writes the jobs
  for the module as a whole. Enable and disable hooks now maintain a
  `bin/disabled` marker and the script refuses to act on it.
- Authorization was controlled by "the token string is not empty". A token
  left in the database from an earlier version silently started rejecting
  scrapes after an upgrade. It is now an explicit `authEnabled` flag that
  defaults to off, and switching it off also clears the stored token.

### Changed
- Collector code was split out of `MetricsCollector` into
  `Lib/Collectors/`: `SystemCollector`, `AsteriskCollector`,
  `TelemetryCollector`, `CallStatsCollector`, `InfrastructureCollector`,
  plus `MetricsWriter` for the exposition format and `CollectorRegistry` for
  orchestration. `MetricsCollector` stays as a thin facade so the worker
  bootstrap is unchanged.
- Every connection is handled in a forked child. A slow or hostile client no
  longer occupies the single accept slot.
- The bearer token is re-read from the database on every request, so rotating
  it no longer requires a restart.
- Listen addresses are normalised: a pasted scheme, a pasted port, a bare
  IPv6 literal or brackets are all accepted.

### Added
- Exporter: `mikopbx_exporter_collector_duration_seconds`, gzip when the
  client asks for it, `HEAD` support, a `/-/ready` endpoint that answers 503
  when a collector is failing, an in-memory payload cache with a configurable
  TTL, and `mikopbx_exporter_scrape_timeout_seconds` for Grafana to warn
  against.
- Textfile collector: any `*.prom` file in the cache directory is appended to
  the payload verbatim.
- Exporter runtime: `mikopbx_exporter_memory_bytes`, `_open_fds`,
  `_gc_runs_total`, OPcache hit ratio and memory.
- System: inode totals, free count and used ratio per filesystem (the classic
  "disk full with 5% used" case), network errors and drops, thread count,
  system-wide file descriptor usage.
- Asterisk: channels grouped by technology, `queue show` metrics (callers
  waiting, active, longest wait), `conference show` metrics, and per channel
  RTP quality from `pjsip show channelstats`.
- Trunks: channel utilisation ratio, registration state transitions detected
  between two background collections.
- Telephony: SIP peer state breakdown and online ratio.
- Call statistics: disposition breakdown, average ring time, average hold
  time, short and long call counters, missed calls per extension.
- Platform: SQLite database sizes by purpose, `mysqladmin status`,
  `chronyc`/`timedatectl` time sync, kernel OOM kills and panics, enabled and
  installed module counts.
- UI: TLS certificate and key settings, response cache TTL, scrape timeout,
  an explicit authorization switch, a connectivity probe button and a
  readiness indicator.
- REST: `status:testConnection` opens the listener from the PBX, which
  separates "exporter down" from "port blocked by the firewall".

## [1.2.0] - 2026-10-19

### Fixed
- `saveAction()` ignored the `id` sent by the form and always took the first
  row of the settings table. It now resolves the record by id.
- The listen address was stored without validation. A typo made the exporter
  fail to bind silently: the UI only showed "Stopped" and the reason was
  buried in syslog. The form now rejects an unusable address, and enabling the
  module checks the port up front (`onBeforeModuleEnable`).
- CR/LF in the bearer token were stored verbatim, producing a token that can
  never be sent in an `Authorization` header.
- `PbxExtensionSetup` was empty, so deleting the module left the exporter
  process running with the port still bound and left the statistics cache on
  disk. `unInstallFiles()` now stops the worker and removes the cache.
- The metrics URL shown in the status panel always used
  `window.location.hostname`, producing a dead link when the exporter is bound
  to a specific address instead of `0.0.0.0`.
- Downloading the scrape config swallowed every error (`.catch(() => {})`), so
  a 401 or a 500 left the button looking broken with no message.
- Removing the module left `/etc/custom_modules/ModulePrometheus` and
  `/var/run/custom_modules/ModulePrometheus` behind on every start: both were
  created and never read.

### Changed
- `PrometheusMain` caches its instance. The constructor creates directories and
  queries the module database, and `getStatus` triggered three full
  constructions per call - the browser polls that endpoint every 10 seconds,
  so an open settings page cost three database queries every 10 seconds.
  `restartService()` drops the cache so a settings change is picked up.
- The bearer token field is a password input with a show/hide toggle, instead
  of plain text visible over the operator's shoulder and stored in browser
  history.
- Status polling pauses while the browser tab is hidden and resumes
  immediately on return.

### Added
- `mikopbx_exporter_memory_bytes{type="current"|"peak"}` - the exporter is a
  long-running PHP process and nothing reported its footprint.
- `mikopbx_exporter_config_info{listen_port,listen_address,auth_enabled}` -
  shows which port and auth mode each target is scraped on without opening the
  web interface.
- A failure message when the module is enabled while the listen port is taken.

## [1.1.0] - 2026-10-19

### Fixed
- `mikopbx_system_filesystem_used_bytes` and `_used_ratio` were overstated by
  the whole filesystem reserve. `disk_free_space()` returns `f_bavail`
  ("free for this caller"), so `total - free` counted the reserve as used.
  Used space is now `f_blocks - f_bfree` read via busybox `stat -f`.
- A single failing REST endpoint marked the whole `telephony` collector as
  failed and silently dropped the Asterisk metrics that had already been
  collected.
- `getAllProviderStatuses()` returned an empty array both on API failure and
  when no trunks exist, so trunk metrics disappeared instead of reporting an
  error.

### Changed
- **BREAKING**: `mikopbx_system_cpu_busy_ratio` removed. Replaced by the raw
  counter `mikopbx_system_cpu_seconds_total{cpu,mode}`. Busy ratio is now
  computed in Grafana with `1 - avg(rate(...{mode="idle"}[5m]))`. The old
  gauge added a 200ms `usleep` to every scrape and could not be averaged.
- **BREAKING**: `mikopbx_system_filesystem_free_bytes` now means free space
  including the reserve (previously free space available to this process).
  New metric `mikopbx_system_filesystem_available_bytes` carries the old
  meaning.
- **BREAKING**: `mikopbx_asterisk_calls_processed` renamed to
  `mikopbx_asterisk_calls_processed_total` (Prometheus counter convention).
- `mikopbx_exporter_collector_success` is now split per data source:
  `system`, `asterisk`, `calls`, `sip`, `trunk_status`, `trunk_cache`.
- Container and virtual interfaces (`veth*`, `docker*`, `br-*`, `virbr*`,
  `tun*`, `tap*`) excluded from network metrics.
- Connection accept/read timeouts lowered from 5s to 2s so a scanner opening
  a silent connection cannot hold the single worker slot.

### Added
- Exporter self-monitoring: `mikopbx_exporter_up`,
  `mikopbx_exporter_start_time_seconds`, `mikopbx_exporter_scrapes_total`,
  `mikopbx_exporter_scrape_failures_total`.
- Call quality metrics derived from the existing trunk cache:
  `mikopbx_trunk_answer_rate_ratio` (ASR) and
  `mikopbx_trunk_average_billsec_seconds` (ACD).
- `mikopbx_stats_cache_age_seconds` - growing age means the cron collector
  stopped running.

## [1.0.0] - 2026-10-16

### Added
- HTTP exporter worker (`Lib/WorkerPrometheusExporter.php`) serving the
  Prometheus text exposition format on a configurable TCP port
- System metrics: uptime, load average, memory, swap, CPU busy ratio
  (aggregate and per core), filesystems, network counters, process count
- Telephony metrics: Asterisk up/version/uptime/channels, active calls by
  direction via the MikoPBX REST API, SIP peers, SIP/IAX trunks with
  per-trunk up/state labels
- Per-trunk CDR call statistics (hour/day sliding window) served from a
  flat-file cache filled by a cron collector (`Lib/StatsCache.php`)
- Web UI: exporter status panel, listen address/port and optional bearer
  token settings, scrape config download button
- REST API v3: `status:getStatus`, `status:downloadScrapeConfig`
- Firewall rule for the exporter port and cron tasks (per-minute health
  check, five-minute statistics cache)
