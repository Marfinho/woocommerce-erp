/*
 * ERP-Seite „Support“ (nativ): Kundenanfragen lesen, beantworten, Status setzen,
 * Gutschrift buchen. Texte der Kunden gelangen nur über textContent in die Seite.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	UI.pages['support'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Support', 'Kundenanfragen beantworten und abschließen.', ui.classicBtn(item));

		var showAll = false;
		var open = 0;
		var box = h('div');
		var bar = h('div', { class: 'presets', style: 'margin-bottom:12px' });
		ui.view().appendChild(bar);
		ui.view().appendChild(box);

		function fail(e) { ui.toast(e.message || 'Das hat nicht geklappt.', true); }

		function post(t, body, btn, after) {
			if (btn) { btn.disabled = true; }
			return ui.api('support/' + t.id + '/aktion', { method: 'POST', body: body }).then(function (r) {
				ui.toast(r.message);
				return load();
			}).catch(function (e) { fail(e); if (btn) { btn.disabled = false; } });
		}

		function card(t) {
			var isOpen = open === t.id;
			var head = h('button', { class: 'row-card', type: 'button', 'aria-expanded': isOpen ? 'true' : 'false', style: 'width:100%;text-align:left;cursor:pointer', onclick: function () { open = isOpen ? 0 : t.id; paint(lastData); } },
				h('div', { class: 'row-main' },
					h('b', null, '#' + t.id + ' · ' + t.subject),
					h('small', null, t.customer + (t.order ? ' · Bestellung #' + t.order : '') + (t.ts ? ' · ' + new Date(t.ts * 1000).toLocaleDateString('de-DE') : ''))
				),
				h('span', { class: 'tag ' + (t.status === 'geloest' ? 'now' : (t.status === 'offen' ? 'later' : '')) }, t.label)
			);
			var wrap = h('div', { style: 'margin-bottom:8px' }, head);
			if (!isOpen) { return wrap; }

			var body = h('div', { class: 'card', style: 'margin-top:6px' });
			body.appendChild(h('p', { style: 'white-space:pre-wrap;margin:0 0 12px;overflow-wrap:anywhere' }, t.message));

			var answer = h('textarea', { class: 'field', rows: '3', placeholder: 'Antwort an den Kunden …', 'aria-label': 'Antwort an den Kunden', style: 'padding:10px 12px;width:100%' });
			var send = h('button', { class: 'btn primary', type: 'button', onclick: function () { post(t, { do: 'antwort', text: answer.value }, send); } }, 'Antwort senden');
			body.appendChild(h('div', { style: 'display:grid;gap:8px;margin-bottom:12px' }, answer, h('div', null, send), h('small', { class: 'muted' }, 'Die Antwort geht per Mail an ' + (t.email || 'den Kunden') + '.')));

			var amount = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: 'Betrag in €', 'aria-label': 'Betrag in Euro', style: 'max-width:160px' });
			var why = h('input', { class: 'field', type: 'text', placeholder: 'Begründung', 'aria-label': 'Begründung' });
			var credit = h('button', { class: 'btn', type: 'button', onclick: function () {
				if (!window.confirm('Gutschrift von ' + amount.value + ' € auf das Guthaben des Kunden buchen?')) { return; }
				post(t, { do: 'gutschrift', betrag: amount.value, grund: why.value }, credit);
			} }, 'Gutschrift buchen');
			body.appendChild(h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px' }, amount, why, credit));

			var actions = h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px' });
			if (t.status !== 'in_bearbeitung') { actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (e) { post(t, { do: 'in_bearbeitung' }, e.target); } }, 'In Bearbeitung')); }
			if (t.status !== 'geloest') { actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (e) { post(t, { do: 'geloest' }, e.target); } }, 'Gelöst')); }
			body.appendChild(actions);
			wrap.appendChild(body);
			return wrap;
		}

		var lastData = null;

		function paint(d) {
			lastData = d;
			bar.textContent = '';
			bar.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': showAll ? 'false' : 'true', onclick: function () { showAll = false; load(); } }, 'Offen'));
			bar.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': showAll ? 'true' : 'false', onclick: function () { showAll = true; load(); } }, 'Alle'));
			box.textContent = '';
			if (!d.tickets.length) { box.appendChild(h('div', { class: 'card muted' }, showAll ? 'Noch keine Anfragen.' : 'Keine offenen Anfragen.')); return; }
			d.tickets.forEach(function (t) { box.appendChild(card(t)); });
		}

		function load() {
			return ui.api('support' + (showAll ? '?alle=1' : '')).then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
