---
tags:
  - prometheus
  - grafana
  - alerting
aliases:
  - Алерты
  - Grafana
created: 2026-10-16
updated: 2026-10-20
---

# Алертинг и Grafana

## Правила алертинга

Готовый пример для `rules/mikopbx.yml` (подключите через `rule_files`
в `prometheus.yml`). Актуальная версия — в `examples/alerts.yml`:

```yaml
groups:
  - name: mikopbx
    rules:
      - alert: MikoPBXExporterDown
        expr: up{job="mikopbx"} == 0
        for: 5m
        labels: {severity: critical}
        annotations:
          summary: "Экспортер MikoPBX недоступен ({{ $labels.instance }})"

      - alert: MikoPBXCollectorFailed
        expr: mikopbx_exporter_collector_success < 1
        for: 15m
        labels: {severity: warning}
        annotations:
          summary: "Сборщик {{ $labels.collector }} не собирает метрики"

      - alert: MikoPBXScrapeTimeout
        expr: increase(mikopbx_exporter_scrape_timeouts_total[30m]) > 0
        for: 5m
        labels: {severity: warning}
        annotations:
          summary: "Сбор не уложился в таймаут"

      - alert: MikoPBXAuthFailures
        expr: increase(mikopbx_exporter_auth_failures_total[15m]) > 3
        for: 5m
        labels: {severity: warning}
        annotations:
          summary: "Запросы к /metrics отклоняются: токен не совпадает"

      - alert: MikoPBXBlockMissing
        expr: mikopbx_exporter_block_present == 0
        for: 30m
        labels: {severity: info}
        annotations:
          summary: "Блок {{ $labels.block }} не отдаёт данные: {{ $labels.reason }}"

      - alert: MikoPBXAsteriskDown
        expr: mikopbx_asterisk_up == 0
        for: 5m
        labels: {severity: critical}
        annotations:
          summary: "Asterisk не отвечает"

      - alert: MikoPBXTrunkDown
        expr: mikopbx_trunk_up == 0
        for: 5m
        labels: {severity: warning}
        annotations:
          summary: "Транк {{ $labels.name }} не зарегистрирован"

      - alert: MikoPBXPhoneLinesDown
        expr: mikopbx_sip_trunks{state="up"} == 0
        for: 10m
        labels: {severity: critical}
        annotations:
          summary: "Ни одного зарегистрированного транка"

      - alert: MikoPBXStorageLow
        expr: >
          mikopbx_system_filesystem_available_bytes{mountpoint="/storage"}
          / mikopbx_system_filesystem_size_bytes{mountpoint="/storage"} < 0.1
        for: 15m
        labels: {severity: warning}
        annotations:
          summary: "На /storage осталось менее 10% места"

      - alert: MikoPBXStorageCritical
        expr: >
          mikopbx_system_filesystem_available_bytes{mountpoint="/storage"}
          / mikopbx_system_filesystem_size_bytes{mountpoint="/storage"} < 0.05
        for: 5m
        labels: {severity: critical}
        annotations:
          summary: "На /storage осталось менее 5% места"

      - alert: MikoPBXNoSipPeers
        expr: mikopbx_sip_peers_total > 0 and mikopbx_sip_peers_online == 0
        for: 10m
        labels: {severity: warning}
        annotations:
          summary: "Ни один SIP-абонент не онлайн"

      - alert: MikoPBXStatsCacheStale
        expr: time() - mikopbx_stats_cache_last_update_timestamp_seconds > 900
        for: 15m
        labels: {severity: warning}
        annotations:
          summary: "Кэш статистики транков не обновлялся более 15 минут"

      - alert: MikoPBXMissedCallsSourceDown
        expr: mikopbx_stats_cache_missed_error_age_seconds > 900
        for: 10m
        labels: {severity: warning}
        annotations:
          summary: "Источник пропущенных вызовов не отвечает более 15 минут"
```

> [!tip] Почему `up{job="mikopbx"} == 0`
> `up` — служебная метрика самого Prometheus: она ловит даже те случаи,
> когда процесс АТС-экспортера жив, но порт недоступен по сети.

> [!warning] Не используйте `rate()` на скользящих окнах
> `mikopbx_trunk_calls`, `_answered` и подобные — это `gauge`: значения
> относятся к окну и могут уменьшаться, когда вызовы из окна выпадают.
> Берите `rate()` только по `*_total` (счётчикам).

## Recording rules (по желанию)

```yaml
groups:
  - name: mikopbx.recording
    rules:
      - record: pbx:storage_available_ratio
        expr: >
          mikopbx_system_filesystem_available_bytes{mountpoint="/storage"}
          / mikopbx_system_filesystem_size_bytes{mountpoint="/storage"}
      - record: pbx:calls_active_total
        expr: sum(mikopbx_calls_active_by_direction)
      - record: pbx:cpu_busy_ratio
        expr: >
          sum(rate(mikopbx_system_cpu_seconds_total{mode!="idle", cpu!="all"}[5m]))
          / count(count by (cpu) (mikopbx_system_cpu_seconds_total{cpu!="all"}))
```

> [!warning] Формула CPU в 1.4.0
> `mikopbx_system_cpu_seconds_total` теперь отдаёт **секунды**, поэтому
> делить на 100 больше не нужно. Если дашборд был заполнен по формуле
> 1.3.x, в ней надо убрать `/ 100` — иначе занятость будет в 100 раз ниже.
> Подробности — в [[Справочник метрик]].

## Grafana

1. **Connections → Data sources → Add Prometheus** — укажите URL сервера
   Prometheus (например `http://prometheus:9090`).
2. Импортируйте/создайте дашборд. Опорные запросы для панелей:

| Панель | Тип | PromQL |
|---|---|---|
| Asterisk UP | Stat | `mikopbx_asterisk_up` |
| Активные вызовы | Time series | `mikopbx_calls_active_by_direction` |
| Транки | State timeline | `mikopbx_trunk_up` |
| Экспортёр жив | Stat | `mikopbx_exporter_up` |
| Скрейпов всего | Stat | `mikopbx_exporter_scrapes_total` |
| Ошибок сбора | Stat | `mikopbx_exporter_scrape_failures_total` |
| Ответов по коду | Time series | `sum by (code) (rate(mikopbx_exporter_http_responses_total[5m]))` |
| Семейств в ответе | Stat | `mikopbx_exporter_scrape_families` |
| Свободно на /storage | Gauge | `mikopbx_system_filesystem_available_bytes{mountpoint="/storage"} / 1024^3` |
| RAM | Time series | `mikopbx_system_memory_used_bytes / mikopbx_system_memory_total_bytes * 100` |
| CPU | Time series | `100 * pbx:cpu_busy_ratio` |
| CPU по ядрам | Gauge | `100 * rate(mikopbx_system_cpu_seconds_total{mode!="idle",cpu!="all"}[5m])` |
| Сеть | Time series | `rate(mikopbx_system_network_receive_bytes_total[5m])` |
| Звонки за час по транкам | Bar chart | `mikopbx_trunk_calls{period="hour",direction="all"}` |
| ASR по транкам | Bar chart | `mikopbx_trunk_answer_rate_ratio{period="hour",direction="all"} * 100` |
| ACD по транкам | Time series | `mikopbx_trunk_average_billsec_seconds{period="hour",direction="all"}` |
| Свежесть кэша | Stat | `mikopbx_stats_cache_age_seconds` |
| Каналы по типам | Bar chart | `mikopbx_asterisk_channels_by_type` |
| RTP: худшее значение | Stat | `mikopbx_asterisk_channel_quality_max{metric="rtt"}` |

> [!note] Дашборд не входит в поставку
> Это материал для следующей итерации модуля: JSON-дашборд и файлы правил
> планируется отдавать прямо из интерфейса модуля.

## Дальше

- [[Справочник метрик]]
- [[Подключение Prometheus]]
- [[Траблшутинг]]
