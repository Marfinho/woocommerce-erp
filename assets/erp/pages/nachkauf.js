/*
 * ERP-Seite „Nachkauf“: wer bald wieder bestellt, wer wegzubleiben droht.
 * Schnittstelle: includes/Erp/Pages/Nachkauf.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['nachkauf'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { f: 'bald', art: '' };
		var body = h('div');
		var data = null;

		ui.pageHead('Nachkauf', 'Aus dem Bestellrhythmus jedes Händlers und Stammkunden: wer bald wieder bestellen dürfte – und wer seit zu langer Zeit fehlt.');
		ui.view().appendChild(body);

		var labels = { bald: 'bald fällig', ueberfaellig: 'überfällig', verloren: 'droht verloren', zeit: 'noch Zeit' };

		function mailDialog(r) {
			ui.api('nachkauf/' + r.key + '/mail').then(function (m) {
				var to = k.input(m.to, { type: 'email', required: '' });
				var subject = k.input(m.subject, { required: '' });
				var text = k.area(m.body, { rows: 12 });
				k.formDialog('Nachkauf: ' + r.name, [k.field('An', to), k.field('Betreff', subject), k.field('Text', text, m.b2b ? '„[Nachbestell-Link]“ wird beim Senden durch einen frischen, persönlichen Link ersetzt.' : null)], 'Senden', function () {
					return ui.api('nachkauf/' + r.key + '/senden', { method: 'POST', body: { to: to.value, subject: subject.value, body: text.value } });
				}, load, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function snoozeDialog(r) {
			var until = k.input('', { type: 'date' });
			k.formDialog('Ruhe: ' + r.name, [k.field('Bis', until, 'Leer = 30 Tage. Zum Beispiel, wenn der Kunde sich schon gemeldet hat.')], 'Ruhen lassen', function () {
				return ui.api('nachkauf/' + r.key + '/ruhe', { method: 'POST', body: { until: until.value } });
			}, load);
		}

		function rowCard(r) {
			var when = r.days_to >= 0 ? 'in ' + r.days_to + ' Tagen' : 'seit ' + (-r.days_to) + ' Tagen fällig';
			var tags = h('div', { class: 'row-tags' },
				k.tag(labels[r.state], r.state === 'bald' ? 'now' : (r.state === 'zeit' ? '' : 'later')),
				k.tag(r.b2b ? 'Händler/Firma' : 'Stammkunde'),
				k.tag('Sicherheit ' + Math.round(r.confidence * 100) + ' %'));
			if (r.mailed) { tags.appendChild(k.tag('angeschrieben ' + k.dmy(r.mailed))); }
			if (r.snooze) { tags.appendChild(k.tag('ruht bis ' + k.dmy(r.snooze))); }
			return h('article', { class: 'row-card kt-row' + (r.done ? ' kt-dim' : '') },
				h('div', { class: 'row-main' },
					h('b', null, r.name),
					h('small', null, r.orders + ' Bestellungen · alle ~' + r.interval + ' Tage (± ' + r.spread + ') · zuletzt ' + k.dmy(r.last) + ' · nächste ' + when),
					r.favourites.length ? h('small', { class: 'muted' }, 'Mag: ' + r.favourites.map(function (f) { return f.label; }).join(', ')) : null,
					tags),
				h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, k.eur(r.avg)), h('small', { class: 'muted' }, 'je Bestellung')),
				h('div', { class: 'kt-btns' },
					r.contact_id ? h('a', { class: 'btn', href: '#/kontakt/' + r.contact_id }, 'Akte') : null,
					h('button', { class: 'btn', type: 'button', onclick: function () { snoozeDialog(r); } }, 'Ruhe'),
					h('button', { class: 'btn' + (r.done ? '' : ' primary'), type: 'button', disabled: r.email ? null : true, onclick: function () { mailDialog(r); } }, 'Mail')));
		}

		function paint() {
			var d = data;
			body.textContent = '';
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Bald fällig', d.counts.bald, 'in den nächsten 14 Tagen'),
				k.tile('Überfällig', d.counts.ueberfaellig, 'länger als 1,5 Abstände'),
				k.tile('Droht verloren', d.counts.verloren, 'länger als 3 Abstände'),
				k.tile('Mit Rhythmus', d.rows.length)));
			var art = k.select([['', 'Alle'], ['b2b', 'Händler/Firmen'], ['b2c', 'Stammkunden']], state.art, { 'aria-label': 'Art' });
			art.addEventListener('change', function () { state.art = art.value; paint(); });
			body.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['bald', 'Bald fällig'], ['ueberfaellig', 'Überfällig'], ['verloren', 'Droht verloren'], ['alle', 'Alle']], state.f, function (f) { state.f = f; paint(); }),
				h('div', { class: 'kt-btns' }, art, h('button', { class: 'btn', type: 'button', onclick: function () { load(true); } }, 'Neu berechnen'))));
			var rows = d.rows.filter(function (r) {
				if (state.art === 'b2b' && !r.b2b) { return false; }
				if (state.art === 'b2c' && r.b2b) { return false; }
				return state.f === 'alle' || r.state === state.f;
			});
			var list = h('div', { class: 'rows' });
			if (!rows.length) { list.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Hier ist gerade niemand.' : 'Noch zu wenig Bestellungen: Ein Rhythmus braucht mindestens zwei Bestellungen je Kunde.')); }
			rows.forEach(function (r) { list.appendChild(rowCard(r)); });
			body.appendChild(list);
		}

		function load(fresh) {
			k.loading(body);
			ui.api('nachkauf' + (fresh ? '?neu=1' : '')).then(function (d) { data = d; paint(); }).catch(function (e) { k.fail(body, e); });
		}

		load(false);
	};
})();
