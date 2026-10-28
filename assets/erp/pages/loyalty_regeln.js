/*
 * Loyalty im ERP: Regeln (Punkte je Euro, Bonuspunkte je Auslöser) und
 * zeitlich begrenzte Aktionen. Die Seite selbst baut verkauf.js; hier stehen
 * die beiden Reiter. Server: includes/Erp/Pages/LoyaltyRegeln.php.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	var STATE = { laeuft: ['läuft', 'now'], geplant: ['geplant', ''], vorbei: ['vorbei', 'later'] };

	function fmtDate(ymd) {
		var p = String(ymd || '').split('-');
		return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : ymd;
	}

	function factorTxt(f) {
		f = Number(f) || 1;
		if (f === 2) { return 'doppelte Punkte'; }
		if (f === 3) { return 'dreifache Punkte'; }
		return String(f).replace('.', ',') + '-fache Punkte';
	}

	function fail(ui, box, e) {
		box.textContent = '';
		box.appendChild(ui.h('div', { class: 'notice warn' }, e.message));
	}

	/* ---------------- Regeln ---------------- */

	UI.loyaltyRules = function (box, ui) {
		var h = ui.h;
		box.textContent = '';
		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));

		ui.api('loyalty/regeln').then(function (d) {
			box.textContent = '';
			var inputs = {};
			var form = h('form', { class: 'lr-form', novalidate: true });

			if (!d.can_edit) {
				form.appendChild(h('div', { class: 'notice' }, 'Du kannst die Regeln ansehen. Ändern darf, wer das Recht „Loyalty-Regeln einstellen“ hat.'));
			}

			d.groups.forEach(function (g) {
				var card = h('fieldset', { class: 'card lr-group' }, h('legend', null, g.title));
				d.fields.filter(function (f) { return f.group === g.id; }).forEach(function (f) {
					var id = 'lr-' + f.key;
					var input;
					var off = f.group !== 'grund' && /punkte/.test(f.key);
					if (f.type === 'bool') {
						input = h('input', { type: 'checkbox', id: id, checked: !!Number(f.value), disabled: !d.can_edit });
						card.appendChild(h('label', { class: 'lr-check', for: id }, input, h('span', null, h('b', null, f.label), h('small', null, f.hint))));
					} else {
						input = h('input', {
							class: 'field', id: id, type: 'number', inputmode: f.type === 'int' ? 'numeric' : 'decimal',
							min: f.min, max: f.max, step: f.type === 'int' ? 1 : 0.1, value: String(f.value), disabled: !d.can_edit
						});
						card.appendChild(h('div', { class: 'lr-row' },
							h('label', { for: id }, h('b', null, f.label), h('small', null, f.hint + (off ? ' 0 = aus, Vorschlag: ' + f.suggest + '.' : ''))),
							input));
					}
					inputs[f.key] = { el: input, type: f.type };
				});
				if (g.id === 'stufen') { card.appendChild(tierEditor()); }
				form.appendChild(card);
			});

			// Stufen: Name, ab welchem Umsatz (12 Monate), Punkte-Faktor.
			var tierRows = [];
			function tierEditor() {
				var list = h('div', { class: 'lr-tiers' });
				var add = h('button', { class: 'btn', type: 'button', disabled: !d.can_edit }, 'Stufe hinzufügen');
				function paint() {
					list.textContent = '';
					list.appendChild(h('div', { class: 'lr-tier lr-tier-head', 'aria-hidden': 'true' }, h('span', null, 'Name'), h('span', null, 'ab € Umsatz in 12 Monaten'), h('span', null, 'Punkte ×'), h('span')));
					tierRows.forEach(function (row, i) {
						var del = h('button', { class: 'btn', type: 'button', 'aria-label': 'Stufe ' + row.name.value + ' entfernen', disabled: !d.can_edit || tierRows.length <= 2 || i === 0 }, '–');
						del.addEventListener('click', function () { tierRows.splice(i, 1); paint(); });
						list.appendChild(h('div', { class: 'lr-tier' }, row.name, row.ab, row.faktor, del));
					});
					add.disabled = !d.can_edit || tierRows.length >= 6;
				}
				function row(t, i) {
					return {
						name: h('input', { class: 'field', value: t.name, maxlength: 30, 'aria-label': 'Name der Stufe ' + (i + 1), disabled: !d.can_edit }),
						ab: h('input', { class: 'field', type: 'number', min: 0, step: 1, value: String(t.ab), 'aria-label': 'Umsatz ab, Stufe ' + (i + 1), disabled: !d.can_edit || i === 0 }),
						faktor: h('input', { class: 'field', type: 'number', min: 1, max: 3, step: 0.05, value: String(t.faktor), 'aria-label': 'Faktor, Stufe ' + (i + 1), disabled: !d.can_edit })
					};
				}
				(d.tiers || []).forEach(function (t, i) { tierRows.push(row(t, i)); });
				add.addEventListener('click', function () {
					var last = tierRows[tierRows.length - 1];
					tierRows.push(row({ name: '', ab: (Number(last.ab.value) || 0) + 100, faktor: last.faktor.value }, tierRows.length));
					paint();
				});
				paint();
				return h('div', { class: 'lr-tier-box' },
					h('p', { class: 'muted small' }, 'Zählt: abgeschlossene Bestellungen der letzten 12 Monate, abzüglich Erstattungen. Der Faktor gilt für Bestellpunkte (auch zusammen mit Aktionen).'),
					list, add);
			}

			if (d.can_edit) {
				var save = h('button', { class: 'btn primary', type: 'submit' }, 'Regeln speichern');
				form.appendChild(h('div', { class: 'lr-actions' }, h('span', { class: 'muted small' }, 'Änderungen gelten ab sofort, schon vergebene Punkte bleiben.'), save));
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					var body = {};
					Object.keys(inputs).forEach(function (k) {
						body[k] = inputs[k].type === 'bool' ? (inputs[k].el.checked ? 1 : 0) : inputs[k].el.value;
					});
					body.stufen = tierRows.map(function (r) { return { name: r.name.value, ab: r.ab.value, faktor: r.faktor.value }; });
					save.disabled = true;
					ui.api('loyalty/regeln', { method: 'POST', body: body })
						.then(function (r) { ui.toast(r.message); })
						.catch(function (err) { ui.toast(err.message, true); })
						.then(function () { save.disabled = false; });
				});
			}
			box.appendChild(form);
		}).catch(function (e) { fail(ui, box, e); });
	};

	/* ---------------- Aktionen ---------------- */

	UI.loyaltyCampaigns = function (box, ui) {
		var h = ui.h;
		box.textContent = '';
		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));

		function reload() { UI.loyaltyCampaigns(box, ui); }

		ui.api('loyalty/regeln').then(function (d) {
			box.textContent = '';
			var catName = {};
			d.categories.forEach(function (c) { catName[c.id] = c.name; });

			var head = h('div', { class: 'card lr-intro' },
				h('div', null,
					h('h3', null, 'Aktionen'),
					h('p', { class: 'muted' }, 'Zeitlich begrenzt mehr Punkte, z. B. doppelte Punkte am Wochenende oder auf eine Kategorie. Es zählt der Tag der Bestellung. Überschneiden sich Aktionen, gilt je Artikel die stärkste.')),
				d.can_edit ? h('button', { class: 'btn primary', type: 'button', onclick: function () { edit(null); } }, 'Neue Aktion') : null);
			box.appendChild(head);

			if (!d.campaigns.length) {
				box.appendChild(h('div', { class: 'notice' }, 'Noch keine Aktion. Kunden sehen eine laufende Aktion in ihrer Konto-Übersicht.'));
				return;
			}

			box.appendChild(h('div', { class: 'rows' }, d.campaigns.map(function (c) {
				var st = STATE[c.state] || ['', ''];
				var cats = (c.kategorien || []).map(function (id) { return catName[id] || ('Kategorie ' + id); });
				return h('div', { class: 'row-card lr-camp' },
					h('div', { class: 'row-main' },
						h('b', null, c.name),
						h('small', null, fmtDate(c.von) + ' – ' + fmtDate(c.bis) + ' · ' + (cats.length ? cats.join(', ') : 'ganzer Shop'))),
					h('div', { class: 'row-num' }, factorTxt(c.faktor)),
					h('div', { class: 'row-tags' },
						h('span', { class: 'tag ' + st[1] }, st[0]),
						d.can_edit ? h('button', { class: 'btn', type: 'button', onclick: function () { edit(c); } }, 'Bearbeiten') : null));
			})));

			function edit(c) {
				var isNew = !c;
				c = c || { name: '', von: d.today, bis: d.today, faktor: 2, kategorien: [] };
				var name = h('input', { class: 'field', id: 'lr-c-name', value: c.name, maxlength: 80, placeholder: 'z. B. Doppelte Punkte im Herbst' });
				var von = h('input', { class: 'field', id: 'lr-c-von', type: 'date', value: c.von });
				var bis = h('input', { class: 'field', id: 'lr-c-bis', type: 'date', value: c.bis });
				var faktor = h('select', { class: 'field', id: 'lr-c-faktor' });
				[1.5, 2, 3, 5].concat([1.5, 2, 3, 5].indexOf(Number(c.faktor)) > -1 ? [] : [Number(c.faktor)]).forEach(function (f) {
					var o = h('option', { value: String(f) }, factorTxt(f));
					if (Number(c.faktor) === f) { o.selected = true; }
					faktor.appendChild(o);
				});
				var checks = d.categories.map(function (cat) {
					var cb = h('input', { type: 'checkbox', value: String(cat.id), checked: (c.kategorien || []).indexOf(cat.id) > -1 });
					return h('label', { class: 'lr-cat' }, cb, h('span', null, cat.name));
				});

				var save = h('button', { class: 'btn primary', type: 'submit' }, isNew ? 'Aktion anlegen' : 'Speichern');
				var del = isNew ? null : h('button', { class: 'btn', type: 'button' }, 'Löschen');
				var form = h('form', { class: 'lr-dlg' },
					h('div', { class: 'lr-row' }, h('label', { for: 'lr-c-name' }, h('b', null, 'Name'), h('small', null, 'So steht es beim Kunden in der Übersicht.')), name),
					h('div', { class: 'lr-two' },
						h('div', { class: 'lr-row' }, h('label', { for: 'lr-c-von' }, h('b', null, 'Start')), von),
						h('div', { class: 'lr-row' }, h('label', { for: 'lr-c-bis' }, h('b', null, 'Ende (einschließlich)')), bis)),
					h('div', { class: 'lr-row' }, h('label', { for: 'lr-c-faktor' }, h('b', null, 'Wie viele Punkte?')), faktor),
					h('fieldset', { class: 'lr-cats' }, h('legend', null, 'Nur für Kategorien (keine Auswahl = ganzer Shop)'),
						checks.length ? h('div', { class: 'lr-cat-grid' }, checks) : h('p', { class: 'muted' }, 'Keine Produktkategorien gefunden.')),
					h('div', { class: 'lr-actions' }, del, save));
				var dialog = ui.dlg(isNew ? 'Neue Aktion' : 'Aktion bearbeiten', form);

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					save.disabled = true;
					var body = {
						id: isNew ? '' : c.id, name: name.value, von: von.value, bis: bis.value, faktor: faktor.value,
						kategorien: checks.map(function (l) { return l.querySelector('input'); }).filter(function (i) { return i.checked; }).map(function (i) { return parseInt(i.value, 10); })
					};
					ui.api('loyalty/aktionen', { method: 'POST', body: body })
						.then(function (r) { ui.toast(r.message); dialog.close(); reload(); })
						.catch(function (err) { save.disabled = false; ui.toast(err.message, true); });
				});
				if (del) {
					del.addEventListener('click', function () {
						if (!window.confirm('Aktion „' + c.name + '“ löschen? Schon vergebene Punkte bleiben.')) { return; }
						del.disabled = true;
						ui.api('loyalty/aktionen/' + c.id + '/loeschen', { method: 'POST', body: {} })
							.then(function (r) { ui.toast(r.message); dialog.close(); reload(); })
							.catch(function (err) { del.disabled = false; ui.toast(err.message, true); });
					});
				}
			}
		}).catch(function (e) { fail(ui, box, e); });
	};
})();
