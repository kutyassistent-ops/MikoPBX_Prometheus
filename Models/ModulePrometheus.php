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
namespace Modules\ModulePrometheus\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class ModulePrometheus extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Listen address of the exporter (0.0.0.0 by default)
     *
     * @Column(type="string", nullable=true)
     */
    public $listenAddress;

    /**
     * TCP port of the exporter
     *
     * @Column(type="integer", nullable=true)
     */
    public $listenPort;

    /**
     * Require a bearer token for scraping. Off by default: a fresh
     * install answers /metrics without an Authorization header.
     *
     * @Column(type="string", nullable=true)
     */
    public $authEnabled;

    /**
     * Bearer token required for scraping. Only used while authEnabled is
     * set; an empty token never enables authorization on its own.
     *
     * @Column(type="string", nullable=true)
     */
    public $authToken;

    /**
     * How long a rendered payload may be reused, in seconds.
     * 0 collects fresh data on every request.
     *
     * @Column(type="integer", nullable=true)
     */
    public $scrapeCacheTtl;

    /**
     * Advertised scrape budget in seconds, published as a metric so
     * Grafana can warn before Prometheus starts timing out.
     *
     * @Column(type="integer", nullable=true)
     */
    public $scrapeTimeout;

    /**
     * Serve HTTPS instead of plain HTTP (1 = enabled)
     *
     * @Column(type="string", nullable=true)
     */
    public $tlsEnabled;

    /**
     * Absolute path to the TLS certificate chain
     *
     * @Column(type="string", nullable=true)
     */
    public $tlsCert;

    /**
     * Absolute path to the TLS private key
     *
     * @Column(type="string", nullable=true)
     */
    public $tlsKey;

    /**
     * Comma separated list of IP addresses or CIDR networks allowed to
     * scrape the exporter. Empty means "any source" - the firewall rule
     * of the module stays the only restriction.
     *
     * @Column(type="string", nullable=true)
     */
    public $allowedSourceIp;

    /**
     * Publish the per channel RTP quality family (1 = enabled).
     *
     * The per channel series multiply with the number of concurrent calls
     * and are expensive to store, so only the aggregated form is published
     * by default.
     *
     * @Column(type="string", nullable=true)
     */
    public $publishChannelQuality;

    /**
     * How many scrapes may be collected at the same time. Requests above
     * the limit are answered with 503 instead of forking another child.
     *
     * @Column(type="integer", nullable=true)
     */
    public $maxConcurrentScrapes;

    public function initialize(): void
    {
        $this->setSource('m_ModulePrometheus');
        parent::initialize();
    }
}