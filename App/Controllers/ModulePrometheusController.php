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
namespace Modules\ModulePrometheus\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModulePrometheus\App\Forms\ModulePrometheusForm;
use Modules\ModulePrometheus\Lib\PrometheusMain;
use Modules\ModulePrometheus\Models\ModulePrometheus;

class ModulePrometheusController extends BaseController
{
    private $moduleUniqueID = 'ModulePrometheus';
    private $moduleDir;

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = "{$this->url->get()}assets/img/cache/{$this->moduleUniqueID}/logo.svg";
        $this->view->submitMode = null;
        parent::initialize();
    }

    /**
     * Index page controller
     */
    public function indexAction(): void
    {
        $footerCollection = $this->assets->collection(AssetProvider::FOOTER_JS);
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-prometheus-status-worker.js", true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/module-prometheus-index.js", true);

        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss("css/cache/{$this->moduleUniqueID}/module-prometheus.css", true);

        $settings = ModulePrometheus::findFirst();
        if ($settings === null) {
            $settings = new ModulePrometheus();
        }

        // Every value is passed as a separate variable. The Volt templates of
        // the admin cabinet read view variables as plain PHP variables, and
        // an array element is not reachable with the dot notation - the
        // earlier "settings.listenAddress" rendered an empty value and the
        // form looked unconfigured while the exporter was running.
        $this->view->moduleId = (string)($settings->id ?? '');
        $this->view->listenAddress = !empty($settings->listenAddress)
            ? (string)$settings->listenAddress
            : PrometheusMain::DEFAULT_LISTEN_ADDRESS;
        $this->view->listenPort = !empty($settings->listenPort)
            ? (int)$settings->listenPort
            : PrometheusMain::DEFAULT_LISTEN_PORT;
        $this->view->authEnabled = (string)($settings->authEnabled ?? PrometheusMain::AUTH_DISABLED);
        // The token itself never reaches the browser: only the fact that it
        // is stored. An empty field means "keep the stored token".
        $authTokenSet = trim((string)($settings->authToken ?? '')) !== '';
        $this->view->authTokenSet = $authTokenSet;
        $this->view->authTokenPlaceholder = $authTokenSet
            ? $this->translation->_('modpma_AuthTokenSavedPlaceholder')
            : $this->translation->_('modpma_AuthTokenPlaceholder');
        $this->view->scrapeCacheTtl = isset($settings->scrapeCacheTtl) && $settings->scrapeCacheTtl !== null
            ? (int)$settings->scrapeCacheTtl
            : PrometheusMain::DEFAULT_SCRAPE_CACHE_TTL;
        $this->view->scrapeTimeout = !empty($settings->scrapeTimeout)
            ? (int)$settings->scrapeTimeout
            : PrometheusMain::DEFAULT_SCRAPE_TIMEOUT;
        $this->view->maxConcurrentScrapes = !empty($settings->maxConcurrentScrapes)
            ? (int)$settings->maxConcurrentScrapes
            : PrometheusMain::DEFAULT_MAX_CONCURRENT_SCRAPES;
        $this->view->tlsEnabled = (string)($settings->tlsEnabled ?? '0');
        $this->view->tlsCert = (string)($settings->tlsCert ?? '');
        $this->view->tlsKey = (string)($settings->tlsKey ?? '');
        $this->view->allowedSourceIp = (string)($settings->allowedSourceIp ?? '');
        $this->view->publishChannelQuality = (string)($settings->publishChannelQuality ?? '0');

        $this->view->form = new ModulePrometheusForm($settings);
        // App/Views is symlinked by PbxExtensionUtils::createViewSymlinks()
        // to src/AdminCabinet/Views/Modules/ModulePrometheus
        $this->view->pick('Modules/'.$this->moduleUniqueID.'/index');
    }

    /**
     * Save settings AJAX action
     */
    public function saveAction(): void
    {
        if (!$this->request->isPost()) {
            return;
        }
        $data = $this->request->getPost();

        // Trust the id from the form instead of always taking the first row:
        // the table is keyed by identity and findFirst() would silently pick
        // the wrong record if more than one ever exists.
        $recordId = (int)($data['id'] ?? 0);
        $record = $recordId > 0 ? ModulePrometheus::findFirstById($recordId) : null;
        if ($record === null) {
            $record = ModulePrometheus::findFirst();
        }
        if ($record === null) {
            $record = new ModulePrometheus();
        }

        $address = trim((string)($data['listenAddress'] ?? ''));
        if ($address !== '' && !PrometheusMain::isValidListenAddress($address)) {
            // Mirror what BaseController::saveEntity() does on failure, so
            // the shared form.js renders the message the usual way
            $this->view->success = false;
            $this->flash->error($this->translation->_('modpma_ErrInvalidAddress', ['address' => $address]));
            return;
        }
        $record->listenAddress = $address !== '' ? $address : PrometheusMain::DEFAULT_LISTEN_ADDRESS;

        $port = (int)($data['listenPort'] ?? 0);
        $record->listenPort = ($port >= 1 && $port <= 65535) ? $port : PrometheusMain::DEFAULT_LISTEN_PORT;

        // Authorization is opt-in. Switching it off also drops the token so
        // nothing can silently start rejecting scrapes after the fact.
        $authEnabled = (string)($data['authEnabled'] ?? '0') === '1'
            ? PrometheusMain::AUTH_ENABLED
            : PrometheusMain::AUTH_DISABLED;
        $record->authEnabled = $authEnabled;

        // CR/LF in the token would make the Authorization header
        // unusable, so strip them instead of storing a value that can
        // never authenticate
        $token = str_replace(["\r", "\n"], '', (string)($data['authToken'] ?? ''));
        $token = trim($token);
        if ($authEnabled === PrometheusMain::AUTH_ENABLED) {
            if ($token !== '') {
                $record->authToken = $token;
            } elseif (trim((string)($record->authToken ?? '')) === '') {
                // Authorization without a token would leave the exporter
                // completely open - refuse the change instead of silently
                // publishing the metrics to everybody.
                $this->view->success = false;
                $this->flash->error($this->translation->_('modpma_ErrTokenRequired'));
                return;
            }
            // An empty field keeps the stored token: the browser never gets
            // to see it, so it cannot send it back by accident.
        } else {
            $record->authToken = '';
        }

        // 0 means "collect fresh on every request"
        $ttl = (int)($data['scrapeCacheTtl'] ?? 0);
        $record->scrapeCacheTtl = ($ttl >= 0 && $ttl <= 300) ? $ttl : 0;

        $timeout = (int)($data['scrapeTimeout'] ?? 0);
        $record->scrapeTimeout = ($timeout >= 1 && $timeout <= 120) ? $timeout : PrometheusMain::DEFAULT_SCRAPE_TIMEOUT;

        $limit = (int)($data['maxConcurrentScrapes'] ?? 0);
        $record->maxConcurrentScrapes = ($limit >= 1 && $limit <= 64)
            ? $limit
            : PrometheusMain::DEFAULT_MAX_CONCURRENT_SCRAPES;

        $tlsEnabled = (string)($data['tlsEnabled'] ?? '0') === '1' ? '1' : '0';
        $record->tlsEnabled = $tlsEnabled;
        $record->tlsCert = trim((string)($data['tlsCert'] ?? ''));
        $record->tlsKey = trim((string)($data['tlsKey'] ?? ''));

        if ($tlsEnabled === '1' && ($record->tlsCert === '' || $record->tlsKey === '')) {
            $this->view->success = false;
            $this->flash->error($this->translation->_('modpma_ErrTlsPathsRequired'));
            return;
        }

        // The certificate has to exist before a TLS listener can start
        if ($tlsEnabled === '1' && (!is_readable($record->tlsCert) || !is_readable($record->tlsKey))) {
            $this->view->success = false;
            $this->flash->error($this->translation->_('modpma_ErrTlsUnreadable'));
            return;
        }

        $sourceError = PrometheusMain::validateSourceIps((string)($data['allowedSourceIp'] ?? ''));
        if ($sourceError !== null) {
            $this->view->success = false;
            $this->flash->error($this->translation->_('modpma_ErrSourceIps', ['error' => $sourceError]));
            return;
        }
        $record->allowedSourceIp = trim((string)($data['allowedSourceIp'] ?? ''));

        $record->publishChannelQuality = (string)($data['publishChannelQuality'] ?? '0') === '1' ? '1' : '0';

        $this->saveEntity($record);
    }

    /**
     * Drops the rendered payload by restarting the worker.
     *
     * The payload cache lives in the worker process, so there is no file
     * to delete. A restart is the honest way to force a fresh collection;
     * the bearer token itself does not need this, it is re-read from the
     * database on every request.
     */
    public function refreshAction(): void
    {
        PrometheusMain::restartService();
        $this->view->success = true;
    }
}
