/*
 * Benachrichtigungen (Web-Push): Einstellung im Profilmenü.
 *
 * Das Gerät meldet sich beim Push-Dienst des Browsers an (VAPID-Schlüssel vom
 * Server), der Server weckt den Service Worker (assets/erp/sw.js), der die
 * Meldungen abholt. Auf dem iPhone geht das nur, wenn das ERP über „Zum
 * Home-Bildschirm“ als App installiert ist (iOS 16.4 oder neuer).
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	function keyBytes(b64) {
		var pad = '='.repeat((4 - (b64.length % 4)) % 4);
		var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
		return out;
	}

	function supported() {
		return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	}

	function isIos() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
	function standalone() { return window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches); }

	UI.pushDialog = function () {
		var h = UI.h;
		var body = h('div', { style: 'display:grid;gap:12px;padding:4px 0' }, h('p', null, 'Wird geladen …'));
		UI.dlg('Benachrichtigungen', body);

		function paint(st, sub) {
			body.textContent = '';
			if (!supported()) {
				body.appendChild(h('div', { class: 'notice' },
					isIos() && !standalone()
						? 'Auf dem iPhone und iPad gehen Benachrichtigungen nur, wenn das ERP als App installiert ist: Teilen-Symbol → „Zum Home-Bildschirm“, dann die App von dort öffnen.'
						: 'Dieser Browser kann keine Benachrichtigungen empfangen.'));
				return;
			}
			if (!st.server) {
				body.appendChild(h('div', { class: 'notice' }, 'Der Server kann keine Benachrichtigungen versenden (OpenSSL fehlt oder kann keine Schlüssel erzeugen).'));
				return;
			}
			var denied = Notification.permission === 'denied';
			var mine = sub && st.geraete.some(function (g) { return g.endpoint === sub.endpoint; });

			body.appendChild(h('p', null, mine
				? 'Dieses Gerät erhält Benachrichtigungen.'
				: 'Dieses Gerät erhält keine Benachrichtigungen.'));
			if (denied) {
				body.appendChild(h('div', { class: 'notice' }, 'Benachrichtigungen sind für diese Seite im Browser blockiert. Bitte in den Website-Einstellungen des Browsers erlauben.'));
			}
			if (!mine) {
				body.appendChild(h('button', { class: 'btn primary', type: 'button', disabled: denied, onclick: function () { enable(st); } }, 'Auf diesem Gerät einschalten'));
			} else {
				body.appendChild(h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' },
					h('button', { class: 'btn', type: 'button', onclick: test }, 'Testmeldung senden'),
					h('button', { class: 'btn', type: 'button', onclick: function () { disable(sub); } }, 'Auf diesem Gerät ausschalten')));
			}

			if (st.arten.length) {
				body.appendChild(h('h4', { style: 'margin:8px 0 0' }, 'Worüber?'));
				st.arten.forEach(function (a) {
					var cb = h('input', { type: 'checkbox', checked: a.an });
					cb.addEventListener('change', function () {
						var arten = {};
						st.arten.forEach(function (x) { arten[x.key] = x === a ? cb.checked : x.an; });
						a.an = cb.checked;
						UI.api('push/arten', { method: 'POST', body: { arten: arten } }).catch(function (e) { UI.toast(e.message, true); });
					});
					body.appendChild(h('label', { style: 'display:flex;gap:10px;align-items:center;min-height:44px' }, cb, a.label));
				});
			}
			if (st.geraete.length > 1 || (st.geraete.length === 1 && !mine)) {
				body.appendChild(h('small', { style: 'opacity:.7' }, st.geraete.length + ' Gerät(e) auf deinem Konto angemeldet.'));
			}
		}

		function load() {
			Promise.all([
				UI.api('push/status'),
				supported() ? navigator.serviceWorker.getRegistration().then(function (r) { return r ? r.pushManager.getSubscription() : null; }) : Promise.resolve(null)
			]).then(function (r) { paint(r[0], r[1]); }).catch(function (e) {
				body.textContent = '';
				body.appendChild(h('div', { class: 'notice' }, e.message));
			});
		}

		function enable(st) {
			Notification.requestPermission().then(function (perm) {
				if (perm !== 'granted') { UI.toast('Ohne Erlaubnis im Browser gibt es keine Benachrichtigungen.', true); load(); return; }
				return navigator.serviceWorker.ready.then(function (reg) {
					return reg.pushManager.getSubscription().then(function (s) {
						return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(st.publicKey) });
					});
				}).then(function (s) {
					return UI.api('push/anmelden', { method: 'POST', body: { endpoint: s.endpoint, agent: navigator.userAgent } });
				}).then(function () { UI.toast('Benachrichtigungen eingeschaltet'); load(); });
			}).catch(function (e) { UI.toast(e.message || 'Einschalten fehlgeschlagen', true); load(); });
		}

		function disable(sub) {
			var ep = sub.endpoint;
			sub.unsubscribe().then(function () {
				return UI.api('push/abmelden', { method: 'POST', body: { endpoint: ep } });
			}).then(function () { load(); }).catch(function (e) { UI.toast(e.message, true); });
		}

		function test() {
			UI.api('push/test', { method: 'POST', body: {} }).then(function (r) {
				UI.toast(r.ok ? 'Testmeldung ist unterwegs' : (r.message || 'Nicht zugestellt'), !r.ok);
			}).catch(function (e) { UI.toast(e.message, true); });
		}

		load();
	};
})();
