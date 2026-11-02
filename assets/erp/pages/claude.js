/*
 * ERP-Seite „Claude-Zugang“ (nativ, Profilmenü): Schlüssel für den
 * MCP-Zugang anlegen und löschen, Anleitung für den täglichen Lagebericht.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['claude'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Claude-Zugang', 'Claude liest Shop und ERP und schreibt dir jeden Morgen einen Lagebericht mit Handlungsanweisungen (MCP-Server).');

		var box = h('div');
		ui.view().appendChild(box);

		function dt(ts) {
			return ts ? new Date(ts * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '–';
		}

		function copyField(label, value, hint) {
			var field = h('input', { class: 'field', type: 'text', readonly: 'readonly', value: value, style: 'font-family:monospace' });
			field.addEventListener('focus', function () { field.select(); });
			var btn = h('button', { class: 'btn', type: 'button' }, 'Kopieren');
			btn.addEventListener('click', function () {
				field.select();
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(value).then(function () { ui.toast('Kopiert'); }, function () { ui.toast('Kopieren ging nicht – bitte von Hand markieren.', true); });
				} else { ui.toast('Markiert – jetzt kopieren.'); }
			});
			return h('div', { style: 'margin:10px 0' },
				h('b', null, label),
				hint ? h('div', { class: 'muted' }, hint) : null,
				h('div', { style: 'display:flex;gap:8px;margin-top:4px' }, field, btn));
		}

		function shown(r) {
			ui.dlg('Neuer Schlüssel', h('div', null,
				h('div', { class: 'notice warn' }, 'Der Schlüssel wird nur jetzt angezeigt. Wie ein Administrator-Passwort behandeln.'),
				copyField('Adresse mit Schlüssel – für claude.ai', r.url_key, 'Einstellungen → Connectors → Eigenen Connector hinzufügen. OAuth-Felder leer lassen.'),
				copyField('Für Claude Code', r.cli, 'Schlüssel als Kopfzeile, die Adresse bleibt ohne Geheimnis.'),
				copyField('Nur der Schlüssel', r.token)), true);
		}

		function paint(d) {
			box.textContent = '';

			// Schlüssel.
			var tbl = h('table', { class: 'list' });
			tbl.appendChild(h('thead', null, h('tr', null,
				h('th', null, 'Bezeichnung'), h('th', null, 'Schlüssel'), h('th', null, 'Konto'), h('th', null, 'Darf'),
				h('th', null, 'Angelegt'), h('th', null, 'Zuletzt benutzt'), h('th'))));
			var tb = h('tbody');
			if (!d.tokens.length) { tb.appendChild(h('tr', null, h('td', { colspan: '7' }, 'Noch kein Schlüssel – der Zugang ist geschlossen.'))); }
			d.tokens.forEach(function (t) {
				var del = h('button', { class: 'btn', type: 'button' }, 'Löschen');
				del.addEventListener('click', function () {
					if (!window.confirm('Schlüssel „' + t.label + '“ löschen? Claude kommt damit sofort nicht mehr hinein.')) { return; }
					del.disabled = true;
					ui.api('claude/' + t.id + '/loeschen', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); load(); })
						.catch(function (e) { ui.toast(e.message, true); del.disabled = false; });
				});
				tb.appendChild(h('tr', null,
					h('td', null, t.label), h('td', null, h('code', null, t.hint)), h('td', null, t.konto),
					h('td', null, t.write ? 'lesen und ändern' : 'nur lesen'),
					h('td', null, dt(t.created)), h('td', null, dt(t.last_used)), h('td', null, del)));
			});
			tbl.appendChild(tb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', null, 'Schlüssel'),
				h('div', { style: 'overflow-x:auto' }, tbl)));

			// Neuer Schlüssel.
			var label = h('input', { class: 'field', type: 'text', maxlength: '80', placeholder: 'z. B. Claude Tagesbericht' });
			var write = h('input', { type: 'checkbox' });
			var add = h('button', { class: 'btn primary', type: 'submit' }, 'Schlüssel anlegen');
			var form = h('form', null,
				h('label', null, h('b', null, 'Bezeichnung'), label),
				h('label', { style: 'display:block;margin:10px 0' }, write, ' Darf auch ändern (Aufträge bestätigen, Status setzen, Bestände buchen …)'),
				h('p', { class: 'muted' }, 'Für den täglichen Bericht reicht „nur lesen“. Auch mit Änderungsrecht bleiben Rechte, Konten, Plugins und Einstellungen gesperrt; jede Änderung steht im Protokoll. Der Schlüssel arbeitet mit den Rechten deines Kontos (' + d.konto + ').'),
				add);
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				add.disabled = true;
				ui.api('claude', { method: 'POST', body: { label: label.value, write: write.checked } }).then(function (r) {
					shown(r);
					load();
				}).catch(function (err) { ui.toast(err.message, true); add.disabled = false; });
			});
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Neuer Schlüssel'), form));

			// Anleitung.
			var prompt = h('textarea', { class: 'field', readonly: 'readonly', rows: '12', style: 'font-family:monospace;width:100%' });
			prompt.value = d.prompt;
			box.appendChild(h('div', { class: 'card' },
				h('h3', null, 'Täglicher Lagebericht'),
				h('ol', null,
					h('li', null, 'Schlüssel „nur lesen“ anlegen und die Adresse mit Schlüssel kopieren.'),
					h('li', null, 'In claude.ai: Einstellungen → Connectors → „Eigenen Connector hinzufügen“, Name „Lindenweg16 ERP“, Adresse einfügen.'),
					h('li', null, 'Eine geplante Aufgabe anlegen (z. B. werktags 7:30 Uhr), den Auftrag unten einfügen und den Connector freigeben.')),
				h('p', null, h('b', null, 'Auftrag für Claude'), ' (auch als Vorlage „tagesbericht“ im Connector):'),
				prompt,
				h('p', { class: 'muted' }, 'Adresse ohne Schlüssel: ', h('code', null, d.url), '. Ausführlich: docs/claude-mcp.md im Plugin.')));
		}

		function load() {
			return ui.api('claude').then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
