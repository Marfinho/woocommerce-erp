/*
 * ERP-Seite „Urlaub & Abwesenheit“: beantragen, Krankmeldung, Kalender, Genehmigen.
 * Schnittstelle: includes/Erp/Pages/Abwesenheit.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['abwesenheit'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { month: new Date().toISOString().slice(0, 7), tab: 'ich' };
		var body = h('div');
		var data = null;

		var newBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () { newDialog('urlaub'); } }, 'Urlaub beantragen');
		var sickBtn = h('button', { class: 'btn', type: 'button', onclick: function () { newDialog('krank'); } }, 'Krank melden');
		ui.pageHead('Urlaub & Abwesenheit', 'Urlaub beantragen, Krankheit melden und sehen, wer wann da ist.', [sickBtn, newBtn]);
		ui.view().appendChild(body);

		function days(n) { return String(n).replace('.', ',') + (n === 1 ? ' Tag' : ' Tage'); }
		var statusLabel = { beantragt: 'beantragt', genehmigt: 'genehmigt', abgelehnt: 'abgelehnt', zurueckgezogen: 'zurückgezogen' };

		function newDialog(kind, forUser) {
			var d = data;
			if (!d) { return; }
			var kindSel = k.select(Object.keys(d.kinds).map(function (key) { return [key, d.kinds[key]]; }), kind);
			var today = new Date().toISOString().slice(0, 10);
			var from = k.input(today, { type: 'date', required: '' });
			var to = k.input(today, { type: 'date' });
			var half = h('input', { type: 'checkbox' });
			var note = k.input('', { placeholder: kind === 'krank' ? 'optional, z. B. AU bis …' : 'optional' });
			var fields = [k.field('Art', kindSel), h('div', { class: 'kt-grid' }, k.field('Von', from), k.field('Bis', to)), h('label', { class: 'lg-chk' }, half, ' Halber Tag (nur bei einem Tag)'), k.field('Notiz', note)];
			var who = null;
			if (d.can_approve && d.people) {
				who = k.select(d.people.map(function (p) { return [p.id, p.name]; }), forUser || d.me);
				fields.unshift(k.field('Für', who, 'Für andere eingetragener Urlaub gilt gleich als genehmigt.'));
			}
			from.addEventListener('change', function () { if (to.value < from.value) { to.value = from.value; } });
			k.formDialog(kind === 'krank' ? 'Krank melden' : 'Abwesenheit eintragen', fields, kind === 'krank' ? 'Melden' : 'Eintragen', function () {
				return ui.api('abwesenheit', { method: 'POST', body: { kind: kindSel.value, from: from.value, to: to.value, half_day: half.checked ? 1 : 0, note: note.value, user_id: who ? who.value : '' } });
			}, load);
		}

		function calendar(d) {
			var first = new Date(d.month + '-01T12:00:00Z');
			var y = first.getUTCFullYear(), m = first.getUTCMonth();
			var lastDay = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
			var offset = (first.getUTCDay() + 6) % 7;
			var grid = h('div', { class: 'kt-cal' });
			['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].forEach(function (n) { grid.appendChild(h('b', { style: 'font-size:12px;text-align:center' }, n)); });
			for (var i = 0; i < offset; i++) { grid.appendChild(h('div', { class: 'off' })); }
			for (var day = 1; day <= lastDay; day++) {
				var date = d.month + '-' + ('0' + day).slice(-2);
				var dow = (offset + day - 1) % 7;
				var hol = d.holidays[date];
				var cell = h('div', { class: dow >= 5 || hol ? 'we' : '' }, h('span', null, String(day)), hol ? h('small', { class: 'muted', style: 'display:block;font-size:10px' }, hol) : null);
				d.calendar.forEach(function (a) {
					if (a.from <= date && a.to >= date) {
						cell.appendChild(h('span', { class: 'ab ' + a.kind + (a.status === 'beantragt' ? ' beantragt' : ''), title: a.name + ': ' + a.kind_label + (a.status === 'beantragt' ? ' (beantragt)' : '') }, a.name.split(' ')[0]));
					}
				});
				grid.appendChild(cell);
			}
			function shift(n) {
				var t = new Date(Date.UTC(y, m + n, 1));
				state.month = t.toISOString().slice(0, 7);
				load();
			}
			var title = first.toLocaleDateString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' });
			return h('div', { class: 'card kt-gap' },
				h('div', { class: 'kt-bar' }, h('button', { class: 'btn', type: 'button', onclick: function () { shift(-1); } }, '←'), h('b', null, title), h('button', { class: 'btn', type: 'button', onclick: function () { shift(1); } }, '→')),
				grid);
		}

		function entryCard(a, approve) {
			var btns = h('div', { class: 'kt-btns' });
			if (approve) {
				btns.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { decide(a, 'genehmigt'); } }, 'Genehmigen'));
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { decide(a, 'abgelehnt'); } }, 'Ablehnen'));
			} else if ((a.mine || data.can_approve) && (a.status === 'beantragt' || a.status === 'genehmigt')) {
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					k.confirmDo('Zurückziehen', a.kind_label + ' vom ' + k.dmy(a.from) + ' bis ' + k.dmy(a.to) + ' zurückziehen?', 'Zurückziehen', function () {
						return ui.api('abwesenheit/' + a.id + '/zurueckziehen', { method: 'POST', body: {} });
					}, load);
				} }, 'Zurückziehen'));
			}
			var cls = a.status === 'genehmigt' ? 'now' : (a.status === 'beantragt' ? 'later' : '');
			return h('article', { class: 'row-card kt-row' + (a.status === 'abgelehnt' || a.status === 'zurueckgezogen' ? ' kt-dim' : '') },
				h('div', { class: 'row-main' },
					h('b', null, (approve || !a.mine ? a.name + ' · ' : '') + a.kind_label),
					h('small', null, k.dmy(a.from) + (a.to !== a.from ? ' – ' + k.dmy(a.to) : '') + (a.half_day ? ' (halber Tag)' : '') + (a.note ? ' · ' + a.note : '') + (a.decision ? ' · ' + a.decision : '')),
					h('div', { class: 'row-tags' }, k.tag(statusLabel[a.status] || a.status, cls))),
				h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, days(a.days))),
				btns);
		}

		function decide(a, decision) {
			var note = k.input('', { placeholder: 'optional, geht per Mail mit' });
			k.formDialog(decision === 'genehmigt' ? 'Genehmigen' : 'Ablehnen', [h('p', null, a.name + ': ' + a.kind_label + ' ' + k.dmy(a.from) + ' – ' + k.dmy(a.to) + ' (' + days(a.days) + ')'), k.field('Notiz', note)],
				decision === 'genehmigt' ? 'Genehmigen' : 'Ablehnen', function () {
					return ui.api('abwesenheit/' + a.id + '/entscheiden', { method: 'POST', body: { decision: decision, note: note.value } });
				}, load);
		}

		function teamBox(d) {
			var box = h('div');
			if (d.pending.length) {
				box.appendChild(h('h3', { class: 'kt-h' }, 'Offene Anträge'));
				var l = h('div', { class: 'rows' });
				d.pending.forEach(function (a) { l.appendChild(entryCard(a, true)); });
				box.appendChild(l);
			}
			box.appendChild(h('h3', { class: 'kt-h' }, 'Urlaubskonten ' + new Date().getFullYear()));
			box.appendChild(k.table(['Person', 'Anspruch', 'Übertrag', 'genommen', 'geplant', 'beantragt', 'Rest', 'Krankheitstage', ''], d.people.map(function (p) {
				return [p.name, days(p.anspruch), p.uebertrag ? days(p.uebertrag) + (p.verfallen ? ' (' + days(p.verfallen) + ' verfallen)' : '') : '—', days(p.genommen), days(p.geplant), days(p.beantragt), h('b', null, days(p.rest)), String(p.krank).replace('.', ','),
					h('button', { class: 'btn', type: 'button', onclick: function () {
						var inp = k.input(String(p.anspruch).replace('.', ','), { inputmode: 'decimal' });
						k.formDialog('Urlaubsanspruch: ' + p.name, [k.field('Tage je Jahr', inp)], 'Speichern', function () {
							return ui.api('abwesenheit/anspruch', { method: 'POST', body: { user_id: p.id, days: inp.value } });
						}, load);
					} }, 'Anspruch')];
			}), [1, 2, 3, 4, 5, 6, 7]));

			var land = k.select(Object.keys(d.states).map(function (s) { return [s, d.states[s]]; }), d.settings.bundesland);
			var std = k.input(String(d.settings.tage_standard), { inputmode: 'numeric' });
			var carry = h('input', { type: 'checkbox', checked: !!d.settings.uebertrag });
			var exp = k.input(d.settings.verfall, { placeholder: '03-31' });
			var btn = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var form = h('form', null, h('div', { class: 'kt-grid kt-gap' }, k.field('Bundesland (Feiertage)', land), k.field('Urlaubstage Vorgabe', std), k.field('Übertrag verfällt nach (MM-TT)', exp)),
				h('label', { class: 'lg-chk' }, carry, ' Resturlaub ins nächste Jahr übertragen (wird zuerst verbraucht)'),
				h('div', { class: 'kt-actions' }, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				ui.api('abwesenheit/anspruch', { method: 'POST', body: { bundesland: land.value, tage_standard: std.value, uebertrag: carry.checked ? 1 : 0, verfall: exp.value } }).then(function (r) { ui.toast(r.message); load(); })
					.catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			box.appendChild(h('details', { class: 'card kt-gap' }, h('summary', null, 'Feiertage und Vorgaben'), form));
			return box;
		}

		function paint() {
			var d = data;
			body.textContent = '';
			var b = d.balance;
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Resturlaub ' + new Date().getFullYear(), days(b.rest), 'Anspruch ' + days(b.anspruch) + (b.uebertrag ? ' + ' + days(b.uebertrag) + ' aus dem Vorjahr' : '') + (b.verfallen ? ', ' + days(b.verfallen) + ' verfallen' : '')),
				k.tile('Genommen', days(b.genommen)),
				k.tile('Geplant', days(b.geplant)),
				k.tile('Beantragt', days(b.beantragt))));
			var tabs = [['ich', 'Meine Einträge'], ['kalender', 'Kalender']];
			if (d.can_approve) { tabs.push(['team', 'Team' + (d.pending.length ? ' (' + d.pending.length + ')' : '')]); }
			body.appendChild(h('div', { class: 'kt-bar' }, k.pills(tabs, state.tab, function (t) { state.tab = t; paint(); })));
			if (state.tab === 'kalender') {
				body.appendChild(calendar(d));
				body.appendChild(h('p', { class: 'muted' }, d.can_approve ? 'Gestrichelt = beantragt.' : 'Bei anderen steht nur „abwesend“ – den Grund sieht nur die Person selbst und wer Urlaub genehmigt.'));
			} else if (state.tab === 'team' && d.can_approve) {
				body.appendChild(teamBox(d));
			} else {
				var l = h('div', { class: 'rows' });
				if (!d.mine.length) { l.appendChild(h('div', { class: 'card muted' }, 'Noch nichts eingetragen.')); }
				d.mine.forEach(function (a) { l.appendChild(entryCard(a, false)); });
				body.appendChild(l);
			}
		}

		function load() {
			ui.api('abwesenheit?' + k.qs({ monat: state.month })).then(function (d) { data = d; paint(); }).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
