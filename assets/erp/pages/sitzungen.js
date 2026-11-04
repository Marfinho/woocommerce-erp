/*
 * Angemeldete Geräte (Profilmenü): Übersicht der eigenen Sitzungen, mit
 * „Alle anderen abmelden“. Eine neue Anmeldung beendet andere nicht.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	function device(ua) {
		ua = ua || '';
		var os = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'Unbekanntes Gerät';
		var br = /Edg\//.test(ua) ? 'Edge' : /OPR\/|Opera/.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\/|CriOS/.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
		return br ? os + ' · ' + br : os;
	}

	function dt(ts) {
		return ts ? new Date(ts * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–';
	}

	UI.sessionsDialog = function () {
		var h = UI.h;
		var body = h('div', { style: 'display:grid;gap:12px;padding:4px 0' }, h('p', null, 'Wird geladen …'));
		UI.dlg('Angemeldete Geräte', body);

		function paint(list) {
			body.textContent = '';
			body.appendChild(h('small', { style: 'opacity:.7' }, 'Jedes Gerät und jeder Browser (auch die App vom Home-Bildschirm) hat eine eigene Anmeldung. Eine neue Anmeldung meldet die anderen nicht ab.'));
			list.forEach(function (s) {
				body.appendChild(h('div', { class: 'card', style: 'padding:10px' },
					h('b', null, device(s.ua) + (s.current ? ' (dieses Gerät)' : '')),
					h('div', { class: 'muted' }, 'Angemeldet: ' + dt(s.login) + ' · gültig bis ' + dt(s.expiration)),
					h('div', { class: 'muted' }, 'Adresse: ' + (s.ip || '–'))));
			});
			if (list.length > 1) {
				body.appendChild(h('button', {
					class: 'btn', type: 'button',
					onclick: function () {
						if (!window.confirm('Alle anderen Geräte abmelden?')) { return; }
						UI.api('sitzungen/andere-beenden', { method: 'POST', body: {} }).then(function (r) { UI.toast(r.message); load(); })
							.catch(function (e) { UI.toast(e.message, true); });
					}
				}, 'Alle anderen abmelden'));
			}
		}

		function load() {
			UI.api('sitzungen').then(function (r) { paint(r.sitzungen || []); })
				.catch(function (e) { body.textContent = e.message; });
		}
		load();
	};
})();
