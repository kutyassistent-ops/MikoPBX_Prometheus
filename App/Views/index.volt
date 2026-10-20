<form method="post" action="module-prometheus/module-prometheus/save" role="form" class="ui form large" id="module-prometheus-form"
      data-token-set="{{ authTokenSet ? '1' : '0' }}">
    {{ form.render('id') }}

    <div id="prometheus-exporter-status" class="ui icon message">
        <i class="spinner loading icon" id="prom-status-icon"></i>
        <div class="content">
            <div class="header">{{ t._('modpma_ServiceStatus') }}</div>
            <table class="ui very basic compact table" id="prom-status-table">
                <tbody>
                    <tr>
                        <td class="four wide">{{ t._('modpma_StatusLabel') }}</td>
                        <td><span id="prom-status-label" class="ui mini label grey">...</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_PidLabel') }}</td>
                        <td><span id="prom-status-pid">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_VersionLabel') }}</td>
                        <td><span id="prom-status-version">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_MetricsUrlLabel') }}</td>
                        <td>
                            <a id="prom-status-url" href="#" target="_blank" rel="noopener">—</a>
                            <button type="button" class="ui mini basic button" id="prom-copy-url">
                                {{ t._('modpma_CopyUrl') }}
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_ReadyLabel') }}</td>
                        <td><span id="prom-status-ready" class="ui mini label grey">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_LastScrapeLabel') }}</td>
                        <td><span id="prom-status-last-scrape">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_RequestsLabel') }}</td>
                        <td><span id="prom-status-requests">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_FailuresLabel') }}</td>
                        <td><span id="prom-status-failures">—</span></td>
                    </tr>
                    <tr>
                        <td>{{ t._('modpma_CacheAgeLabel') }}</td>
                        <td><span id="prom-status-cache-age">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ui hidden divider"></div>

    <div id="prom-risk-banner" class="ui warning message" style="display:none;">
        <div class="header">{{ t._('modpma_RiskTitle') }}</div>
        <p>{{ t._('modpma_RiskText') }}</p>
    </div>

    <div class="disability">
        {# ===== Сеть ===== #}
        <div class="ui attached blue segment">
            <div class="ui ribbon blue label">
                <i class="sitemap icon"></i> {{ t._('modpma_NetworkTitle') }}
            </div>
            <div class="two fields">
                <div class="field">
                    <label>{{ t._('modpma_ListenAddressLabel') }}</label>
                    <input type="text" name="listenAddress" id="prom-listen-address" value="{{ listenAddress }}"
                           placeholder="0.0.0.0">
                </div>
                <div class="field">
                    <label>{{ t._('modpma_ListenPortLabel') }}</label>
                    <input type="number" name="listenPort" id="prom-listen-port" min="1" max="65535" value="{{ listenPort }}">
                </div>
            </div>
            <div class="field">
                <label>{{ t._('modpma_AllowedSourceIpLabel') }}</label>
                <input type="text" name="allowedSourceIp" id="prom-allowed-source" value="{{ allowedSourceIp }}"
                       placeholder="192.168.1.10, 10.0.0.0/8">
                <p class="help-text">{{ t._('modpma_AllowedSourceIpHelp') }}</p>
            </div>
        </div>

        {# ===== Доступ ===== #}
        <div class="ui attached teal segment">
            <div class="ui ribbon teal label">
                <i class="lock icon"></i> {{ t._('modpma_AccessTitle') }}
            </div>
            <div class="inline field">
                <div class="ui checkbox">
                    <input type="checkbox" name="authEnabled" id="prom-auth-enabled"
                           {{ authEnabled == '1' ? 'checked' : '' }}>
                    <label>{{ t._('modpma_AuthEnabledLabel') }}</label>
                </div>
            </div>
            <div class="field">
                <label>{{ t._('modpma_AuthTokenLabel') }}</label>
                <div class="ui icon input">
                    <input type="password" name="authToken" id="prom-auth-token" value=""
                           placeholder="{{ authTokenPlaceholder }}"
                           autocomplete="off">
                    <i class="eye icon" id="prom-auth-token-toggle"
                       title="{{ t._('modpma_ToggleTokenVisibility') }}"></i>
                </div>
                <button type="button" class="ui mini basic button" id="prom-generate-token">
                    <i class="refresh icon"></i>{{ t._('modpma_GenerateToken') }}
                </button>
                <p class="help-text">{{ t._('modpma_AuthTokenHint') }}</p>
            </div>
        </div>

        {# ===== HTTPS ===== #}
        <div class="ui attached green segment">
            <div class="ui ribbon green label">
                <i class="shield icon"></i> {{ t._('modpma_TlsTitle') }}
            </div>
            <div class="inline field">
                <div class="ui checkbox">
                    <input type="checkbox" name="tlsEnabled" id="prom-tls-enabled"
                           {{ tlsEnabled == '1' ? 'checked' : '' }}>
                    <label>{{ t._('modpma_TlsLabel') }}</label>
                </div>
            </div>
            <div class="two fields">
                <div class="field">
                    <label>{{ t._('modpma_TlsCertLabel') }}</label>
                    <input type="text" name="tlsCert" id="prom-tls-cert" value="{{ tlsCert }}"
                           placeholder="/etc/mikopbx/certs/prometheus.crt">
                </div>
                <div class="field">
                    <label>{{ t._('modpma_TlsKeyLabel') }}</label>
                    <input type="text" name="tlsKey" id="prom-tls-key" value="{{ tlsKey }}"
                           placeholder="/etc/mikopbx/certs/prometheus.key">
                </div>
            </div>
        </div>

        {# ===== Сбор ===== #}
        <div class="ui attached purple segment">
            <div class="ui ribbon purple label">
                <i class="chart bar icon"></i> {{ t._('modpma_CollectionTitle') }}
            </div>
            <div class="three fields">
                <div class="field">
                    <label>{{ t._('modpma_ScrapeCacheTtlLabel') }}</label>
                    <input type="number" name="scrapeCacheTtl" id="prom-cache-ttl" min="0" max="300"
                           value="{{ scrapeCacheTtl }}">
                </div>
                <div class="field">
                    <label>{{ t._('modpma_ScrapeTimeoutLabel') }}</label>
                    <input type="number" name="scrapeTimeout" id="prom-scrape-timeout" min="1" max="120"
                           value="{{ scrapeTimeout }}">
                </div>
                <div class="field">
                    <label>{{ t._('modpma_MaxConcurrentLabel') }}</label>
                    <input type="number" name="maxConcurrentScrapes" id="prom-max-concurrent" min="1" max="64"
                           value="{{ maxConcurrentScrapes }}">
                </div>
            </div>
            <div class="inline field">
                <div class="ui checkbox">
                    <input type="checkbox" name="publishChannelQuality" id="prom-channel-quality"
                           {{ publishChannelQuality == '1' ? 'checked' : '' }}>
                    <label>{{ t._('modpma_ChannelQualityLabel') }}</label>
                </div>
            </div>
            <div class="field">
                <label>{{ t._('modpma_TextfileLabel') }}</label>
                <p class="help-text">{{ t._('modpma_TextfileHint') }}</p>
            </div>
        </div>
    </div>

    <div class="ui hidden divider"></div>

    <button class="ui labeled icon basic button" type="button" id="test-connection">
        <i class="plug icon"></i>
        {{ t._('modpma_TestConnection') }}
    </button>

    <button class="ui labeled icon basic button" type="button" id="restart-exporter">
        <i class="redo icon"></i>
        {{ t._('modpma_RestartExporter') }}
    </button>

    <div class="ui hidden divider"></div>

{{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>
