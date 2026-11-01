/*
 * Gemeinsame Bausteine der Seiten Bank, Offene Posten, Steuer, Liquidität,
 * Angebote, Reklamationen, Inventur, Bestellungen an Lieferanten und Abwesenheit.
 * Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	UI.kit = function (ui) {
		var h = ui.h;

		function field(label, control, hint) {
			return h('label', { class: 'kt-f' }, h('span', { class: 'kt-l' }, label), control, hint ? h('small', { class: 'muted' }, hint) : null);
		}

		function input(value, attrs) {
			var a = { class: 'field', type: 'text', value: value === null || value === undefined ? '' : String(value), autocomplete: 'off' };
			Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
			return h('input', a);
		}

		function area(value, attrs) {
			var a = { class: 'field', rows: 3 };
			Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
			var t = h('textarea', a);
			t.value = value || '';
			return t;
		}

		function select(pairs, value, attrs) {
			var a = { class: 'field' };
			Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
			return h('select', a, pairs.map(function (p) {
				return h('option', { value: String(p[0]), selected: String(p[0]) === String(value) ? true : null }, p[1]);
			}));
		}

		function pills(items, current, onPick) {
			return h('div', { class: 'pills', role: 'tablist' }, items.map(function (t) {
				return h('button', { class: 'pill' + (t[0] === current ? ' on' : ''), type: 'button', role: 'tab', 'aria-selected': t[0] === current ? 'true' : 'false', onclick: function () { onPick(t[0]); } }, t[1]);
			}));
		}

		function tile(label, value, note) {
			return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, String(value)), note ? h('small', { class: 'muted' }, note) : null);
		}

		function fail(box, e) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'notice warn' }, e && e.message ? e.message : String(e)));
		}

		function loading(box) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card muted' }, 'Wird geladen …'));
		}

		// Datei aus einer Antwort speichern: {filename, mime, b64} oder {filename, mime, text}.
		function download(r) {
			var data;
			if (r.b64) {
				var bin = atob(r.b64);
				data = new Uint8Array(bin.length);
				for (var i = 0; i < bin.length; i++) { data[i] = bin.charCodeAt(i); }
			} else {
				data = r.text || '';
			}
			var blob = new Blob([data], { type: r.mime || 'application/octet-stream' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url; a.download = r.filename || 'datei';
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
		}

		// Knopf, der eine Datei holt und speichert.
		function fileBtn(label, path, primary) {
			var b = h('button', { class: 'btn' + (primary ? ' primary' : ''), type: 'button' }, ui.ico ? ui.ico('download') : null, label);
			b.addEventListener('click', function () {
				b.disabled = true;
				ui.api(typeof path === 'function' ? path() : path).then(function (r) { b.disabled = false; download(r); })
					.catch(function (e) { b.disabled = false; ui.toast(e.message, true); });
			});
			return b;
		}

		// Formular im Dialog: Knopf sperren, Fehler im Dialog zeigen, bei Erfolg schließen.
		function formDialog(title, fields, submitLabel, send, done, wide) {
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, submitLabel);
			var form = h('form', { class: 'kt-form' }, fields, err, h('div', { class: 'kt-actions' }, btn));
			var d = ui.dlg(title, h('div', { class: 'sheet-body' }, form), wide);
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				err.textContent = '';
				btn.disabled = true;
				send().then(function (r) {
					d.close();
					if (r && r.message) { ui.toast(r.message); }
					if (done) { done(r); }
				}).catch(function (e2) {
					btn.disabled = false;
					err.appendChild(h('div', { class: 'notice warn' }, e2.message));
				});
			});
			return d;
		}

		function confirmDo(title, text, label, send, done) {
			return formDialog(title, [h('p', null, text)], label, send, done);
		}

		function qs(o) {
			return Object.keys(o || {}).filter(function (k) { return o[k] !== '' && o[k] !== null && o[k] !== undefined; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
		}

		function dmy(ymd) {
			var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
			return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
		}

		function eur(n) { return ui.money(Number(n) || 0, 'EUR'); }

		function tag(text, cls) { return h('span', { class: 'tag' + (cls ? ' ' + cls : '') }, text); }

		function table(head, rows, numCols) {
			numCols = numCols || [];
			return h('div', { class: 'kt-scroll' }, h('table', { class: 'list' },
				h('thead', null, h('tr', null, head.map(function (t, i) { return h('th', { class: numCols.indexOf(i) > -1 ? 'num' : null }, t); }))),
				h('tbody', null, rows.map(function (r) {
					return h('tr', null, r.map(function (c, i) { return h('td', { class: numCols.indexOf(i) > -1 ? 'num' : null }, c); }));
				}))));
		}

		return {
			field: field, input: input, area: area, select: select, pills: pills, tile: tile, fail: fail, loading: loading,
			download: download, fileBtn: fileBtn, formDialog: formDialog, confirmDo: confirmDo, qs: qs, dmy: dmy, eur: eur, tag: tag, table: table
		};
	};
})();
