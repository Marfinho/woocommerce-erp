/*
 * ERP-Seite „Bankabgleich“: Kontoauszug (CAMT/CSV) einlesen, Umsätze zuordnen.
 * Schnittstelle: includes/Erp/Pages/Bank.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	// Datei als Text lesen: UTF-8, sonst Windows-1252 (ältere Bank-CSVs).
	function readText(file) {
		return new Promise(function (resolve, reject) {
			var r = new FileReader();
			r.onload = function () {
				var buf = new Uint8Array(r.result);
				try { resolve(new TextDecoder('utf-8', { fatal: true }).decode(buf)); }
				catch (e) { resolve(new TextDecoder('windows-1252').decode(buf)); }
			};
			r.onerror = function () { reject(new Error('Die Datei ließ sich nicht lesen.')); };
			r.readAsArrayBuffer(file);
		});
	}

	UI.pages['bank'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { status: 'offen', q: '', page: 1 };
		var head = h('div');
		var body = h('div');
		var timer = null;

		var fileIn = h('input', { type: 'file', accept: '.xml,.csv,.txt,.sta', hidden: true });
		var importBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () { fileIn.click(); } }, ui.ico ? ui.ico('upload') : null, 'Kontoauszug einlesen');
		fileIn.addEventListener('change', function () {
			var f = fileIn.files && fileIn.files[0];
			if (!f) { return; }
			importBtn.disabled = true;
			readText(f).then(function (text) {
				return ui.api('bank/import', { method: 'POST', body: { name: f.name, content: text } });
			}).then(function (r) {
				ui.toast(r.message);
				state.status = 'offen'; state.page = 1;
				load();
			}).catch(function (e) { ui.toast(e.message, true); })
				.then(function () { importBtn.disabled = false; fileIn.value = ''; });
		});

		ui.pageHead('Bankabgleich', 'Kontoauszug einlesen und jede Zahlung einer Rechnung, Shop-Bestellung oder einem Beleg zuordnen.', [importBtn, fileIn]);
		ui.view().appendChild(head);
		ui.view().appendChild(body);

		function money(n) {
			return h('span', { class: 'row-num ' + (n < 0 ? 'kt-neg' : 'kt-pos') }, (n > 0 ? '+' : '') + k.eur(n));
		}

		function assign(t, c) {
			ui.api('bank/' + t.id + '/zuordnen', { method: 'POST', body: { type: c.type, target_id: c.id } })
				.then(function (r) { ui.toast(r.message); load(); })
				.catch(function (e) { ui.toast(e.message, true); });
		}

		function pickDialog(t) {
			var box = h('div', { class: 'sheet-body' }, h('p', { class: 'muted' }, 'Wird geladen …'));
			var d = ui.dlg('Zuordnen: ' + k.eur(t.amount), box, true);
			ui.api('bank/' + t.id + '/kandidaten').then(function (r) {
				box.textContent = '';
				var search = k.input('', { type: 'search', placeholder: 'Nummer, Name oder Betrag …', 'aria-label': 'Suchen' });
				var list = h('div', { class: 'rows' });
				function paint() {
					var q = search.value.trim().toLowerCase();
					list.textContent = '';
					var rows = r.all.filter(function (c) { return !q || (c.label + ' ' + c.amount + ' ' + (c.refs || []).join(' ')).toLowerCase().indexOf(q) > -1; }).slice(0, 80);
					if (!rows.length) { list.appendChild(h('div', { class: 'card muted' }, r.all.length ? 'Nichts gefunden.' : (t.amount > 0 ? 'Keine offene Rechnung und keine wartende Shop-Bestellung.' : 'Kein Beleg der letzten 120 Tage offen.'))); }
					rows.forEach(function (c) {
						list.appendChild(h('article', { class: 'row-card kt-row' },
							h('div', { class: 'row-main' }, h('b', null, c.label), h('small', null, k.dmy(c.date))),
							h('div', { class: 'kt-num' }, k.eur(c.amount)),
							h('div', { class: 'kt-btns' }, h('button', { class: 'btn primary', type: 'button', onclick: function () { d.close(); assign(t, c); } }, 'Zuordnen'))));
					});
				}
				search.addEventListener('input', paint);
				box.appendChild(h('p', null, h('b', null, t.counterparty || '—'), ' · ', t.purpose || ''));
				box.appendChild(search);
				box.appendChild(list);
				paint();
				search.focus();
			}).catch(function (e) { k.fail(box, e); });
		}

		function ignoreDialog(t) {
			var note = k.input('', { placeholder: 'z. B. Privatentnahme, Umbuchung Tagesgeld', required: '' });
			k.formDialog('Ohne Zuordnung erledigen', [
				h('p', null, k.eur(t.amount), ' · ', t.counterparty || ''),
				k.field('Was ist das?', note, 'Steht im Protokoll und in der Liste „erledigt“.')
			], 'Erledigt', function () {
				return ui.api('bank/' + t.id + '/ignorieren', { method: 'POST', body: { note: note.value } });
			}, load);
			note.focus();
		}

		function rowCard(t) {
			var main = h('div', { class: 'row-main' },
				h('b', null, t.counterparty || 'ohne Namen'),
				h('small', null, k.dmy(t.date) + (t.iban ? ' · ' + t.iban : '')),
				t.purpose ? h('small', null, t.purpose) : null);
			var btns = h('div', { class: 'kt-btns' });

			if (t.status === 'offen') {
				var sug = h('div', { class: 'row-tags' });
				t.suggestions.forEach(function (c) {
					sug.appendChild(h('button', { class: 'btn' + (c.score >= 90 ? ' primary' : ''), type: 'button', title: (c.why || []).join(', '), onclick: function () { assign(t, c); } }, '→ ' + c.label + ' (' + k.eur(c.amount) + ')'));
				});
				if (!t.suggestions.length) { sug.appendChild(h('small', { class: 'muted' }, 'Kein eindeutiger Vorschlag.')); }
				main.appendChild(sug);
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { pickDialog(t); } }, 'Andere wählen'));
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { ignoreDialog(t); } }, 'Ohne Zuordnung'));
			} else {
				main.appendChild(h('div', { class: 'row-tags' },
					k.tag(t.status === 'zugeordnet' ? 'zugeordnet' : 'ohne Zuordnung', t.status === 'zugeordnet' ? 'now' : ''),
					h('small', { class: 'muted' }, ' ' + t.match_note + (t.matched_by ? ' · ' + t.matched_by : ''))));
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					k.confirmDo('Zuordnung lösen', 'Der Umsatz kommt zurück in „offen“. Eine schon bezahlt markierte Rechnung bleibt bezahlt.', 'Lösen', function () {
						return ui.api('bank/' + t.id + '/loesen', { method: 'POST', body: {} });
					}, load);
				} }, 'Lösen'));
			}

			return h('article', { class: 'row-card kt-row' + (t.status === 'offen' ? '' : ' kt-dim') }, main, h('div', { class: 'kt-num' }, money(t.amount)), btns);
		}

		function paint(d) {
			head.textContent = '';
			head.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Kontostand', d.balance ? k.eur(d.balance.amount) : '—', d.balance ? 'laut Auszug vom ' + k.dmy(d.balance.date) : 'kommt mit dem ersten CAMT-Auszug'),
				k.tile('Offen', d.counts.offen || 0),
				k.tile('Zugeordnet', d.counts.zugeordnet || 0),
				k.tile('Ohne Zuordnung', d.counts.ignoriert || 0)));

			var search = k.input(state.q, { type: 'search', placeholder: 'Name, Verwendungszweck oder Betrag …', 'aria-label': 'Suchen' });
			search.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () { state.q = search.value.trim(); state.page = 1; load(); }, 350);
			});
			head.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['offen', 'Offen'], ['zugeordnet', 'Zugeordnet'], ['ignoriert', 'Ohne Zuordnung'], ['alle', 'Alle']], state.status, function (s) { state.status = s; state.page = 1; load(); }),
				h('div', { style: 'flex:1 1 240px;max-width:420px' }, search)));

			body.textContent = '';
			if (!d.rows.length) {
				body.appendChild(h('div', { class: 'card muted' }, d.total || state.q ? 'Nichts gefunden.' : (state.status === 'offen' ? 'Alles zugeordnet. Neue Umsätze kommen mit dem nächsten Kontoauszug.' : 'Hier ist noch nichts.')));
			}
			var list = h('div', { class: 'rows' });
			d.rows.forEach(function (t) { list.appendChild(rowCard(t)); });
			body.appendChild(list);

			if (d.pages > 1) {
				body.appendChild(h('div', { class: 'kt-bar' },
					h('button', { class: 'btn', type: 'button', disabled: d.page <= 1 ? true : null, onclick: function () { state.page--; load(); } }, '← Neuere'),
					h('span', { class: 'muted' }, 'Seite ' + d.page + ' von ' + d.pages),
					h('button', { class: 'btn', type: 'button', disabled: d.page >= d.pages ? true : null, onclick: function () { state.page++; load(); } }, 'Ältere →')));
			}

			body.appendChild(h('details', { class: 'card kt-gap' },
				h('summary', null, 'So kommst du an den Kontoauszug'),
				h('p', null, 'Im Online-Banking unter Umsätze → Export: am besten „CAMT.053“ oder „XML“ wählen – dann kommt auch der Kontostand mit. Eine CSV-Datei mit Kopfzeile (Buchungstag, Betrag, Name, Verwendungszweck) geht auch. Denselben Zeitraum zweimal einlesen schadet nicht: Doppelte Umsätze werden erkannt.'),
				d.imports && d.imports.length ? k.table(['Datei', 'Format', 'Zeilen', 'neu', 'Kontostand'], d.imports.map(function (i) {
					return [i.file_name, i.format.toUpperCase(), String(i.rows_total), String(i.rows_new), i.closing_balance !== null ? k.eur(i.closing_balance) + ' (' + k.dmy(i.closing_date) + ')' : '—'];
				}), [2, 3, 4]) : null));
		}

		function load() {
			ui.api('bank?' + k.qs({ status: state.status, q: state.q, page: state.page }))
				.then(paint).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
