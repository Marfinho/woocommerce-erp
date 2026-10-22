/*
 * Buchhaltung im ERP: Reiter Rechnungen, Kasse, Journal, Dauerbuchungen.
 * Server: includes/Erp/Pages/BuchBuchungen.php (Routen unter erp/buch/…).
 * Alle Daten gelangen über textContent bzw. setAttribute in die Seite, nie
 * als HTML-Text. Die Reiter melden sich in UI.buchTabs an; von ctx wird nur
 * ui, canWrite und download benutzt, alles andere ist hier selbst gebaut.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.buchTabs = UI.buchTabs || {};

	/* ---------- Hilfen ---------- */

	function qs(o) {
		return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] !== null && o[k] !== undefined; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
	}

	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
	}

	function day(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : (s || '–');
	}

	function make(ctx) {
		var ui = ctx.ui;
		var h = ui.h;
		var api = {};

		api.ui = ui;
		api.h = h;
		api.canWrite = typeof ctx.canWrite === 'function' ? ctx.canWrite() !== false : ctx.canWrite !== false;
		api.eur = function (v) { return v === null || v === undefined ? '–' : ui.money(v, 'EUR'); };

		api.get = function (path, q) { return ui.api('buch/' + path + (q ? '?' + qs(q) : '')); };
		api.post = function (path, body) { return ui.api('buch/' + path, { method: 'POST', body: body }); };

		api.fail = function (box, e) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'notice warn' }, e.message));
		};

		api.loading = function (box) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		};

		// Detail als Dialog (Seitenblatt); build(body, close) füllt ihn.
		api.sheet = function (title, build, wide) {
			var body = h('div', { class: 'bb-body' });
			var dlg = ui.dlg(title, body, wide);
			build(body, function () { dlg.close(); });
			return dlg;
		};

		api.lab = function (text, input) { return h('label', null, text, input); };

		api.chips = function (items, current, onPick) {
			var row = h('div', { class: 'bb-chips' });
			items.forEach(function (it) {
				row.appendChild(h('button', {
					class: 'chip', type: 'button', 'aria-pressed': String(it[0]) === String(current) ? 'true' : 'false',
					onclick: function () { onPick(it[0]); }
				}, it[1]));
			});
			return row;
		};

		api.sum = function (label, value, tone) {
			return h('div', { class: 'bb-sum' + (tone ? ' ' + tone : '') }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value));
		};

		api.item = function (title, sub, amount, small, off, onclick) {
			return h('li', null, h('button', { class: 'bb-item' + (off ? ' off' : ''), type: 'button', onclick: onclick },
				h('span', { class: 't' }, title),
				h('span', { class: 's' }, sub),
				h('span', { class: 'a' }, amount, small ? h('small', null, small) : null)));
		};

		api.pager = function (d, go) {
			if (!d || d.seiten <= 1) { return null; }
			return h('div', { class: 'bb-pager' },
				h('button', { class: 'btn', type: 'button', disabled: d.seite <= 1, onclick: function () { go(d.seite - 1); } }, 'Zurück'),
				h('span', { class: 'muted' }, 'Seite ' + d.seite + ' von ' + d.seiten + ' · ' + d.gesamt + ' Einträge'),
				h('button', { class: 'btn', type: 'button', disabled: d.seite >= d.seiten, onclick: function () { go(d.seite + 1); } }, 'Weiter'));
		};

		api.download = function (name, mime, text) {
			if (typeof ctx.download === 'function') { ctx.download(name, mime, text); return; }
			var blob = new Blob([text], { type: mime });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url; a.download = name;
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
		};

		// Kopfzeile eines Reiters mit „Neu“-Knopf (nur mit Schreibrecht).
		api.head = function (title, onNew, newLabel) {
			return h('div', { class: 'bb-bar' }, h('h3', null, title),
				onNew && api.canWrite ? h('button', { class: 'btn primary bb-new', type: 'button', onclick: onNew }, '+ ' + (newLabel || 'Neu')) : null);
		};

		// Knopf, der beim Senden sperrt und Fehler in errBox zeigt.
		api.send = function (btn, errBox, fn) {
			btn.disabled = true;
			errBox.textContent = '';
			return fn().then(function (r) { btn.disabled = false; return r; })
				.catch(function (e) { btn.disabled = false; errBox.textContent = e.message; return null; });
		};

		return api;
	}

	// Storno-Block mit Pflicht-Grund: zeigt erst einen Knopf, dann das Formular.
	function stornoBlock(B, label, onSend) {
		var h = B.h;
		var wrap = h('div');
		var open = h('button', { class: 'btn', type: 'button', onclick: function () { show(); } }, label);

		function show() {
			wrap.textContent = '';
			var why = h('textarea', { class: 'field', maxlength: '200', placeholder: 'z. B. Kunde hat storniert, falscher Betrag …', 'aria-label': 'Grund' });
			var err = h('div', { class: 'bb-err', role: 'alert' });
			var go = h('button', { class: 'btn primary', type: 'button' }, label);
			var extra = { bestaetigt: false };

			go.addEventListener('click', function () {
				if (!why.value.trim()) { err.textContent = 'Bitte nenne einen Grund. Er wird im Protokoll festgehalten.'; why.focus(); return; }
				B.send(go, err, function () { return onSend(why.value.trim(), extra.bestaetigt); }).then(function (r) {
					if (r && r.bestaetigen) {
						err.textContent = r.message;
						go.textContent = 'Trotzdem stornieren';
						extra.bestaetigt = true;
					}
				});
			});
			wrap.appendChild(h('div', { class: 'bb-form' },
				B.lab('Grund (Pflicht) – Ein Storno löscht nichts, es bucht gegen und steht im Protokoll.', why),
				err,
				h('div', { class: 'bb-actions' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { wrap.textContent = ''; wrap.appendChild(open); } }, 'Abbrechen'))));
			why.focus();
		}

		wrap.appendChild(open);
		return wrap;
	}

	/* ====================================================================
	 * Rechnungen
	 * ================================================================== */

	UI.buchTabs['rechnungen'] = {
		label: 'Rechnungen',
		order: 20,
		render: function (host, ctx) {
			var B = make(ctx);
			var h = B.h;
			var f = { q: '', von: '', bis: '', status: '', seite: 1 };
			var box = h('div');
			host.textContent = '';
			host.appendChild(B.head('Ausgangsrechnungen', newSheet, 'Neu'));
			host.appendChild(box);

			function newSheet() {
				B.sheet('Neue Rechnung', function (body) {
					body.appendChild(h('p', null, 'Rechnungen entstehen automatisch, sobald eine Bestellung auf „Abgeschlossen“ steht. Sie bekommen dabei die nächste Nummer, die Einnahme wird im Journal gebucht und das PDF abgelegt.'));
					body.appendChild(h('p', { class: 'muted' }, 'Von Hand anlegen oder nachholen lässt sich eine Rechnung hier nicht, damit die Nummern lückenlos bleiben. Diese abgeschlossenen Bestellungen haben noch keine Rechnung:'));
					var list = h('div', null, 'Wird geprüft …');
					body.appendChild(list);
					B.get('rechnungen/fehlend').then(function (d) {
						list.textContent = '';
						if (!d.rows.length) { list.appendChild(h('div', { class: 'notice' }, 'Alle zuletzt abgeschlossenen Bestellungen haben eine Rechnung.')); return; }
						var ul = h('ul', { class: 'bb-list' });
						d.rows.forEach(function (r) {
							ul.appendChild(h('li', { class: 'bb-item', style: 'cursor:default' },
								h('span', { class: 't' }, 'Bestellung #' + r.bestellung),
								h('span', { class: 's' }, day(r.datum) + (r.kunde ? ' · ' + r.kunde : '')),
								h('span', { class: 'a' }, B.eur(r.betrag))));
						});
						list.appendChild(h('div', { class: 'notice warn' }, d.rows.length + ' Bestellung(en) ohne Rechnung. Bitte in der Bestellung prüfen, ob der Abschluss durchgelaufen ist.'));
						list.appendChild(ul);
					}).catch(function (e) { list.textContent = e.message; });
				});
			}

			function load() {
				B.loading(box);
				B.get('rechnungen', f).then(paint).catch(function (e) { B.fail(box, e); });
			}

			function paint(d) {
				box.textContent = '';
				var q = h('input', { class: 'field', type: 'search', value: f.q, placeholder: 'Rechnungs- oder Bestellnummer', 'aria-label': 'Suche' });
				var von = h('input', { class: 'field', type: 'date', value: f.von, 'aria-label': 'Von' });
				var bis = h('input', { class: 'field', type: 'date', value: f.bis, 'aria-label': 'Bis' });
				function go() { f.q = q.value.trim(); f.von = von.value; f.bis = bis.value; f.seite = 1; load(); }
				q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
				box.appendChild(h('div', { class: 'bb-filter' }, q, von, bis, h('button', { class: 'btn primary', type: 'button', onclick: go }, 'Filtern')));
				box.appendChild(B.chips([['', 'Alle'], ['aktiv', 'Gültig'], ['storniert', 'Storniert']], f.status, function (k) { f.status = k; f.seite = 1; load(); }));
				box.appendChild(h('div', { class: 'bb-sums' },
					B.sum('Gültige Rechnungen', B.eur(d.summe.aktiv), 'ok'),
					B.sum('Storniert', B.eur(d.summe.storniert), d.summe.storniert > 0 ? 'warn' : ''),
					B.sum('Treffer', String(d.gesamt))));
				if (!d.rows.length) { box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Keine Rechnungen für diesen Filter.'))); return; }
				var ul = h('ul', { class: 'bb-list' });
				d.rows.forEach(function (r) {
					ul.appendChild(B.item(r.nummer, 'Bestellung #' + r.bestellung + ' · ' + day(r.erstellt) + (r.storniert ? ' · storniert' : ''), B.eur(r.betrag), '', r.storniert, function () { detail(r.id); }));
				});
				box.appendChild(ul);
				var pg = B.pager(d, function (s) { f.seite = s; load(); });
				if (pg) { box.appendChild(pg); }
			}

			function detail(id) {
				B.get('rechnungen/' + id).then(function (d) {
					var r = d.rechnung;
					B.sheet('Rechnung ' + r.nummer, function (body, close) {
						var kv = h('dl', { class: 'bb-kv' },
							h('dt', null, 'Bestellung'), h('dd', null, '#' + r.bestellung + (d.bestellung ? ' (' + d.bestellung + ')' : '')),
							h('dt', null, 'Kunde'), h('dd', null, d.kunde || '–'),
							h('dt', null, 'Betrag'), h('dd', null, B.eur(r.betrag)),
							h('dt', null, 'Erstellt'), h('dd', null, day(r.erstellt)),
							h('dt', null, 'Status'), h('dd', null, r.storniert ? 'storniert' : 'gültig'));
						body.appendChild(kv);
						if (r.storniert && r.storno_grund) { body.appendChild(h('div', { class: 'notice' }, 'Grund des Stornos: ' + r.storno_grund)); }
						if (d.buchungen.length) {
							body.appendChild(h('div', { class: 'bb-log' }, d.buchungen.map(function (b) {
								return h('div', null, 'Journal ' + b.nummer + ' · ' + b.typ + ' · ' + B.eur(b.betrag) + ' · ' + day(b.datum) + (b.storniert ? ' (storniert)' : ''));
							})));
						}
						var act = h('div', { class: 'bb-actions' });
						if (r.url) { act.appendChild(h('a', { class: 'btn', href: r.url, target: '_blank', rel: 'noopener' }, 'PDF öffnen')); }
						else { act.appendChild(h('span', { class: 'muted' }, 'Zu dieser Rechnung liegt keine PDF-Datei vor.')); }
						body.appendChild(act);
						if (!r.storniert && B.canWrite) {
							body.appendChild(stornoBlock(B, 'Rechnung stornieren', function (grund) {
								return B.post('rechnungen/storno', { id: r.id, grund: grund }).then(function (res) {
									ui_toast(B, res.message);
									close();
									load();
									return res;
								});
							}));
						}
					});
				}).catch(function (e) { B.ui.toast(e.message, true); });
			}

			load();
		}
	};

	function ui_toast(B, msg) { B.ui.toast(msg); }

	/* ====================================================================
	 * Kasse
	 * ================================================================== */

	UI.buchTabs['kasse'] = {
		label: 'Kasse',
		order: 30,
		render: function (host, ctx) {
			var B = make(ctx);
			var h = B.h;
			var f = { von: '', bis: '', seite: 1 };
			var box = h('div');
			var tag = h('div');
			host.textContent = '';
			host.appendChild(B.head('Kassenbuch', newSheet, 'Neu'));
			host.appendChild(box);
			host.appendChild(tag);

			function newSheet() {
				B.sheet('Neuer Kasseneintrag', function (body, close) {
					var art = 'ausgabe';
					var bEin = h('button', { class: 'btn', type: 'button', 'aria-pressed': 'false' }, 'Einnahme');
					var bAus = h('button', { class: 'btn', type: 'button', 'aria-pressed': 'true' }, 'Ausgabe');
					function pick(a) { art = a; bEin.setAttribute('aria-pressed', a === 'einnahme' ? 'true' : 'false'); bAus.setAttribute('aria-pressed', a === 'ausgabe' ? 'true' : 'false'); }
					bEin.addEventListener('click', function () { pick('einnahme'); });
					bAus.addEventListener('click', function () { pick('ausgabe'); });
					var betrag = h('input', { class: 'field bb-amount', type: 'text', inputmode: 'decimal', placeholder: '0,00', autocomplete: 'off', 'aria-label': 'Betrag in Euro' });
					var text = h('input', { class: 'field', type: 'text', maxlength: '200', placeholder: 'z. B. Marktstand Tageseinnahme, Porto', 'aria-label': 'Text' });
					var datum = h('input', { class: 'field', type: 'date', value: today(), 'aria-label': 'Datum' });
					var err = h('div', { class: 'bb-err', role: 'alert' });
					var go = h('button', { class: 'btn primary', type: 'button', style: 'min-height:56px;font-size:16px' }, 'Eintrag buchen');
					var bestaetigt = false;
					go.addEventListener('click', function () {
						B.send(go, err, function () {
							return B.post('kasse/eintrag', { datum: datum.value, beschreibung: text.value, art: art, betrag: betrag.value, bestaetigt: bestaetigt });
						}).then(function (r) {
							if (!r) { return; }
							if (r.bestaetigen) { err.textContent = r.message; go.textContent = 'Trotzdem buchen'; bestaetigt = true; return; }
							B.ui.toast(r.message);
							close();
							load();
						});
					});
					[betrag, text, datum].forEach(function (x) { x.addEventListener('input', function () { bestaetigt = false; go.textContent = 'Eintrag buchen'; }); });
					body.appendChild(h('div', { class: 'bb-form' },
						h('div', { class: 'bb-big' }, bEin, bAus),
						B.lab('Betrag (€)', betrag),
						B.lab('Wofür?', text),
						B.lab('Datum', datum),
						h('p', { class: 'muted', style: 'font-size:13px;margin:0' }, 'Gebucht wird endgültig. Ein Irrtum lässt sich nur durch ein Storno mit Gegenbuchung korrigieren.'),
						err, go));
					betrag.focus();
				});
			}

			function load() {
				B.loading(box);
				B.get('kasse', f).then(paint).catch(function (e) { B.fail(box, e); });
				tagesabschluss(today());
			}

			function paint(d) {
				box.textContent = '';
				var von = h('input', { class: 'field', type: 'date', value: f.von, 'aria-label': 'Von' });
				var bis = h('input', { class: 'field', type: 'date', value: f.bis, 'aria-label': 'Bis' });
				box.appendChild(h('div', { class: 'bb-sums' },
					B.sum('Kassenstand', B.eur(d.saldo), d.saldo < 0 ? 'bad' : 'ok'),
					B.sum('Einnahmen (Filter)', B.eur(d.summe.einnahmen), 'ok'),
					B.sum('Ausgaben (Filter)', B.eur(d.summe.ausgaben), 'bad')));
				box.appendChild(h('div', { class: 'bb-filter' }, von, bis, h('button', { class: 'btn primary', type: 'button', onclick: function () { f.von = von.value; f.bis = bis.value; f.seite = 1; load(); } }, 'Filtern')));
				if (!d.rows.length) { box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Keine Kasseneinträge für diesen Zeitraum.'))); return; }
				var ul = h('ul', { class: 'bb-list' });
				d.rows.forEach(function (r) {
					var ein = r.einnahme > 0;
					ul.appendChild(B.item(r.beschreibung, r.nummer + ' · ' + day(r.datum) + (r.storniert ? ' · storniert' : '') + (r.ist_storno ? ' · Stornobuchung' : ''),
						h('span', { class: ein ? 'bb-in' : 'bb-out' }, (ein ? '+ ' : '− ') + B.eur(ein ? r.einnahme : r.ausgabe)),
						'Stand ' + B.eur(r.saldo), r.storniert, function () { detail(r); }));
				});
				box.appendChild(ul);
				var pg = B.pager(d, function (s) { f.seite = s; load(); });
				if (pg) { box.appendChild(pg); }
			}

			function tagesabschluss(datum) {
				tag.textContent = '';
				B.get('kasse/tagesabschluss', { datum: datum }).then(function (t) {
					tag.textContent = '';
					var dIn = h('input', { class: 'field', type: 'date', value: t.datum, 'aria-label': 'Tag' });
					var counted = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: 'Gezählt, z. B. 123,45', 'aria-label': 'Gezählter Bestand' });
					var diff = h('div', { class: 'muted' });
					counted.addEventListener('input', function () {
						var v = parseFloat(String(counted.value).replace(',', '.'));
						if (isNaN(v)) { diff.textContent = ''; return; }
						var x = Math.round((v - t.saldo) * 100) / 100;
						diff.textContent = x === 0 ? 'Stimmt: Kasse und Kassenbuch sind gleich.' : (x > 0 ? 'Es sind ' + B.eur(x) + ' mehr in der Kasse als im Buch.' : 'Es fehlen ' + B.eur(-x) + ' gegenüber dem Buch.');
						diff.className = x === 0 ? 'muted' : 'bb-err';
					});
					tag.appendChild(h('div', { class: 'card', style: 'margin-top:14px' },
						h('h3', { style: 'margin-top:0;font-size:17px' }, 'Tagesabschluss'),
						h('div', { class: 'bb-filter' }, dIn, h('button', { class: 'btn', type: 'button', onclick: function () { tagesabschluss(dIn.value || today()); } }, 'Tag anzeigen')),
						h('div', { class: 'bb-sums' },
							B.sum('Buchungen', String(t.anzahl)),
							B.sum('Einnahmen', B.eur(t.einnahmen), 'ok'),
							B.sum('Ausgaben', B.eur(t.ausgaben), 'bad'),
							B.sum('Soll-Bestand', B.eur(t.saldo))),
						h('div', { class: 'bb-form' }, B.lab('Kassensturz: Was liegt wirklich in der Kasse? (wird nicht gespeichert)', counted), diff)));
				}).catch(function (e) { B.fail(tag, e); });
			}

			function detail(r) {
				B.sheet('Kasseneintrag ' + r.nummer, function (body, close) {
					body.appendChild(h('dl', { class: 'bb-kv' },
						h('dt', null, 'Datum'), h('dd', null, day(r.datum)),
						h('dt', null, 'Text'), h('dd', null, r.beschreibung),
						h('dt', null, r.einnahme > 0 ? 'Einnahme' : 'Ausgabe'), h('dd', null, B.eur(r.einnahme > 0 ? r.einnahme : r.ausgabe)),
						h('dt', null, 'Kassenstand danach'), h('dd', null, B.eur(r.saldo)),
						h('dt', null, 'Status'), h('dd', null, r.storniert ? 'storniert' : (r.ist_storno ? 'Stornobuchung' : 'gültig'))));
					if (!r.storniert && !r.ist_storno && B.canWrite) {
						body.appendChild(stornoBlock(B, 'Eintrag stornieren', function (grund, ok) {
							return B.post('kasse/storno', { id: r.id, grund: grund, bestaetigt: ok }).then(function (res) {
								if (res.bestaetigen) { return res; }
								B.ui.toast(res.message);
								close();
								load();
								return res;
							});
						}));
					}
				});
			}

			load();
		}
	};

	/* ====================================================================
	 * Journal
	 * ================================================================== */

	UI.buchTabs['journal'] = {
		label: 'Journal',
		order: 40,
		render: function (host, ctx) {
			var B = make(ctx);
			var h = B.h;
			var f = { q: '', von: '', bis: '', typ: '', kategorie: '', status: '', seite: 1 };
			var box = h('div');
			host.textContent = '';
			var exportBtn = h('button', { class: 'btn bb-new', type: 'button' }, 'CSV-Export');
			exportBtn.addEventListener('click', function () {
				B.send(exportBtn, errBox, function () {
					return B.get('journal/export', { q: f.q, von: f.von, bis: f.bis, typ: f.typ, kategorie: f.kategorie, status: f.status });
				}).then(function (r) { if (r) { B.download(r.filename, r.mime, r.text); B.ui.toast(r.anzahl + ' Buchungen exportiert.'); } });
			});
			var errBox = h('div', { class: 'bb-err', role: 'alert' });
			host.appendChild(h('div', { class: 'bb-bar' }, h('h3', null, 'Journal'), exportBtn));
			host.appendChild(h('p', { class: 'muted', style: 'font-size:13px;margin:0 0 10px' }, 'Das Journal entsteht von selbst aus Rechnungen, Belegen und Gebühren. Buchungen werden nicht von Hand angelegt; Korrekturen laufen über das Storno an der Quelle (Rechnung, Beleg).'));
			host.appendChild(errBox);
			host.appendChild(box);

			function load() {
				B.loading(box);
				B.get('journal', f).then(paint).catch(function (e) { B.fail(box, e); });
			}

			function paint(d) {
				box.textContent = '';
				var q = h('input', { class: 'field', type: 'search', value: f.q, placeholder: 'Nummer, Text, Kategorie', 'aria-label': 'Suche' });
				var von = h('input', { class: 'field', type: 'date', value: f.von, 'aria-label': 'Von' });
				var bis = h('input', { class: 'field', type: 'date', value: f.bis, 'aria-label': 'Bis' });
				var kat = h('select', { class: 'field', 'aria-label': 'Kategorie' }, h('option', { value: '' }, 'Alle Kategorien'));
				d.kategorien.forEach(function (k) { kat.appendChild(h('option', { value: k }, k)); });
				kat.value = f.kategorie;
				function go() { f.q = q.value.trim(); f.von = von.value; f.bis = bis.value; f.kategorie = kat.value; f.seite = 1; load(); }
				q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
				box.appendChild(h('div', { class: 'bb-filter' }, q, von, bis, kat, h('button', { class: 'btn primary', type: 'button', onclick: go }, 'Filtern')));
				box.appendChild(B.chips([['', 'Alle Arten'], ['einnahme', 'Einnahmen'], ['ausgabe', 'Ausgaben'], ['storno', 'Stornos']], f.typ, function (k) { f.typ = k; f.seite = 1; load(); }));
				box.appendChild(B.chips([['', 'Alle Stände'], ['aktiv', 'Nur gültige'], ['storniert', 'Nur stornierte']], f.status, function (k) { f.status = k; f.seite = 1; load(); }));
				var s = d.summe;
				box.appendChild(h('div', { class: 'bb-sums' },
					B.sum('Einnahmen', B.eur(s.einnahmen), 'ok'),
					B.sum('Ausgaben', B.eur(s.ausgaben), 'bad'),
					B.sum('Saldo', B.eur(s.saldo), s.saldo < 0 ? 'bad' : 'ok'),
					B.sum('Storniert / Gegenbuchungen', B.eur(s.storniert) + ' / ' + B.eur(s.gegenbuchungen), 'warn'),
					B.sum('Buchungen', String(s.anzahl))));
				box.appendChild(h('p', { class: 'muted', style: 'font-size:12px;margin:0 0 10px' }, 'Summen gelten für alle Treffer des Filters, nicht nur für diese Seite. Stornierte Buchungen und ihre Gegenbuchungen zählen nicht in Einnahmen und Ausgaben.'));
				if (!d.rows.length) { box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Keine Buchungen für diesen Filter.'))); return; }
				var ul = h('ul', { class: 'bb-list' });
				d.rows.forEach(function (r) {
					ul.appendChild(B.item(r.beschreibung || r.kategorie || r.nummer, r.nummer + ' · ' + day(r.datum) + ' · ' + r.typ + (r.kategorie ? ' · ' + r.kategorie : '') + (r.storniert ? ' · storniert' : ''),
						h('span', { class: r.typ === 'einnahme' ? 'bb-in' : (r.typ === 'ausgabe' ? 'bb-out' : '') }, B.eur(r.betrag)), '', r.storniert, function () { detail(r); }));
				});
				box.appendChild(ul);
				var pg = B.pager(d, function (p) { f.seite = p; load(); });
				if (pg) { box.appendChild(pg); }
			}

			function detail(r) {
				B.sheet('Buchung ' + r.nummer, function (body) {
					body.appendChild(h('dl', { class: 'bb-kv' },
						h('dt', null, 'Datum'), h('dd', null, day(r.datum)),
						h('dt', null, 'Art'), h('dd', null, r.typ),
						h('dt', null, 'Betrag'), h('dd', null, B.eur(r.betrag)),
						h('dt', null, 'Kategorie'), h('dd', null, r.kategorie || '–'),
						h('dt', null, 'Text'), h('dd', null, r.beschreibung || '–'),
						h('dt', null, 'Status'), h('dd', null, r.storniert ? 'storniert' : 'gültig')));
				});
			}

			load();
		}
	};

	/* ====================================================================
	 * Dauerbuchungen
	 * ================================================================== */

	UI.buchTabs['dauer'] = {
		label: 'Dauerbuchungen',
		order: 50,
		render: function (host, ctx) {
			var B = make(ctx);
			var h = B.h;
			var box = h('div');
			var data = null;
			host.textContent = '';
			host.appendChild(B.head('Dauerbuchungen', function () { form(null); }, 'Neu'));
			host.appendChild(box);

			function load() {
				B.loading(box);
				B.get('dauer').then(function (d) { data = d; paint(d); }).catch(function (e) { B.fail(box, e); });
			}

			function preview(r) {
				var v = r.vorschau;
				if (!v) { return null; }
				return h('div', { class: 'bb-preview' },
					h('b', null, 'Das würde gebucht'),
					h('span', null, 'Beleg über ' + B.eur(v.betrag) + ' („' + r.bezeichnung + '“, ' + r.kategorie + ') mit Datum ' + day(v.beleg_datum) + ', sofort festgeschrieben und im Journal gebucht.'),
					h('span', null, 'Danach ist die nächste Buchung am ' + day(v.naechste) + (v.endet ? ' – das liegt nach dem Ende, die Dauerbuchung wird dann abgeschaltet.' : '.')),
					v.rueckstand > 0 ? h('span', { class: 'bb-err' }, 'Es sind noch ' + v.rueckstand + ' weitere Termine offen. Pro Lauf wird nur einer gebucht, der Rest folgt im nächsten Lauf.') : null);
			}

			function paint(d) {
				box.textContent = '';
				if (d.faellig > 0) {
					var runAll = h('button', { class: 'btn primary', type: 'button', onclick: function () { runSheet(); } }, 'Fällige prüfen und ausführen');
					box.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:12px' },
						h('p', { style: 'margin:0 0 10px' }, d.faellig + ' Dauerbuchung(en) fällig.'), B.canWrite ? runAll : null));
				}
				if (!d.rows.length) { box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Noch keine Dauerbuchungen. Mit „Neu“ legst du Miete, Versicherung oder Abos an.'))); }
				else {
					var ul = h('ul', { class: 'bb-list' });
					d.rows.forEach(function (r) {
						ul.appendChild(B.item(r.bezeichnung, r.intervall_text + (r.aktiv ? ' · nächste ' + day(r.naechste) : ' · deaktiviert') + (r.faellig ? ' · FÄLLIG' : ''),
							B.eur(r.betrag), r.kategorie, !r.aktiv, function () { detail(r); }));
					});
					box.appendChild(ul);
				}
				box.appendChild(h('h3', { style: 'margin:22px 0 8px;font-size:17px' }, 'Letzte Läufe und Änderungen'));
				if (!d.protokoll.length) { box.appendChild(h('p', { class: 'muted' }, 'Noch nichts protokolliert.')); }
				else {
					box.appendChild(h('div', { class: 'bb-log' }, d.protokoll.map(function (l) {
						return h('div', null, h('b', null, l.aktion), ' · ' + l.zeit + ' · ' + l.person + (l.details ? ' · ' + l.details : ''));
					})));
				}
			}

			function runSheet() {
				var due = data.rows.filter(function (r) { return r.faellig; });
				B.sheet('Fällige Dauerbuchungen', function (body, close) {
					body.appendChild(h('p', { class: 'muted', style: 'margin:0' }, 'Bitte prüfen: Das wird jetzt gebucht und lässt sich nur per Storno rückgängig machen.'));
					due.forEach(function (r) { body.appendChild(preview(r)); });
					var err = h('div', { class: 'bb-err', role: 'alert' });
					var go = h('button', { class: 'btn primary', type: 'button', style: 'min-height:56px;font-size:16px' }, due.length + ' Buchung(en) jetzt ausführen');
					go.addEventListener('click', function () {
						B.send(go, err, function () { return B.post('dauer/ausfuehren', {}); }).then(function (r) {
							if (r) { B.ui.toast(r.message); close(); load(); }
						});
					});
					body.appendChild(err);
					body.appendChild(go);
				}, true);
			}

			function detail(r) {
				B.sheet(r.bezeichnung, function (body, close) {
					body.appendChild(h('dl', { class: 'bb-kv' },
						h('dt', null, 'Betrag'), h('dd', null, B.eur(r.betrag)),
						h('dt', null, 'Kostenart'), h('dd', null, r.kategorie),
						h('dt', null, 'Rhythmus'), h('dd', null, r.intervall_text),
						h('dt', null, 'Start'), h('dd', null, day(r.start)),
						h('dt', null, 'Ende'), h('dd', null, r.ende ? day(r.ende) : 'unbefristet'),
						h('dt', null, 'Nächste'), h('dd', null, r.aktiv ? day(r.naechste) : '–'),
						h('dt', null, 'Zuletzt'), h('dd', null, r.letzte ? day(r.letzte) : 'noch nie'),
						h('dt', null, 'Status'), h('dd', null, r.aktiv ? 'aktiv' : 'deaktiviert')));
					if (!B.canWrite || !r.aktiv) { return; }
					var pv = preview(r);
					if (pv) {
						body.appendChild(pv);
						var err = h('div', { class: 'bb-err', role: 'alert' });
						var run = h('button', { class: 'btn primary', type: 'button', style: 'min-height:56px;font-size:16px' }, 'Jetzt buchen');
						run.addEventListener('click', function () {
							B.send(run, err, function () { return B.post('dauer/ausfuehren', { id: r.id }); }).then(function (res) {
								if (res) { B.ui.toast(res.message); close(); load(); }
							});
						});
						body.appendChild(err);
						body.appendChild(run);
					}
					var act = h('div', { class: 'bb-actions' },
						h('button', { class: 'btn', type: 'button', onclick: function () { close(); form(r); } }, 'Ändern'));
					body.appendChild(act);
					body.appendChild(stornoLike(r, close));
				});
			}

			// Deaktivieren mit Rückfrage (kein Löschen).
			function stornoLike(r, close) {
				var wrap = h('div');
				var start = h('button', { class: 'btn', type: 'button', onclick: ask }, 'Deaktivieren');
				function ask() {
					wrap.textContent = '';
					var err = h('div', { class: 'bb-err', role: 'alert' });
					var yes = h('button', { class: 'btn primary', type: 'button' }, 'Ja, deaktivieren');
					yes.addEventListener('click', function () {
						B.send(yes, err, function () { return B.post('dauer/deaktivieren', { id: r.id }); }).then(function (res) {
							if (res) { B.ui.toast(res.message); close(); load(); }
						});
					});
					wrap.appendChild(h('div', { class: 'bb-form' },
						h('p', { style: 'margin:0' }, '„' + r.bezeichnung + '“ nicht mehr buchen? Schon Gebuchtes bleibt stehen, die Dauerbuchung wird nur abgeschaltet.'),
						err, h('div', { class: 'bb-actions' }, yes, h('button', { class: 'btn', type: 'button', onclick: function () { wrap.textContent = ''; wrap.appendChild(start); } }, 'Abbrechen'))));
				}
				wrap.appendChild(start);
				return wrap;
			}

			function form(r) {
				if (!data) { return; }
				B.sheet(r ? 'Dauerbuchung ändern' : 'Neue Dauerbuchung', function (body, close) {
					var name = h('input', { class: 'field', type: 'text', maxlength: '200', placeholder: 'z. B. Büromiete, Hosting, Versicherung', value: r ? r.bezeichnung : '', 'aria-label': 'Bezeichnung' });
					var betrag = h('input', { class: 'field bb-amount', type: 'text', inputmode: 'decimal', placeholder: '0,00', value: r ? String(r.betrag).replace('.', ',') : '', 'aria-label': 'Betrag in Euro' });
					var kat = h('select', { class: 'field', 'aria-label': 'Kostenart' });
					var cats = data.kategorien.slice();
					if (r && cats.indexOf(r.kategorie) < 0) { cats.push(r.kategorie); }
					cats.forEach(function (k) { kat.appendChild(h('option', { value: k }, k)); });
					if (r) { kat.value = r.kategorie; }
					var intv = h('select', { class: 'field', 'aria-label': 'Rhythmus' });
					Object.keys(data.intervalle).forEach(function (k) { intv.appendChild(h('option', { value: k }, data.intervalle[k])); });
					intv.value = r ? r.intervall : 'monatlich';
					var start = h('input', { class: 'field', type: 'date', value: r ? r.start : today(), 'aria-label': 'Start' });
					if (r) { start.disabled = true; }
					var ende = h('input', { class: 'field', type: 'date', value: r ? r.ende : '', 'aria-label': 'Ende' });
					var err = h('div', { class: 'bb-err', role: 'alert' });
					var go = h('button', { class: 'btn primary', type: 'button', style: 'min-height:56px;font-size:16px' }, r ? 'Änderung speichern' : 'Dauerbuchung anlegen');
					go.addEventListener('click', function () {
						B.send(go, err, function () {
							return B.post('dauer/speichern', { id: r ? r.id : 0, bezeichnung: name.value, betrag: betrag.value, kategorie: kat.value, intervall: intv.value, start_datum: start.value, ende_datum: ende.value });
						}).then(function (res) { if (res) { B.ui.toast(res.message); close(); load(); } });
					});
					body.appendChild(h('div', { class: 'bb-form' },
						B.lab('Bezeichnung', name),
						B.lab('Betrag (€)', betrag),
						B.lab('Kostenart', kat),
						B.lab('Wie oft?', intv),
						h('div', { class: 'bb-two' }, B.lab(r ? 'Start (fest)' : 'Erste Buchung', start), B.lab('Ende (leer = unbefristet)', ende)),
						r ? h('p', { class: 'muted', style: 'font-size:13px;margin:0' }, 'Eine Änderung gilt nur für künftige Buchungen. Schon gebuchte Belege bleiben unverändert; die Änderung steht im Protokoll.') : null,
						err, go));
					name.focus();
				});
			}

			load();
		}
	};
})();
