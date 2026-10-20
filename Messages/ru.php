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
return [
    'repModulePrometheus' => 'Экспортер Prometheus - %repesent%',
    'mo_ModuleModulePrometheus' => 'Экспортер Prometheus',
    'BreadcrumbModulePrometheus' => 'Экспортер Prometheus',
    'SubHeaderModulePrometheus' => 'Мониторинг MikoPBX через Prometheus',
    'fw_moduleprometheusDescription' => 'Prometheus - мониторинг MikoPBX через Prometheus',
    'modpma_ServiceStatus' => 'Статус экспортера',
    'modpma_StatusLabel' => 'Состояние',
    'modpma_StatusRunning' => 'Запущен',
    'modpma_StatusStopped' => 'Остановлен',
    'modpma_StatusReady' => 'Готов к сбору',
    'modpma_StatusNotReady' => 'Ошибка сбора',
    'modpma_PidLabel' => 'PID',
    'modpma_VersionLabel' => 'Версия',
    'modpma_MetricsUrlLabel' => 'URL метрик',
    'modpma_CopyUrl' => 'Копировать',
    'modpma_ReadyLabel' => 'Готовность',
    'modpma_LastScrapeLabel' => 'Последний сбор',
    'modpma_LastScrapeValue' => 'назад',
    'modpma_RequestsLabel' => 'Запросов всего',
    'modpma_FailuresLabel' => 'Ошибок',
    'modpma_CacheAgeLabel' => 'Кэш ответа',
    'modpma_CacheTtlValue' => 'TTL',
    'modpma_CacheDisabled' => 'выключен',
    'modpma_RiskTitle' => 'Метрики доступны без авторизации',
    'modpma_RiskText' => 'Bearer-токен не задан, а экспортёр слушает все интерфейсы — метрики прочитает любой, кто дотянется до порта. Включите токен, ограничьте адреса или закройте порт правилом firewall.',
    'modpma_NetworkTitle' => 'Сеть',
    'modpma_AccessTitle' => 'Доступ',
    'modpma_TlsTitle' => 'HTTPS',
    'modpma_CollectionTitle' => 'Сбор',
    'modpma_ListenAddressLabel' => 'Адрес прослушивания',
    'modpma_ListenPortLabel' => 'Порт',
    'modpma_AllowedSourceIpLabel' => 'Разрешённые адреса (IP или CIDR)',
    'modpma_AllowedSourceIpHelp' => 'Через запятую. Пусто — доступ с любого адреса. Список проверяется экспортёром при каждом подключении.',
    'modpma_ScrapeCacheTtlLabel' => 'Кэш ответа, сек',
    'modpma_ScrapeTimeoutLabel' => 'Таймаут сбора, сек',
    'modpma_MaxConcurrentLabel' => 'Параллельных сборов',
    'modpma_ChannelQualityLabel' => 'Публиковать RTP-качество по каждому каналу',
    'modpma_AuthEnabledLabel' => 'Требовать bearer-токен',
    'modpma_AuthTokenLabel' => 'Bearer-токен',
    'modpma_AuthTokenPlaceholder' => 'Введите токен или сгенерируйте',
    'modpma_AuthTokenSavedPlaceholder' => 'Сохранён — введите новый, чтобы заменить',
    'modpma_AuthTokenHint' => 'Токен никогда не показывается и не отправляется в браузер повторно: пустое поле оставляет сохранённый токен без изменений.',
    'modpma_GenerateToken' => 'Сгенерировать',
    'modpma_TokenGenerated' => 'Новый токен сгенерирован — сохраните настройки',
    'modpma_ToggleTokenVisibility' => 'Показать или скрыть токен',
    'modpma_TlsLabel' => 'Отдавать по HTTPS',
    'modpma_TlsCertLabel' => 'Путь к сертификату',
    'modpma_TlsKeyLabel' => 'Путь к приватному ключу',
    'modpma_TextfileLabel' => 'Textfile-коллектор',
    'modpma_TextfileHint' => 'Любой .prom файл в каталоге кэша добавляется к метрикам как есть. Файлы больше 1 МБ и файлы, не похожие на exposition, пропускаются.',
    'modpma_TestConnection' => 'Проверить доступность',
    'modpma_TestingConnection' => 'Проверка...',
    'modpma_TestOk' => 'Экспортёр отвечает',
    'modpma_TestFailed' => 'Экспортёр недоступен',
    'modpma_RestartExporter' => 'Перезапустить экспортёр',
    'modpma_Restarting' => 'Перезапуск экспортёра...',
    'modpma_RestartOk' => 'Экспортёр перезапущен',
    'modpma_RestartFailed' => 'Не удалось перезапустить экспортёр',
    'modpma_UrlCopied' => 'Ссылка скопирована',
    'modpma_UrlCopyFailed' => 'Не удалось скопировать ссылку',
    'modpma_ErrInvalidAddress' => 'Не удалось использовать адрес прослушивания: %address%',
    'modpma_ErrTlsPathsRequired' => 'Для HTTPS нужно указать пути к сертификату и ключу',
    'modpma_ErrTlsUnreadable' => 'Не удалось прочитать сертификат или ключ',
    'modpma_ErrTokenRequired' => 'Включена авторизация, но токен не задан: укажите токен или отключите требование',
    'modpma_ErrSourceIps' => 'Некорректный список адресов: %error%',
    'modpma_ErrRange' => 'Значение вне допустимого диапазона (%min-%max)',
];