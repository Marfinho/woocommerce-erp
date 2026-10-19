/*
 * Fertigung nativ: Druckjournal, Drucker & Wartung, Bambu-Lab-Drucker,
 * Qualität, Kapazität, Produktion. Server: includes/Erp/Pages/Produktion.php.
 * Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	/* ---------- Hilfen ---------- */

	function nf(n, d) {
		if (n === null || n === undefined || n === '') { return '–'; }
		return Number(n).toLocaleString('de-DE', { minimumFractionDigits: d || 0, maximumFractionDigits: d === undefined ? 1 : d });
	}

	function day(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
	}

	function errBox(box, e) {
		box.textContent = '';
		box.appendChild(UI.h('div', { class: 'notice warn' }, e.message));
	}

	function field(label, input, hint) {
		return UI.h('div', { class: 'pf' }, UI.h('label', null, label), input, hint ? UI.h('small', { class: 'muted' }, hint) : null);
	}

	function input(type, value, attrs) {
		var a = { class: 'field', type: type, value: value === null || value === undefined ? '' : String(value) };
		Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
		return UI.h('input', a);
	}

	function select(options, value, attrs) {
		var s = UI.h('select', Object.assign({ class: 'field' }, attrs || {}));
		options.forEach(function (o) {
			var opt = UI.h('option', { value: String(o[0]) }, o[1]);
			if (String(o[0]) === String(value)) { opt.selected = true; }
			s.appendChild(opt);
		});
		return s;
	}

	function pills(items, current, onpick) {
		var wrap = UI.h('div', { class: 'presets', style: 'margin-bottom:12px' });
		items.forEach(function (i) {
			wrap.appendChild(UI.h('button', { class: 'chip', type: 'button', 'aria-pressed': i[0] === current ? 'true' : 'false', onclick: function () { onpick(i[0]); } }, i[1]));
		});
		return wrap;
	}

	function tile(label, value, level) {
		return UI.h('div', { class: 'tile' }, UI.h('div', { class: 'lab' }, label), UI.h('div', { class: 'val' + (level ? ' pf-' + level : '') }, value));
	}

	function table(heads, rows, numCols) {
		var t = UI.h('table', { class: 'list' });
		var tr = UI.h('tr');
		heads.forEach(function (h, i) { tr.appendChild(UI.h('th', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : '' }, h)); });
		t.appendChild(UI.h('thead', null, tr));
		var body = UI.h('tbody');
		rows.forEach(function (r) {
			var row = UI.h('tr');
			r.forEach(function (c, i) { row.appendChild(UI.h('td', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : '' }, c)); });
			body.appendChild(row);
		});
		t.appendChild(body);
		return UI.h('div', { class: 'pf-scroll' }, t);
	}

	function levelTag(level, text) {
		return UI.h('span', { class: 'tag pf-tag-' + level }, text);
	}

	// Knopf, der sich während der Anfrage sperrt (kein doppeltes Absenden).
	function post(btn, path, body, ok) {
		btn.disabled = true;
		return UI.api(path, { method: 'POST', body: body || {} }).then(function (r) {
			btn.disabled = false;
			if (ok) { ok(r); }
			return r;
		}).catch(function (e) {
			btn.disabled = false;
			UI.toast(e.message, true);
		});
	}

	function head(item, title, sub) {
		UI.pageHead(title, sub, UI.classicBtn(item));
		UI.view().classList.add('broad');
	}

	function classicTab(item, query) {
		var c = UI.config;
		return item.path ? UI.h('a', { class: 'btn', href: c.adminUrl + item.path + query, target: '_blank', rel: 'noopener' }, UI.ico('ext'), 'Klassische Ansicht') : null;
	}

	/* ====================================================================
	 * Druckjournal
	 * ================================================================== */

	UI.pages['druckjournal'] = function (item) {
		head(item, 'Druckjournal', 'Jeder Druck mit guten Teilen und Ausschuss. Beim Erfassen werden Lagerzugang und Charge gebucht.');
		var view = UI.view();
		var filter = { von: '', bis: '', printer_id: '', article_id: '', ausschuss: false };
		var opts = null;
		var formBox = UI.h('div');
		var filterBox = UI.h('div');
		var listBox = UI.h('div');
		view.appendChild(formBox);
		view.appendChild(filterBox);
		view.appendChild(listBox);

		function query() {
			var q = [];
			['von', 'bis', 'printer_id', 'article_id'].forEach(function (k) { if (filter[k]) { q.push(k + '=' + encodeURIComponent(filter[k])); } });
			if (filter.ausschuss) { q.push('ausschuss=1'); }
			return q.length ? '?' + q.join('&') : '';
		}

		function presetRange(days) {
			var d = new Date();
			filter.bis = d.toISOString().slice(0, 10);
			d.setDate(d.getDate() - days);
			filter.von = d.toISOString().slice(0, 10);
		}

		function loadList() {
			listBox.textContent = '';
			listBox.appendChild(UI.h('div', { class: 'muted' }, 'Wird geladen …'));
			UI.api('druckjournal' + query()).then(paintList).catch(function (e) { errBox(listBox, e); });
		}

		function paintFilter() {
			filterBox.textContent = '';
			var pr = [['', 'alle Drucker']].concat(opts.printers.map(function (p) { return [p.id, p.code]; }));
			var ar = [['', 'alle Artikel']].concat(opts.articles.map(function (a) { return [a.id, a.sku]; }));
			var von = input('date', filter.von, { 'aria-label': 'Von' });
			var bis = input('date', filter.bis, { 'aria-label': 'Bis' });
			var sp = select(pr, filter.printer_id, { 'aria-label': 'Drucker' });
			var sa = select(ar, filter.article_id, { 'aria-label': 'Artikel' });
			var chk = UI.h('input', { type: 'checkbox', checked: filter.ausschuss });
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				filter.von = von.value; filter.bis = bis.value; filter.printer_id = sp.value; filter.article_id = sa.value; filter.ausschuss = chk.checked;
				loadList();
			} }, 'Filtern');
			var presets = UI.h('div', { class: 'presets' },
				UI.h('button', { class: 'chip', type: 'button', onclick: function () { presetRange(7); paintFilter(); loadList(); } }, 'letzte 7 Tage'),
				UI.h('button', { class: 'chip', type: 'button', onclick: function () { presetRange(30); paintFilter(); loadList(); } }, 'letzte 30 Tage'),
				UI.h('button', { class: 'chip', type: 'button', onclick: function () { filter.von = ''; filter.bis = ''; paintFilter(); loadList(); } }, 'alles'));
			filterBox.appendChild(UI.h('div', { class: 'card', style: 'margin:14px 0' },
				UI.h('div', { class: 'sec', style: 'margin-top:0' }, 'Journal filtern'),
				UI.h('div', { class: 'form-grid' }, field('Von', von), field('Bis', bis), field('Drucker', sp), field('Artikel', sa)),
				UI.h('div', { class: 'label-area', style: 'margin-top:10px' }, UI.h('label', { class: 'chk' }, chk, 'nur mit Ausschuss'), go),
				presets));
		}

		function paintList(d) {
			listBox.textContent = '';
			var s = d.summary;
			listBox.appendChild(UI.h('div', { class: 'grid tiles' },
				tile('Jobs', nf(s.jobs, 0)),
				tile('Teile i. O.', nf(s.ok, 0) + ' von ' + nf(s.planned, 0)),
				tile('Ausschuss', nf(s.rate, 1) + ' %', s.level),
				tile('Druckzeit', nf(s.hours, 1) + ' h')));

			var tools = UI.h('div', { class: 'label-area', style: 'margin:10px 0' });
			if (d.export_url) { tools.appendChild(UI.h('a', { class: 'btn', href: d.export_url }, UI.ico('download'), 'CSV-Export')); }
			tools.appendChild(UI.h('a', { class: 'btn', href: d.mobile_url, target: '_blank', rel: 'noopener' }, 'Mobile Schnellerfassung'));
			listBox.appendChild(tools);

			if (!d.jobs.length) { listBox.appendChild(UI.h('div', { class: 'notice' }, 'Keine Druckjobs für diese Auswahl.')); return; }
			if (d.total > d.jobs.length) { listBox.appendChild(UI.h('div', { class: 'notice', style: 'margin-bottom:10px' }, 'Gezeigt werden die neuesten ' + d.jobs.length + ' von ' + d.total + '. Zeitraum eingrenzen, um mehr zu sehen.')); }

			var rows = UI.h('div', { class: 'rows' });
			d.jobs.forEach(function (j) {
				var tags = UI.h('div', { class: 'row-tags' });
				if (j.voided) { tags.appendChild(UI.h('span', { class: 'tag later' }, 'storniert')); }
				else { tags.appendChild(levelTag(j.level, nf(j.scrap, 0) + ' Ausschuss · ' + nf(j.rate, 1) + ' %')); }
				if (j.reason && j.reason !== 'keine') { tags.appendChild(UI.h('span', { class: 'tag' }, j.reason)); }
				var card = UI.h('div', { class: 'row-card pj' + (j.voided ? ' pf-dim' : '') },
					UI.h('div', { class: 'row-main' },
						UI.h('b', null, j.sku + ' – ' + j.article),
						UI.h('small', null, day(j.date) + ' · ' + j.week + ' · ' + j.printer + ' · ' + j.color),
						UI.h('small', null, nf(j.ok, 0) + ' von ' + nf(j.planned, 0) + ' i. O. · ' + nf(j.hours, 2) + ' h · ' + nf(j.changes, 0) + ' Plattenwechsel (' + nf(j.per_change, 1) + ' Teile/Wechsel)'),
						j.note ? UI.h('small', null, 'Bemerkung: ' + j.note) : null,
						j.voided && j.void_reason ? UI.h('small', null, 'Storno: ' + j.void_reason) : null),
					tags,
					!j.voided && d.can_void ? UI.h('button', { class: 'btn', type: 'button', onclick: function () { voidDialog(j); } }, 'Stornieren') : null);
				rows.appendChild(card);
			});
			listBox.appendChild(rows);
		}

		function voidDialog(j) {
			var reason = input('text', '', { placeholder: 'Begründung (Pflicht)', 'aria-label': 'Begründung' });
			var dlg;
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				if (!reason.value.trim()) { UI.toast('Ein Storno braucht eine Begründung.', true); return; }
				post(go, 'druckjournal/' + j.id + '/stornieren', { reason: reason.value }, function (r) { dlg.close(); UI.toast(r.message); loadList(); });
			} }, 'Druckjob stornieren');
			dlg = UI.dlg('Druckjob stornieren', UI.h('div', { class: 'sheet-body' },
				UI.h('p', null, j.sku + ' · ' + day(j.date) + ' · ' + nf(j.ok, 0) + ' Teile. Der Lagerzugang wird gegengebucht, Kleinteile kommen zurück, die Charge wird storniert. Der Job selbst bleibt im Journal stehen.'),
				field('Begründung', reason), UI.h('div', { style: 'margin-top:12px' }, go)));
		}

		function paintForm() {
			formBox.textContent = '';
			if (!opts.printers.length || !opts.articles.length) {
				formBox.appendChild(UI.h('div', { class: 'notice warn' }, 'Vor dem ersten Druckjob braucht es mindestens einen Drucker (Seite „3D-Drucker & Wartung“) und einen druckbaren Artikel.'));
				return;
			}
			var open = UI.h('div', { hidden: true });
			var toggle = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () { open.hidden = !open.hidden; } }, UI.ico('check'), 'Druckjob erfassen / nachtragen');
			formBox.appendChild(UI.h('div', { style: 'margin-bottom:6px' }, toggle));
			formBox.appendChild(open);

			var f = {};
			f.order = select([['', '– ohne Druckauftrag –']].concat(opts.orders.map(function (o) { return [o.id, o.code]; })), '');
			f.date = input('date', opts.today);
			f.printer = select(opts.printers.map(function (p) { return [p.id, p.label]; }), opts.printers[0].id);
			f.article = select(opts.articles.map(function (a) { return [a.id, a.sku + ' – ' + a.name + (a.revision ? ' (' + a.revision + ')' : ' – keine aktive Revision')]; }), opts.articles[0].id);
			f.color = select(opts.colors.map(function (c) { return [c.slug, c.label]; }), opts.default_color);
			f.lot = select([['0', '– keine –']].concat(opts.lots.map(function (l) { return [l.id, l.code + ' – ' + l.material + ' ' + l.color]; })), '0');
			f.planned = input('text', '', { inputmode: 'decimal' });
			f.ok = input('text', '', { inputmode: 'decimal' });
			f.hours = input('text', '', { inputmode: 'decimal' });
			f.changes = input('text', '1', { inputmode: 'numeric' });
			f.failure = select(opts.failures.map(function (x) { return [x.key, x.label]; }), 'keine');
			f.note = UI.h('textarea', { class: 'field', rows: 2, style: 'padding:8px 12px' });

			f.order.addEventListener('change', function () {
				var o = opts.orders.filter(function (x) { return String(x.id) === f.order.value; })[0];
				if (!o) { return; }
				f.printer.value = String(o.printer_id); f.article.value = String(o.article_id);
				if (o.color) { f.color.value = o.color; }
				f.planned.value = String(o.qty_planned); f.ok.value = String(o.qty_planned);
			});

			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				post(go, 'druckjournal/erfassen', {
					work_order_id: f.order.value, job_date: f.date.value, printer_id: f.printer.value, article_id: f.article.value, color: f.color.value,
					filament_lot_id: f.lot.value, parts_planned: f.planned.value, parts_ok: f.ok.value, print_time_h: f.hours.value,
					plate_changes: f.changes.value, failure_reason: f.failure.value, note: f.note.value
				}, function (r) {
					UI.toast(r.message);
					f.planned.value = ''; f.ok.value = ''; f.hours.value = ''; f.note.value = ''; f.order.value = '';
					open.hidden = true;
					UI.api('druckjournal/optionen').then(function (o) { opts = o; paintForm(); });
					loadList();
				});
			} }, 'Druckjob buchen');

			open.appendChild(UI.h('div', { class: 'card' },
				UI.h('div', { class: 'form-grid' },
					opts.orders.length ? field('Aus Druckauftrag', f.order, 'Mit dem Buchen wird der Auftrag fertiggemeldet.') : null,
					field('Datum', f.date), field('Drucker', f.printer),
					field('Artikel', f.article, 'Die aktive Revision wird mitgeschrieben – sie bestimmt Gewicht und Druckzeit.'),
					field('Farbe', f.color), field('Filamentcharge', f.lot, 'Ohne Charge ist nicht rückverfolgbar, aus welchem Material ein Teil stammt.'),
					field('Teile geplant', f.planned), field('Teile in Ordnung', f.ok), field('Druckzeit (h)', f.hours), field('Plattenwechsel', f.changes),
					field('Fehlerursache', f.failure, 'Bei Ausschuss Pflicht – sonst lässt sich die Quote nicht auswerten.'),
					field('Bemerkung', f.note)),
				UI.h('div', { style: 'margin-top:12px' }, go)));
		}

		UI.api('druckjournal/optionen').then(function (o) {
			opts = o;
			paintForm();
			paintFilter();
			loadList();
		}).catch(function (e) { errBox(listBox, e); });
	};

	/* ====================================================================
	 * Drucker & Wartung
	 * ================================================================== */

	UI.pages['drucker'] = function (item) {
		head(item, '3D-Drucker & Wartung', 'Geräte mit Stundensatz, Betriebsstunden und Wartung. Der Stundensatz wird gerechnet, nie von Hand gesetzt.');
		var box = UI.h('div');
		UI.view().appendChild(box);
		var data = null;

		function load() {
			return UI.api('geraete').then(function (d) { data = d; paint(); }).catch(function (e) { errBox(box, e); });
		}

		function paint() {
			box.textContent = '';
			var due = data.devices.filter(function (x) { return x.reasons.length; });
			var top = UI.h('div', { class: 'label-area', style: 'margin-bottom:12px' });
			if (data.can_edit) { top.appendChild(UI.h('button', { class: 'btn primary', type: 'button', onclick: function () { editDialog(null); } }, 'Gerät hinzufügen')); }
			if (data.devices.length) { top.appendChild(UI.h('button', { class: 'btn', type: 'button', onclick: function () { serviceDialog(data.devices[0].id); } }, 'Wartung erfassen')); }
			if (data.labels_url) { top.appendChild(UI.h('a', { class: 'btn', href: data.labels_url }, UI.ico('download'), 'Aufkleber als PDF')); }
			box.appendChild(top);

			if (!data.devices.length) {
				box.appendChild(UI.h('div', { class: 'notice warn' }, 'Noch keine Geräte erfasst. Solange hier nichts steht, fehlt in jeder Kalkulation die Abschreibung.'));
				return;
			}

			box.appendChild(due.length
				? UI.h('div', { class: 'notice warn', style: 'margin-bottom:12px' }, UI.h('b', null, 'Wartung fällig'), UI.h('ul', { class: 'pf-list' }, due.map(function (x) {
					return UI.h('li', null, UI.h('b', null, x.code + ': '), x.reasons.join('; '));
				})))
				: UI.h('div', { class: 'notice', style: 'margin-bottom:12px' }, 'Keine Wartung fällig.'));

			var rows = UI.h('div', { class: 'rows' });
			data.devices.forEach(function (x) {
				var tags = UI.h('div', { class: 'row-tags' }, UI.h('span', { class: 'tag ' + (x.productive ? 'now' : 'later') }, x.status_text));
				if (x.reasons.length) { tags.appendChild(UI.h('span', { class: 'tag later' }, x.reasons.length + ' Wartungshinweis' + (x.reasons.length > 1 ? 'e' : ''))); }
				rows.appendChild(UI.h('div', { class: 'row-card pj' },
					UI.h('div', { class: 'row-main' },
						UI.h('b', null, x.code + ' · ' + (x.manufacturer + ' ' + x.model).trim()),
						UI.h('small', null, nf(x.hours, 1) + ' Betriebsstunden · ' + nf(x.nozzle_hours, 0) + ' h seit Düsenwechsel · Anschaffung ' + (x.cost === null ? '–' : UI.money(x.cost, 'EUR'))),
						UI.h('small', null, 'Satz ' + nf(x.rate.summe, 4) + ' €/h (Abschreibung ' + nf(x.rate.abschreibung, 4) + ', Verschleiß ' + nf(x.rate.verschleiss, 4) + ', Energie ' + nf(x.rate.energie, 4) + ', Fläche ' + nf(x.rate.flaeche, 4) + ')')),
					tags,
					UI.h('div', { class: 'label-area' },
						UI.h('button', { class: 'btn', type: 'button', onclick: function () { serviceDialog(x.id); } }, 'Wartung'),
						UI.h('a', { class: 'btn', href: '#/drucker/' + x.id }, 'Akte'),
						data.can_edit ? UI.h('button', { class: 'btn', type: 'button', onclick: function () { editDialog(x.id); } }, 'Bearbeiten') : null)));
			});
			box.appendChild(rows);
			box.appendChild(UI.h('p', { class: 'muted', style: 'margin-top:12px' }, 'Flottenmittel für die Artikelkalkulation: ' + nf(data.fleet.rate, 4) + ' €/h aus ' + data.fleet.count + ' produzierenden Geräten (in Betrieb oder Wartung).'));
		}

		function editDialog(id) {
			(id ? UI.api('geraete/' + id) : Promise.resolve({ device: { status: 'betrieb' }, history: [] })).then(function (r) {
				var d = r.device;
				var f = {};
				var dlg;
				var def = data.defaults;
				function t(k, attrs) { return input('text', d[k] || '', Object.assign({ inputmode: 'decimal' }, attrs || {})); }
				f.code = input('text', d.code || '', { maxlength: 20 });
				f.manufacturer = input('text', d.manufacturer || '');
				f.model = input('text', d.model || '');
				f.serial = input('text', d.serial || '');
				f.status = select(data.statuses.map(function (s) { return [s.key, s.label]; }), d.status || 'betrieb');
				f.acquired_on = input('date', d.acquired_on || '');
				f.acquisition_cost = t('acquisition_cost');
				f.lifetime_h = t('lifetime_h');
				f.power_cost_per_h = t('power_cost_per_h');
				f.power_kw = t('power_kw');
				f.spare_parts_per_year = t('spare_parts_per_year');
				f.annual_operating_hours = t('annual_operating_hours');
				f.nozzle_mm = t('nozzle_mm');
				f.hours_at_last_nozzle_change = t('hours_at_last_nozzle_change');
				f.notes = UI.h('textarea', { class: 'field', rows: 3, style: 'padding:8px 12px' }, d.notes || '');
				var reason = input('text', '', { placeholder: 'erscheint im Protokoll' });

				var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
					var body = {};
					Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
					if (id) { body.reason = reason.value; }
					post(go, id ? 'geraete/' + id : 'geraete', body, function (res) { dlg.close(); UI.toast(res.message); load(); });
				} }, id ? 'Änderungen speichern' : 'Gerät anlegen');

				dlg = UI.dlg(id ? 'Gerät bearbeiten' : 'Gerät hinzufügen', UI.h('div', { class: 'sheet-body' },
					UI.h('p', { class: 'muted' }, 'Leer gelassene Felder greifen auf die globale Annahme zurück.'),
					UI.h('div', { class: 'form-grid' },
						field('Gerätekürzel', f.code, 'Pflicht, eindeutig, z. B. P1. Steht auf dem QR-Aufkleber.'),
						field('Hersteller', f.manufacturer), field('Typ', f.model), field('Seriennummer', f.serial),
						field('Status', f.status, 'Nur Geräte in Betrieb oder Wartung gehen in den Flottensatz ein.'),
						field('Angeschafft am', f.acquired_on), field('Anschaffungspreis netto (€)', f.acquisition_cost, 'Grundlage der Abschreibung.'),
						field('Lebensdauer (Betriebsstunden)', f.lifetime_h, 'Leer = globale Annahme (' + nf(def.lifetime_h, 0) + ' h).'),
						field('Stromkosten (€/h)', f.power_cost_per_h, 'Leer = aus Leistung × Strompreis (derzeit ' + nf(def.power_cost_per_h, 4) + ' €/h).'),
						field('Leistungsaufnahme (kW)', f.power_kw, 'Wird ignoriert, wenn oben ein Eurobetrag steht.'),
						field('Ersatzteile (€/Jahr)', f.spare_parts_per_year, 'Leer = globale Annahme (' + nf(def.spare_parts_per_year, 2) + ' €).'),
						field('Betriebsstunden je Jahr', f.annual_operating_hours, 'Leer = globale Annahme (' + nf(def.annual_operating_hours, 0) + ' h). Nicht 0.'),
						field('Düsendurchmesser (mm)', f.nozzle_mm),
						field('Betriebsstunden beim letzten Düsenwechsel', f.hours_at_last_nozzle_change, 'Grundlage der Fälligkeit (Düsentausch nach 500–800 h).'),
						field('Bemerkung', f.notes), id ? field('Begründung der Änderung', reason) : null),
					UI.h('div', { style: 'margin-top:12px' }, go)), true);
			}).catch(function (e) { UI.toast(e.message, true); });
		}

		function serviceDialog(preId) {
			var dlg;
			var sel = select(data.devices.map(function (x) { return [x.id, x.code]; }), preId);
			var kind = select(data.checklists.map(function (c) { return [c.key, c.label]; }), 'woechentlich');
			var date = input('date', data.today);
			var part = input('text', '', { placeholder: 'Ersatzteil' });
			var noz = UI.h('input', { type: 'checkbox' });
			var note = UI.h('textarea', { class: 'field', rows: 2, style: 'padding:8px 12px' });
			var listBox = UI.h('div');
			var status = UI.h('small', { class: 'muted' });
			var checks = {};

			function current() { return data.checklists.filter(function (c) { return c.key === kind.value; })[0]; }
			function count() {
				var c = current();
				if (!c.items.length) { status.textContent = 'Anlassbezogen – hier gibt es keine feste Liste. Was gemacht wurde, gehört ins Feld Ersatzteil oder in die Notiz.'; return; }
				var n = c.items.filter(function (i) { return checks[i.key] && checks[i.key].checked; }).length;
				status.textContent = n === c.items.length ? 'Alle Punkte erledigt.' : n + ' von ' + c.items.length + ' Punkten erledigt.';
			}
			function paintList() {
				listBox.textContent = '';
				checks = {};
				var c = current();
				if (!c.items.length) { count(); return; }
				if (c.includes) { listBox.appendChild(UI.h('p', { class: 'muted' }, 'Enthält die Punkte der kürzeren Intervalle: Wer die Achsen schmiert, saugt vorher aus.')); }
				var wrap = UI.h('div', { class: 'pick-list' });
				c.items.forEach(function (i) {
					var cb = UI.h('input', { type: 'checkbox', onchange: count });
					checks[i.key] = cb;
					wrap.appendChild(UI.h('label', { class: 'pick-line' }, cb, UI.h('span', null, i.text)));
				});
				listBox.appendChild(wrap);
				count();
			}
			kind.addEventListener('change', paintList);

			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				var tasks = Object.keys(checks).filter(function (k) { return checks[k].checked; });
				post(go, 'geraete/' + sel.value + '/wartung', { interval_type: kind.value, performed_on: date.value, part_replaced: part.value, nozzle_changed: noz.checked, note: note.value, tasks: tasks }, function (r) {
					dlg.close(); UI.toast(r.message); load();
				});
			} }, 'Wartung erfassen');

			paintList();
			dlg = UI.dlg('Wartung erfassen', UI.h('div', { class: 'sheet-body' },
				UI.h('div', { class: 'form-grid' }, field('Gerät', sel), field('Intervall', kind), field('Datum', date), field('Ersatzteil', part)),
				UI.h('label', { class: 'chk', style: 'margin:10px 0' }, noz, 'Düse gewechselt (setzt den Zähler zurück)'),
				listBox, field('Notiz', note), UI.h('div', { style: 'margin-top:12px' }, go, ' ', status)), true);
		}

		load();
	};

	/* ====================================================================
	 * Bambu-Lab-Drucker
	 * ================================================================== */

	UI.pages['bambu'] = function (item) {
		head(item, 'Bambu-Lab-Drucker', 'Live-Stand der angebundenen Drucker und ihre Verbindung zum Gerätestamm.');
		var box = UI.h('div');
		UI.view().appendChild(box);
		UI.view().appendChild(UI.h('div', { class: 'notice', style: 'margin-top:14px' },
			'Druckaufträge einreihen, Platten anordnen und Slicing-Profile gibt es noch in der klassischen Ansicht: ',
			classicTab(item, '&tab=auftraege'), ' ', classicTab(item, '&tab=platte'), ' ', classicTab(item, '&tab=slicing')));
		var data = null;
		var token = null;

		function load() {
			return UI.api('bambu/drucker').then(function (d) { data = d; paint(); }).catch(function (e) { errBox(box, e); });
		}

		function stateText(p) {
			if (p.state === 'offline') { return p.last_seen ? 'Bridge offline (' + UI.ago(p.last_seen, data.now) + ')' : 'Bridge noch nie gemeldet'; }
			return p.status_text || 'online';
		}

		function paint() {
			box.textContent = '';
			if (token) {
				box.appendChild(UI.h('div', { class: 'notice warn', style: 'margin-bottom:12px' },
					UI.h('b', null, 'Token für ' + token.name + ' – wird nur jetzt angezeigt.'),
					UI.h('p', null, 'Jetzt in die .env der Bambu-Bridge auf dem Raspberry Pi eintragen. Nach dem Neuladen ist es nicht mehr abrufbar; dann hilft nur „Token neu erzeugen“.'),
					UI.h('code', { style: 'user-select:all;word-break:break-all;display:block' }, token.value),
					UI.h('button', { class: 'btn', type: 'button', style: 'margin-top:8px', onclick: function () { token = null; paint(); } }, 'Verstanden, ausblenden')));
			}
			if (data.manage) {
				box.appendChild(UI.h('div', { style: 'margin-bottom:12px' }, UI.h('button', { class: 'btn primary', type: 'button', onclick: function () { addDialog(); } }, 'Bambu-Drucker anlegen')));
			}
			if (!data.printers.length) { box.appendChild(UI.h('div', { class: 'notice' }, 'Noch kein Bambu-Drucker angelegt.')); return; }

			var grid = UI.h('div', { class: 'pf-grid' });
			data.printers.forEach(function (p) {
				var card = UI.h('article', { class: 'card pf-card' },
					UI.h('header', { style: 'display:flex;justify-content:space-between;gap:8px;align-items:center' },
						UI.h('h3', { style: 'margin:0' }, p.name),
						UI.h('span', { class: 'tag ' + (p.state === 'ready' || p.state === 'busy' ? 'now' : 'later') }, p.state === 'busy' ? 'druckt' : (p.state === 'ready' ? 'online' : (p.state === 'offline' ? 'offline' : 'Achtung')))),
					UI.h('small', { class: 'muted' }, p.printer_id + (p.model ? ' · ' + p.model : '')),
					UI.h('div', null, stateText(p)));

				if (p.progress !== null) {
					card.appendChild(UI.h('div', { class: 'bar', role: 'progressbar', 'aria-valuenow': p.progress, 'aria-valuemin': 0, 'aria-valuemax': 100 }, UI.h('i', { style: 'width:' + p.progress + '%' })));
					card.appendChild(UI.h('small', null, p.progress + ' %' + (p.remaining !== null ? ' · noch ' + p.remaining + ' min' : '')));
				}
				if (p.nozzle_temp !== null || p.bed_temp !== null) { card.appendChild(UI.h('small', { class: 'muted' }, 'Düse ' + nf(p.nozzle_temp, 0) + ' °C · Bett ' + nf(p.bed_temp, 0) + ' °C')); }
				if (p.ams.length) {
					card.appendChild(UI.h('ul', { class: 'pf-list' }, p.ams.map(function (a) {
						return UI.h('li', null, UI.h('span', { class: 'pf-swatch', style: 'background:#' + a.color }), 'AMS ' + a.unit + ' Fach ' + a.tray + ': ' + a.material + (a.remain !== null ? ' (' + a.remain + ' %)' : ''));
					})));
				}
				if (p.last_error) { card.appendChild(UI.h('div', { class: 'notice warn' }, 'Letzter Fehler: ' + p.last_error)); }
				if (p.snapshot) { card.appendChild(UI.h('img', { src: p.snapshot, alt: 'Kamera-Standbild von ' + p.name, class: 'pf-snap' })); }
				card.appendChild(UI.h('small', { class: 'muted' }, 'Wartende Aufträge: ' + p.queued + (p.bed ? ' · Druckbett ' + p.bed : '')));

				// Die Verknüpfung entscheidet, ob Meldungen im Journal ankommen.
				if (p.link === 'ok') { card.appendChild(UI.h('div', null, UI.h('span', { class: 'tag now' }, 'Gerät ' + p.device))); }
				else if (p.link === 'missing') { card.appendChild(UI.h('div', { class: 'notice warn' }, 'Das verknüpfte Gerät gibt es nicht mehr. Bitte neu verknüpfen.')); }
				else { card.appendChild(UI.h('div', { class: 'notice warn' }, 'Nicht mit einem Gerät verknüpft: Es lässt sich keine Platte auf diesem Drucker einplanen, und Ist-Meldungen landen nicht im Druckjournal.')); }
				if (p.link_double) { card.appendChild(UI.h('div', { class: 'notice warn' }, 'Dasselbe Gerät ist noch mit einem weiteren Bambu-Drucker verknüpft. Das führt zu vermischten Journaleinträgen.')); }

				if (data.manage) {
					card.appendChild(UI.h('div', { class: 'label-area' },
						UI.h('button', { class: 'btn', type: 'button', onclick: function () { editDialog(p); } }, 'Verbindung bearbeiten'),
						UI.h('button', { class: 'btn', type: 'button', onclick: function () { regen(p, this); } }, 'Token neu erzeugen'),
						UI.h('button', { class: 'btn', type: 'button', onclick: function () { del(p, this); } }, 'Löschen')));
				}
				grid.appendChild(card);
			});
			box.appendChild(grid);
		}

		function deviceSelect(value) {
			return select([['0', '– keine Verknüpfung –']].concat(data.devices.map(function (d) { return [d.id, d.label]; })), value || 0);
		}

		function addDialog() {
			var dlg;
			var f = { printer_id: input('text', '', { placeholder: 'bambu_p1s_1', autocapitalize: 'off' }), name: input('text', '', { placeholder: 'P1S Werkstatt' }), ip: input('text', '', { placeholder: '192.168.1.50' }), access_code: input('text', '', { autocomplete: 'off' }), bambu_serial: input('text', '', { placeholder: '01P00A000000000' }), geraet_id: deviceSelect(0) };
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				var body = {};
				Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
				post(go, 'bambu/drucker', body, function (r) {
					dlg.close(); UI.toast(r.message);
					token = { name: body.name, value: r.token };
					load();
				});
			} }, 'Drucker anlegen');
			dlg = UI.dlg('Bambu-Drucker anlegen', UI.h('div', { class: 'sheet-body' },
				UI.h('p', { class: 'muted' }, 'IP-Adresse und LAN-Zugangscode stehen am Drucker unter Einstellungen → LAN-Modus (derselbe Netz wie der Raspberry Pi mit der Bridge). Die Seriennummer steht darunter.'),
				UI.h('div', { class: 'form-grid' },
					field('Drucker-ID', f.printer_id, 'Schlüssel, den die Bridge als PRINTER_ID einträgt. Kleinbuchstaben, Ziffern, _ und -.'),
					field('Name', f.name), field('IP-Adresse', f.ip), field('LAN-Zugangscode', f.access_code), field('Bambu-Seriennummer', f.bambu_serial),
					field('Verknüpftes Gerät (Kalkulation)', f.geraet_id, 'Wichtig: Ohne Verknüpfung landen Ist-Meldungen nicht im Druckjournal.')),
				UI.h('div', { style: 'margin-top:12px' }, go)));
		}

		function editDialog(p) {
			var dlg;
			var code = input('password', '', { autocomplete: 'new-password', placeholder: p.has_code ? 'hinterlegt – zum Ändern neu eintragen' : 'nicht hinterlegt' });
			var clear = UI.h('input', { type: 'checkbox' });
			var f = { name: input('text', p.name), ip: input('text', p.ip), bambu_serial: input('text', p.serial), printer_model: input('text', p.model, { placeholder: 'z. B. X1C' }), bed_width_mm: input('number', p.bed_width_mm, { min: 1 }), bed_height_mm: input('number', p.bed_height_mm, { min: 1 }), geraet_id: deviceSelect(p.geraet_id) };
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				var body = { access_code: code.value, access_code_loeschen: clear.checked };
				Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
				post(go, 'bambu/drucker/' + p.id, body, function (r) { dlg.close(); UI.toast(r.message); load(); });
			} }, 'Speichern');
			dlg = UI.dlg('Verbindung: ' + p.name, UI.h('div', { class: 'sheet-body' },
				UI.h('div', { class: 'form-grid' },
					field('Name', f.name), field('IP', f.ip), field('Zugangscode', code, 'Der hinterlegte Code wird nie angezeigt. Leer lassen = unverändert.'),
					p.has_code ? UI.h('label', { class: 'chk' }, clear, 'Hinterlegten Zugangscode entfernen') : null,
					field('Seriennummer', f.bambu_serial), field('Druckermodell', f.printer_model, 'Leer = wartet auf die Meldung der Bridge.'),
					field('Druckbett Breite (mm)', f.bed_width_mm), field('Druckbett Höhe (mm)', f.bed_height_mm, 'Beide leer = automatisch nach Modell.'),
					field('Verknüpftes Gerät', f.geraet_id, 'Ohne Verknüpfung landen Ist-Meldungen nicht im Druckjournal.')),
				UI.h('div', { style: 'margin-top:12px' }, go)));
		}

		function regen(p, btn) {
			if (!window.confirm('Neues Token erzeugen? Das alte wird sofort ungültig – die Bridge muss neu konfiguriert werden.')) { return; }
			post(btn, 'bambu/drucker/' + p.id + '/token', {}, function (r) { UI.toast(r.message); token = { name: p.name, value: r.token }; load(); });
		}

		function del(p, btn) {
			if (!window.confirm('Diesen Drucker wirklich löschen? Alle seine Druckaufträge werden mitgelöscht.')) { return; }
			post(btn, 'bambu/drucker/' + p.id + '/loeschen', {}, function (r) { UI.toast(r.message); load(); });
		}

		load();
		UI.every(function () { if (!document.querySelector('dialog[open]')) { load(); } }, 10000);
	};

	/* ====================================================================
	 * Qualität
	 * ================================================================== */

	UI.pages['qualitaet'] = function (item) {
		head(item, 'Qualität', 'Chargenbuch und Rückverfolgung: Jeder Druckjob mit guten Teilen erzeugt eine Charge, deren Code ins Bauteil geprägt wird.');
		var tab = 'chargen';
		var tabs = UI.h('div');
		var box = UI.h('div');
		UI.view().appendChild(tabs);
		UI.view().appendChild(box);

		function setTab(t) { tab = t; draw(); }

		function draw() {
			tabs.textContent = '';
			tabs.appendChild(pills([['chargen', 'Chargenbuch'], ['rueck', 'Rückverfolgung']], tab, setTab));
			box.textContent = '';
			if (tab === 'chargen') { batches(); } else { trace(0); }
		}

		function orderTable(orders) {
			if (!orders.length) { return UI.h('p', { class: 'muted' }, 'Keine betroffenen Bestellungen.'); }
			return table(['Herkunft', 'Datum', 'Menge'], orders.map(function (o) { return [o.source + ' #' + o.ref, day(o.date), nf(o.qty, 0)]; }), [2]);
		}

		function batches() {
			UI.api('qualitaet/chargen').then(function (d) {
				if (!d.batches.length) { box.appendChild(UI.h('div', { class: 'notice' }, 'Noch keine Chargen.')); return; }
				var rows = UI.h('div', { class: 'rows' });
				d.batches.forEach(function (b) {
					rows.appendChild(UI.h('div', { class: 'row-card pj' },
						UI.h('div', { class: 'row-main' }, UI.h('b', { style: 'font-family:var(--font-mono)' }, b.code), UI.h('small', null, day(b.date) + ' · ' + b.sku + ' – ' + b.article + ' · ' + nf(b.qty, 0) + ' Stück')),
						UI.h('div', { class: 'row-tags' }, UI.h('span', { class: 'tag ' + (b.inspected ? 'now' : '') }, b.inspected ? 'geprüft' : 'nicht geprüft')),
						UI.h('button', { class: 'btn', type: 'button', onclick: function () { dossier(b.id); } }, 'Akte')));
				});
				box.appendChild(rows);
			}).catch(function (e) { errBox(box, e); });
		}

		function dossier(id) {
			UI.api('qualitaet/chargen/' + id).then(function (d) {
				var body = UI.h('div', { class: 'sheet-body' },
					UI.h('p', { style: 'font:700 28px var(--font-mono);margin:0' }, d.code),
					UI.h('p', { class: 'muted' }, 'Diesen Code ins Bauteil prägen und in den Slicer übernehmen.'),
					UI.h('div', { class: 'kv' },
						UI.h('span', null, 'Artikel'), UI.h('b', null, d.article), UI.h('span', null, 'Revision'), UI.h('b', null, d.revision || '–'),
						UI.h('span', null, 'Material'), UI.h('b', null, d.material || '–'), UI.h('span', null, 'Filamentcharge'), UI.h('b', null, d.lot || '–'),
						UI.h('span', null, 'Drucker'), UI.h('b', null, d.printer || '–'), UI.h('span', null, 'Stückzahl'), UI.h('b', null, nf(d.qty, 0)),
						UI.h('span', null, 'Produziert'), UI.h('b', null, day(d.date))),
					d.gpsr_url ? UI.h('p', null, UI.h('a', { class: 'btn primary', href: d.gpsr_url }, UI.ico('download'), 'GPSR-Nachweis als PDF')) : null,
					UI.h('div', { class: 'sec' }, 'Betroffene Bestellungen (' + d.orders.length + ')'), orderTable(d.orders));
				UI.dlg('Chargenakte', body, true);
			}).catch(function (e) { UI.toast(e.message, true); });
		}

		function trace(lot) {
			UI.api('qualitaet/rueckverfolgung' + (lot ? '?lot=' + lot : '')).then(function (d) {
				box.textContent = '';
				var sel = select([['0', '– Filamentcharge wählen –']].concat(d.lots.map(function (l) { return [l.id, l.code + ' – ' + l.material]; })), lot, { 'aria-label': 'Filamentcharge' });
				box.appendChild(UI.h('p', { class: 'muted' }, 'Von einer Filamentcharge zu allen betroffenen Chargen und Bestellungen.'));
				box.appendChild(UI.h('div', { class: 'label-area', style: 'margin-bottom:12px' }, sel, UI.h('button', { class: 'btn primary', type: 'button', onclick: function () { trace(parseInt(sel.value, 10) || 0); } }, 'Verfolgen')));
				if (!d.trace) { return; }
				var t = d.trace;
				box.appendChild(UI.h('div', { class: 'card' },
					UI.h('h3', { style: 'margin-top:0' }, 'Aus Filamentcharge ' + t.lot + ' entstanden'),
					UI.h('p', null, t.jobs + ' Druckjobs, ' + t.batches.length + ' Chargen, ' + t.orders.length + ' betroffene Bestellungen.'),
					t.batches.length ? table(['Chargencode', 'Produziert', 'Artikel', 'Stück'], t.batches.map(function (b) { return [b.code, day(b.date), b.sku, nf(b.qty, 0)]; }), [3]) : null,
					UI.h('div', { class: 'sec' }, 'Betroffene Bestellungen'), orderTable(t.orders)));
			}).catch(function (e) { errBox(box, e); });
		}

		draw();
	};

	/* ====================================================================
	 * Kapazität
	 * ================================================================== */

	UI.pages['kapazitaet'] = function (item) {
		head(item, 'Kapazität', 'Wie ausgelastet waren die Drucker, und wie viele Bestellungen schaffst du diese Woche?');
		var box = UI.h('div');
		UI.view().appendChild(box);

		UI.api('kapazitaet').then(function (d) {
			var inputs = {};
			var result = UI.h('div');
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				var mengen = {};
				Object.keys(inputs).forEach(function (id) { if (parseFloat(String(inputs[id].value).replace(',', '.')) > 0) { mengen[id] = inputs[id].value; } });
				result.textContent = '';
				go.disabled = true;
				UI.api('kapazitaet/rechnen', { method: 'POST', body: { mengen: mengen } }).then(function (r) { go.disabled = false; paintResult(result, r); }).catch(function (e) { go.disabled = false; errBox(result, e); });
			} }, 'Rechnen');

			var list = UI.h('div', { class: 'pf-qty' });
			d.articles.forEach(function (a) {
				inputs[a.id] = input('number', '', { min: 0, step: 1, inputmode: 'numeric', placeholder: '0', 'aria-label': 'Erwartete Stückzahl ' + a.sku });
				list.appendChild(UI.h('label', { class: 'pf-qline' }, UI.h('span', null, UI.h('b', null, a.sku), ' ', a.name), inputs[a.id]));
			});

			box.appendChild(UI.h('div', { class: 'card' },
				UI.h('div', { class: 'sec', style: 'margin-top:0' }, 'Vorschau für eine Woche'),
				UI.h('p', { class: 'muted' }, 'Erwartete Bestellungen je Artikel eintragen. Die Rechnung löst Stücklisten auf und weist Druckzeit, Nachbearbeitung und benötigte Zukaufmengen aus. Gerechnet wird mit ' + d.printers + ' produzierenden Geräten und ' + nf(d.net_h, 0) + ' Nettostunden je Gerät.'),
				d.articles.length ? list : UI.h('div', { class: 'notice' }, 'Keine verkäuflichen Artikel vorhanden.'),
				UI.h('div', { style: 'margin-top:12px' }, go), result));

			box.appendChild(UI.h('div', { class: 'sec' }, 'Auslastungsverlauf'));
			box.appendChild(table(['Woche', 'Druckstunden', 'Kapazität', 'Auslastung', 'Plattenwechsel', 'Bedienzeit', 'Arbeitsstunden'], d.weeks.map(function (w) {
				return [w.label, nf(w.print_h, 1) + ' h', nf(w.capacity_h, 0) + ' h', levelTag(w.level, nf(w.utilisation, 0) + ' %'), nf(w.changes, 0), nf(w.operator_h, 1) + ' h', nf(w.work_h, 1) + ' h'];
			}), [1, 2, 4, 5, 6]));
		}).catch(function (e) { errBox(box, e); });

		function paintResult(res, r) {
			res.appendChild(UI.h('div', { class: 'grid tiles', style: 'margin-top:14px' },
				tile('Benötigte Druckstunden', nf(r.print_h, 1) + ' h'), tile('Verfügbare Kapazität', nf(r.capacity_h, 1) + ' h'),
				tile('Nachbearbeitung', nf(r.post_h, 1) + ' h'), tile('Auslastung', nf(r.utilisation, 1) + ' %', r.level)));
			res.appendChild(UI.h('p', { style: 'font-size:18px' }, UI.h('b', null, r.max_orders === null ? 'Unbegrenzt viele Bestellungen' : r.max_orders + ' Bestellungen'), ' diese Woche – begrenzt durch ' + r.limiting + '.'));
			if (r.purchased.length) {
				res.appendChild(UI.h('div', { class: 'sec' }, 'Benötigte Zukaufmengen'));
				res.appendChild(table(['Artikel', 'Bedarf', 'im Lager'], r.purchased.map(function (p) { return [p.sku, nf(p.qty, 0), nf(p.stock, 0)]; }), [1, 2]));
			}
		}
	};

	/* ====================================================================
	 * Produktion
	 * ================================================================== */

	UI.pages['produktion'] = function (item) {
		head(item, 'Produktionsplanung', 'Druckaufträge des Produktionsmoduls und Ist gegen Plan.');
		var tab = 'jobs';
		var tabs = UI.h('div');
		var box = UI.h('div');
		UI.view().appendChild(tabs);
		UI.view().appendChild(box);
		var data = null;

		function load() { return UI.api('produktion/auftraege').then(function (d) { data = d; draw(); }).catch(function (e) { errBox(box, e); }); }

		function draw() {
			tabs.textContent = '';
			tabs.appendChild(pills([['jobs', 'Druckaufträge'], ['plan', 'Ist vs. Plan']], tab, function (t) { tab = t; draw(); }));
			box.textContent = '';
			if (tab === 'jobs') { jobs(); } else { plan(); }
		}

		function jobs() {
			if (!data.jobs.length) { box.appendChild(UI.h('div', { class: 'notice' }, 'Keine offenen Druckaufträge.')); return; }
			var rows = UI.h('div', { class: 'rows' });
			data.jobs.forEach(function (j) {
				var action = null;
				if (j.next) {
					action = UI.h('button', { class: 'btn', type: 'button', onclick: function () { post(this, 'produktion/auftraege/' + j.id + '/weiter', {}, function (r) { UI.toast(r.message); load(); }); } }, '→ ' + j.next_text);
				} else if (j.status === 'fertig') {
					var h = input('text', '', { inputmode: 'decimal', placeholder: 'Druckzeit (h)', 'aria-label': 'Ist-Druckzeit in Stunden' });
					var m = input('text', '', { inputmode: 'decimal', placeholder: 'Arbeitszeit (min)', 'aria-label': 'Ist-Arbeitszeit in Minuten' });
					var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
						post(go, 'produktion/auftraege/' + j.id + '/abschluss', { ist_druckzeit_h: h.value, ist_arbeitszeit_min: m.value }, function (r) { UI.toast(r.message); load(); });
					} }, 'Verpackt & Log speichern');
					action = UI.h('div', { class: 'label-area' }, h, m, go);
				}
				rows.appendChild(UI.h('div', { class: 'row-card pj' },
					UI.h('div', { class: 'row-main' }, UI.h('b', null, j.name), UI.h('small', null, nf(j.qty, 0) + ' Stück · angelegt ' + day(j.created) + (j.ref ? ' · ' + j.ref : '') + (j.plan_h !== null ? ' · Plan ' + nf(j.plan_h, 2) + ' h, ' + nf(j.plan_min, 0) + ' min' : ''))),
					UI.h('div', { class: 'row-tags' }, UI.h('span', { class: 'tag' }, j.text)),
					action));
			});
			box.appendChild(rows);
		}

		function plan() {
			if (data.modules.length) {
				box.appendChild(table(['Modul', 'Plan Arbeitszeit', 'Ist-Ø Arbeitszeit', 'Abweichung', 'Ist-Ø Druckzeit'], data.modules.map(function (x) {
					var dev = x.deviation === null ? '–' : levelTag(Math.abs(x.deviation) >= 20 ? 'rot' : 'gruen', (x.deviation > 0 ? '+' : '') + x.deviation + ' %');
					return [x.name, nf(x.plan_min, 1) + ' min', x.ist_min === null ? '–' : nf(x.ist_min, 1) + ' min', dev, x.ist_h === null ? '–' : nf(x.ist_h, 2) + ' h'];
				}), [1, 2, 4]));
			} else { box.appendChild(UI.h('div', { class: 'notice' }, 'Keine Module vorhanden.')); }
			box.appendChild(UI.h('p', { class: 'muted' }, 'Empfehlung, keine automatische Anpassung – die Kalkulationswerte änderst du bewusst selbst.'));

			var weeks = input('text', data.excluded, { placeholder: '2026-51, 2026-52', 'aria-label': 'Ausgeschlossene Wochen' });
			var go = UI.h('button', { class: 'btn primary', type: 'button', onclick: function () {
				post(go, 'produktion/wochen', { excluded_weeks: weeks.value }, function (r) { UI.toast(r.message); data.excluded = r.excluded; weeks.value = r.excluded; });
			} }, 'Speichern');
			box.appendChild(UI.h('div', { class: 'card', style: 'margin-top:14px' },
				UI.h('div', { class: 'sec', style: 'margin-top:0' }, 'Ausgeschlossene Wochen (Mengenvorschläge)'),
				UI.h('p', { class: 'muted' }, 'Wochen im Format JJJJ-WW, die bei den vorausschauenden Mengenvorschlägen als saisonale Ausreißer übersprungen werden.'),
				UI.h('div', { class: 'label-area' }, weeks, go)));
		}

		load();
	};
})();
