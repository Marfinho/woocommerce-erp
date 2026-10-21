/*
 * Lager im ERP: Seiten „lager“ (Bestand, Buchen, Journal), „lagerplaetze“
 * (Plätze, Etiketten, Fertigungsaufträge) und „bedarf“ (was fehlt).
 * Schnittstelle: includes/Erp/Pages/Lager.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	function mk(ui) {
		var h = ui.h;

		function opt(value, label, selected) {
			return h('option', { value: String(value), selected: selected ? true : null }, label);
		}

		function select(pairs, value, label) {
			return h('select', { class: 'field', 'aria-label': label },
				pairs.map(function (p) { return opt(p[0], p[1], String(p[0]) === String(value)); }));
		}

		function row(label, control, hint) {
			return h('label', { class: 'lg-f' },
				h('span', { class: 'lg-l' }, label),
				control,
				hint ? h('small', { class: 'muted' }, hint) : null);
		}

		function textIn(value, label, extra) {
			var a = { class: 'field', type: 'text', value: value || '', 'aria-label': label, autocomplete: 'off' };
			Object.keys(extra || {}).forEach(function (k) { a[k] = extra[k]; });
			return h('input', a);
		}

		function num(n) { return ui.qty(Number(n) || 0); }

		function pills(items, current, onPick) {
			return h('div', { class: 'pills', role: 'tablist' }, items.map(function (t) {
				return h('button', { class: 'pill' + (t[0] === current ? ' on' : ''), type: 'button', role: 'tab', 'aria-selected': t[0] === current ? 'true' : 'false', onclick: function () { onPick(t[0]); } }, t[1]);
			}));
		}

		function failBox(box, e) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'notice warn' }, e.message));
		}

		function uid() {
			return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2);
		}

		// Formular im Dialog absenden: Knopf sperren, Fehler im Dialog zeigen.
		function submitter(d, errBox, btn, send, done) {
			return function (e) {
				e.preventDefault();
				errBox.textContent = '';
				btn.disabled = true;
				send().then(function (r) {
					if (r && r.queued) {
						ui.toast('Ohne Verbindung vorgemerkt – wird gesendet, sobald das Netz wieder da ist.');
					} else {
						ui.toast((r && r.message) || 'Gespeichert');
						((r && r.warnings) || []).forEach(function (w) { ui.toast(w, true); });
					}
					d.close();
					done();
				}).catch(function (err) {
					btn.disabled = false;
					errBox.appendChild(h('div', { class: 'notice warn', role: 'alert' }, err.message));
				});
			};
		}

		/* ---------------- Seite Lager ---------------- */

		function articleSelect(articles, value) {
			var search = h('input', { class: 'field', type: 'search', placeholder: 'Artikel suchen …', 'aria-label': 'Artikel suchen', autocomplete: 'off' });
			var sel = h('select', { class: 'field', 'aria-label': 'Artikel' });

			function fill() {
				var q = search.value.trim().toLowerCase();
				var keep = sel.value || String(value || '');
				sel.textContent = '';
				articles.filter(function (a) { return !q || (a.sku + ' ' + a.name).toLowerCase().indexOf(q) > -1 || String(a.id) === keep; }).forEach(function (a) {
					sel.appendChild(opt(a.id, a.sku + ' – ' + a.name + (a.active ? '' : ' (nicht mehr im Angebot)'), String(a.id) === keep));
				});
			}
			search.addEventListener('input', fill);
			fill();
			return { box: h('div', { class: 'lg-art' }, search, sel), sel: sel };
		}

		function bookDialog(d, pre, done) {
			pre = pre || {};
			var kindPairs = Object.keys(d.kinds).filter(function (k) { return k !== 'kommission' || d.dealers.length; }).map(function (k) { return [k, d.kinds[k]]; });
			var kind = select(kindPairs, pre.kind || 'zugang', 'Art der Buchung');
			var art = articleSelect(d.articles, pre.article_id);
			var color = select(d.colors.map(function (c) { return [c.slug, c.label]; }), pre.color || (d.colors[0] && d.colors[0].slug), 'Farbe');
			var reasonBox = h('div');
			var binOpts = [['0', '– ohne Platz –']].concat(d.bins.filter(function (b) { return b.active; }).map(function (b) { return [b.id, b.code + (b.name ? ' · ' + b.name : '')]; }));
			var allBins = [['0', '– bitte wählen –']].concat(d.bins.map(function (b) { return [b.id, b.code + (b.name ? ' · ' + b.name : '') + (b.active ? '' : ' (gesperrt)')]; }));
			var bin = select(binOpts, '0', 'Lagerplatz');
			var from = select(allBins, '0', 'Von Platz');
			var to = select(allBins.filter(function (p) { var b = d.bins.filter(function (x) { return String(x.id) === String(p[0]); })[0]; return !b || b.active; }), '0', 'Nach Platz');
			var qty = textIn('', 'Menge', { inputmode: 'decimal', placeholder: 'z. B. 5', required: '' });
			var note = textIn('', 'Begründung oder Notiz');
			var dealer = select(d.dealers.map(function (x) { return [x.id, x.name]; }), '', 'Händler');
			var ctype = select(Object.keys(d.commission_types).map(function (k) { return [k, d.commission_types[k]]; }), 'kommission_raus', 'Vorgang');
			var reason = h('select', { class: 'field', 'aria-label': 'Grund' });
			var rBin = row('Lagerplatz', bin, 'Leer lassen, wenn der Platz nicht bekannt ist.');
			var rFrom = row('Von Platz', from);
			var rTo = row('Nach Platz', to);
			var rReason = row('Grund', reason);
			var rDealer = row('Händler', dealer);
			var rType = row('Vorgang', ctype);
			var rNote = row('Begründung', note);
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, 'Buchen');
			var hint = h('p', { class: 'muted lg-hint' });

			function sync() {
				var k = kind.value;
				var reasons = d.reasons[k] || {};
				reason.textContent = '';
				Object.keys(reasons).forEach(function (r) { reason.appendChild(opt(r, reasons[r])); });
				rReason.hidden = Object.keys(reasons).length < 2;
				rBin.hidden = k === 'umlagerung' || k === 'kommission';
				rFrom.hidden = rTo.hidden = k !== 'umlagerung';
				rDealer.hidden = rType.hidden = k !== 'kommission';
				rNote.hidden = k === 'kommission';
				note.setAttribute('placeholder', k === 'korrektur' ? 'Pflicht, z. B. „Inventur“' : 'optional');
				if (k === 'korrektur') { note.setAttribute('required', ''); } else { note.removeAttribute('required'); }
				hint.textContent = {
					zugang: 'Du tippst nur die Stückzahl; sie kommt zum Bestand dazu.',
					abgang: 'Du tippst nur die Stückzahl; sie geht vom Bestand ab.',
					korrektur: 'Plus oder Minus, wie die Inventur es ergibt – mit Begründung, damit es nachvollziehbar bleibt.',
					umlagerung: 'Die Ware wechselt den Platz; der Gesamtbestand bleibt gleich. Beide Buchungen stehen einzeln im Journal.',
					kommission: 'Ausliefern oder zurücknehmen verschiebt nur zwischen Lager und Händler; Abrechnen verkauft die Ware.'
				}[k] || '';
			}
			kind.addEventListener('change', sync);
			sync();

			var form = h('form', { class: 'lg-form' },
				row('Was?', kind), row('Artikel', art.box), row('Farbe', color), rReason, rDealer, rType, rBin, rFrom, rTo,
				row('Menge', qty), rNote, hint, err, h('div', { class: 'lg-actions' }, btn));

			var dialog = ui.dlg('Bestand buchen', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, btn, function () {
				var k = kind.value;
				var ref = uid();
				if (k === 'kommission') {
					return ui.api('lager/kommission', { method: 'POST', queue: true, body: { client_ref: ref, contact_id: parseInt(dealer.value, 10) || 0, movement_type: ctype.value, article_id: parseInt(art.sel.value, 10) || 0, color: color.value, qty: qty.value } });
				}
				return ui.api('lager/buchen', { method: 'POST', queue: true, body: { client_ref: ref, kind: k, reason: reason.value, article_id: parseInt(art.sel.value, 10) || 0, color: color.value, qty: qty.value, note: note.value, bin_id: parseInt(k === 'umlagerung' ? from.value : bin.value, 10) || 0, to_bin_id: parseInt(to.value, 10) || 0 } });
			}, done));
			qty.focus();
		}

		function targetDialog(d, r, done) {
			var qty = textIn(r.soll === null ? '' : String(r.soll).replace('.', ','), 'Sollmenge', { inputmode: 'decimal', placeholder: 'z. B. 20' });
			var err = h('div', { 'aria-live': 'polite' });
			var save = h('button', { class: 'btn primary', type: 'submit' }, 'Soll speichern');
			var remove = h('button', { class: 'btn', type: 'button' }, 'Soll entfernen');
			var form = h('form', { class: 'lg-form' },
				h('p', null, h('b', null, r.sku), ' ', r.name, ' · ', r.color_label),
				row('Sollbestand', qty, 'Darunter meldet die Bedarfsplanung Bedarf. „Soll entfernen“ ist etwas anderes als Soll 0: Es meldet dann gar nicht mehr.'),
				err, h('div', { class: 'lg-actions' }, save, r.soll === null ? null : remove));
			var dialog = ui.dlg('Sollbestand', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, save, function () {
				return ui.api('lager/soll', { method: 'POST', body: { article_id: r.article_id, color: r.color, target_qty: qty.value } });
			}, done));
			remove.addEventListener('click', submitter(dialog, err, remove, function () {
				return ui.api('lager/soll', { method: 'POST', body: { article_id: r.article_id, color: r.color, remove: true } });
			}, done));
		}

		function partDialog(p, done) {
			var qty = textIn('', 'Menge', { inputmode: 'decimal', placeholder: 'z. B. 50 oder -10', required: '' });
			var note = textIn('', 'Begründung', { placeholder: 'Pflicht, z. B. „Inventur“', required: '' });
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, 'Korrigieren');
			var form = h('form', { class: 'lg-form' },
				h('p', null, h('b', null, p.title), h('br'), h('span', { class: 'muted' }, 'Jetzt: ' + num(p.qty))),
				row('Menge ±', qty, 'Plus bringt Bestand dazu, Minus nimmt weg.'), row('Begründung', note),
				h('p', { class: 'muted lg-hint' }, 'Dieser Bestand ist ein Feld, kein Bewegungskonto: Die Korrektur steht mit Begründung, Stand vorher und nachher im Änderungsprotokoll.'),
				err, h('div', { class: 'lg-actions' }, btn));
			var dialog = ui.dlg('Bestand korrigieren', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, btn, function () {
				return ui.api('lager/teil', { method: 'POST', queue: true, body: { client_ref: uid(), part_type: p.type, part_id: p.id, qty: qty.value, note: note.value } });
			}, done));
			qty.focus();
		}

		function voidDialog(m, done) {
			var reason = textIn('', 'Grund', { placeholder: 'Pflicht, z. B. „falsch gezählt“', required: '' });
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, 'Storno buchen');
			var form = h('form', { class: 'lg-form' },
				h('p', null, h('b', null, m.sku), ' ', m.color_label, ': ', (m.qty > 0 ? '+' : '') + num(m.qty), ' (', m.type, ')'),
				row('Grund', reason, 'Die Buchung bleibt stehen; eine Gegenbuchung hebt sie auf.'),
				err, h('div', { class: 'lg-actions' }, btn));
			var dialog = ui.dlg('Buchung stornieren', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, btn, function () {
				return ui.api('lager/storno', { method: 'POST', body: { movement_id: m.id, reason: reason.value } });
			}, done));
			reason.focus();
		}

		function stockList(d, state, reload) {
			var box = h('div');
			var search = h('input', { class: 'field', type: 'search', placeholder: 'Artikel oder Farbe suchen …', 'aria-label': 'Suchen', autocomplete: 'off', value: state.q });
			var chipDefs = [['alle', 'Alle'], ['unter_soll', 'Unter Soll'], ['ohne_platz', 'Ohne Lagerplatz'], ['leer', 'Leer']];
			var chipBox = h('div', { class: 'presets' });
			var list = h('div', { class: 'rows' });

			function paint() {
				chipBox.textContent = '';
				chipDefs.forEach(function (c) {
					var n = c[0] === 'alle' ? d.rows.length : d.rows.filter(function (r) { return r.flags[c[0]]; }).length;
					chipBox.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': state.f === c[0] ? 'true' : 'false', onclick: function () { state.f = c[0]; paint(); } }, c[1] + ' (' + n + ')'));
				});
				var q = state.q.trim().toLowerCase();
				var rows = d.rows.filter(function (r) {
					return (state.f === 'alle' || r.flags[state.f]) && (!q || (r.sku + ' ' + r.name + ' ' + r.color_label).toLowerCase().indexOf(q) > -1);
				});
				list.textContent = '';
				if (!rows.length) {
					list.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Nichts gefunden. Suche oder Filter lockern.' : 'Noch keine Bewegungen gebucht.'));
				}
				rows.forEach(function (r) { list.appendChild(stockRow(r)); });
			}

			function stockRow(r) {
				var tags = h('div', { class: 'row-tags' });
				if (r.flags.unter_soll) { tags.appendChild(h('span', { class: 'tag later' }, 'unter Soll (' + num(r.diff) + ')')); }
				if (r.flags.ohne_platz) { tags.appendChild(h('span', { class: 'tag later' }, num(r.unplaced) + ' ohne Platz')); }
				if (r.flags.leer) { tags.appendChild(h('span', { class: 'tag' }, 'leer')); }
				if (r.soll !== null && !r.flags.unter_soll) { tags.appendChild(h('span', { class: 'tag now' }, 'Soll ' + num(r.soll) + ' erreicht')); }

				var detail = [];
				if (r.kommission) { detail.push('beim Händler ' + num(r.kommission)); }
				if (r.unterwegs) { detail.push('unterwegs ' + num(r.unterwegs)); }
				detail.push(r.reach === null ? 'kein Verbrauch' : 'reicht ' + String(r.reach).replace('.', ',') + ' Wochen');
				if (r.bins.length) { detail.push('Plätze: ' + r.bins.map(function (b) { return b.code + ' (' + num(b.qty) + ')'; }).join(', ')); }

				var actions = h('div', { class: 'lg-btns' });
				if (d.can_book) {
					actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { bookDialog(d, { article_id: r.article_id, color: r.color }, reload); } }, 'Buchen'));
				}
				if (d.can_target) {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { targetDialog(d, r, reload); } }, 'Soll'));
				}

				return h('article', { class: 'row-card lg-row' },
					h('div', { class: 'row-main' }, h('b', null, r.sku), h('small', null, r.name + ' · ' + r.color_label), h('small', null, detail.join(' · ')), tags),
					h('div', { class: 'lg-num' }, h('span', { class: 'row-num' }, num(r.lager)), h('small', { class: 'muted' }, 'im Lager'), r.gesamt !== r.lager ? h('small', { class: 'muted' }, 'gesamt ' + num(r.gesamt)) : null),
					actions);
			}

			search.addEventListener('input', function () { state.q = search.value; paint(); });
			box.appendChild(h('div', { class: 'lg-bar' }, search));
			box.appendChild(chipBox);
			box.appendChild(list);
			paint();
			return box;
		}

		function partsList(d, reload) {
			var list = h('div', { class: 'rows' });
			if (!d.parts || !d.parts.length) { list.appendChild(h('div', { class: 'card muted' }, 'Noch nichts angelegt. Kleinteile und Kartons pflegst du unter „Material“.')); }
			(d.parts || []).forEach(function (p) {
				list.appendChild(h('article', { class: 'row-card lg-row' },
					h('div', { class: 'row-main' }, h('b', null, p.title), h('small', null, (p.type === 'supply' ? 'Karton / Verbrauchsmaterial · ' : 'Kleinteil · ') + p.sub)),
					h('div', { class: 'lg-num' }, h('span', { class: 'row-num' }, num(p.qty)), h('small', { class: 'muted' }, 'Bestand')),
					h('div', { class: 'lg-btns' }, d.can_book ? h('button', { class: 'btn', type: 'button', onclick: function () { partDialog(p, reload); } }, 'Korrigieren') : null)));
			});
			return h('div', null, h('p', { class: 'muted' }, 'Schrauben, Dübel, Kartons und Klebeband. Wareneingang und Verbrauch schreiben den Bestand fort; Korrekturen hier landen mit Begründung im Änderungsprotokoll.'), list);
		}

		function journal(d, reload) {
			var box = h('div');
			var limit = 60;

			function load() {
				box.textContent = '';
				box.appendChild(h('div', { class: 'card muted' }, 'Wird geladen …'));
				ui.api('lager/journal?limit=' + limit).then(function (j) {
					box.textContent = '';
					var list = h('div', { class: 'rows' });
					if (!j.rows.length) { list.appendChild(h('div', { class: 'card muted' }, 'Noch keine Bewegungen.')); }
					j.rows.forEach(function (m) {
						var tags = h('div', { class: 'row-tags' }, h('span', { class: 'tag' }, m.type), h('span', { class: 'tag' }, m.place));
						if (m.state === 'storniert') { tags.appendChild(h('span', { class: 'tag later' }, 'storniert')); }
						if (m.state === 'gegenbuchung') { tags.appendChild(h('span', { class: 'tag' }, 'Gegenbuchung')); }
						list.appendChild(h('article', { class: 'row-card lg-row' + (m.state === 'offen' ? '' : ' lg-dim') },
							h('div', { class: 'row-main' },
								h('b', null, m.sku + ' · ' + m.color_label),
								h('small', null, ui.when(m.ts) + (m.bin ? ' · Platz ' + m.bin : '') + (m.origin ? ' · ' + m.origin : '')),
								m.note ? h('small', null, m.note) : null, tags),
							h('div', { class: 'lg-num' }, h('span', { class: 'row-num' }, (m.qty > 0 ? '+' : '') + num(m.qty))),
							h('div', { class: 'lg-btns' }, m.can_void ? h('button', { class: 'btn', type: 'button', onclick: function () { voidDialog(m, load); } }, 'Stornieren') : null)));
					});
					box.appendChild(list);
					if (j.rows.length >= limit && limit < 200) {
						box.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: function () { limit = Math.min(200, limit + 60); load(); } }, 'Ältere zeigen')));
					}
				}).catch(function (e) { failBox(box, e); });
			}
			load();
			return box;
		}

		// Kommission vom Lager aus: erst den Händler wählen, dann die Liste (dieselbe wie in der Kundenakte).
		function commissionPicker(done) {
			var body = h('div', { class: 'lg-dlg' }, h('p', { class: 'muted' }, 'Wird geladen …'));
			var dlg = ui.dlg('Kommission', body);
			ui.api('kontakte').then(function (d) {
				var sel = h('select', { class: 'field', 'aria-label': 'Händler' }, d.contacts.map(function (c) { return h('option', { value: c.id }, c.company + (c.city ? ' · ' + c.city : '')); }));
				body.textContent = '';
				if (!d.contacts.length) { body.appendChild(h('p', { class: 'muted' }, 'Es gibt noch keinen Kontakt. Lege ihn unter Verkauf → Kunden an.')); return; }
				body.appendChild(h('label', { class: 'lg-field' }, h('span', null, 'Händler'), sel));
				var row = h('div', { class: 'lg-btns', style: 'margin-top:12px;flex-wrap:wrap' });
				[['kommission_raus', 'Ausliefern', true], ['kommission_abgerechnet', 'Abrechnen', false], ['kommission_retour', 'Zurücknehmen', false]].forEach(function (a) {
					row.appendChild(h('button', { class: 'btn' + (a[2] ? ' primary' : ''), type: 'button', onclick: function () { dlg.close(); UI.commissionDialog(parseInt(sel.value, 10), a[0], a[1], done); } }, a[1]));
				});
				body.appendChild(row);
			}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function pageLager(item) {
			var state = { tab: 'bestand', f: 'alle', q: '' };
			var head = h('div');
			var body = h('div');
			var shown = null;

			ui.pageHead('Lager', 'Der Bestand ist die Summe aller Buchungen – Korrekturen sind eigene Buchungen mit Begründung.', ui.classicBtn(item));
			ui.view().classList.add('lg');
			ui.view().appendChild(head);
			ui.view().appendChild(body);
			body.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));

			function paint() {
				var d = shown;
				head.textContent = '';
				var tiles = h('div', { class: 'grid tiles' },
					tile('Positionen', d.rows.length),
					tile('Unter Soll', d.rows.filter(function (r) { return r.flags.unter_soll; }).length),
					tile('Ohne Lagerplatz', d.rows.filter(function (r) { return r.flags.ohne_platz; }).length));
				head.appendChild(tiles);
				if (d.unresolved) {
					head.appendChild(h('div', { class: 'notice warn lg-gap' }, d.unresolved + ' Buchung(en) ohne eindeutige Farbe: gebucht, aber keiner Farbe zugeordnet. Farbattribut am Produkt prüfen und per Korrektur umbuchen.'));
				}
				var bar = h('div', { class: 'lg-top' },
					pills([['bestand', 'Bestand'], ['teile', 'Kleinteile & Kartons'], ['journal', 'Journal']], state.tab, function (t) { state.tab = t; paint(); }),
					h('div', { class: 'lg-btns' },
						d.can_book && ui.can('verkauf.kontakte') && UI.commissionDialog ? h('button', { class: 'btn', type: 'button', onclick: function () { commissionPicker(load); } }, 'Kommission') : null,
						d.can_book ? h('button', { class: 'btn primary', type: 'button', onclick: function () { bookDialog(d, {}, load); } }, 'Neue Buchung') : null));
				head.appendChild(bar);
				body.textContent = '';
				if (state.tab === 'bestand') { body.appendChild(stockList(d, state, load)); }
				else if (state.tab === 'teile') { body.appendChild(partsList(d, load)); }
				else { body.appendChild(journal(d, load)); }
				if (!d.can_book) { body.appendChild(h('p', { class: 'muted lg-gap' }, h('span', { class: 'tag' }, 'Nur ansehen'), ' Bestand buchen darfst du nicht.')); }
			}

			function tile(label, value) {
				return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, String(value)));
			}

			function load() {
				return ui.api('lager').then(function (d) { shown = d; paint(); }).catch(function (e) { failBox(body, e); });
			}
			load();
			ui.every(function () { if (!document.querySelector('dialog[open]') && state.tab === 'bestand') { ui.api('lager').then(function (d) { shown = d; paint(); }).catch(function () {}); } }, 60000);
		}

		/* ---------------- Seite Lagerplätze ---------------- */

		function binDialog(d, bin, done) {
			var code = textIn(bin ? bin.code : '', 'Code', { required: '', placeholder: 'z. B. A01, KISTE3', autocapitalize: 'characters' });
			var name = textIn(bin ? bin.name : '', 'Bezeichnung', { placeholder: 'z. B. Regal links, oben' });
			var kind = select(d.kinds.map(function (k) { return [k, k.charAt(0).toUpperCase() + k.slice(1)]; }), bin ? bin.kind : 'regal', 'Art');
			var sort = textIn(bin ? String(bin.sort_order) : '0', 'Sortierung', { inputmode: 'numeric' });
			var note = textIn(bin ? bin.note : '', 'Notiz');
			var active = h('input', { type: 'checkbox', checked: bin ? bin.active : true, 'aria-label': 'aktiv' });
			var reason = textIn('', 'Begründung der Änderung', { placeholder: 'optional, steht im Protokoll' });
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');
			var form = h('form', { class: 'lg-form' },
				row('Code', code, 'Steht auf dem Etikett und wird gescannt. Kurz halten.'),
				row('Bezeichnung', name), row('Art', kind), row('Sortierung', sort), row('Notiz', note),
				h('label', { class: 'lg-chk' }, active, h('span', null, 'aktiv (ein gesperrter Platz nimmt nichts mehr auf)')),
				bin ? row('Begründung', reason) : null, err, h('div', { class: 'lg-actions' }, btn));
			var dialog = ui.dlg(bin ? 'Lagerplatz bearbeiten' : 'Lagerplatz anlegen', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, btn, function () {
				return ui.api('lagerplaetze/speichern', { method: 'POST', body: { id: bin ? bin.id : 0, code: code.value, name: name.value, kind: kind.value, sort_order: parseInt(sort.value, 10) || 0, note: note.value, active: active.checked, reason: reason.value } });
			}, done));
			code.focus();
		}

		function plaetzeTab(d, reload) {
			var box = h('div');
			var actions = h('div', { class: 'lg-btns lg-gap0' });
			if (d.can_edit) { actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { binDialog(d, null, reload); } }, 'Lagerplatz anlegen')); }
			if (d.sheet_url) { actions.appendChild(h('a', { class: 'btn', href: d.sheet_url, target: '_blank', rel: 'noopener' }, 'Alle Etiketten als PDF')); }
			box.appendChild(actions);
			if (!d.bins.length) {
				box.appendChild(h('div', { class: 'card lg-gap' }, 'Noch kein Lagerplatz angelegt. Ohne Plätze ist der Bestand da, aber niemand weiß wo.'));
			}
			var list = h('div', { class: 'rows lg-gap' });
			d.bins.forEach(function (b) {
				var content = b.content.length
					? h('ul', { class: 'lg-content' }, b.content.map(function (c) { return h('li', null, h('b', null, c.sku), ' ' + c.color_label + ': ' + num(c.qty)); }))
					: h('p', { class: 'muted' }, 'Dieser Platz ist leer.');
				list.appendChild(h('article', { class: 'row-card lg-row' + (b.active ? '' : ' lg-dim') },
					h('div', { class: 'row-main' }, h('b', null, b.code + (b.name ? ' · ' + b.name : '')),
						h('div', { class: 'row-tags' }, h('span', { class: 'tag' }, b.kind), h('span', { class: 'tag ' + (b.active ? 'now' : 'later') }, b.active ? 'aktiv' : 'gesperrt'), h('span', { class: 'tag' }, num(b.pieces) + ' Stück')),
						h('details', { class: 'lg-det' }, h('summary', null, 'Was liegt dort?'), content)),
					h('div', { class: 'lg-num' }),
					h('div', { class: 'lg-btns' },
						b.label_url ? h('a', { class: 'btn', href: b.label_url, target: '_blank', rel: 'noopener' }, 'Etikett') : null,
						d.can_edit ? h('button', { class: 'btn', type: 'button', onclick: function () { binDialog(d, b, reload); } }, 'Bearbeiten') : null)));
			});
			box.appendChild(list);
			if (!d.sheet_url) { box.appendChild(h('p', { class: 'muted' }, 'Etiketten drucken darfst du nicht (Recht „Etikettendrucker“).')); }
			return box;
		}

		function workDialog(d, done) {
			var kind = select(Object.keys(d.kinds).map(function (k) { return [k, d.kinds[k]]; }), 'montage', 'Art');
			var art = h('select', { class: 'field', 'aria-label': 'Artikel' });
			var qty = textIn('', 'Stückzahl', { inputmode: 'decimal', required: '' });
			var color = select(d.colors.map(function (c) { return [c.slug, c.label]; }), d.colors[0] && d.colors[0].slug, 'Farbe');
			var err = h('div', { 'aria-live': 'polite' });
			var btn = h('button', { class: 'btn primary', type: 'submit' }, 'Auftrag anlegen');
			function sync() {
				art.textContent = '';
				d.articles.filter(function (a) { return a.kinds.indexOf(kind.value) > -1; }).forEach(function (a) { art.appendChild(opt(a.id, a.label)); });
			}
			kind.addEventListener('change', sync);
			sync();
			var form = h('form', { class: 'lg-form' }, row('Art', kind), row('Artikel', art), row('Stück', qty), row('Farbe', color),
				h('p', { class: 'muted lg-hint' }, 'Beim Anlegen entsteht die Materialliste (eine Ebene tief). Gebucht wird beim Fertigmelden: Material geht ab, das Fertigteil kommt dazu. Ein Druckauftrag wird im Druckjournal gebucht.'),
				err, h('div', { class: 'lg-actions' }, btn));
			var dialog = ui.dlg('Neuer Auftrag', h('div', { class: 'sheet-body' }, form));
			form.addEventListener('submit', submitter(dialog, err, btn, function () {
				return ui.api('fertigung/anlegen', { method: 'POST', body: { kind: kind.value, article_id: parseInt(art.value, 10) || 0, qty_planned: qty.value, color: color.value } });
			}, done));
		}

		function fertigungTab(reload) {
			var box = h('div', null, h('div', { class: 'card' }, 'Wird geladen …'));
			ui.api('fertigung').then(function (d) {
				box.textContent = '';
				box.appendChild(h('p', { class: 'muted' }, 'Zwischen Rohling und fertigem Artikel liegen Arbeitsschritte, die kein Druckjob sind – etwa Gewindemuttern einschmelzen. Der Fertigungsauftrag ist das Gegenstück zum Druckjob, nur ohne Drucker.'));
				if (d.can_make) { box.appendChild(h('p', null, d.articles.length ? h('button', { class: 'btn primary', type: 'button', onclick: function () { workDialog(d, reload); } }, 'Neuer Auftrag') : h('span', { class: 'muted' }, 'Noch kein montierter oder gedruckter Artikel angelegt. Ein Halbzeug bekommt unter „Alle Produkte“ den Beschaffungsweg „Montage“ und eine Stückliste.'))); }
				var list = h('div', { class: 'rows' });
				if (!d.orders.length) { list.appendChild(h('div', { class: 'card muted' }, 'Noch keine Aufträge.')); }
				d.orders.forEach(function (o) { list.appendChild(workRow(o, reload)); });
				box.appendChild(list);
			}).catch(function (e) { failBox(box, e); });
			return box;
		}

		function workRow(o, reload) {
			var actions = h('div', { class: 'lg-btns' });
			var note = h('div');

			function post(path, body) {
				return ui.api(path, { method: 'POST', body: body }).then(function (r) { ui.toast(r.message); reload(); }).catch(function (e) { ui.toast(e.message, true); });
			}

			if (o.open) {
				actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { post('fertigung/uhr', { id: o.id, pause: o.running }); } }, o.running ? 'Pausieren' : 'Starten'));
				if (o.kind === 'druck') {
					if (ui.findItem('druckjournal')) { actions.appendChild(h('a', { class: 'btn primary', href: '#/seite/druckjournal' }, 'Im Druckjournal buchen')); }
				} else {
					var q = textIn(String(o.planned).replace('.', ','), 'Fertige Stückzahl', { inputmode: 'decimal', class: 'field lg-small' });
					actions.appendChild(q);
					actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { post('fertigung/fertig', { id: o.id, qty_done: q.value }); } }, 'Fertigmelden'));
				}
				actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					var why = textIn('', 'Grund', { placeholder: 'Grund für den Storno', required: '' });
					var err = h('div', { 'aria-live': 'polite' });
					var go = h('button', { class: 'btn primary', type: 'submit' }, 'Auftrag stornieren');
					var f = h('form', { class: 'lg-form' }, row('Grund', why), err, h('div', { class: 'lg-actions' }, go));
					var dialog = ui.dlg('Auftrag ' + o.code + ' stornieren', h('div', { class: 'sheet-body' }, f));
					f.addEventListener('submit', submitter(dialog, err, go, function () { return ui.api('fertigung/fertig', { method: 'POST', body: { id: o.id, cancel: true, reason: why.value } }); }, reload));
				} }, 'Stornieren'));
			} else if (o.status === 'erledigt') {
				note.appendChild(h('span', { class: 'muted' }, num(o.done) + ' gefertigt'));
			}

			return h('article', { class: 'row-card lg-row' + (o.open ? '' : ' lg-dim') },
				h('div', { class: 'row-main' }, h('b', null, o.code + ' · ' + o.article), h('small', null, o.kind_label + ' · ' + o.color_label + ' · geplant ' + num(o.planned) + (o.time ? ' · Zeit ' + o.time : '')),
					o.material.length ? h('small', null, 'Material: ' + o.material.join(' · ')) : null,
					h('div', { class: 'row-tags' }, h('span', { class: 'tag ' + (o.running ? 'now' : (o.open ? 'later' : '')) }, o.status_text),
						o.picklist ? h('a', { class: 'tag', href: '#/seite/picken' }, 'Pickliste ' + o.picklist) : null)),
				h('div', { class: 'lg-num' }, note), actions);
		}

		function pickTab() {
			return h('div', { class: 'card' }, h('h3', null, 'Picklisten'),
				h('p', null, 'Unter „Picklisten“ erzeugst du eine Liste aus den offenen Bestellungen und arbeitest offene Listen ab: Positionen entnehmen oder den Artikel scannen. Gebucht wird beim Abhaken.'),
				ui.findItem('picken') ? h('a', { class: 'btn primary', href: '#/seite/picken' }, 'Zu den Picklisten') : h('p', { class: 'muted' }, 'Dafür fehlt dir das Recht.'));
		}

		function pagePlaetze(item) {
			var state = { tab: 'plaetze' };
			var body = h('div');
			var shown = null;
			ui.pageHead('Lagerplätze', 'Fächer, Kisten und Regale – der Platz ist eine Angabe an der Buchung, kein zweiter Bestand.', ui.classicBtn(item));
			ui.view().classList.add('lg');
			var tabBox = h('div');
			ui.view().appendChild(tabBox);
			ui.view().appendChild(body);

			function draw() {
				tabBox.textContent = '';
				tabBox.appendChild(pills([['plaetze', 'Lagerplätze'], ['fertigung', 'Fertigungsaufträge'], ['picken', 'Picklisten']], state.tab, function (t) { state.tab = t; draw(); }));
				body.textContent = '';
				if (state.tab === 'plaetze') {
					if (shown) { body.appendChild(plaetzeTab(shown, load)); } else { body.appendChild(h('div', { class: 'card' }, 'Wird geladen …')); }
				} else if (state.tab === 'fertigung') { body.appendChild(fertigungTab(draw)); }
				else { body.appendChild(pickTab()); }
			}

			function load() {
				return ui.api('lagerplaetze').then(function (d) { shown = d; draw(); }).catch(function (e) { failBox(body, e); });
			}
			draw();
			load();
		}

		/* ---------------- Seite Bedarf ---------------- */

		function pageBedarf(item) {
			var soft = true;
			var body = h('div');
			ui.pageHead('Bedarf', 'Was fehlt, woher der Bedarf kommt, was liegt und was schon in Arbeit ist. Aufträge entstehen im Workflow, Bestellungen im Einkauf.', ui.classicBtn(item));
			ui.view().classList.add('lg');
			ui.view().appendChild(body);

			function load() {
				body.textContent = '';
				body.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
				ui.api('bedarf' + (soft ? '' : '?nur_fest=1')).then(paint).catch(function (e) { failBox(body, e); });
			}

			function go(target, label) {
				return ui.findItem(target) ? h('a', { class: 'btn' + (target === 'workflow' ? ' primary' : ''), href: '#/seite/' + target }, label) : h('span', { class: 'muted' }, 'Dafür fehlt dir das Recht.');
			}

			function paint(d) {
				body.textContent = '';
				body.appendChild(h('div', { class: 'presets' },
					h('button', { class: 'chip', type: 'button', 'aria-pressed': soft ? 'true' : 'false', onclick: function () { soft = true; load(); } }, 'Mit Wunschliste'),
					h('button', { class: 'chip', type: 'button', 'aria-pressed': soft ? 'false' : 'true', onclick: function () { soft = false; load(); } }, 'Nur Bestellungen und Sollbestand')));
				d.warnings.forEach(function (w) { body.appendChild(h('div', { class: 'notice warn lg-gap' }, w)); });

				if (!d.todo.length) {
					body.appendChild(h('div', { class: 'notice lg-gap' }, 'Nichts zu tun – alle Bedarfe sind durch Bestand und laufende Aufträge gedeckt.'));
				} else {
					body.appendChild(h('h3', { class: 'lg-h' }, 'Zu tun'));
					body.appendChild(h('p', { class: 'muted' }, 'Was am tiefsten in der Stückliste steckt, steht oben – es muss zuerst da sein.'));
					var list = h('div', { class: 'rows' });
					d.todo.forEach(function (r) {
						list.appendChild(h('article', { class: 'row-card lg-row' },
							h('div', { class: 'row-main' }, h('b', null, r.sku), h('small', null, r.name),
								h('div', { class: 'row-tags' }, h('span', { class: 'tag ' + (r.action === 'bestellen' ? 'later' : 'now') }, r.action_text)),
								h('small', null, 'Bedarf ' + num(r.gross) + ' · Bestand ' + num(r.stock) + ' · in Arbeit ' + num(r.in_work)),
								r.why.length ? h('small', null, 'Wofür: ' + r.why.join(' · ')) : null),
							h('div', { class: 'lg-num' }, h('span', { class: 'row-num' }, num(r.net)), h('small', { class: 'muted' }, 'fehlt')),
							h('div', { class: 'lg-btns' }, go(r.target, r.target === 'einkauf' ? 'Zum Einkauf' : 'Zum Plan'))));
					});
					body.appendChild(list);
				}

				body.appendChild(h('h3', { class: 'lg-h' }, 'Kleinteile für diese Bedarfe'));
				if (!d.components.length) {
					body.appendChild(h('p', { class: 'muted' }, 'Keine Kleinteile betroffen.'));
				} else {
					var cl = h('div', { class: 'rows' });
					d.components.forEach(function (c) {
						cl.appendChild(h('article', { class: 'row-card lg-row' },
							h('div', { class: 'row-main' }, h('b', null, c.code), h('small', null, c.name + ' · benötigt ' + num(c.gross) + ' · Bestand ' + num(c.stock))),
							h('div', { class: 'lg-num' }, h('span', { class: 'row-num' }, c.net > 0.0005 ? num(c.net) : '–'), h('small', { class: 'muted' }, c.net > 0.0005 ? 'fehlt' : 'vorrätig')),
							h('div', { class: 'lg-btns' }, c.net > 0.0005 ? go('einkauf', 'Zum Einkauf') : null)));
					});
					body.appendChild(cl);
				}

				if (d.covered.length) {
					body.appendChild(h('h3', { class: 'lg-h' }, 'Gedeckt'));
					body.appendChild(h('p', { class: 'muted' }, 'Diese Bedarfe bestehen, sind aber durch Bestand oder laufende Aufträge abgedeckt.'));
					body.appendChild(h('div', { class: 'card' }, h('div', { class: 'kv' }, d.covered.reduce(function (acc, r) {
						acc.push(h('span', null, r.sku), h('div', null, r.name + ' – Bedarf ' + num(r.gross) + ' · Bestand ' + num(r.stock) + ' · in Arbeit ' + num(r.in_work)));
						return acc;
					}, []))));
				}
			}
			load();
		}

		return { lager: pageLager, plaetze: pagePlaetze, bedarf: pageBedarf };
	}

	function page(name) {
		return function (item, ui) { return mk(ui)[name](item); };
	}

	UI.pages['lager'] = page('lager');
	UI.pages['lagerplaetze'] = page('plaetze');
	UI.pages['bedarf'] = page('bedarf');
})();
