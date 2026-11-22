/*
 * Beileger einstellen: was zu einer Bestellung in den Karton muss (Danke-Zettel,
 * Anleitungen, Montageset). Der Dialog öffnet auf der Seite Versand; die Regeln
 * zeigt später das Kommissionieren als Pflicht-Haken. Server: OrderInserts.php.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	UI.beilagenEditor = function (ui, onSaved) {
		var h = ui.h;
		var body = h('div', { class: 'run' }, h('div', { class: 'card' }, 'Wird geladen …'));
		var d = ui.dlg('Beileger einstellen', body, true);

		ui.api('beilagen').then(function (data) {
			var rules = data.rules.map(function (r) { return Object.assign({}, r); });
			var list = h('div', { style: 'display:grid;gap:10px' });
			var save = h('button', { class: 'btn primary', type: 'button' }, 'Speichern');
			var canEdit = data.can_edit;

			function row(r, i) {
				var name = h('input', { class: 'field', type: 'text', value: r.name, maxlength: '80', 'aria-label': 'Name des Beilegers', placeholder: 'z. B. Danke-Zettel', disabled: !canEdit });
				name.oninput = function () { r.name = name.value; };

				var wenn = h('select', { class: 'field', 'aria-label': 'Wann', disabled: !canEdit },
					Object.keys(data.conditions).map(function (k) { return h('option', { value: k, selected: k === r.wenn }, data.conditions[k]); }));
				var sku = h('input', { class: 'field', type: 'text', value: r.sku || '', 'aria-label': 'Artikelnummern, mit Komma getrennt', placeholder: 'Artikelnummer(n), mit Komma', style: r.wenn === 'sku' ? '' : 'display:none', disabled: !canEdit });
				sku.oninput = function () { r.sku = sku.value; };
				wenn.onchange = function () { r.wenn = wenn.value; sku.style.display = r.wenn === 'sku' ? '' : 'none'; };

				var menge = h('select', { class: 'field', 'aria-label': 'Menge', disabled: !canEdit },
					h('option', { value: 'einmal', selected: r.menge !== 'je_stueck' }, 'einmal je Bestellung'),
					h('option', { value: 'je_stueck', selected: r.menge === 'je_stueck' }, 'je Stück'));
				menge.onchange = function () { r.menge = menge.value; };

				function chk(label, key) {
					var c = h('input', { type: 'checkbox', checked: !!r[key], disabled: !canEdit });
					c.onchange = function () { r[key] = c.checked; };
					return h('label', { class: 'chk', style: 'display:flex;gap:8px;align-items:center' }, c, label);
				}

				return h('div', { class: 'card', style: 'display:grid;gap:8px' },
					name,
					h('div', { class: 'label-area' }, h('span', null, 'Wenn'), wenn, sku),
					h('div', { class: 'label-area' }, h('span', null, 'Menge'), menge),
					h('div', { class: 'label-area' },
						chk('Pflicht: muss beim Kommissionieren abgehakt werden', 'pflicht'),
						chk('Freunde-werben-Freunde-Code des Kunden dazu anzeigen', 'code')),
					canEdit ? h('div', null, h('button', { class: 'btn', type: 'button', style: 'color:var(--error)', onclick: function () { rules.splice(i, 1); paint(); } }, 'Entfernen')) : null);
			}

			function paint() {
				list.textContent = '';
				if (!rules.length) { list.appendChild(h('div', { class: 'muted' }, 'Keine Beileger. Beim Kommissionieren erscheint dann nichts zum Abhaken.')); }
				rules.forEach(function (r, i) { list.appendChild(row(r, i)); });
			}

			save.onclick = function () {
				save.disabled = true;
				ui.api('beilagen', { method: 'POST', body: { rules: rules } })
					.then(function (r) { ui.toast(r.message); if (onSaved) { onSaved(); } d.close(); })
					.catch(function (e) { save.disabled = false; ui.toast(e.message, true); });
			};

			body.textContent = '';
			body.appendChild(h('p', { class: 'muted', style: 'margin:0 0 10px' }, 'Hier legst du fest, was zu einer Bestellung in den Karton gehört. Beim Kommissionieren erscheint jede passende Zeile als Haken. Pflicht-Zeilen müssen abgehakt sein, sonst geht „Kommissioniert“ nicht.'));
			body.appendChild(list);
			if (canEdit) {
				body.appendChild(h('div', { class: 'ship-actions', style: 'margin-top:12px' },
					h('button', { class: 'btn', type: 'button', onclick: function () {
						rules.push({ id: 'neu' + Date.now().toString(36), name: '', wenn: 'immer', sku: '', menge: 'einmal', pflicht: true, code: false });
						paint();
					} }, '+ Beileger hinzufügen'),
					save));
			} else {
				body.appendChild(h('div', { class: 'notice' }, 'Ändern darfst du die Beileger nicht.'));
			}
			paint();
		}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice warn' }, e.message)); });
	};
})();
