/*
 * Lohnabrechnungen: aus den erfassten Zeiten je Person und Monat. Erreichbar
 * über die Seite Löhne (#/lohn). Rechte wie Löhne (verwaltung.loehne).
 * Server: includes/Erp/Pages/Lohn.php, Regeln in includes/Erp/Payroll.php.
 * Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.routes = UI.routes || {};

	function h() { return UI.h.apply(null, arguments); }

	function eur(v) { return Number(v).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }

	function hrs(v) { return Number(v).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

	function dmy(ymd) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || ''); return m ? m[3] + '.' + m[2] + '.' + m[1] : ymd; }

	function shift(month, by) {
		var p = month.split('-');
		var d = new Date(Number(p[0]), Number(p[1]) - 1 + by, 1);
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2);
	}

	function num(text) {
		var t = String(text).trim();
		if (t.indexOf(',') > -1) { t = t.replace(/\./g, '').replace(',', '.'); }
		return /^-?\d+(\.\d+)?$/.test(t) ? Number(t) : null;
	}

	var TAG = { entwurf: 'tag later', festgeschrieben: 'tag now', bezahlt: 'tag now', storniert: 'tag' };

	UI.routes['lohn'] = function (r, ui) {
		var month = /^\d{4}-\d{2}$/.test(r.id || '') ? r.id : new Date().toISOString().slice(0, 7);
		var box = h('div', { class: 'lh' });
		var data = null;

		ui.setHead('Lohnabrechnungen', 'Verwaltung');
		if (!ui.can('verwaltung.loehne')) {
			ui.view().appendChild(h('div', { class: 'notice warn' }, 'Lohnabrechnungen darf nur sehen, wer das Recht „Löhne“ hat. Rechte vergibst du unter „Benutzer & Rechte“.'));
			return;
		}
		ui.pageHead('Lohnabrechnungen', 'Aus den erfassten Zeiten je Person und Monat: Entwurf, festschreiben, als bezahlt markieren.', [
			h('a', { class: 'btn', href: '#/seite/loehne' }, '← Löhne'),
			h('a', { class: 'btn', id: 'lh-csv', href: '#/lohn' }, 'Liste als CSV')
		]);
		ui.view().appendChild(box);

		function load() {
			return ui.api('lohn/monat?monat=' + month).then(function (d) {
				data = d;
				document.getElementById('lh-csv').setAttribute('href', d.csv);
				paint();
			}).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function go(m) { location.hash = '#/lohn/' + m; }

		function act(path, body) {
			return ui.api(path, { method: 'POST', body: body || {} }).then(function (x) { ui.toast(x.message); return load(); }, function (e) { ui.toast(e.message, true); });
		}

		function ask(title, label, value, type, onOk) {
			var input = h('input', { class: 'field', type: type, value: value || '', 'aria-label': label });
			var ok = h('button', { class: 'btn primary', type: 'submit' }, 'Weiter');
			var f = h('form', { class: 'sheet-body' }, h('label', null, label), input, h('div', { style: 'margin-top:12px' }, ok));
			var d = ui.dlg(title, f);
			f.addEventListener('submit', function (e) { e.preventDefault(); d.close(); onOk(input.value); });
		}

		function paint() {
			box.textContent = '';
			var d = data;

			box.appendChild(h('div', { class: 'lh-month' },
				h('button', { class: 'btn', type: 'button', 'aria-label': 'Vorheriger Monat', onclick: function () { go(shift(month, -1)); } }, '‹'),
				h('b', null, d.label),
				h('button', { class: 'btn', type: 'button', 'aria-label': 'Nächster Monat', onclick: function () { go(shift(month, 1)); } }, '›'),
				h('input', { class: 'field', type: 'month', value: month, 'aria-label': 'Monat wählen', onchange: function (e) { if (e.target.value) { go(e.target.value); } } })));

			box.appendChild(h('div', { class: 'notice' }, h('b', null, 'Bruttoabrechnung. '), 'Gerechnet werden Stunden mal Stundenlohn und die Zuschläge und Abzüge, die du einträgst. Lohnsteuer und Sozialversicherung werden nicht berechnet. Dafür ist die Übergabe an den Steuerberater oder ein Lohnprogramm gedacht (PDF je Person, CSV für die Liste).'));

			box.appendChild(h('div', { class: 'grid tiles' },
				tile('Stunden', hrs(d.totals.hours)), tile('Brutto aus Stunden', eur(d.totals.gross)), tile('Gesamt brutto', eur(d.totals.total))));

			var open = d.people.filter(function (p) { return !p.slip && p.hours > 0 && p.unpriced_hours === 0; });
			if (open.length) {
				box.appendChild(h('div', { class: 'lh-bulk' }, h('button', { class: 'btn primary', type: 'button', onclick: function () {
					var done = 0;
					(function next() {
						if (!open.length) { ui.toast(done + ' Entwürfe angelegt.'); load(); return; }
						var p = open.shift();
						ui.api('lohn/abrechnung', { method: 'POST', body: { user_id: p.id, monat: month } }).then(function () { done++; next(); }, function (e) { ui.toast(p.name + ': ' + e.message, true); next(); });
					})();
				} }, 'Alle offenen als Entwurf anlegen (' + open.length + ')')));
			}

			if (!d.people.length) { box.appendChild(h('div', { class: 'card muted' }, 'In diesem Monat sind keine Stunden erfasst.')); }

			d.people.forEach(function (p) { box.appendChild(row(p)); });
		}

		function tile(label, value) {
			return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value));
		}

		function row(p) {
			var s = p.slip;
			var actions = h('div', { class: 'lh-actions' });
			var warn = null;

			if (p.unpriced_hours > 0) { warn = h('div', { class: 'notice warn' }, 'Für ' + hrs(p.unpriced_hours) + ' Stunden ist kein Stundenlohn hinterlegt. Trag ihn unter Löhne mit passendem Stichtag ein, sonst lässt sich nicht festschreiben.'); }

			if (!s) {
				actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { openSlip(p, null); } }, 'Abrechnung erstellen'));
			} else {
				actions.appendChild(h('a', { class: 'btn', href: s.pdf }, 'PDF'));
				if (s.status === 'entwurf') {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { openSlip(p, s); } }, 'Bearbeiten'));
					actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () {
						if (window.confirm('Abrechnung für ' + p.name + ' festschreiben? Danach lässt sie sich nicht mehr ändern, nur stornieren.')) { act('lohn/abrechnung/' + s.id + '/festschreiben'); }
					} }, 'Festschreiben'));
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { if (window.confirm('Entwurf löschen?')) { act('lohn/abrechnung/' + s.id + '/loeschen'); } } }, 'Löschen'));
				}
				if (s.status === 'festgeschrieben') {
					actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { ask('Als bezahlt markieren', 'Bezahlt am', new Date().toISOString().slice(0, 10), 'date', function (v) { act('lohn/abrechnung/' + s.id + '/bezahlt', { paid_on: v }); }); } }, 'Bezahlt markieren'));
				}
				if (s.status === 'festgeschrieben' || s.status === 'bezahlt') {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { ask('Abrechnung ' + s.number + ' stornieren', 'Grund', '', 'text', function (v) { act('lohn/abrechnung/' + s.id + '/storno', { reason: v }); }); } }, 'Stornieren'));
				}
			}

			return h('article', { class: 'row-card lh-row' },
				h('div', { class: 'lh-main' },
					h('b', null, p.name),
					h('div', { class: 'muted small' }, p.email + ' · ' + (p.rate === null ? 'kein Stundenlohn' : eur(p.rate) + ' je Stunde')),
					s ? h('div', { class: 'chips' }, h('span', { class: TAG[s.status] || 'tag' }, s.status_text), s.number ? h('span', { class: 'chip' }, s.number) : null, s.paid_on ? h('span', { class: 'chip' }, 'bezahlt ' + dmy(s.paid_on)) : null,
						s.drift ? h('span', { class: 'chip warn', title: 'Seit der Abrechnung wurden Zeiten für diesen Monat nachgetragen oder geändert.' }, (s.drift > 0 ? '+' : '') + hrs(s.drift) + ' h seit Abrechnung') : null) : null,
					p.voided ? h('div', { class: 'muted small' }, p.voided + ' stornierte Abrechnung' + (p.voided > 1 ? 'en' : '') + ' in diesem Monat') : null,
					warn),
				h('div', { class: 'lh-num' }, h('div', null, hrs(s ? s.hours : p.hours) + ' h'), h('b', null, eur(s ? s.total : p.gross))),
				actions);
		}

		// Dialog: Rechnung ansehen, Zuschläge und Abzüge eintragen, als Entwurf speichern.
		function openSlip(p, slip) {
			var body = h('div', { class: 'sheet-body' }, h('p', { class: 'muted' }, 'Wird geladen …'));
			var dialog = ui.dlg('Abrechnung ' + p.name + ', ' + data.label, body, true);
			var request = slip ? ui.api('lohn/abrechnung/' + slip.id) : ui.api('lohn/vorschau?user_id=' + p.id + '&monat=' + month);

			request.then(function (x) {
				var calc = x.calc;
				var adjustments = slip ? slip.adjustments.map(function (a) { return { label: a.label, amount: String(a.amount).replace('.', ',') }; }) : [];
				var note = h('input', { class: 'field', type: 'text', maxlength: '255', value: slip ? slip.note : '', 'aria-label': 'Bemerkung', placeholder: 'Bemerkung (steht auf dem Beleg)' });
				var adjBox = h('div', { class: 'lh-adj' });
				var totalEl = h('b', null, '');
				var err = h('div', { 'aria-live': 'polite' });

				function sum() {
					var t = 0;
					adjustments.forEach(function (a) { var n = num(a.amount); if (n !== null) { t += n; } });
					return t;
				}

				function paintTotal() { totalEl.textContent = eur(calc.gross + sum()); }

				function paintAdj() {
					adjBox.textContent = '';
					adjustments.forEach(function (a, i) {
						var label = h('input', { class: 'field', type: 'text', value: a.label, maxlength: '80', placeholder: 'z. B. Prämie, Vorschuss', 'aria-label': 'Bezeichnung', oninput: function (e) { a.label = e.target.value; } });
						var amount = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: a.amount, placeholder: '50 oder -25,50', 'aria-label': 'Betrag', style: 'width:130px', oninput: function (e) { a.amount = e.target.value; paintTotal(); } });
						adjBox.appendChild(h('div', { class: 'lh-adjrow' }, label, amount, h('button', { class: 'btn', type: 'button', 'aria-label': 'Zeile entfernen', onclick: function () { adjustments.splice(i, 1); paintAdj(); paintTotal(); } }, '✕')));
					});
					adjBox.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { adjustments.push({ label: '', amount: '' }); paintAdj(); } }, '+ Zuschlag oder Abzug'));
				}

				body.textContent = '';
				if (calc.unpriced_hours > 0) { body.appendChild(h('div', { class: 'notice warn' }, hrs(calc.unpriced_hours) + ' Stunden haben keinen Stundenlohn. Der Entwurf lässt sich speichern, festschreiben geht erst, wenn der Lohn eingetragen ist.')); }

				var lines = h('tbody');
				calc.lines.forEach(function (l) { lines.appendChild(h('tr', null, h('td', null, hrs(l.hours) + ' h × ' + eur(l.rate)), h('td', { class: 'num' }, eur(l.amount)))); });
				body.appendChild(h('table', { class: 'list' }, lines));

				var days = h('tbody');
				calc.days.forEach(function (d) {
					days.appendChild(h('tr', null, h('td', null, dmy(d.date)), h('td', null, d.category_text + (d.description ? ': ' + d.description : '')), h('td', { class: 'num' }, hrs(d.hours)), h('td', { class: 'num' }, d.rate === null ? 'kein Lohn' : eur(d.rate))));
				});
				body.appendChild(h('details', { class: 'adv' }, h('summary', null, calc.days.length + ' Zeiteinträge ansehen'), h('div', { class: 'vw-scroll' }, h('table', { class: 'list' }, h('thead', null, h('tr', null, h('th', null, 'Datum'), h('th', null, 'Tätigkeit'), h('th', { class: 'num' }, 'Std.'), h('th', { class: 'num' }, 'Satz'))), days))));

				body.appendChild(h('h4', null, 'Zuschläge und Abzüge'));
				body.appendChild(h('p', { class: 'muted small', style: 'margin-top:0' }, 'Frei eintragen, Abzüge mit Minus (zum Beispiel Vorschuss -100). Steuern und Sozialabgaben gehören nicht hierher.'));
				body.appendChild(adjBox);
				body.appendChild(note);
				body.appendChild(h('p', { class: 'lh-total' }, 'Gesamtbetrag brutto: ', totalEl));
				body.appendChild(err);

				var save = h('button', { class: 'btn primary', type: 'button' }, slip ? 'Speichern' : 'Als Entwurf anlegen');
				save.addEventListener('click', function () {
					save.disabled = true;
					var payload = { adjustments: adjustments, note: note.value };
					var call = slip ? ui.api('lohn/abrechnung/' + slip.id + '/speichern', { method: 'POST', body: payload }) : ui.api('lohn/abrechnung', { method: 'POST', body: { user_id: p.id, monat: month, adjustments: adjustments, note: note.value } });
					call.then(function (res) { dialog.close(); ui.toast(res.message); load(); }, function (e) { save.disabled = false; err.textContent = ''; err.appendChild(h('div', { class: 'notice warn', role: 'alert' }, e.message)); });
				});
				body.appendChild(h('div', { style: 'margin-top:12px;display:flex;gap:8px' }, save));
				paintAdj();
				paintTotal();
			}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
