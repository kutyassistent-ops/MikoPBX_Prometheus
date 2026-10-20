/* global globalTranslate */

const PrometheusStatusWorker = {
	timeOut: 10000,
	timeOutHandle: null,
	$statusIcon: $('#prom-status-icon'),
	$statusLabel: $('#prom-status-label'),
	$statusPid: $('#prom-status-pid'),
	$statusVersion: $('#prom-status-version'),
	$statusUrl: $('#prom-status-url'),
	$statusReady: $('#prom-status-ready'),
	$statusMessage: $('#prometheus-exporter-status'),
	$lastScrape: $('#prom-status-last-scrape'),
	$requests: $('#prom-status-requests'),
	$failures: $('#prom-status-failures'),
	$cacheAge: $('#prom-status-cache-age'),

	initialize() {
		PrometheusStatusWorker.watchVisibility();
		PrometheusStatusWorker.restartWorker();
	},

	/**
	 * Polling used to run every 10 seconds even with the tab in the
	 * background. Each poll is a REST call that queries the module
	 * database, so it pauses while the tab is hidden and resumes
	 * immediately when the tab comes back.
	 */
	watchVisibility() {
		document.addEventListener('visibilitychange', () => {
			if (document.hidden) {
				window.clearTimeout(PrometheusStatusWorker.timeOutHandle);
				return;
			}
			PrometheusStatusWorker.restartWorker();
		});
	},

	restartWorker() {
		window.clearTimeout(PrometheusStatusWorker.timeOutHandle);
		PrometheusStatusWorker.worker();
	},

	worker() {
		$.api({
			url: '/pbxcore/api/v3/module-prometheus/status:getStatus',
			on: 'now',
			successTest(response) {
				return response !== undefined && response.result === true;
			},
			onSuccess(response) {
				PrometheusStatusWorker.updateStatus(response.data);
				PrometheusStatusWorker.loadExporterInfo();
				PrometheusStatusWorker.scheduleNext();
			},
			onFailure() {
				PrometheusStatusWorker.showUnknown();
				PrometheusStatusWorker.scheduleNext();
			},
		});
	},

	scheduleNext() {
		if (document.hidden) {
			return;
		}
		PrometheusStatusWorker.timeOutHandle = window.setTimeout(
			PrometheusStatusWorker.worker,
			PrometheusStatusWorker.timeOut,
		);
	},

	updateStatus(data) {
		PrometheusStatusWorker.$statusIcon.removeClass('spinner loading');

		if (data.running) {
			PrometheusStatusWorker.$statusIcon.addClass('check circle').removeClass('exclamation circle');
			PrometheusStatusWorker.$statusMessage.removeClass('negative').addClass('positive');
			PrometheusStatusWorker.$statusLabel
				.text(globalTranslate.modpma_StatusRunning)
				.removeClass('grey red')
				.addClass('green');
			PrometheusStatusWorker.$statusPid.text(data.pid || '—');
			PrometheusStatusWorker.$statusVersion.text(data.version || '—');
		} else {
			PrometheusStatusWorker.$statusIcon.addClass('exclamation circle').removeClass('check circle');
			PrometheusStatusWorker.$statusMessage.removeClass('positive').addClass('negative');
			PrometheusStatusWorker.$statusLabel
				.text(globalTranslate.modpma_StatusStopped)
				.removeClass('grey green')
				.addClass('red');
			PrometheusStatusWorker.$statusPid.text('—');
			PrometheusStatusWorker.$statusVersion.text('—');
		}

		// Readiness is probed by the PBX, not by this page: /-/ready sits
		// behind the same bearer token as /metrics and the browser has no
		// business holding that token.
		const ready = data.ready;
		if (ready === true) {
			PrometheusStatusWorker.$statusReady
				.removeClass('red grey')
				.addClass('green')
				.text(globalTranslate.modpma_StatusReady);
		} else if (ready === false) {
			PrometheusStatusWorker.$statusReady
				.removeClass('green grey')
				.addClass('red')
				.text(globalTranslate.modpma_StatusNotReady);
		} else {
			PrometheusStatusWorker.$statusReady
				.removeClass('green red')
				.addClass('grey')
				.text('—');
		}

		const url = PrometheusStatusWorker.buildUrl(data);
		PrometheusStatusWorker.$statusUrl
			.text(url === '—' ? '—' : url)
			.attr('href', url === '—' ? '#' : url);

		// The exporter has to be asked for the details - the browser cannot
		// reach its port, and the PBX proxies the answer.
		PrometheusStatusWorker.$lastScrape.text('—');
		PrometheusStatusWorker.$requests.text('—');
		PrometheusStatusWorker.$failures.text('—');
		PrometheusStatusWorker.$cacheAge.text('—');
	},

	/**
	 * Asks the PBX for the counters of the exporter process.
	 */
	loadExporterInfo() {
		$.api({
			url: '/pbxcore/api/v3/module-prometheus/status:getExporterInfo',
			on: 'now',
			successTest(response) {
				return response !== undefined && response.result === true;
			},
			onSuccess(response) {
				PrometheusStatusWorker.renderExporterInfo(response.data || {});
			},
			onFailure() {
				PrometheusStatusWorker.$lastScrape.text('—');
			},
		});
	},

	renderExporterInfo(info) {
		const last = info.lastScrape || {};
		if (last.at) {
			const age = Math.max(0, Math.round((Date.now() / 1000) - last.at));
			PrometheusStatusWorker.$lastScrape.text(
				`${globalTranslate.modpma_LastScrapeValue} ${age}s, ${last.families || 0} fam, ${last.bytes || 0} B`
			);
		} else {
			PrometheusStatusWorker.$lastScrape.text('—');
		}

		const requests = info.requestsTotal || 0;
		const failures = (info.scrapeFailures || 0) + (info.scrapeTimeouts || 0) + (info.authFailures || 0);
		PrometheusStatusWorker.$requests.text(String(requests));
		PrometheusStatusWorker.$failures.text(String(failures));

		const cacheTtl = info.scrapeCacheTtl || 0;
		PrometheusStatusWorker.$cacheAge.text(
			cacheTtl > 0
				? `${globalTranslate.modpma_CacheTtlValue} ${cacheTtl}s`
				: globalTranslate.modpma_CacheDisabled
		);
	},

	/**
	 * Builds the metrics URL shown in the panel.
	 *
	 * The old code always used window.location.hostname, which produced a
	 * dead link whenever the exporter is bound to a specific address
	 * instead of 0.0.0.0.
	 */
	buildUrl(data) {
		const port = data?.listenPort;
		if (!port) {
			return '—';
		}
		const scheme = data.tlsEnabled ? 'https' : 'http';
		const configured = (data.listenAddress || '').trim();
		const wildcard = ['', '0.0.0.0', '::', '*'].includes(configured);
		const host = wildcard ? window.location.hostname : configured;
		return `${scheme}://${host}:${port}${data.metricsPath || '/metrics'}`;
	},

	showUnknown() {
		PrometheusStatusWorker.$statusIcon
			.removeClass('spinner loading check circle')
			.addClass('exclamation circle');
		PrometheusStatusWorker.$statusMessage.removeClass('positive').addClass('negative');
		PrometheusStatusWorker.$statusLabel
			.text('?')
			.removeClass('green')
			.addClass('grey');
		PrometheusStatusWorker.$statusReady
			.removeClass('green red')
			.addClass('grey')
			.text('—');
	},
};

$(document).ready(() => {
	PrometheusStatusWorker.initialize();
});
