/**
 * Envío del formulario.
 *
 * Tres cosas importantes:
 *  1. La clave de idempotencia se genera UNA vez por formulario cargado y se reusa en los
 *     reintentos del visitante. Un doble clic no crea dos leads.
 *  2. La atribución de PRIMERA visita se guarda en cookie propia: solo el último clic miente,
 *     y la campaña que de verdad trajo al lead se queda sin crédito.
 *  3. Si el servidor responde que el envío quedó en cola, al visitante se le muestra éxito
 *     igual: su envío está guardado y garantizado.
 */
(function () {
	'use strict';

	var COOKIE_ATRIB = 'lcrm_attr';
	var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
		});
	}

	function paramsActuales() {
		var qs = new URLSearchParams(window.location.search);
		var out = {};
		UTM.forEach(function (k) {
			if (qs.get(k)) out[k] = qs.get(k);
		});
		if (qs.get('gclid')) out.gclid = qs.get('gclid');
		if (qs.get('fbclid')) out.fbclid = qs.get('fbclid');
		return out;
	}

	function leerCookie(nombre) {
		var m = document.cookie.match('(^|;)\\s*' + nombre + '\\s*=\\s*([^;]+)');
		if (!m) return null;
		try {
			return JSON.parse(decodeURIComponent(m.pop()));
		} catch (e) {
			return null;
		}
	}

	function guardarCookie(nombre, valor, dias) {
		var d = new Date();
		d.setTime(d.getTime() + dias * 86400000);
		document.cookie =
			nombre + '=' + encodeURIComponent(JSON.stringify(valor)) +
			';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
	}

	function atribucion() {
		var actuales = paramsActuales();
		var primera = leerCookie(COOKIE_ATRIB);

		if (!primera && Object.keys(actuales).length) {
			primera = {
				first: actuales,
				landingPage: window.location.href,
				referrer: document.referrer || ''
			};
			guardarCookie(COOKIE_ATRIB, primera, 90);
		}

		return {
			first: (primera && primera.first) || {},
			last: actuales,
			gclid: actuales.gclid || '',
			fbclid: actuales.fbclid || '',
			referrer: (primera && primera.referrer) || document.referrer || '',
			landingPage: (primera && primera.landingPage) || window.location.href,
			pageUrl: window.location.href,
			pageTitle: document.title
		};
	}

	function valores(form) {
		var out = {};
		var data = new FormData(form);
		data.forEach(function (v, k) {
			var m = k.match(/^f\[(.+)\]$/);
			if (m) out[m[1]] = v;
		});
		return out;
	}

	function mensaje(form, texto, tipo) {
		var caja = form.querySelector('.lcrm-message');
		if (!caja) return;
		caja.textContent = texto;
		caja.className = 'lcrm-message lcrm-message-' + tipo;
	}

	function iniciar(form) {
		if (form.dataset.lcrmReady) return;
		form.dataset.lcrmReady = '1';

		// Una clave por formulario cargado: el reintento del visitante no duplica el lead.
		form.dataset.idempotencyKey = uuid();
		form.dataset.loadedAt = String(Date.now());

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			if (!form.reportValidity()) return;

			var boton = form.querySelector('.lcrm-submit');
			var textoBoton = boton ? boton.textContent : '';
			if (boton) {
				boton.disabled = true;
				boton.textContent = 'Enviando…';
			}
			mensaje(form, '', '');

			var hp = form.querySelector('[name="lcrm_website"]');
			var consent = form.querySelector('[name="lcrm_consent"]');
			var version = form.querySelector('[name="lcrm_consent_version"]');

			var cuerpo = {
				formId: form.dataset.formId,
				idempotencyKey: form.dataset.idempotencyKey,
				values: valores(form),
				consent: {
					accepted: consent ? consent.checked : true,
					version: version ? version.value : ''
				},
				attribution: atribucion(),
				antispam: {
					honeypot: hp ? hp.value : '',
					elapsedSeconds: Math.round((Date.now() - Number(form.dataset.loadedAt)) / 1000)
				}
			};

			fetch(form.dataset.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(cuerpo)
			})
				.then(function (r) {
					return r.json().then(function (b) {
						return { status: r.status, body: b };
					});
				})
				.then(function (res) {
					if (!res.body || !res.body.ok) {
						if (boton) {
							boton.disabled = false;
							boton.textContent = textoBoton;
						}
						mensaje(form, (res.body && res.body.message) || 'No se pudo enviar. Intenta de nuevo.', 'error');
						return;
					}

					var exito = res.body.success || { type: 'message', value: 'Gracias por escribirnos.' };
					if (exito.type === 'redirect') {
						window.location.href = exito.value;
						return;
					}

					form.querySelector('.lcrm-fields').style.display = 'none';
					var acciones = form.querySelector('.lcrm-actions');
					if (acciones) acciones.style.display = 'none';
					var consentBox = form.querySelector('.lcrm-field-consent');
					if (consentBox) consentBox.style.display = 'none';
					mensaje(form, exito.value, 'exito');

					form.dispatchEvent(new CustomEvent('lucuma-crm:submitted', { bubbles: true, detail: cuerpo }));
				})
				.catch(function () {
					// El servidor del sitio no respondió. No se pudo ni encolar.
					if (boton) {
						boton.disabled = false;
						boton.textContent = textoBoton;
					}
					mensaje(form, 'No se pudo enviar. Revisa tu conexión e intenta de nuevo.', 'error');
				});
		});
	}

	function init() {
		document.querySelectorAll('.lcrm-form').forEach(iniciar);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
