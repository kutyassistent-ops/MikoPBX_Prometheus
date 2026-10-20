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
    'repModulePrometheus' => 'Prometheus exporter - %repesent%',
    'mo_ModuleModulePrometheus' => 'Prometheus exporter',
    'BreadcrumbModulePrometheus' => 'Prometheus exporter',
    'SubHeaderModulePrometheus' => 'MikoPBX monitoring via Prometheus',
    'fw_moduleprometheusDescription' => 'Prometheus - MikoPBX monitoring via Prometheus',
    'modpma_ServiceStatus' => 'Exporter status',
    'modpma_StatusLabel' => 'State',
    'modpma_StatusRunning' => 'Running',
    'modpma_StatusStopped' => 'Stopped',
    'modpma_StatusReady' => 'Ready to serve',
    'modpma_StatusNotReady' => 'Collection failing',
    'modpma_PidLabel' => 'PID',
    'modpma_VersionLabel' => 'Version',
    'modpma_MetricsUrlLabel' => 'Metrics URL',
    'modpma_CopyUrl' => 'Copy',
    'modpma_ReadyLabel' => 'Readiness',
    'modpma_LastScrapeLabel' => 'Last scrape',
    'modpma_LastScrapeValue' => 'ago',
    'modpma_RequestsLabel' => 'Requests',
    'modpma_FailuresLabel' => 'Failures',
    'modpma_CacheAgeLabel' => 'Response cache',
    'modpma_CacheTtlValue' => 'TTL',
    'modpma_CacheDisabled' => 'disabled',
    'modpma_RiskTitle' => 'Metrics are served without authorization',
    'modpma_RiskText' => 'No bearer token is set and the exporter listens on every interface, so anybody who reaches the port can read the metrics. Enable the token, restrict the allowed addresses or close the port with a firewall rule.',
    'modpma_NetworkTitle' => 'Network',
    'modpma_AccessTitle' => 'Access',
    'modpma_TlsTitle' => 'HTTPS',
    'modpma_CollectionTitle' => 'Collection',
    'modpma_ListenAddressLabel' => 'Listen address',
    'modpma_ListenPortLabel' => 'Listen port',
    'modpma_AllowedSourceIpLabel' => 'Allowed addresses (IP or CIDR)',
    'modpma_AllowedSourceIpHelp' => 'Comma separated. Empty means any source. The list is checked by the exporter on every connection.',
    'modpma_ScrapeCacheTtlLabel' => 'Response cache, sec',
    'modpma_ScrapeTimeoutLabel' => 'Scrape timeout, sec',
    'modpma_MaxConcurrentLabel' => 'Concurrent scrapes',
    'modpma_ChannelQualityLabel' => 'Publish the RTP quality of every channel',
    'modpma_AuthEnabledLabel' => 'Require a bearer token',
    'modpma_AuthTokenLabel' => 'Bearer token',
    'modpma_AuthTokenPlaceholder' => 'Type a token or generate one',
    'modpma_AuthTokenSavedPlaceholder' => 'Saved — type a new one to replace it',
    'modpma_AuthTokenHint' => 'The token is never shown and never sent to the browser again: an empty field keeps the stored token.',
    'modpma_GenerateToken' => 'Generate',
    'modpma_TokenGenerated' => 'A new token was generated — save the settings',
    'modpma_ToggleTokenVisibility' => 'Show or hide the token',
    'modpma_TlsLabel' => 'Serve over HTTPS',
    'modpma_TlsCertLabel' => 'Certificate path',
    'modpma_TlsKeyLabel' => 'Private key path',
    'modpma_TextfileLabel' => 'Textfile collector',
    'modpma_TextfileHint' => 'Any .prom file in the cache directory is appended to the metrics verbatim. Files larger than 1 MB and files that do not look like an exposition document are skipped.',
    'modpma_TestConnection' => 'Test connectivity',
    'modpma_TestingConnection' => 'Testing...',
    'modpma_TestOk' => 'Exporter responds',
    'modpma_TestFailed' => 'Exporter unreachable',
    'modpma_RestartExporter' => 'Restart exporter',
    'modpma_Restarting' => 'Restarting the exporter...',
    'modpma_RestartOk' => 'The exporter was restarted',
    'modpma_RestartFailed' => 'The exporter could not be restarted',
    'modpma_UrlCopied' => 'The link was copied',
    'modpma_UrlCopyFailed' => 'The link could not be copied',
    'modpma_ErrInvalidAddress' => 'Cannot use listen address: %address%',
    'modpma_ErrTlsPathsRequired' => 'HTTPS requires both a certificate and a key path',
    'modpma_ErrTlsUnreadable' => 'Cannot read the certificate or the key',
    'modpma_ErrTokenRequired' => 'Authorization is enabled but no token is set: type a token or switch the requirement off',
    'modpma_ErrSourceIps' => 'Invalid list of addresses: %error%',
    'modpma_ErrRange' => 'The value is outside the allowed range (%min-%max)',
];