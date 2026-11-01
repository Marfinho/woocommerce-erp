/*
 * ERP-Seite „Angebote“: Liste, Bearbeiten, PDF, Mail, in Auftrag umwandeln.
 * Schnittstelle: includes/Erp/Pages/Angebote.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['angebote'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { status: 'offen', q: '' };
		var body = h('div');
		var opts = null;
		var timer = null;

		var newBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () { edit(null); } }, 'Neues Angebot');
		ui.pageHead('Angebote', 'Angebote schreiben, als PDF verschicken und angenommene direkt zum Auftrag machen.', newBtn);
		ui.view().appendChild(body);

		function num(n) { return String(Math.round((Number(n) || 0) * 1000) / 1000).replace('.', ','); }
		function parse(v) {
			var t = String(v || '').trim().replace(/\s/g, '');
			if (t.indexOf(',') > -1) { t = t.replace(/\./g, '').replace(',', '.'); }
			var n = parseFloat(t);
			return isFinite(n) ? n : 0;
		}

		function withOpts() {
			return opts ? Promise.resolve(opts) : ui.api('angebote/optionen').then(function (o) { opts = o; return o; });
		}

		/* ---------------- Liste ---------------- */

		function list() {
			k.loading(body);
			ui.api('angebote?' + k.qs({ q: state.q })).then(function (d) {
				body.textContent = '';
				var c = d.counts;
				body.appendChild(h('div', { class: 'grid tiles' },
					k.tile('Offen (versendet)', c.versendet || 0, k.eur(d.open_value)),
					k.tile('Entwürfe', c.entwurf || 0),
					k.tile('Angenommen', c.angenommen || 0),
					k.tile('Abgelaufen', c.abgelaufen || 0)));
				var search = k.input(state.q, { type: 'search', placeholder: 'Nummer oder Empfänger …', 'aria-label': 'Suchen' });
				search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { state.q = search.value.trim(); list(); }, 350); });
				body.appendChild(h('div', { class: 'kt-bar' },
					k.pills([['offen', 'Offen'], ['angenommen', 'Angenommen'], ['abgelehnt', 'Abgelehnt'], ['abgelaufen', 'Abgelaufen'], ['alle', 'Alle']], state.status, function (s) { state.status = s; list(); }),
					h('div', { style: 'flex:1 1 220px;max-width:360px' }, search)));
				var rows = d.rows.filter(function (r) {
					return state.status === 'alle' || (state.status === 'offen' ? (r.shown === 'entwurf' || r.shown === 'versendet') : r.shown === state.status);
				});
				var box = h('div', { class: 'rows' });
				if (!rows.length) { box.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Hier ist nichts.' : 'Noch kein Angebot. Leg oben rechts das erste an.')); }
				rows.forEach(function (r) {
					box.appendChild(h('article', { class: 'row-card kt-row', style: 'cursor:pointer', onclick: function () { open(r.id); } },
						h('div', { class: 'row-main' }, h('b', null, r.number + ' · ' + r.recipient),
							h('small', null, k.dmy(r.quote_date) + ' · gültig bis ' + k.dmy(r.valid_until)),
							h('div', { class: 'row-tags' }, k.tag(r.shown_label, r.shown === 'angenommen' ? 'now' : (r.shown === 'abgelaufen' || r.shown === 'abgelehnt' ? 'later' : '')))),
						h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, k.eur(r.total))),
						h('div', { class: 'kt-btns' })));
				});
				body.appendChild(box);
			}).catch(function (e) { k.fail(body, e); });
		}

		function open(id) {
			k.loading(body);
			ui.api('angebote/' + id).then(function (q) { edit(q); }).catch(function (e) { k.fail(body, e); });
		}

		/* ---------------- Bearbeiten ---------------- */

		function edit(q) {
			k.loading(body);
			withOpts().then(function (o) { paintEditor(q, o); }).catch(function (e) { k.fail(body, e); });
		}

		function paintEditor(q, o) {
			body.textContent = '';
			var ro = q && !q.editable;
			var lines = [];
			var lineBox = h('div', { class: 'kt-lines' });
			var sumBox = h('div', { class: 'card kt-gap' });

			var contact = k.select([['', '— ohne Kontakt —']].concat(o.contacts.map(function (c) { return [c.id, c.company + (c.person ? ' · ' + c.person : '')]; })), q ? q.contact_id || '' : '', { 'aria-label': 'Kontakt', disabled: ro || null });
			var recipient = k.input(q ? q.recipient : '', { disabled: ro || null });
			var email = k.input(q ? q.recipient_email : '', { type: 'email', disabled: ro || null });
			var address = k.area(q ? q.recipient_address : '', { rows: 3, disabled: ro || null });
			var qdate = k.input(q ? q.quote_date : o.today, { type: 'date', disabled: ro || null });
			var until = k.input(q ? q.valid_until : '', { type: 'date', disabled: ro || null, placeholder: '30 Tage' });
			var intro = k.area(q ? q.intro : o.intro, { rows: 2, disabled: ro || null });
			var outro = k.area(q ? q.outro : o.outro, { rows: 2, disabled: ro || null });
			var disc = k.input(q ? num(q.discount_pct) : '0', { inputmode: 'decimal', disabled: ro || null });
			var ship = k.input(q ? num(q.shipping_gross) : '0', { inputmode: 'decimal', disabled: ro || null });
			var note = k.input(q ? q.note : '', { placeholder: 'nur intern', disabled: ro || null });
			var dealerPrices = false;

			contact.addEventListener('change', function () {
				var c = o.contacts.filter(function (x) { return String(x.id) === contact.value; })[0];
				if (!c) { return; }
				recipient.value = c.company;
				email.value = c.email || '';
				address.value = (c.person ? c.person + '\n' : '') + (c.address || '');
				dealerPrices = c.type === 'haendler';
				if (c.discount) { disc.value = num(c.discount); }
				calc();
			});

			function addLine(it) {
				it = it || { article_id: '', color: '', title: '', description: '', qty: 1, unit_price_gross: 0, discount_pct: 0 };
				var art = k.select([['', '— freie Position —']].concat(o.articles.map(function (a) { return [a.id, a.sku + ' · ' + a.name]; })), it.article_id || '', { 'aria-label': 'Artikel', disabled: ro || null });
				var color = k.select([['', '—']].concat(Object.keys(o.colors).map(function (s) { return [s, o.colors[s]]; })), it.color || '', { 'aria-label': 'Farbe', disabled: ro || null });
				var title = k.input(it.title, { placeholder: 'Bezeichnung', disabled: ro || null });
				var qty = k.input(num(it.qty), { inputmode: 'decimal', 'aria-label': 'Menge', disabled: ro || null });
				var price = k.input(num(it.unit_price_gross), { inputmode: 'decimal', 'aria-label': 'Einzelpreis brutto', disabled: ro || null });
				var ldisc = k.input(num(it.discount_pct), { inputmode: 'decimal', 'aria-label': 'Rabatt %', disabled: ro || null });
				var row = h('div', { class: 'card' });
				var line = { art: art, color: color, title: title, qty: qty, price: price, disc: ldisc, desc: it.description || '', row: row };
				art.addEventListener('change', function () {
					var a = o.articles.filter(function (x) { return String(x.id) === art.value; })[0];
					if (!a) { return; }
					title.value = a.name;
					price.value = num(dealerPrices && a.dealer !== null ? a.dealer : a.price);
					calc();
				});
				[qty, price, ldisc].forEach(function (i) { i.addEventListener('input', calc); });
				row.appendChild(h('div', { class: 'kt-grid' }, k.field('Artikel', art), k.field('Farbe', color)));
				row.appendChild(h('div', { class: 'kt-line kt-gap' }, k.field('Bezeichnung', title), k.field('Menge', qty), k.field('Preis brutto', price), k.field('Rabatt %', ldisc),
					ro ? h('span') : h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Position löschen', onclick: function () { lines.splice(lines.indexOf(line), 1); row.remove(); calc(); } }, ui.ico ? ui.ico('x') : '×')));
				lines.push(line);
				lineBox.appendChild(row);
			}

			function calc() {
				var sub = 0;
				lines.forEach(function (l) { sub += parse(l.qty.value) * parse(l.price.value) * (1 - Math.min(100, parse(l.disc.value)) / 100); });
				var d = sub * Math.min(100, parse(disc.value)) / 100;
				var total = sub - d + parse(ship.value);
				sumBox.textContent = '';
				sumBox.appendChild(h('div', { class: 'kv' },
					h('span', null, 'Zwischensumme'), h('b', null, k.eur(sub)),
					h('span', null, 'Rabatt'), h('b', null, '−' + k.eur(d)),
					h('span', null, 'Versand'), h('b', null, k.eur(parse(ship.value))),
					h('span', null, 'Gesamt'), h('b', { style: 'font-size:20px' }, k.eur(total))));
				if (!o.ku) { sumBox.appendChild(h('small', { class: 'muted' }, 'darin 19 % USt: ' + k.eur(total - total / 1.19))); }
				else { sumBox.appendChild(h('small', { class: 'muted' }, 'Kleinunternehmer: ohne Umsatzsteuer-Ausweis.')); }
			}

			(q ? q.items : [null]).forEach(addLine);

			var back = h('button', { class: 'btn', type: 'button', onclick: list }, '← Alle Angebote');
			body.appendChild(h('div', { class: 'kt-bar' }, back, q ? h('div', null, h('b', null, q.number + ' '), k.tag(q.shown_label, q.shown === 'angenommen' ? 'now' : '')) : h('b', null, 'Neues Angebot')));

			body.appendChild(h('div', { class: 'card' },
				h('div', { class: 'kt-grid' }, k.field('Kontakt', contact, 'Füllt Empfänger, Adresse und Rabatt aus der Kondition.'), k.field('Empfänger', recipient), k.field('E-Mail', email)),
				h('div', { class: 'kt-grid kt-gap' }, k.field('Adresse', address), k.field('Angebotsdatum', qdate), k.field('Gültig bis', until, 'leer = 30 Tage')),
				h('div', { class: 'kt-gap' }, k.field('Einleitung', intro))));
			body.appendChild(h('h3', { class: 'kt-h' }, 'Positionen'));
			body.appendChild(lineBox);
			if (!ro) { body.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: function () { addLine(null); calc(); } }, '+ Position'))); }
			body.appendChild(h('div', { class: 'card kt-gap' },
				h('div', { class: 'kt-grid' }, k.field('Rabatt auf alles %', disc), k.field('Versand brutto', ship), k.field('Notiz', note)),
				h('div', { class: 'kt-gap' }, k.field('Schlusstext', outro))));
			[disc, ship].forEach(function (i) { i.addEventListener('input', calc); });
			body.appendChild(sumBox);
			calc();

			var err = h('div', { 'aria-live': 'polite', class: 'kt-gap' });
			var actions = h('div', { class: 'kt-actions kt-gap' });
			body.appendChild(err);
			body.appendChild(actions);

			function payload() {
				return {
					id: q ? q.id : 0, contact_id: contact.value, recipient: recipient.value, recipient_email: email.value, recipient_address: address.value,
					quote_date: qdate.value, valid_until: until.value, intro: intro.value, outro: outro.value, discount_pct: disc.value, shipping_gross: ship.value, note: note.value,
					items: lines.map(function (l) { return { article_id: l.art.value, color: l.color.value, title: l.title.value, description: l.desc, qty: l.qty.value, unit_price_gross: l.price.value, discount_pct: l.disc.value }; })
				};
			}

			function save() {
				err.textContent = '';
				return ui.api('angebote', { method: 'POST', body: payload() }).then(function (r) { ui.toast(r.message); return r.quote; })
					.catch(function (e) { err.appendChild(h('div', { class: 'notice warn' }, e.message)); throw e; });
			}

			if (!ro) {
				actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { save().then(function (nq) { paintEditor(nq, o); }).catch(function () {}); } }, 'Speichern'));
			}
			if (q) {
				actions.appendChild(k.fileBtn('PDF', 'angebote/' + q.id + '/pdf'));
				if (q.editable) {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { save().then(function (nq) { mailDialog(nq, o); }).catch(function () {}); } }, 'Per Mail senden'));
					if (ui.can('verkauf.orders')) {
						actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () {
							save().then(function (nq) {
								k.confirmDo('In Auftrag umwandeln', 'Es entsteht ein bestätigter Auftrag für ' + nq.recipient + ' über ' + k.eur(nq.total) + '. Die Artikel werden aus dem Lager ausgebucht.', 'Auftrag anlegen', function () {
									return ui.api('angebote/' + nq.id + '/auftrag', { method: 'POST', body: {} });
								}, function () { open(nq.id); });
							}).catch(function () {});
						} }, 'Angenommen → Auftrag'));
					}
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
						k.confirmDo('Abgelehnt', 'Das Angebot wird als abgelehnt abgelegt.', 'Abgelehnt', function () {
							return ui.api('angebote/' + q.id + '/status', { method: 'POST', body: { status: 'abgelehnt' } });
						}, function () { open(q.id); });
					} }, 'Abgelehnt'));
				}
				if (q.status === 'abgelehnt') {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
						ui.api('angebote/' + q.id + '/status', { method: 'POST', body: { status: 'entwurf' } }).then(function () { open(q.id); }).catch(function (e) { ui.toast(e.message, true); });
					} }, 'Wieder öffnen'));
				}
				actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					ui.api('angebote/' + q.id + '/kopie', { method: 'POST', body: {} }).then(function (r) { ui.toast('Kopie angelegt: ' + r.quote.number); paintEditor(r.quote, o); }).catch(function (e) { ui.toast(e.message, true); });
				} }, 'Kopie'));
				if (q.order_op_id) { body.appendChild(h('div', { class: 'notice kt-gap' }, 'Daraus wurde Auftrag A-' + q.order_op_id + ' (Verkauf → Aufträge).')); }
			}
		}

		function mailDialog(q) {
			ui.api('angebote/' + q.id + '/mail').then(function (m) {
				var to = k.input(m.to, { type: 'email', required: '' });
				var subject = k.input(m.subject, { required: '' });
				var text = k.area(m.body, { rows: 9 });
				k.formDialog('Angebot ' + q.number + ' senden', [k.field('An', to), k.field('Betreff', subject), k.field('Text', text, 'Das PDF hängt an.')], 'Senden', function () {
					return ui.api('angebote/' + q.id + '/senden', { method: 'POST', body: { to: to.value, subject: subject.value, body: text.value } });
				}, function () { open(q.id); }, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		list();
	};
})();
