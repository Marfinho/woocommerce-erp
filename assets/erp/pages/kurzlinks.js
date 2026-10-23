/*
 * ERP-Seite „Kurzlinks“ (nativ): feste Adressen für Printmaterial verwalten,
 * Aufrufe und Bestellungen nach Herkunft ansehen.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	UI.pages['kurzlinks'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Kurzlinks', 'Feste Adressen für QR-Codes auf Paketbeilage, Flyer, Sticker und Stand – das Ziel bleibt änderbar.', ui.classicBtn(item));

		var box = h('div');
		ui.view().appendChild(box);
		var days = 90;

		function fmt(n) { return new Intl.NumberFormat('de-DE').format(n); }
		function eur(n) { return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' €'; }

		function paint(d) {
			box.textContent = '';
			var rows = [];

			var tbl = h('table', { class: 'list' });
			tbl.appendChild(h('thead', null, h('tr', null,
				h('th', null, 'Adresse'), h('th', null, 'Beschreibung'), h('th', null, 'Ziel'), h('th', null, 'Aktiv'), h('th', null, 'Löschen'))));
			var tb = h('tbody');

			function addRow(link) {
				var neu = !link;
				link = link || { slug: '', beschreibung: '', seite_id: 0, url: '', aktiv: true };
				var r = { orig: neu ? '' : link.slug };

				r.slug = h('input', { class: 'field', type: 'text', maxlength: '64', placeholder: 'neuer-slug', style: 'max-width:12em' });
				r.beschreibung = h('input', { class: 'field', type: 'text', value: link.beschreibung, placeholder: 'z. B. Paketbeilage, Innenseite links' });
				r.seite = h('select', { class: 'field' }, h('option', { value: '0' }, '— Startseite oder freie URL —'));
				d.pages.forEach(function (p) { r.seite.appendChild(h('option', { value: String(p.id), selected: p.id === link.seite_id }, p.title)); });
				r.url = h('input', { class: 'field', type: 'text', value: link.url, placeholder: 'oder freie URL: https://…' });
				r.aktiv = h('input', { type: 'checkbox', checked: !!link.aktiv, 'aria-label': 'Aktiv' });
				r.loeschen = h('input', { type: 'checkbox', 'aria-label': 'Löschen' });
				rows.push(r);

				var first = neu
					? h('td', null, h('code', null, d.home), r.slug)
					: h('td', null, h('a', { href: link.address, target: '_blank', rel: 'noopener' }, h('code', null, link.address)),
						h('div', { class: 'muted' }, 'Für den QR-Code z. B. ?utm_source=beilage'));
				var target = h('td', null, r.seite, h('div', null, r.url));
				if (!neu && link.placeholder) { target.appendChild(h('small', { class: 'muted' }, 'Noch kein Ziel gewählt – Platzhalter: Startseite.')); }
				if (!neu && link.warning) { target.appendChild(h('div', { class: 'notice warn' }, link.warning)); }

				tb.appendChild(h('tr', null, first, h('td', null, r.beschreibung), target,
					h('td', null, r.aktiv), h('td', null, neu ? '' : r.loeschen)));

			}

			d.links.forEach(function (l) { addRow(l); });
			addRow(null);
			tbl.appendChild(tb);

			var save = h('button', { class: 'btn primary', type: 'button' }, 'Speichern');
			save.addEventListener('click', function () {
				var payload = rows.map(function (r) {
					var o = { beschreibung: r.beschreibung.value, seite_id: parseInt(r.seite.value, 10) || 0, url: r.url.value, aktiv: r.aktiv.checked, loeschen: r.loeschen.checked };
					if (r.orig) { o.orig = r.orig; } else { o.slug = r.slug.value; }
					return o;
				});
				save.disabled = true;
				ui.api('kurzlinks', { method: 'POST', body: { rows: payload } }).then(function (res) {
					ui.toast(res.message);
					return load();
				}).catch(function (e) {
					ui.toast(e.message, true);
				}).then(function () { save.disabled = false; });
			});

			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', null, 'Links'),
				h('div', { style: 'overflow-x:auto' }, tbl),
				h('p', { class: 'muted' }, 'Ein bestehender Slug lässt sich nicht umbenennen, weil er schon gedruckt ist. Ein deaktivierter Link liefert „Seite nicht gefunden“. Löschen nur, wenn der Link nie gedruckt wurde. Bei Zielen auf dieser Website werden die Parameter der Anfrage (z. B. ?utm_source=beilage) angehängt, damit WooCommerce die Bestellherkunft erfasst; externe Ziele bekommen keine Parameter.'),
				save
			));

			// Aufrufe.
			var st = h('table', { class: 'list' });
			st.appendChild(h('thead', null, h('tr', null, h('th', null, 'Link'), h('th', null, 'Quelle'), h('th', { class: 'num' }, '7 Tage'), h('th', { class: 'num' }, '30 Tage'), h('th', { class: 'num' }, 'Gesamt'))));
			var sb = h('tbody');
			if (!d.scans.length) { sb.appendChild(h('tr', null, h('td', { colspan: '5' }, 'Noch keine Aufrufe gezählt.'))); }
			d.scans.forEach(function (g) {
				sb.appendChild(h('tr', null, h('td', null, h('b', null, '/' + g.slug)), h('td', null, h('i', null, 'alle Quellen')),
					h('td', { class: 'num' }, h('b', null, fmt(g.d7))), h('td', { class: 'num' }, h('b', null, fmt(g.d30))), h('td', { class: 'num' }, h('b', null, fmt(g.gesamt)))));
				g.quellen.forEach(function (q) {
					sb.appendChild(h('tr', null, h('td'), h('td', null, q.quelle === '' ? '(ohne Quelle)' : q.quelle),
						h('td', { class: 'num' }, fmt(q.d7)), h('td', { class: 'num' }, fmt(q.d30)), h('td', { class: 'num' }, fmt(q.gesamt))));
				});
			});
			st.appendChild(sb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', null, 'Aufrufe je Link und Quelle'),
				h('div', { style: 'overflow-x:auto' }, st),
				h('p', { class: 'muted' }, 'Gezählt wird serverseitig, anonym und je Tag: ohne IP-Adresse, ohne User-Agent, ohne Cookie, ohne JavaScript. HEAD-Anfragen und gängige Bots zählen nicht. Es ist eine Zählung von Aufrufen, nicht von Personen.')
			));

			// Herkunft.
			var presets = h('div', { class: 'presets', style: 'margin-bottom:10px' });
			Object.keys(d.ranges).forEach(function (k) {
				presets.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': parseInt(k, 10) === d.days ? 'true' : 'false', onclick: function () { days = parseInt(k, 10); load(); } }, d.ranges[k]));
			});
			var ot = h('table', { class: 'list' });
			ot.appendChild(h('thead', null, h('tr', null, h('th', null, 'Herkunft (utm_source)'), h('th', { class: 'num' }, 'Bestellungen'), h('th', { class: 'num' }, 'Umsatz (brutto, nach Erstattungen)'))));
			var ob = h('tbody');
			if (!d.origin.length) { ob.appendChild(h('tr', null, h('td', { colspan: '3' }, 'Keine bezahlten Bestellungen im Zeitraum.'))); }
			d.origin.forEach(function (r) {
				ob.appendChild(h('tr', null, h('td', null, r.quelle), h('td', { class: 'num' }, fmt(r.anzahl)), h('td', { class: 'num' }, eur(r.umsatz))));
			});
			if (d.origin.length) {
				ob.appendChild(h('tr', null, h('td', null, h('b', null, 'Summe')), h('td', { class: 'num' }, h('b', null, fmt(d.origin_sum.anzahl))), h('td', { class: 'num' }, h('b', null, eur(d.origin_sum.umsatz)))));
			}
			ot.appendChild(ob);
			box.appendChild(h('div', { class: 'card' },
				h('h3', null, 'Bestellungen nach Herkunft'),
				presets,
				h('div', { style: 'overflow-x:auto' }, ot),
				h('p', { class: 'muted' }, 'Gezählt werden bezahlte Bestellungen (in Bearbeitung, abgeschlossen). Die Herkunft kommt aus der WooCommerce-Bestellherkunft. Ohne Cookie-Einwilligung erfasst WooCommerce keine Herkunft; solche Bestellungen stehen als „unbekannt“ in der Liste – das ist kein Fehler. Scans und Bestellungen ergeben deshalb nie eine vollständige Quote.')
			));
		}

		function load() {
			return ui.api('kurzlinks?herkunft=' + days).then(function (d) { paint(d); }).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
