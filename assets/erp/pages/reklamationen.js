/*
 * ERP-Seite „Reklamationen“: Fälle, Wareneingang, Lösung, Auswertung.
 * Schnittstelle: includes/Erp/Pages/Reklamationen.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['reklamationen'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { f: 'offen', q: '' };
		var body = h('div');
		var data = null;
		var timer = null;

		var newBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () { newDialog(); } }, 'Neuer Fall');
		ui.pageHead('Reklamationen', 'Reklamationen und Rücksendungen von der Meldung bis zur Lösung – mit Lagerbuchung und Auswertung der Gründe.', newBtn);
		ui.view().appendChild(body);

		function q3(n) { return String(Math.round((Number(n) || 0) * 1000) / 1000).replace('.', ','); }

		function newDialog() {
			var d = data;
			if (!d) { return; }
			var orderNo = k.input('', { inputmode: 'numeric', placeholder: 'z. B. 1234' });
			var look = h('button', { class: 'btn', type: 'button' }, 'Nachschlagen');
			var customer = k.input('');
			var email = k.input('', { type: 'email' });
			var art = k.select([['', '— ohne Artikel —']].concat(d.articles), '');
			var color = k.select([['', '—']].concat(Object.keys(d.colors).map(function (c) { return [c, d.colors[c]]; })), '');
			var qty = k.input('1', { inputmode: 'decimal' });
			var reason = k.select(Object.keys(d.reasons).map(function (r) { return [r, d.reasons[r]]; }), 'defekt');
			var desc = k.area('', { rows: 3, placeholder: 'Was ist passiert? Fotos liegen z. B. im Postfach.' });
			var notify = h('input', { type: 'checkbox', checked: true });
			var pick = h('div');
			look.addEventListener('click', function () {
				look.disabled = true;
				ui.api('reklamationen/bestellung/' + encodeURIComponent(orderNo.value.replace(/\D/g, ''))).then(function (o) {
					customer.value = o.customer;
					email.value = o.email;
					pick.textContent = '';
					o.items.forEach(function (it) {
						pick.appendChild(h('button', { class: 'chip', type: 'button', onclick: function () {
							if (it.article_id) { art.value = String(it.article_id); }
							if (it.color) { color.value = it.color; }
							qty.value = q3(it.qty);
						} }, it.name + ' × ' + q3(it.qty)));
					});
				}).catch(function (e) { ui.toast(e.message, true); }).then(function () { look.disabled = false; });
			});
			k.formDialog('Neuer Fall', [
				h('div', { class: 'kt-grid' }, k.field('Shop-Bestellung (optional)', orderNo), h('div', null, look)),
				h('div', { class: 'presets' }, pick),
				h('div', { class: 'kt-grid' }, k.field('Kunde', customer), k.field('E-Mail', email)),
				h('div', { class: 'kt-grid' }, k.field('Artikel', art), k.field('Farbe', color), k.field('Menge', qty)),
				k.field('Grund', reason),
				k.field('Beschreibung', desc),
				h('label', { class: 'lg-chk' }, notify, ' Eingangsbestätigung mit Fallnummer an den Kunden schicken')
			], 'Anlegen', function () {
				return ui.api('reklamationen', { method: 'POST', body: { wc_order_id: orderNo.value.replace(/\D/g, ''), customer: customer.value, email: email.value, article_id: art.value, color: color.value, qty: qty.value, reason: reason.value, description: desc.value, notify: notify.checked ? 1 : 0 } });
			}, load, true);
		}

		function receiveDialog(c) {
			var left = c.qty - c.restocked - c.scrapped;
			var restock = k.input(data.can_book && c.article_id ? q3(left) : '0', { inputmode: 'decimal', disabled: data.can_book && c.article_id ? null : true });
			var scrap = k.input('0', { inputmode: 'decimal' });
			var note = k.input('', { placeholder: 'z. B. Zustand der Ware' });
			k.formDialog('Ware zurück: ' + c.number, [
				h('p', { class: 'muted' }, 'Noch offen: ' + q3(left) + ' von ' + q3(c.qty) + '.'),
				k.field('Zurück ins Lager (verkaufsfähig)', restock, data.can_book ? (c.article_id ? 'Wird als Retoure gebucht.' : 'Der Fall hat keinen Artikel.') : 'Einbuchen darf nur, wer Bestand buchen darf.'),
				k.field('Ausschuss', scrap, 'Nicht mehr verkaufbar – keine Lagerbuchung, nur festgehalten.'),
				k.field('Notiz', note)
			], 'Speichern', function () {
				return ui.api('reklamationen/' + c.id + '/ware', { method: 'POST', body: { restock: restock.value, scrap: scrap.value, note: note.value } });
			}, load);
		}

		function resolveDialog(c) {
			var res = k.select(Object.keys(data.resolutions).map(function (r) { return [r, data.resolutions[r]]; }), c.resolution || 'ersatz');
			var refund = k.input(c.refund !== null ? String(c.refund).replace('.', ',') : '', { inputmode: 'decimal' });
			var book = h('input', { type: 'checkbox', checked: !!(data.can_book && c.article_id) });
			var refundRow = k.field('Betrag der Gutschrift', refund, c.wc_order_id && data.can_refund ? 'Das Geld zurück: Shop-Bestellung → Erstatten.' : null);
			var bookRow = h('label', { class: 'lg-chk' }, book, ' Ersatz (' + q3(c.qty) + ') aus dem Lager ausbuchen');
			var note = k.input('');
			var notify = h('input', { type: 'checkbox', checked: !!c.email });
			function toggle() {
				refundRow.hidden = res.value !== 'gutschrift';
				bookRow.hidden = res.value !== 'ersatz' || !data.can_book || !c.article_id;
			}
			res.addEventListener('change', toggle);
			toggle();
			k.formDialog('Lösung: ' + c.number, [k.field('Lösung', res), refundRow, bookRow, k.field('Notiz (intern)', note), c.email ? h('label', { class: 'lg-chk' }, notify, ' Kunden per Mail über die Lösung informieren') : null], 'Speichern', function () {
				return ui.api('reklamationen/' + c.id + '/loesung', { method: 'POST', body: { resolution: res.value, refund: refund.value, book_replacement: book.checked && !bookRow.hidden ? 1 : 0, note: note.value, notify: notify.checked ? 1 : 0 } });
			}, load);
		}

		function noteDialog(c) {
			var text = k.area('', { rows: 3 });
			k.formDialog('Notiz: ' + c.number, [k.field('Notiz', text)], 'Speichern', function () {
				return ui.api('reklamationen/' + c.id + '/notiz', { method: 'POST', body: { text: text.value } });
			}, load);
		}

		function caseCard(c) {
			var btns = h('div', { class: 'kt-btns' });
			if (c.status !== 'erledigt') {
				if (c.restocked + c.scrapped < c.qty) { btns.appendChild(h('button', { class: 'btn' + (c.status === 'eingegangen' ? ' primary' : ''), type: 'button', onclick: function () { receiveDialog(c); } }, 'Ware zurück')); }
				btns.appendChild(h('button', { class: 'btn' + (c.status === 'ware_da' ? ' primary' : ''), type: 'button', onclick: function () { resolveDialog(c); } }, c.resolution ? 'Lösung ändern' : 'Lösung'));
				if (c.resolution) {
					btns.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () {
						ui.api('reklamationen/' + c.id + '/erledigt', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); load(); }).catch(function (e) { ui.toast(e.message, true); });
					} }, 'Erledigt'));
				}
			}
			btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { noteDialog(c); } }, 'Notiz'));

			var tags = h('div', { class: 'row-tags' }, k.tag(c.status_label, c.status === 'erledigt' ? 'now' : (c.age_days > 14 ? 'later' : '')), k.tag(c.reason_label));
			if (c.res_label) { tags.appendChild(k.tag(c.res_label + (c.refund !== null ? ' ' + k.eur(c.refund) : ''))); }
			var hist = h('details', null, h('summary', { class: 'muted' }, 'Verlauf (' + c.history.length + ')'),
				h('ul', { class: 'lg-content' }, c.history.slice().reverse().map(function (e) { return h('li', null, ui.when(e.ts) + ' · ' + (e.who ? e.who + ': ' : '') + e.text); })));

			return h('article', { class: 'row-card kt-row' + (c.status === 'erledigt' ? ' kt-dim' : '') },
				h('div', { class: 'row-main' },
					h('b', null, c.number + ' · ' + c.customer + (c.wc_order_id ? ' · #' + c.wc_order_id : '')),
					h('small', null, (c.article ? c.article + (c.color_label ? ' · ' + c.color_label : '') + ' · ' : '') + q3(c.qty) + ' Stück' + (c.restocked ? ' · ' + q3(c.restocked) + ' zurück im Lager' : '') + (c.scrapped ? ' · ' + q3(c.scrapped) + ' Ausschuss' : '')),
					c.description ? h('small', null, c.description) : null,
					tags, hist),
				h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, c.age_days + ' T.'), h('small', { class: 'muted' }, 'alt')),
				btns);
		}

		function paint() {
			var d = data;
			body.textContent = '';
			var open = d.rows.filter(function (c) { return c.status !== 'erledigt'; });
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Offen', open.length),
				k.tile('Älter als 14 Tage', open.filter(function (c) { return c.age_days > 14; }).length),
				k.tile('Häufigster Grund (12 Monate)', d.stats.by_reason.length ? d.stats.by_reason[0][1] : '—', d.stats.by_reason.length ? d.stats.by_reason[0][2] + ' Fälle' : null)));

			var search = k.input(state.q, { type: 'search', placeholder: 'Nummer, Kunde, Bestellung, Artikel …', 'aria-label': 'Suchen' });
			search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { state.q = search.value.trim(); paint(); }, 250); });
			body.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['offen', 'Offen'], ['erledigt', 'Erledigt'], ['alle', 'Alle'], ['auswertung', 'Auswertung']], state.f, function (f) { state.f = f; paint(); }),
				state.f === 'auswertung' ? null : h('div', { style: 'flex:1 1 220px;max-width:360px' }, search)));

			if (state.f === 'auswertung') {
				body.appendChild(h('h3', { class: 'kt-h' }, 'Gründe der letzten 12 Monate'));
				body.appendChild(k.table(['Grund', 'Fälle', 'Stück'], d.stats.by_reason.map(function (r) { return [r[1], String(r[2]), q3(r[3])]; }), [1, 2]));
				body.appendChild(h('h3', { class: 'kt-h' }, 'Am häufigsten reklamiert'));
				body.appendChild(d.stats.top_articles.length ? k.table(['Artikel', 'Fälle'], d.stats.top_articles.map(function (a) { return [a[0], String(a[1])]; }), [1]) : h('p', { class: 'muted' }, 'Noch keine Fälle mit Artikel.'));
				return;
			}

			var q = state.q.toLowerCase();
			var rows = d.rows.filter(function (c) {
				if (state.f === 'offen' && c.status === 'erledigt') { return false; }
				if (state.f === 'erledigt' && c.status !== 'erledigt') { return false; }
				return !q || (c.number + ' ' + c.customer + ' ' + c.wc_order_id + ' ' + c.article + ' ' + c.email).toLowerCase().indexOf(q) > -1;
			});
			var list = h('div', { class: 'rows' });
			if (!rows.length) { list.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Hier ist nichts.' : 'Noch keine Reklamation – zum Glück.')); }
			rows.forEach(function (c) { list.appendChild(caseCard(c)); });
			body.appendChild(list);
		}

		function load() {
			ui.api('reklamationen').then(function (d) { data = d; paint(); }).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
