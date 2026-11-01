/*
 * ERP-Seite „Bestellungen an Lieferanten“: Bestellschein bündeln, schicken, Bestätigung, Lieferstand.
 * Schnittstelle: includes/Erp/Pages/Lieferantenbestellungen.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['lieferantenbestellungen'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { tab: 'offen' };
		var body = h('div');

		ui.pageHead('Bestellungen an Lieferanten', 'Offene Einkäufe je Lieferant als Bestellschein verschicken, Bestätigung und Lieferstand im Blick.',
			ui.findItem('einkauf') ? h('a', { class: 'btn', href: '#/seite/einkauf' }, 'Einkauf & Wareneingang') : null);
		ui.view().appendChild(body);

		function q3(n) { return String(Math.round((Number(n) || 0) * 1000) / 1000).replace('.', ','); }

		function lineRows(lines) {
			return k.table(['Position', 'Menge', 'Preis netto', 'Summe', 'Stand'], lines.map(function (l) {
				return [l.label, q3(l.qty) + ' ' + l.unit, l.price ? k.eur(l.price) : '—', l.total ? k.eur(l.total) : '—', l.state + (l.expected ? ' · erwartet ' + k.dmy(l.expected) : '')];
			}), [1, 2, 3]);
		}

		function createDialog(g) {
			var checks = g.lines.map(function (l) { return h('input', { type: 'checkbox', checked: true, value: String(l.id) }); });
			var email = k.input(g.email, { type: 'email', placeholder: 'bestellung@lieferant.de' });
			var wanted = k.input('', { type: 'date' });
			var note = k.area('', { rows: 2, placeholder: 'z. B. Kundennummer, Lieferhinweis' });
			k.formDialog('Bestellschein: ' + g.supplier, [
				h('div', { class: 'kt-lines' }, g.lines.map(function (l, i) {
					return h('label', { class: 'lg-chk' }, checks[i], ' ' + l.label + ' · ' + q3(l.qty) + ' ' + l.unit + (l.total ? ' · ' + k.eur(l.total) : ''));
				})),
				k.field('E-Mail des Lieferanten', email, 'Wird für das nächste Mal gemerkt.'),
				k.field('Gewünscht bis', wanted),
				k.field('Hinweis auf dem Bestellschein', note)
			], 'Anlegen', function () {
				return ui.api('lieferantenbestellungen', { method: 'POST', body: {
					supplier: g.supplier, email: email.value, wanted_by: wanted.value, note: note.value,
					purchase_ids: checks.filter(function (c) { return c.checked; }).map(function (c) { return parseInt(c.value, 10); })
				} });
			}, function () { state.tab = 'scheine'; load(); }, true);
		}

		function sendDialog(o) {
			ui.api('lieferantenbestellungen/' + o.id + '/mail').then(function (m) {
				var to = k.input(m.to, { type: 'email', required: '' });
				var subject = k.input(m.subject, { required: '' });
				var text = k.area(m.body, { rows: 8 });
				k.formDialog('Bestellung ' + o.number + ' senden', [k.field('An', to), k.field('Betreff', subject), k.field('Text', text, 'Der Bestellschein hängt als PDF an.')], 'Senden', function () {
					return ui.api('lieferantenbestellungen/' + o.id + '/senden', { method: 'POST', body: { to: to.value, subject: subject.value, body: text.value } });
				}, load, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function confirmDialog(o) {
			var date = k.input(o.confirmed_date || o.wanted_by || '', { type: 'date' });
			var ref = k.input(o.supplier_ref, { placeholder: 'Auftragsnummer des Lieferanten' });
			k.formDialog('Bestätigung: ' + o.number, [k.field('Bestätigter Liefertermin', date, 'Die Einkäufe bekommen diesen Tag als erwartete Lieferung.'), k.field('Referenz des Lieferanten', ref)], 'Speichern', function () {
				return ui.api('lieferantenbestellungen/' + o.id + '/bestaetigt', { method: 'POST', body: { date: date.value, supplier_ref: ref.value } });
			}, load);
		}

		function paint(d) {
			body.textContent = '';
			var open = d.orders.filter(function (o) { return ['entwurf', 'versendet', 'bestaetigt', 'teilgeliefert'].indexOf(o.shown) > -1; });
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Ohne Bestellschein', d.groups.reduce(function (n, g) { return n + g.lines.length; }, 0), d.groups.length + ' Lieferanten'),
				k.tile('Offene Bestellungen', open.length),
				k.tile('Unbestätigt', open.filter(function (o) { return o.shown === 'versendet'; }).length)));
			body.appendChild(h('div', { class: 'kt-bar' }, k.pills([['offen', 'Noch bestellen'], ['scheine', 'Bestellscheine']], state.tab, function (t) { state.tab = t; paint(d); })));

			var list = h('div', { class: 'rows' });
			if (state.tab === 'offen') {
				if (!d.groups.length) { list.appendChild(h('div', { class: 'card muted' }, 'Alle offenen Einkäufe stehen auf einem Bestellschein. Neue Einkäufe legst du im Einkauf an.')); }
				d.groups.forEach(function (g) {
					list.appendChild(h('article', { class: 'card' },
						h('div', { class: 'kt-bar' }, h('b', null, g.supplier), h('div', { class: 'kt-btns' }, h('span', { class: 'muted' }, g.total ? k.eur(g.total) + ' netto' : ''),
							h('button', { class: 'btn primary', type: 'button', onclick: function () { createDialog(g); } }, 'Bestellschein anlegen'))),
						lineRows(g.lines)));
				});
			} else {
				if (!d.orders.length) { list.appendChild(h('div', { class: 'card muted' }, 'Noch kein Bestellschein.')); }
				d.orders.forEach(function (o) {
					var btns = h('div', { class: 'kt-btns' }, k.fileBtn('PDF', 'lieferantenbestellungen/' + o.id + '/pdf'));
					if (o.shown !== 'storniert' && o.shown !== 'geliefert') {
						btns.appendChild(h('button', { class: 'btn' + (o.status === 'entwurf' ? ' primary' : ''), type: 'button', onclick: function () { sendDialog(o); } }, o.status === 'entwurf' ? 'Per Mail senden' : 'Erneut senden'));
						if (o.status !== 'entwurf') { btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { confirmDialog(o); } }, o.status === 'bestaetigt' ? 'Termin ändern' : 'Bestätigt')); }
					}
					if (o.status === 'entwurf') {
						btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
							k.confirmDo('Entwurf auflösen', 'Die Positionen werden wieder frei und können neu gebündelt werden.', 'Auflösen', function () {
								return ui.api('lieferantenbestellungen/' + o.id + '/aufloesen', { method: 'POST', body: {} });
							}, load);
						} }, 'Auflösen'));
					}
					var cls = o.shown === 'geliefert' ? 'now' : (o.shown === 'versendet' ? 'later' : '');
					list.appendChild(h('article', { class: 'card' + (o.shown === 'storniert' || o.shown === 'geliefert' ? ' kt-dim' : '') },
						h('div', { class: 'kt-bar' },
							h('div', null, h('b', null, o.number + ' · ' + o.supplier), ' ', k.tag(o.shown_label, cls),
								h('div', null, h('small', { class: 'muted' }, 'vom ' + k.dmy(o.order_date) + (o.confirmed_date ? ' · Liefertermin ' + k.dmy(o.confirmed_date) : (o.wanted_by ? ' · gewünscht ' + k.dmy(o.wanted_by) : '')) + (o.supplier_ref ? ' · Ref. ' + o.supplier_ref : '')))),
							btns),
						o.lines.length ? lineRows(o.lines) : null,
						o.totals.total ? h('p', { class: 'muted' }, 'Gesamt ' + k.eur(o.totals.total) + ' netto') : null));
				});
			}
			body.appendChild(list);
		}

		function load() {
			ui.api('lieferantenbestellungen').then(paint).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
