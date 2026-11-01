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
		var state = { status: 'offen', q: '', page: 1, konto: '' };
		var last = null;
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

		var syncBtn = h('button', { class: 'btn', type: 'button', hidden: true, onclick: function () {
			syncBtn.disabled = true;
			ui.api('bank/vivid/abrufen', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); load(); })
				.catch(function (e) { ui.toast(e.message, true); }).then(function () { syncBtn.disabled = false; });
		} }, 'Vivid abrufen');
		ui.pageHead('Bankabgleich', 'Umsätze von Vivid automatisch abrufen oder Kontoauszug einlesen – und jede Zahlung einer Rechnung, Shop-Bestellung oder einem Beleg zuordnen.', [syncBtn, importBtn, fileIn]);
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
				h('b', null, t.counterparty || (t.source === 'vivid' && t.ext_type === 'transfer' ? 'Überweisung (Name kommt mit dem Kontoauszug)' : 'ohne Namen')),
				h('small', null, k.dmy(t.date) + (t.iban ? ' · ' + t.iban : '') + (t.source === 'vivid' ? ' · Vivid' : '') + (t.enriched ? ' + Auszug' : '')),
				t.purpose ? h('small', null, t.purpose) : null,
				t.pending ? h('div', { class: 'row-tags' }, k.tag('vorgemerkt – Betrag kann sich noch ändern', 'later')) : null);
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
				if (t.amount < 0 && !t.suggestions.length && ui.findItem('buchhaltung')) { btns.appendChild(h('a', { class: 'btn', href: '#/seite/buchhaltung', title: 'Beleg hochladen, danach hier zuordnen' }, 'Beleg erfassen')); }
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

		function vividBox(d) {
			var v = d.vivid;
			var box = h('div', { class: 'card kt-gap' });
			if (v.connected) {
				var warn = v.expires_in !== null && v.expires_in <= 14;
				box.appendChild(h('div', { class: 'kt-bar' },
					h('div', null, h('b', null, 'Vivid verbunden'), ' ', k.tag('Schlüssel ' + v.key_hint, 'now'),
						h('div', null, h('small', { class: 'muted' }, 'Letzter Abruf: ' + (v.last_sync ? ui.when(v.last_sync) : 'noch keiner') + ' · stündlich automatisch' + (v.expires ? ' · Schlüssel gültig bis ' + k.dmy(v.expires) : '')))),
					d.can_connect ? h('div', { class: 'kt-btns' },
						h('button', { class: 'btn', type: 'button', onclick: function () { connectDialog(d); } }, 'Schlüssel erneuern'),
						h('button', { class: 'btn', type: 'button', onclick: function () {
							k.confirmDo('Vivid trennen', 'Der gespeicherte Schlüssel wird gelöscht. Bereits abgerufene Umsätze bleiben.', 'Trennen', function () {
								return ui.api('bank/vivid', { method: 'POST', body: { disconnect: 1 } });
							}, load);
						} }, 'Trennen')) : null));
				if (warn) { box.appendChild(h('div', { class: 'notice warn' }, v.expires_in < 0 ? 'Der Vivid-Schlüssel ist abgelaufen. Bitte in Vivid Business → API einen neuen anlegen und hier eintragen.' : 'Der Vivid-Schlüssel läuft in ' + v.expires_in + ' Tagen ab. Rechtzeitig einen neuen anlegen.')); }
				if (v.last_error) { box.appendChild(h('div', { class: 'notice warn' }, 'Letzter Abruf fehlgeschlagen (' + ui.when(v.last_error_at) + '): ' + v.last_error)); }
			} else {
				box.appendChild(h('div', { class: 'kt-bar' },
					h('div', null, h('b', null, 'Vivid Business verbinden'), h('div', null, h('small', { class: 'muted' }, 'Umsätze und Kontostand kommen dann stündlich von selbst. Nur lesend – das ERP kann kein Geld bewegen.'))),
					d.can_connect ? h('button', { class: 'btn primary', type: 'button', onclick: function () { connectDialog(d); } }, 'Verbinden') : h('small', { class: 'muted' }, 'Verbinden darf, wer die Einstellungen ändern darf.')));
			}
			return box;
		}

		function connectDialog(d) {
			var key = k.input('', { type: 'password', autocomplete: 'off', placeholder: 'vivid_sk_v1_…', required: '' });
			var exp = k.input(d.vivid.expires || '', { type: 'date' });
			k.formDialog('Vivid Business verbinden', [
				h('ol', { class: 'lg-content' },
					h('li', null, 'In Vivid Business im Browser anmelden (business.vivid.money) → „API“ → „API-Token hinzufügen“.'),
					h('li', null, 'Rechte ankreuzen: „accounts:read“ und „transactions:read“. Gültigkeit wählen (höchstens 180 Tage).'),
					h('li', null, 'Optional auf die IP-Adresse dieses Servers beschränken.'),
					h('li', null, 'Den Schlüssel kopieren – er wird nur einmal angezeigt – und hier einfügen.')),
				k.field('API-Schlüssel', key, 'Wird verschlüsselt gespeichert und nie wieder angezeigt.'),
				k.field('Gültig bis', exp, 'Das Datum aus Vivid. Rechtzeitig vorher kommt eine Erinnerung.'),
				h('p', { class: 'muted' }, 'Hinweis: Vivid gibt bei Überweisungen weder Namen noch Verwendungszweck heraus und nur 90 Tage rückwirkend. Für die Zuordnung nach Rechnungsnummer zusätzlich ab und zu den Umsatz-Export aus Vivid einlesen (CAMT/XML oder CSV) – gleiche Umsätze werden zusammengeführt, nicht doppelt angelegt.')
			], 'Prüfen und verbinden', function () {
				return ui.api('bank/vivid', { method: 'POST', body: { key: key.value, expires: exp.value } });
			}, load, true);
			key.focus();
		}

		function rulesDialog(d) {
			var rows = h('div', { class: 'kt-lines' });
			var list = (d.rules || []).slice();
			function line(r) {
				r = r || { text: '', field: 'beide', direction: 'alle', note: '' };
				var text = k.input(r.text, { placeholder: 'enthält …' });
				var field = k.select([['beide', 'Name oder Zweck'], ['name', 'Name'], ['zweck', 'Verwendungszweck']], r.field);
				var dir = k.select([['alle', 'Ein- und Ausgang'], ['ein', 'nur Eingang'], ['aus', 'nur Ausgang']], r.direction);
				var note = k.input(r.note, { placeholder: 'erledigt als …' });
				var el = h('div', { class: 'card kt-grid' }, k.field('Wenn', text), k.field('in', field), k.field('bei', dir), k.field('dann ohne Zuordnung mit Notiz', note),
					h('button', { class: 'btn', type: 'button', onclick: function () { el.remove(); } }, 'Entfernen'));
				el._get = function () { return { text: text.value, field: field.value, direction: dir.value, note: note.value }; };
				rows.appendChild(el);
			}
			list.forEach(line);
			if (!list.length) { line(null); }
			var auto = h('input', { type: 'checkbox', checked: !!d.settings.auto_zuordnen });
			var thr = k.select([[100, 'nur ganz sicher (Betrag + Nummer)'], [120, 'sehr streng'], [65, 'auch Betrag + Name']], d.settings.auto_schwelle);
			var apply = h('input', { type: 'checkbox', checked: true });
			k.formDialog('Regeln und automatische Zuordnung', [
				h('label', { class: 'lg-chk' }, auto, ' Eindeutige Treffer automatisch zuordnen'),
				k.field('Wie sicher?', thr, 'Automatisch nur, wenn kein zweiter Vorschlag nahe dran ist. Vorgemerkte Umsätze nie.'),
				h('b', null, 'Regeln'), h('p', { class: 'muted' }, 'Für wiederkehrende Umsätze ohne Gegenstück im ERP, z. B. Finanzamt, Privatentnahme, Umbuchung aufs Tagesgeld, Vivid-Gebühren.'),
				rows,
				h('button', { class: 'btn', type: 'button', onclick: function () { line(null); } }, '+ Regel'),
				h('label', { class: 'lg-chk' }, apply, ' Gleich auf alle offenen Umsätze anwenden')
			], 'Speichern', function () {
				var rules = Array.prototype.map.call(rows.children, function (el) { return el._get(); });
				return ui.api('bank/regeln', { method: 'POST', body: { rules: rules, auto_zuordnen: auto.checked ? 1 : 0, auto_schwelle: thr.value, apply: apply.checked ? 1 : 0 } });
			}, load, true);
		}

		function paint(d) {
			last = d;
			syncBtn.hidden = !d.vivid.connected;
			head.textContent = '';
			var bal = d.balance;
			head.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Kontostand', bal ? k.eur(bal.amount) : '—', bal ? (bal.source === 'vivid' ? 'live von Vivid, ' + k.dmy(bal.date) : 'laut Auszug vom ' + k.dmy(bal.date)) : 'mit Vivid oder dem ersten CAMT-Auszug'),
				k.tile('Offen', d.counts.offen || 0),
				k.tile('Zugeordnet', d.counts.zugeordnet || 0),
				k.tile('Ohne Zuordnung', d.counts.ignoriert || 0)));
			head.appendChild(vividBox(d));
			if (d.accounts.length > 1 || (d.accounts.length && d.accounts[0].pending)) {
				head.appendChild(h('div', { class: 'grid tiles kt-gap' }, d.accounts.map(function (a) {
					return k.tile(a.name + (a.provider === 'vivid' ? ' (Vivid)' : ''), a.available !== null ? k.eur(a.available) : '—', (a.iban ? a.iban : '') + (a.pending ? ' · eingehend ' + k.eur(a.pending) : ''));
				})));
			}

			var search = k.input(state.q, { type: 'search', placeholder: 'Name, Verwendungszweck oder Betrag …', 'aria-label': 'Suchen' });
			search.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () { state.q = search.value.trim(); state.page = 1; load(); }, 350);
			});
			var acctSel = d.accounts.length > 1 ? k.select([['', 'Alle Konten']].concat(d.accounts.map(function (a) { return [a.id, a.name]; })), state.konto, { 'aria-label': 'Konto' }) : null;
			if (acctSel) { acctSel.addEventListener('change', function () { state.konto = acctSel.value; state.page = 1; load(); }); }
			head.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['offen', 'Offen'], ['ohne_beleg', 'Ausgaben ohne Beleg' + (d.counts.ohne_beleg ? ' (' + d.counts.ohne_beleg + ')' : '')], ['zugeordnet', 'Zugeordnet'], ['ignoriert', 'Ohne Zuordnung'], ['alle', 'Alle']], state.status, function (s) { state.status = s; state.page = 1; load(); }),
				h('div', { class: 'kt-btns', style: 'flex:1 1 320px;max-width:560px' }, acctSel, search,
					h('button', { class: 'btn', type: 'button', onclick: function () { rulesDialog(d); } }, 'Regeln'))));

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
				h('summary', null, 'Kontoauszug einlesen – wann und wie'),
				h('p', null, 'Mit Vivid: Umsätze kommen automatisch, aber ohne Name und Verwendungszweck bei Überweisungen. Lies dafür ab und zu den Umsatz-Export aus Vivid ein (CAMT/XML oder CSV mit Kopfzeile, je nachdem, was Vivid anbietet): Gleiche Umsätze werden ergänzt, nicht doppelt angelegt. Andere Banken: im Online-Banking unter Umsätze → Export „CAMT.053“/„XML“ oder CSV mit Kopfzeile. Denselben Zeitraum zweimal einlesen schadet nicht.'),
				d.imports && d.imports.length ? k.table(['Datei', 'Format', 'Zeilen', 'neu', 'Kontostand'], d.imports.map(function (i) {
					return [i.file_name, i.format.toUpperCase(), String(i.rows_total), String(i.rows_new), i.closing_balance !== null ? k.eur(i.closing_balance) + ' (' + k.dmy(i.closing_date) + ')' : '—'];
				}), [2, 3, 4]) : null));
		}

		function load() {
			ui.api('bank?' + k.qs({ status: state.status, q: state.q, page: state.page, konto: state.konto }))
				.then(paint).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
