/* global globalRootUrl, globalTranslate, Form, Config, TokenManager */

const ModulePrometheus = {
	$formObj: $('#module-prometheus-form'),
	$disabilityFields: $('#module-prometheus-form .disability'),
	$statusToggle: $('#module-status-toggle'),
	$testBtn: $('#test-connection'),
	$restartBtn: $('#restart-exporter'),
	$riskBanner: $('#prom-risk-banner'),
	messageTimer: null,

	/**
	 * On page load we init the form, the module state toggle handler,
	 * the connectivity probe and the token visibility toggle.
	 */
	initialize() {
		ModulePrometheus.checkStatusToggle();
		window.addEventListener('ModuleStatusChanged', ModulePrometheus.checkStatusToggle);
		ModulePrometheus.$testBtn.on('click', ModulePrometheus.testConnection);
		ModulePrometheus.$restartBtn.on('click', ModulePrometheus.restartExporter);
		$('#prom-auth-token-toggle').on('click', ModulePrometheus.toggleTokenVisibility);
		$('#prom-generate-token').on('click', ModulePrometheus.generateToken);
		$('#prom-copy-url').on('click', ModulePrometheus.copyMetricsUrl);
		$('#prom-auth-enabled').on('change', ModulePrometheus.toggleAuthFields);
		$('#prom-tls-enabled').on('change', ModulePrometheus.toggleTlsFields);
		ModulePrometheus.toggleAuthFields();
		ModulePrometheus.toggleTlsFields();
		ModulePrometheus.updateRiskBanner();
		// The validation handler is bound before the shared Form, so an
		// invalid value stops the request instead of reaching the server.
		ModulePrometheus.$formObj.on('submit', ModulePrometheus.validateForm);
		ModulePrometheus.initializeForm();
	},

	/**
	 * Enables/disables the settings fields depending on the module status.
	 */
	checkStatusToggle() {
		if (ModulePrometheus.$statusToggle.checkbox('is checked')) {
			ModulePrometheus.$disabilityFields.removeClass('disabled');
		} else {
			ModulePrometheus.$disabilityFields.addClass('disabled');
		}
	},

	/**
	 * The token is only meaningful while authorization is switched on, and
	 * the field is disabled while it is off so an empty value is submitted.
	 */
	toggleAuthFields() {
		const enabled = $('#prom-auth-enabled').is(':checked');
		$('#prom-auth-token')
			.prop('disabled', !enabled)
			.closest('.ui.icon.input')
			.toggleClass('disabled', !enabled);
		ModulePrometheus.updateRiskBanner();
	},

	/**
	 * The certificate paths are only meaningful with TLS on.
	 */
	toggleTlsFields() {
		const enabled = $('#prom-tls-enabled').is(':checked');
		ModulePrometheus.$formObj
			.find('input[name="tlsCert"], input[name="tlsKey"]')
			.prop('disabled', !enabled);
	},

	/**
	 * Warns about the combination that publishes the metrics to everybody:
	 * no bearer token and a listener that accepts every interface.
	 */
	updateRiskBanner() {
		if (!ModulePrometheus.$riskBanner.length) {
			return;
		}
		const authEnabled = $('#prom-auth-enabled').is(':checked');
		const address = ($('#prom-listen-address').val() || '').trim();
		const wildcard = ['', '0.0.0.0', '::', '*'].includes(address);
		ModulePrometheus.$riskBanner.toggleClass('hidden', !(!authEnabled && wildcard));
	},

	/**
	 * Builds the REST headers shared by the probe calls.
	 */
	authHeaders() {
		const headers = { 'X-Requested-With': 'XMLHttpRequest' };
		if (typeof TokenManager !== 'undefined' && TokenManager.accessToken) {
			headers.Authorization = `Bearer ${TokenManager.accessToken}`;
		}
		return headers;
	},

	/**
	 * Probes the exporter listener from the PBX. Distinguishes "the exporter
	 * is down" from "the port is blocked in the firewall", which the status
	 * panel alone cannot tell apart.
	 */
	testConnection() {
		const url = `${Config.pbxUrl}/pbxcore/api/v3/module-prometheus/status:testConnection`;
		ModulePrometheus.showMessage(
			globalTranslate?.modpma_TestingConnection ?? 'Проверка...',
			null,
		);

		fetch(url, { headers: ModulePrometheus.authHeaders() })
			.then(response => response.json())
			.then(body => {
				const ok = body && body.result === true;
				ModulePrometheus.showMessage(
					ok
						? (globalTranslate?.modpma_TestOk ?? 'Экспортёр отвечает')
						: (body?.messages?.error?.[0] ?? (globalTranslate?.modpma_TestFailed ?? 'Экспортёр недоступен')),
					!ok,
				);
			})
			.catch(error => {
				console.error('ModulePrometheus: connection test failed', error);
				ModulePrometheus.showMessage(
					globalTranslate?.modpma_TestFailed ?? 'Экспортёр недоступен',
					true,
				);
			});
	},

	/**
	 * Restarts the exporter worker. The rendered payload lives in the worker
	 * process, so a restart is the honest way to force a fresh collection.
	 */
	restartExporter() {
		const url = `${globalRootUrl}module-prometheus/module-prometheus/refresh`;
		ModulePrometheus.$restartBtn.addClass('loading disabled');
		ModulePrometheus.showMessage(
			globalTranslate?.modpma_Restarting ?? 'Перезапуск экспортёра...',
			null,
		);

		fetch(url, {
			method: 'POST',
			headers: ModulePrometheus.authHeaders(),
		})
			.then(response => response.json())
			.then(body => {
				const ok = body && body.success === true;
				ModulePrometheus.showMessage(
					ok
						? (globalTranslate?.modpma_RestartOk ?? 'Экспортёр перезапущен')
						: (globalTranslate?.modpma_RestartFailed ?? 'Не удалось перезапустить экспортёр'),
					!ok,
				);
			})
			.catch(() => {
				ModulePrometheus.showMessage(
					globalTranslate?.modpma_RestartFailed ?? 'Не удалось перезапустить экспортёр',
					true,
				);
			})
			.finally(() => {
				ModulePrometheus.$restartBtn.removeClass('loading disabled');
			});
	},

	/**
	 * Copies the metrics URL into the clipboard.
	 */
	copyMetricsUrl() {
		const url = $('#prom-status-url').attr('href');
		if (!url || url === '#') {
			return;
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url)
				.then(() => ModulePrometheus.showMessage(
					globalTranslate?.modpma_UrlCopied ?? 'Ссылка скопирована',
					false,
				))
				.catch(() => ModulePrometheus.showMessage(
					globalTranslate?.modpma_UrlCopyFailed ?? 'Не удалось скопировать ссылку',
					true,
				));
			return;
		}
		ModulePrometheus.showMessage(
			globalTranslate?.modpma_UrlCopyFailed ?? 'Не удалось скопировать ссылку',
			true,
		);
	},

	/**
	 * Generates a cryptographically strong bearer token (48 hex characters).
	 */
	generateToken() {
		const array = new Uint8Array(24);
		crypto.getRandomValues(array);
		const hex = Array.from(array)
			.map(b => b.toString(16).padStart(2, '0'))
			.join('');
		$('#prom-auth-token').val(hex);
		ModulePrometheus.showMessage(
			globalTranslate?.modpma_TokenGenerated ?? 'Новый токен сгенерирован — сохраните настройки',
			false,
		);
	},

	/**
	 * Validates the numeric fields before the form is sent.
	 *
	 * The server validates as well, but a value that never leaves the browser
	 * is faster to fix and does not restart the worker for nothing.
	 */
	validateForm(event) {
		const checks = [
			{ selector: '#prom-listen-port', min: 1, max: 65535 },
			{ selector: '#prom-cache-ttl', min: 0, max: 300 },
			{ selector: '#prom-scrape-timeout', min: 1, max: 120 },
			{ selector: '#prom-max-concurrent', min: 1, max: 64 },
		];

		for (const check of checks) {
			const $field = $(check.selector);
			if (!$field.length || $field.prop('disabled')) {
				continue;
			}
			const value = parseInt($field.val(), 10);
			if (isNaN(value) || value < check.min || value > check.max) {
				ModulePrometheus.showMessage(
					(globalTranslate?.modpma_ErrRange ?? 'Значение вне допустимого диапазона')
						.replace('%min', check.min)
						.replace('%max', check.max),
					true,
				);
				event.preventDefault();
				event.stopImmediatePropagation();
				return false;
			}
		}

		// Authorization without a token would leave the exporter open.
		if ($('#prom-auth-enabled').is(':checked')) {
			const token = ($('#prom-auth-token').val() || '').trim();
			const stored = ModulePrometheus.$formObj.attr('data-token-set') === '1';
			if (token === '' && !stored) {
				ModulePrometheus.showMessage(
					globalTranslate?.modpma_ErrTokenRequired ?? 'Укажите bearer-токен или отключите авторизацию',
					true,
				);
				event.preventDefault();
				event.stopImmediatePropagation();
				return false;
			}
		}

		return true;
	},

	/**
	 * Shows a short lived inline message next to the action buttons.
	 *
	 * @param {string} text
	 * @param {boolean|null} isError true = error, false = ok, null = neutral
	 */
	showMessage(text, isError) {
		const $host = ModulePrometheus.$testBtn.parent();
		let $msg = $host.find('.prom-inline-message');
		if (!$msg.length) {
			$msg = $('<div class="prom-inline-message"></div>').appendTo($host);
		}
		$msg
			.toggleClass('prom-error', isError === true)
			.toggleClass('prom-ok', isError === false)
			.toggleClass('prom-info', isError === null)
			.text(text)
			.stop(true, true)
			.show();

		window.clearTimeout(ModulePrometheus.messageTimer);
		ModulePrometheus.messageTimer = window.setTimeout(() => $msg.fadeOut(300), 6000);
	},

	/**
	 * Show/hide the bearer token. A password field keeps it out of screen
	 * shares and browser history.
	 */
	toggleTokenVisibility() {
		const $token = $('#prom-auth-token');
		const $icon = $('#prom-auth-token-toggle');
		if (!$token.length) {
			return;
		}
		const isHidden = $token.attr('type') === 'password';
		$token.attr('type', isHidden ? 'text' : 'password');
		if (isHidden) {
			$icon.removeClass('eye').addClass('eye slash outline');
		} else {
			$icon.removeClass('eye slash outline').addClass('eye');
		}
	},

	/**
	 * Callback called before the form is sent.
	 *
	 * @param {Object} settings - The current settings of the form
	 * @returns {Object} - The updated settings
	 */
	cbBeforeSendForm(settings) {
		const result = settings;
		const values = ModulePrometheus.$formObj.form('get values');
		// Semantic UI reports a checkbox as 'false' when it is off, and the
		// server expects 'on' - the DOM is the authoritative source.
		['authEnabled', 'tlsEnabled', 'publishChannelQuality'].forEach(name => {
			const el = ModulePrometheus.$formObj[0].querySelector(`[name="${name}"]`);
			if (el) {
				values[name] = el.checked ? 'on' : 'off';
			}
		});
		result.data = values;
		return result;
	},

	/**
	 * Callback called after the form has been sent.
	 */
	cbAfterSendForm() {
		// The stored token is never sent to the browser, so the page has to
		// learn about the new state from the answer of the server.
		const $token = $('#prom-auth-token');
		if ($token.length) {
			$token.val('');
		}
		ModulePrometheus.updateRiskBanner();
	},

	/**
	 * Initializes the shared Form object for the module page.
	 */
	initializeForm() {
		Form.$formObj = ModulePrometheus.$formObj;
		Form.url = `${globalRootUrl}module-prometheus/module-prometheus/save`;
		Form.validateRules = {};
		Form.cbBeforeSendForm = ModulePrometheus.cbBeforeSendForm;
		Form.cbAfterSendForm = ModulePrometheus.cbAfterSendForm;
		Form.initialize();
	},
};

$(document).ready(() => {
	ModulePrometheus.initialize();
});
