/*
 * ERP-Seite „AR-Ansicht“ (nativ): Einstellungen und Auswertung der Zähler.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	UI.pages['arview'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('AR-Ansicht', '3D-/AR-Ansicht der Produkte im Shop einrichten und auswerten.', ui.classicBtn(item));

		var box = h('div');
		ui.view().appendChild(box);
		var days = 30;

		function fmt(n) { return new Intl.NumberFormat('de-DE').format(n); }

		function field(label, id, input, hint) {
			return h('div', { class: 'pf-field', style: 'display:grid;gap:4px;margin-bottom:12px' },
				h('label', { for: id, style: 'font-weight:600' }, label), input, hint ? h('small', { class: 'muted' }, hint) : null);
		}

		function paint(d) {
			var o = d.settings;
			box.textContent = '';

			if (d.warning) { box.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:12px' }, d.warning)); }

			var enabled = h('input', { type: 'checkbox', id: 'ar-enabled', checked: !!o.enabled });
			var page = h('select', { class: 'field', id: 'ar-page' }, h('option', { value: '0' }, '— Seite wählen —'));
			d.pages.forEach(function (p) { page.appendChild(h('option', { value: String(p.id), selected: p.id === o.page_id }, p.title)); });
			var title = h('input', { class: 'field', id: 'ar-title', type: 'text', value: o.title });
			var text = h('input', { class: 'field', id: 'ar-text', type: 'text', value: o.text });
			var yes = h('input', { class: 'field', id: 'ar-yes', type: 'text', value: o.btn_yes });
			var no = h('input', { class: 'field', id: 'ar-no', type: 'text', value: o.btn_no });
			var lock = h('input', { class: 'field', id: 'ar-lock', type: 'number', step: '1', min: String(d.limits.days_min), max: String(d.limits.days_max), value: String(o.lock_days), style: 'max-width:140px' });
			var delay = h('input', { class: 'field', id: 'ar-delay', type: 'number', step: '1', min: String(d.limits.delay_min), max: String(d.limits.delay_max), value: String(o.delay), style: 'max-width:140px' });

			var save = h('button', { class: 'btn primary', type: 'button' }, 'Speichern');
			save.addEventListener('click', function () {
				var settings = { page_id: parseInt(page.value, 10) || 0, title: title.value, text: text.value, btn_yes: yes.value, btn_no: no.value, lock_days: lock.value, delay: delay.value };
				if (enabled.checked) { settings.enabled = 1; }
				save.disabled = true;
				ui.api('arview', { method: 'POST', body: { settings: settings } }).then(function (r) {
					ui.toast(r.message);
					return load();
				}).catch(function (e) { ui.toast(e.message, true); }).then(function () { save.disabled = false; });
			});

			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', null, 'Einstellungen'),
				h('label', { style: 'display:flex;gap:10px;align-items:center;min-height:44px;margin-bottom:8px' }, enabled, 'AR-Ansicht einschalten (Hinweis-Banner und AR-Seite)'),
				field('Ziel-Seite', 'ar-page', page, 'Die Seite, auf der der Shortcode ' + d.shortcode + ' steht, zum Beispiel /an-deiner-wand.'),
				field('Banner: Titel', 'ar-title', title),
				field('Banner: Text', 'ar-text', text),
				field('Knopf „Ja“', 'ar-yes', yes),
				field('Knopf „Nein“', 'ar-no', no),
				field('Sperrdauer (Tage)', 'ar-lock', lock, 'So lange erscheint der Banner nach „Nein“ oder „Schließen“ auf keiner Produktseite. Standard: 7.'),
				field('Verzögerung (Sekunden)', 'ar-delay', delay, 'Der Banner erscheint nach dieser Zeit oder beim ersten Scrollen – je nachdem, was zuerst eintritt. Standard: 3.'),
				save
			));

			var presets = h('div', { class: 'presets', style: 'margin-bottom:10px' });
			Object.keys(d.ranges).forEach(function (k) {
				presets.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': parseInt(k, 10) === d.days ? 'true' : 'false', onclick: function () { days = parseInt(k, 10); load(); } }, d.ranges[k]));
			});

			var tbl = h('table', { class: 'list' });
			var hr = h('tr', null, h('th', null, 'Produkt'));
			d.events.forEach(function (e) { hr.appendChild(h('th', { class: 'num' }, e)); });
			hr.appendChild(h('th', { class: 'num' }, 'Ja-Quote'));
			tbl.appendChild(h('thead', null, hr));
			var tb = h('tbody');
			if (!d.rows.length) { tb.appendChild(h('tr', null, h('td', { colspan: String(d.events.length + 2) }, 'Noch keine Zahlen im gewählten Zeitraum.'))); }
			d.rows.forEach(function (r) {
				var tr = h('tr', null, h('td', null, r.title));
				r.counts.forEach(function (c) { tr.appendChild(h('td', { class: 'num' }, fmt(c))); });
				tr.appendChild(h('td', { class: 'num' }, r.rate === null ? '–' : new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(r.rate) + ' %'));
				tb.appendChild(tr);
			});
			if (d.rows.length > 1) {
				var sr = h('tr', null, h('td', null, h('b', null, 'Gesamt')));
				d.sum.forEach(function (c) { sr.appendChild(h('td', { class: 'num' }, h('b', null, fmt(c)))); });
				sr.appendChild(h('td', { class: 'num' }, d.sum_rate === null ? '–' : new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(d.sum_rate) + ' %'));
				tb.appendChild(sr);
			}
			tbl.appendChild(tb);

			box.appendChild(h('div', { class: 'card' },
				h('h3', null, 'Auswertung'),
				h('p', { class: 'muted', style: 'margin-top:0' }, 'Anonyme Tageszähler je Produkt. Es werden weder IP-Adressen noch Benutzer- oder Gerätedaten gespeichert. „AR gestartet“ zählt das Antippen des Knopfs „An meiner Wand platzieren“.'),
				presets,
				h('div', { style: 'overflow-x:auto' }, tbl)
			));
		}

		function load() {
			return ui.api('arview?tage=' + days).then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
