---
tags:
  - prometheus
  - integration
  - scrape
aliases:
  - Подключение к Prometheus
  - prometheus.yml
created: 2026-10-16
updated: 2026-10-19
---

# Подключение Prometheus

## Минимальный конфиг

Скопируйте фрагмент ниже в `scrape_configs` файла `prometheus.yml` сервера
Prometheus:

```yaml
scrape_configs:
  - job_name: mikopbx
    scrape_interval: 60s
    scrape_timeout: 10s
    metrics_path: /metrics
    static_configs:
      - targets: ['192.168.1.10:9713']
```

Если в модуле включён чекбокс «Требовать bearer-токен», добавьте
авторизацию. Предпочтительный способ — не хранить токен в конфиге:

```yaml
    bearer_token_file: '/etc/prometheus/secrets/mikopbx.token'
```

Либо прямо в конфиге, если файла создавать не хочется:

```yaml
    authorization:
      credentials: 'ваш-токен'
```

Если в модуле включён HTTPS, добавьте `scheme: https`. Тогда нужен
`bearer_token_file` либо `scheme: https` + `insecure_skip_verify: true`,
если сертификат самоподписанный.

> [!danger] Авторизация выключена по умолчанию
> Пока чекбокс не включён, метрики отдаются **любому**, кто дотянулся до
> порта. В них есть имена всех внешних номеров и состояние транков.
> Либо включайте токен, либо ограничивайте список адресов, либо
> закрывайте порт firewall-правилом.

> [!tip] Ограничение по адресам
> С 1.4.0 в настройках модуля есть список разрешённых адресов (IP или
> CIDR). Экспортёр проверяет его на каждом подключении и отвечает `403`
> всем остальным — это работает независимо от firewall.

Перезагрузите конфигурацию Prometheus (`SIGHUP` или `POST /-/reload`,
если включён `--web.enable-lifecycle`).

## Проверка

1. **Status → Targets** в веб-интерфейсе Prometheus — таргет `mikopbx`
   должен быть **UP**.
2. В **Graph** выполните `mikopbx_asterisk_up` или
   `mikopbx_exporter_build_info` — появится результат.
3. Если таргет DOWN — смотрите [[Траблшутинг]].

> [!tip] Быстрая проверка с самой АТС
> ```bash
> curl -s http://127.0.0.1:9713/-/info | head -20
> ```
> Это JSON-документ со счётчиками процесса экспортёра: сколько запросов
> обработано, сколько ошибок, когда был последний сбор и сколько семейств
> он отдал. Тот же документ показывает страница модуля (панель статуса).

> [!tip] Рекомендуемые интервалы
> - `scrape_interval`: **60s** — метрики PBX меняются не мгновенно, а сеть
>   и диск экономятся; 15s тоже допустимо.
> - `scrape_timeout`: **10s** — с запасом: сбор метрик делает несколько
>   локальных вызовов (CLI + REST + `/proc`), но укладывается в доли секунды.
>   Тот же бюджет задан в модуле («Таймаут сбора»): экспортёр прерывает
>   сбор, который в него не уложился, и отдаёт 503, а не половину метрик.

## Несколько АТС

Добавьте отдельный job для каждой АТС (и label `instance` будет
различать их в запросах):

```yaml
scrape_configs:
  - job_name: mikopbx
    scrape_interval: 60s
    static_configs:
      - targets: ['192.168.1.10:9713']
        labels: {pbx: office-msk}
      - targets: ['192.168.2.10:9713']
        labels: {pbx: office-spb}
```

## Динамические таргеты (file_sd)

Если АТС много или они меняются, удобнее file-based discovery:

```yaml
scrape_configs:
  - job_name: mikopbx
    file_sd_configs:
      - files: ['targets/mikopbx*.yml']
```

```yaml
# targets/mikopbx.yml
- targets: ['192.168.1.10:9713']
  labels: {pbx: office-msk}
```

## Полезные PromQL

```promql
# Длительность сбора
mikopbx_exporter_scrape_duration_seconds

# Успешность сборщиков
mikopbx_exporter_collector_success < 1

# Свежесть кэша CDR-статистики (секунд назад)
time() - mikopbx_stats_cache_last_update_timestamp_seconds

# Аптайм Asterisk в часах
mikopbx_asterisk_uptime_seconds / 3600

# Загрузка CPU, доля от 0 до 1 (с 1.4.0 значения идут в секундах)
sum(rate(mikopbx_system_cpu_seconds_total{mode!="idle", cpu!="all"}[5m])) / count(count by (cpu) (mikopbx_system_cpu_seconds_total{cpu!="all"}))
```

## Ограничение доступа на стороне АТС

Prometheus должен иметь сетевой доступ к порту экспортера. Откройте
порт только для подсети Prometheus (см. [[Настройка модуля#Безопасность|безопасность]]).

## Дальше

- [[Алертинг и Grafana]] — алерты и панели
- [[Справочник метрик]] — что ещё можно снять
