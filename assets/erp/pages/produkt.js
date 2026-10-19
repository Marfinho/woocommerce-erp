/*
 * Produktakte: native Reiter statt der eingebetteten wp-admin-Formulare.
 *
 *   stamm        Stammdaten (anlegen, ändern, stilllegen, löschen)
 *   rev          3MF und Revisionen (Projekt-3MF hochladen, Plattenzeit, aktivieren)
 *   bom          Stückliste
 *   calc         Kalkulation (nur mit dem Recht „Preise, Kosten und Löhne sehen“)
 *   beschaffung  Lieferanten, Mindestmenge, Lieferzeit (Zukauf und Hybrid)
 *
 * Server: includes/Erp/Pages/Produkt.php. Klassisches ES5, kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};
	UI.productTabs = UI.productTabs || {};

	/* ---------- Zahlen und Texte ---------- */

	function dec(v, n) {
		v = Number(v);
		if (!isFinite(v)) { return '–'; }
		return v.toLocaleString('de-DE', { minimumFractionDigits: n, maximumFractionDigits: n });
	}

	function eur(v, n) {
		if (v === null || v === undefined) { return '–'; }
		return dec(v, n === undefined ? 2 : n) + ' €';
	}

	function pct(v) {
		if (v === null || v === undefined) { return '–'; }
		return dec(Number(v) * 100, 1) + ' %';
	}

	function mb(bytes) {
		bytes = Number(bytes) || 0;
		return bytes >= 1048576 ? dec(bytes / 1048576, 1) + ' MB' : dec(bytes / 1024, 0) + ' KB';
	}

	function hoursTxt(h) {
		return dec(h, 2) + ' h';
	}

	/* ---------- Formularbausteine ---------- */

	function Kit(ui) {
		var h = ui.h;
		var kit = {};

		kit.field = function (label, control, hint, cls) {
			return h('label', { class: 'pf-field' + (cls ? ' ' + cls : '') },
				h('span', { class: 'pf-lab' }, label),
				control,
				hint ? h('span', { class: 'pf-hint' }, hint) : null);
		};

		kit.text = function (value, attrs) {
			var a = { class: 'field', type: 'text', autocomplete: 'off', value: value === null || value === undefined ? '' : String(value) };
			Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
			return h('input', a);
		};

		// Zahlenfeld mit Dezimalkomma-Tastatur auf dem Handy; der Server liest Komma und Punkt.
		kit.num = function (value, attrs) {
			var a = { inputmode: 'decimal' };
			Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
			return kit.text(value, a);
		};

		kit.area = function (value, rows) {
			return h('textarea', { class: 'field pf-area', rows: String(rows || 3), value: value || '' });
		};

		kit.select = function (options, value, placeholder) {
			var s = h('select', { class: 'field' });
			if (placeholder !== undefined && placeholder !== null) { s.appendChild(h('option', { value: '' }, placeholder)); }
			var found = false;
			options.forEach(function (o) {
				var op = h('option', { value: String(o.value) }, o.label);
				if (String(o.value) === String(value)) { op.selected = true; found = true; }
				s.appendChild(op);
			});
			if (!found && value !== '' && value !== null && value !== undefined && String(value) !== '0') {
				var extra = h('option', { value: String(value) }, String(value) + ' (nicht mehr in der Liste)');
				extra.selected = true;
				s.appendChild(extra);
			}
			return s;
		};

		kit.check = function (label, checked, hint) {
			var box = h('input', { type: 'checkbox', checked: !!checked });
			var wrap = h('label', { class: 'pf-check' }, box, h('span', null, label, hint ? h('small', { class: 'pf-hint' }, hint) : null));
			wrap.input = box;
			return wrap;
		};

		kit.msg = function () {
			return h('div', { class: 'pf-msg', role: 'status', 'aria-live': 'polite', hidden: true });
		};

		kit.say = function (box, text, isErr) {
			box.textContent = text;
			box.className = 'pf-msg ' + (isErr ? 'err' : 'ok');
			box.hidden = !text;
		};

		// Knopf sperren, solange die Anfrage läuft; Fehler erscheinen in der Meldungszeile.
		kit.run = function (btn, msgBox, work, label) {
			var old = btn.textContent;
			btn.disabled = true;
			if (label) { btn.textContent = label; }
			if (msgBox) { kit.say(msgBox, '', false); }
			function free() { btn.disabled = false; btn.textContent = old; }
			return work().then(function (r) { free(); return r; }, function (e) {
				free();
				if (msgBox) { kit.say(msgBox, e.message, true); } else { ui.toast(e.message, true); }
				return null;
			});
		};

		kit.notice = function (text, warn) {
			return h('div', { class: 'notice' + (warn ? ' warn' : ''), style: 'margin:10px 0' }, text);
		};

		kit.post = function (path, body) {
			return ui.api(path, { method: 'POST', body: body || {} });
		};

		return kit;
	}

	/* ---------- Gemeinsamer Rahmen der Reiter ---------- */

	// Beschaffung hat keine Pille in der Reiterleiste von erp.js; sie wird hier ergänzt,
	// sobald ein Zukauf- oder Hybridartikel einen unserer Reiter zeigt.
	function ensureSourcingPill(d, box, activeKey) {
		if (d.sourcing !== 'zukauf' && d.sourcing !== 'hybrid') { return; }
		var pills = box.querySelector('.pills');
		if (!pills || pills.querySelector('[data-pf="beschaffung"]')) { return; }
		var a = document.createElement('a');
		a.className = 'pill' + (activeKey === 'beschaffung' ? ' on' : '');
		a.setAttribute('href', '#/produkt/' + d.id + '/beschaffung');
		a.setAttribute('data-pf', 'beschaffung');
		a.textContent = 'Beschaffung';
		pills.appendChild(a);
	}

	function shell(d, box, ui, key, build) {
		var kit = Kit(ui);
		ensureSourcingPill(d, box, key);
		var wrap = ui.h('div', { class: 'pf' });
		box.appendChild(wrap);

		function paint(html) { wrap.textContent = ''; wrap.appendChild(html); }
		function loading() { wrap.textContent = ''; wrap.appendChild(ui.h('div', { class: 'card' }, 'Wird geladen …')); }
		function fail(e) { wrap.textContent = ''; wrap.appendChild(kit.notice(e.message, true)); }

		var api = { kit: kit, wrap: wrap, paint: paint, loading: loading, fail: fail };
		build(api);
	}

	/* ====================================================================
	 * Stammdaten
	 * ================================================================== */

	function articleForm(ui, kit, data, options, isNew, done) {
		var h = ui.h;
		var a = data;
		var f = {};

		f.sku = kit.text(a.sku, { maxlength: '60', autocapitalize: 'characters' });
		f.name = kit.text(a.name);
		f.sourcing_type = kit.select(options.sourcing, a.sourcing_type, isNew ? '— bitte wählen —' : undefined);
		f.status = kit.select(options.status, a.status);
		f.sellable = kit.check('Im Shop verkäuflich', a.sellable, 'Abstandshalter und Wandanker sind eigene Artikel, aber nicht verkäuflich.');
		f.discountable = kit.check('Rabattfähig', a.discountable, 'Personalisierte Artikel sind von der Händlerstaffel ausgenommen.');
		f.price_gross = kit.num(a.price_gross);
		f.dealer_price = kit.num(a.dealer_price);
		f.weight_g = kit.num(a.weight_g);
		f.postprocessing_min = kit.num(a.postprocessing_min);
		f.load_rating_kg = kit.num(a.load_rating_kg);
		f.barcode = kit.text(a.barcode);
		f.default_bin_id = kit.select(options.bins.map(function (b) { return { value: b.id, label: b.label }; }), a.default_bin_id, '— kein fester Platz —');
		f.wc_product_id = options.shop.length
			? kit.select(options.shop.map(function (p) { return { value: p.id, label: p.label }; }), a.wc_product_id, '— keine Verknüpfung —')
			: kit.num(a.wc_product_id ? a.wc_product_id : '', { inputmode: 'numeric' });
		f.notes = kit.area(a.notes, 3);
		f.reason = kit.text('', { placeholder: 'z. B. Preis nach Materialerhöhung angepasst' });

		var msg = kit.msg();
		var save = h('button', { class: 'btn primary', type: 'submit' }, isNew ? 'Produkt anlegen' : 'Speichern');

		var form = h('form', { class: 'pf-form', novalidate: true },
			h('div', { class: 'pf-grid' },
				kit.field('Artikelnummer', f.sku, 'Pflicht und eindeutig, z. B. HH-001.'),
				kit.field('Bezeichnung', f.name)),
			h('div', { class: 'pf-grid' },
				kit.field('Beschaffungsweg', f.sourcing_type, 'Rohling = kommt aus dem Drucker (braucht eine Revision mit Gewicht und Druckzeit). Set/Baugruppe = besteht aus anderen Artikeln (Reiter Stückliste). Zukauf = wird bestellt (Reiter Beschaffung). Hybrid = beides.'),
				kit.field('Status', f.status)),
			h('div', { class: 'pf-checks' }, f.sellable, f.discountable),
			h('div', { class: 'pf-grid' },
				kit.field('Verkaufspreis brutto (€)', f.price_gross, 'Der Preis dieses Artikels – hier und nirgends sonst. Ein verknüpftes Shop-Produkt bekommt ihn beim Speichern als Normalpreis; ein laufendes Angebot bleibt unberührt.'),
				kit.field('Händlerpreis (€)', f.dealer_price)),
			h('div', { class: 'pf-grid' },
				kit.field('Gewicht (g)', f.weight_g, 'Bei Zukauf leer lassen – die Kosten kommen aus der Beschaffung.'),
				kit.field('Nachbearbeitung (min)', f.postprocessing_min, 'Entgraten, Gewindeeinsätze setzen, Beipack.')),
			h('div', { class: 'pf-grid' },
				kit.field('Traglast (kg)', f.load_rating_kg, 'Erscheint in der Chargenakte.'),
				kit.field('Standardlagerplatz', f.default_bin_id, 'Nur ein Vorschlag für Buchung und Pickliste.')),
			h('div', { class: 'pf-grid' },
				kit.field('Barcode', f.barcode, 'Fremder Barcode zugekaufter Ware (EAN); wird beim Scannen erkannt.'),
				kit.field(options.shop.length ? 'Shop-Produkt' : 'Shop-Produkt (Nummer)', f.wc_product_id, 'Nötig für Lagerabgang beim Verkauf und Produktbild.')),
			kit.field('Notiz', f.notes),
			isNew ? null : kit.field('Begründung der Änderung', f.reason, 'Erscheint im Protokoll.'),
			msg,
			h('div', { class: 'pf-actions' }, save)
		);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var body = {
				sku: f.sku.value, name: f.name.value, sourcing_type: f.sourcing_type.value, status: f.status.value,
				sellable: f.sellable.input.checked, discountable: f.discountable.input.checked,
				price_gross: f.price_gross.value, dealer_price: f.dealer_price.value, weight_g: f.weight_g.value,
				postprocessing_min: f.postprocessing_min.value, load_rating_kg: f.load_rating_kg.value, barcode: f.barcode.value,
				default_bin_id: f.default_bin_id.value, wc_product_id: f.wc_product_id.value, notes: f.notes.value
			};
			if (!isNew) { body.reason = f.reason.value; }
			kit.run(save, msg, function () {
				return kit.post(isNew ? 'produkt-neu' : 'produkte/' + a.id + '/stamm', body);
			}, 'Wird gespeichert …').then(function (r) { if (r) { done(r); } });
		});

		return form;
	}

	UI.productTabs['stamm'] = function (d, box, ui) {
		shell(d, box, ui, 'stamm', function (s) {
			var kit = s.kit, h = ui.h;
			s.loading();

			function load() {
				ui.api('produkte/' + d.id + '/stamm').then(function (r) {
					var out = h('div');
					out.appendChild(h('h3', { class: 'pf-h' }, 'Stammdaten'));
					out.appendChild(h('div', { class: 'card' }, articleForm(ui, kit, r.article, r.options, false, function (res) {
						ui.toast(res.message);
						ui.reload();
					})));
					out.appendChild(stateCard(r));
					s.paint(out);
				}).catch(s.fail);
			}

			function stateCard(r) {
				var msg = kit.msg();
				var card = h('div', { class: 'card', style: 'margin-top:12px' }, h('h3', null, 'Stilllegen und Löschen'));
				card.appendChild(h('p', { class: 'muted' }, r.active
					? 'Ein stillgelegter Artikel (ausgelaufen) verschwindet aus allen Auswahllisten. Bestand, Bewegungen und Auswertungen bleiben.'
					: 'Dieser Artikel ist stillgelegt und steht in keiner Auswahlliste. Du kannst ihn wieder aufnehmen.'));

				var toggle = h('button', { class: 'btn', type: 'button' }, r.active ? 'Stilllegen' : 'Wieder aufnehmen');
				toggle.addEventListener('click', function () {
					function go() {
						return kit.run(toggle, msg, function () {
							return kit.post('produkte/' + d.id + '/stamm/status', { was: r.active ? 'stilllegen' : 'aufnehmen' });
						}).then(function (res) { if (res) { ui.toast(res.message); ui.reload(); } });
					}
					if (!r.active) { go(); return; }
					confirmDlg(ui, 'Stilllegen?', 'Der Artikel verschwindet aus allen Auswahllisten. Bestand, Bewegungen und Auswertungen bleiben.', 'Stilllegen', go);
				});

				var row = h('div', { class: 'pf-actions' }, toggle);

				if (r.blockers.length) {
					card.appendChild(h('p', { class: 'muted' }, 'Löschen geht nicht, weil Geschichte daran hängt: ' + r.blockers.join(', ') + '. Stilllegen ist der richtige Weg.'));
				} else {
					var del = h('button', { class: 'btn pf-danger', type: 'button' }, 'Löschen');
					del.addEventListener('click', function () {
						confirmDlg(ui, 'Wirklich löschen?', 'An diesem Artikel hängt nichts. Stückliste, Revisionen und Beschaffungsdaten gehen mit.', 'Endgültig löschen', function () {
							return kit.run(del, msg, function () {
								return kit.post('produkte/' + d.id + '/stamm/status', { was: 'loeschen' });
							}).then(function (res) { if (res) { ui.toast(res.message); location.hash = '#/seite/artikel'; } });
						});
					});
					row.appendChild(del);
				}

				card.appendChild(row);
				card.appendChild(msg);
				return card;
			}

			load();
		});
	};

	function confirmDlg(ui, title, text, yes, onYes) {
		var h = ui.h;
		var dlg;
		var ok = h('button', { class: 'btn primary', type: 'button' }, yes);
		var no = h('button', { class: 'btn', type: 'button' }, 'Abbrechen');
		ok.addEventListener('click', function () { dlg.close(); onYes(); });
		no.addEventListener('click', function () { dlg.close(); });
		dlg = ui.dlg(title, h('div', { class: 'sheet-body' }, h('p', null, text), h('div', { class: 'pf-actions' }, ok, no)));
		return dlg;
	}

	// Neues Produkt: erreichbar unter #/produkt-neu.
	UI.routes['produkt-neu'] = function (r, ui) {
		var h = ui.h, kit = Kit(ui);
		ui.section('artikel');
		ui.setHead('Neues Produkt', 'Katalog');

		var view = ui.view();
		view.appendChild(h('a', { class: 'btn back', href: '#/seite/artikel' }, ui.ico('chev', 'flip'), 'Alle Produkte'));

		if (!ui.can('fertigung.stammdaten.edit')) {
			view.appendChild(kit.notice('Neue Produkte anlegen darf nur, wer „Stammdaten ändern“ hat. Bitte bei der Leitung nachfragen.', true));
			return;
		}

		var box = h('div', { class: 'pf' }, h('div', { class: 'card' }, 'Wird geladen …'));
		view.appendChild(box);

		ui.api('produkt-neu').then(function (res) {
			box.textContent = '';
			box.appendChild(h('h3', { class: 'pf-h' }, 'Neues Produkt anlegen'));
			box.appendChild(h('p', { class: 'muted' }, 'Nach dem Anlegen geht es mit dem passenden Reiter weiter: bei Rohlingen die 3MF und Revision, bei Sets die Stückliste, bei Zukauf die Beschaffung.'));
			box.appendChild(h('div', { class: 'card' }, articleForm(ui, kit, res.article, res.options, true, function (created) {
				ui.toast(created.message);
				var next = created.sourcing_type === 'montage' ? 'bom' : (created.sourcing_type === 'zukauf' ? 'beschaffung' : 'rev');
				location.hash = '#/produkt/' + created.id + '/' + next;
			})));
		}).catch(function (e) { box.textContent = ''; box.appendChild(kit.notice(e.message, true)); });
	};

	/* ====================================================================
	 * 3MF und Revisionen
	 * ================================================================== */

	function upload(ui, path, file) {
		var C = ui.config;
		var fd = new FormData();
		fd.append('model', file, file.name);

		return fetch(C.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce },
			body: fd
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok || (json && json.ok === false)) {
					var text;
					if (json && json.code === 'rest_cookie_invalid_nonce') { text = 'Die Sitzung ist abgelaufen – bitte die Seite neu laden und die Datei noch einmal hochladen.'; }
					else if (json && (json.message || json.error)) { text = json.message || json.error; }
					else if (res.status === 413) { text = 'Die Datei ist zu groß für den Server. Bitte beim Hoster das Upload-Limit erhöhen lassen.'; }
					else if (res.status === 403) { text = 'Dafür fehlt dir die Berechtigung (Stammdaten ändern).'; }
					else { text = 'Der Upload hat nicht geklappt (der Server antwortet mit ' + res.status + '). Bitte noch einmal versuchen.'; }
					throw new Error(text);
				}
				return json;
			});
		}, function () {
			throw new Error('Der Upload ist abgebrochen, weil die Verbindung weg war. Bitte noch einmal versuchen, am besten per WLAN.');
		});
	}

	// Vorab prüfen, was der Browser schon wissen kann – spart bei großen Dateien die Wartezeit.
	function checkFile(file, maxBytes) {
		if (!file) { return 'Bitte eine .3mf-Datei wählen.'; }
		if (!/\.3mf$/i.test(file.name)) { return '„' + file.name + '“ ist keine .3mf-Datei. In Bambu Studio: Datei → Projekt speichern unter … (nicht „Platte exportieren“).'; }
		if (maxBytes && file.size > maxBytes) { return 'Die Datei ist ' + mb(file.size) + ' groß, der Server nimmt höchstens ' + mb(maxBytes) + ' an.'; }
		if (file.size < 1) { return 'Die Datei ist leer.'; }
		return '';
	}

	function revisionForm(ui, kit, data, r, isNew, done) {
		var h = ui.h;
		var v = data;
		var f = {};

		f.version = kit.text(v.version, { placeholder: 'z. B. v1.0' });
		f.material = kit.select(r.materials.map(function (m) { return { value: m, label: m }; }), v.material || 'PETG');
		f.color = r.colors.length
			? kit.select(r.colors, v.color)
			: kit.text(v.color, { placeholder: 'leer = farblos' });
		f.weight_g = kit.num(v.weight_g);
		f.parts = kit.num(v.parts_per_plate === '' ? '1' : v.parts_per_plate, { inputmode: 'numeric' });
		f.plate_h = kit.num(v.print_time_plate_h);
		f.nozzle = kit.num(v.nozzle_mm === '' && isNew ? '0.4' : v.nozzle_mm);
		f.layer = kit.num(v.layer_mm === '' && isNew ? '0.2' : v.layer_mm);
		f.perimeters = kit.num(v.perimeters === '' && isNew ? '5' : v.perimeters, { inputmode: 'numeric' });
		f.infill = kit.num(v.infill_pct === '' && isNew ? '0.35' : v.infill_pct);
		f.pattern = kit.text(v.infill_pattern);
		f.nozzle_temp = kit.num(v.nozzle_temp, { inputmode: 'numeric' });
		f.bed_temp = kit.num(v.bed_temp, { inputmode: 'numeric' });
		f.cooling = kit.num(v.cooling_pct, { inputmode: 'numeric' });
		f.supports = kit.check('Stützen', v.supports);
		f.note = kit.area(v.orientation_note, 2);
		f.reason = kit.text('', { placeholder: 'z. B. Plattenzeit nach Messung korrigiert' });
		f.file = h('input', { type: 'file', accept: '.3mf', class: 'pf-file' });

		var per = h('b', null, '–');
		function recalc() {
			var p = parseFloat(String(f.parts.value).replace(',', '.'));
			var t = parseFloat(String(f.plate_h.value).replace(',', '.'));
			per.textContent = (p > 0 && t >= 0) ? dec(t / p, 3) + ' h' : '–';
		}
		f.parts.addEventListener('input', recalc);
		f.plate_h.addEventListener('input', recalc);
		recalc();

		var msg = kit.msg();
		var save = h('button', { class: 'btn primary', type: 'submit' }, isNew ? 'Revision anlegen' : 'Speichern');

		var form = h('form', { class: 'pf-form', novalidate: true },
			h('div', { class: 'pf-grid' }, kit.field('Version', f.version, 'Pflicht, je Artikel nur einmal.'), kit.field('Material', f.material)),
			kit.field('Farbe', f.color, r.colors.length ? 'Nur Farben, für die ein Slicing-Profil hinterlegt ist – so scheitert das automatische Slicen nie an einer fehlenden Zuordnung.' : 'Noch kein Slicing-Profil hinterlegt; die Farbe kann trotzdem als Text eingetragen werden.'),
			h('div', { class: 'pf-grid3' },
				kit.field('Gewicht je Teil (g)', f.weight_g, 'Aus dem Slicer; Grundlage der Materialkosten. Pflicht.'),
				kit.field('Teile je Platte', f.parts, 'Mindestens 1.'),
				kit.field('Druckzeit je Platte (h)', f.plate_h, 'z. B. 2,5 für 2 h 30 min.')),
			h('p', { class: 'muted pf-per' }, 'Druckzeit je Teil: ', per),
			isNew ? h('div', { class: 'pf-filebox' },
				h('b', null, 'Projekt-3MF (optional, geht auch später)'),
				h('p', { class: 'muted' }, 'Die ungeslicte Projektdatei aus Bambu Studio („Projekt speichern“), mit Modell und allen Druckeinstellungen.'),
				f.file) : null,
			h('details', { class: 'pf-more' },
				h('summary', null, 'Weitere Druckeinstellungen'),
				h('div', { class: 'pf-grid3' },
					kit.field('Düse (mm)', f.nozzle), kit.field('Schichthöhe (mm)', f.layer), kit.field('Perimeter', f.perimeters),
					kit.field('Infill (Anteil, z. B. 0,35)', f.infill), kit.field('Infill-Muster', f.pattern), kit.field('Kühlung (%)', f.cooling),
					kit.field('Düsentemperatur (°C)', f.nozzle_temp), kit.field('Betttemperatur (°C)', f.bed_temp)),
				h('div', { class: 'pf-checks' }, f.supports),
				kit.field('Orientierung', f.note, 'z. B. Last quer zur Z-Achse')),
			isNew ? null : kit.field('Begründung der Änderung', f.reason, 'Erscheint im Protokoll.'),
			msg,
			h('div', { class: 'pf-actions' }, save)
		);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var file = f.file.files && f.file.files[0];
			if (isNew && file) {
				var bad = checkFile(file, r.max_bytes);
				if (bad) { kit.say(msg, bad, true); return; }
			}
			var body = {
				version: f.version.value, material: f.material.value, color: f.color.value, weight_g: f.weight_g.value,
				parts_per_plate: f.parts.value, print_time_plate_h: f.plate_h.value, nozzle_mm: f.nozzle.value, layer_mm: f.layer.value,
				perimeters: f.perimeters.value, infill_pct: f.infill.value, infill_pattern: f.pattern.value, nozzle_temp: f.nozzle_temp.value,
				bed_temp: f.bed_temp.value, cooling_pct: f.cooling.value, supports: f.supports.input.checked, orientation_note: f.note.value
			};
			if (!isNew) { body.reason = f.reason.value; }

			kit.run(save, msg, function () {
				return kit.post('produkte/' + r.article.id + '/revisionen' + (isNew ? '' : '/' + v.id), body).then(function (res) {
					if (!isNew || !file) { return res; }
					// Revision steht; die Datei kommt danach – ein Fehler dort verwirft die Revision nicht.
					return upload(ui, 'produkte/' + r.article.id + '/revisionen/' + res.revision + '/datei', file).then(function (up) {
						res.message = res.message + ' ' + up.message;
						return res;
					}, function (err) {
						res.message = res.message + ' Die 3MF fehlt noch: ' + err.message;
						res.warn = true;
						return res;
					});
				});
			}, file ? 'Wird hochgeladen …' : 'Wird gespeichert …').then(function (res) { if (res) { done(res); } });
		});

		return form;
	}

	var BLANK_REVISION = { id: 0, version: '', material: 'PETG', color: '', weight_g: '', parts_per_plate: '', print_time_plate_h: '', nozzle_mm: '', layer_mm: '', perimeters: '', infill_pct: '', infill_pattern: '', nozzle_temp: '', bed_temp: '', cooling_pct: '', supports: false, orientation_note: '' };

	UI.productTabs['rev'] = function (d, box, ui) {
		shell(d, box, ui, 'rev', function (s) {
			var kit = s.kit, h = ui.h;
			var canEdit = !!d.edit;
			var latest = null;
			var flash = null;
			s.loading();

			function load(note, warn) {
				flash = note ? { text: note, warn: !!warn } : null;
				ui.api('produkte/' + d.id + '/revisionen').then(function (r) { latest = r; render(r); }).catch(s.fail);
			}

			function render(r) {
				var out = h('div');
				out.appendChild(h('div', { class: 'pf-head' },
					h('h3', { class: 'pf-h' }, 'Revisionen von ' + r.article.sku),
					canEdit && r.printed ? h('button', { class: 'btn primary', type: 'button', onclick: function () { openForm(r, null); } }, 'Revision anlegen') : null));

				if (flash) { out.appendChild(kit.notice(flash.text, flash.warn)); }

				if (!r.printed) {
					out.appendChild(kit.notice('Dieser Artikel hat kein eigenes Druckprofil und braucht auch keins. Ein Set oder eine Baugruppe besteht aus anderen Artikeln (Reiter Stückliste), ein Zukaufartikel kommt aus der Beschaffung.'));
					s.paint(out);
					return;
				}

				var active = r.revisions.filter(function (x) { return x.active; })[0] || null;
				var hasProject = r.revisions.some(function (x) { return x.model && x.model.kind === 'modell'; });

				if (!r.revisions.length) {
					out.appendChild(kit.notice('Noch keine Revision erfasst. Zuerst eine Revision anlegen (Gewicht und Druckzeit aus dem Slicer), dann die Projekt-3MF daran hängen und die Revision aktiv setzen.', true));
				} else if (!active) {
					out.appendChild(kit.notice('Keine Revision ist aktiv. Solange keine aktiv ist, rechnet und plant das System mit keiner. Bitte unten eine Revision „Aktiv setzen“.', true));
				} else if (!(active.model && active.model.kind === 'modell')) {
					out.appendChild(kit.notice(hasProject
						? 'An der aktiven Revision (' + active.version + ') fehlt die Projekt-3MF, an einer anderen hängt eine. Bitte hier hochladen oder die andere Revision aktiv setzen.'
						: 'Keine Projekt-3MF hinterlegt. Ohne sie lässt sich das Produkt nicht in einen Plan aufnehmen. Bitte unten an der aktiven Revision (' + active.version + ') die ungeslicte Projektdatei hochladen.', true));
				}

				var list = h('div', { class: 'pf-list' });
				r.revisions.forEach(function (rev) { list.appendChild(revisionCard(r, rev)); });
				out.appendChild(list);

				if (r.limit_note) {
					out.appendChild(h('p', { class: 'muted', style: 'font-size:12px' }, 'Hinweis: Der Server nimmt Dateien bis ' + mb(r.max_bytes) + ' an.'));
				}
				s.paint(out);
			}

			function revisionCard(r, rev) {
				var msg = kit.msg();
				var tag = h('span', { class: 'tag ' + (rev.active ? 'now' : 'later') }, rev.active ? 'aktiv' : 'inaktiv');

				var kv = h('div', { class: 'kv' },
					h('span', null, 'Material'), h('b', null, rev.material + ' · ' + rev.color_label),
					h('span', null, 'Gewicht'), h('b', null, dec(rev.weight_g, 1) + ' g'),
					h('span', null, 'Teile je Platte'), h('b', null, dec(rev.parts_per_plate, 0)),
					h('span', null, 'Druckzeit Platte'), h('b', null, hoursTxt(rev.print_time_plate_h)),
					h('span', null, 'Druckzeit je Teil'), h('b', null, dec(rev.per_part_h, 3) + ' h'));

				var fileBox = h('div', { class: 'pf-model' });
				var m = rev.model;
				if (m) {
					var dl = h('button', { class: 'btn', type: 'button' }, ui.ico('download'), 'Herunterladen');
					dl.addEventListener('click', function () { download(r, rev, dl, msg); });
					fileBox.appendChild(h('div', null,
						h('b', null, m.name),
						h('div', { class: 'muted', style: 'font-size:13px' }, m.kind_label + ' · ' + m.size + (m.at ? ' · ' + ui.when(m.at) : ''))));
					if (m.kind !== 'modell') {
						fileBox.appendChild(h('div', { class: 'pf-warn' }, 'Das ist eine geslicte Druckdatei, keine Projekt-3MF. Bitte zusätzlich die ungeslicte Projektdatei hochladen.'));
					}
					fileBox.appendChild(h('div', { class: 'pf-actions' }, dl));
				} else {
					fileBox.appendChild(h('div', { class: 'pf-warn' }, 'Keine Projekt-3MF hinterlegt.'));
				}

				if (canEdit) {
					var pick = h('input', { type: 'file', accept: '.3mf', class: 'pf-file-hidden', 'aria-label': '3MF für ' + rev.version + ' wählen' });
					var lab = h('label', { class: 'btn' + (m ? '' : ' primary') }, ui.ico('download', 'flip'), m ? '3MF ersetzen' : 'Projekt-3MF hochladen', pick);
					pick.addEventListener('change', function () {
						var file = pick.files && pick.files[0];
						pick.value = '';
						if (!file) { return; }
						var bad = checkFile(file, r.max_bytes);
						if (bad) { kit.say(msg, bad, true); return; }
						if (m && !window.confirm('Die bisherige Datei „' + m.name + '“ wird durch „' + file.name + '“ ersetzt. Fortfahren?')) { return; }
						lab.classList.add('busy');
						kit.say(msg, 'Wird hochgeladen: ' + file.name + ' (' + mb(file.size) + ') … bitte warten und die Seite nicht verlassen.', false);
						upload(ui, 'produkte/' + d.id + '/revisionen/' + rev.id + '/datei', file).then(function (res) {
							ui.toast(res.message);
							load(res.message, res.kind !== 'modell');
						}, function (err) {
							lab.classList.remove('busy');
							kit.say(msg, err.message, true);
						});
					});
					fileBox.querySelector('.pf-actions') ? fileBox.querySelector('.pf-actions').appendChild(lab) : fileBox.appendChild(h('div', { class: 'pf-actions' }, lab));

					if (m) {
						var rm = h('button', { class: 'btn pf-danger', type: 'button' }, 'Datei entfernen');
						rm.addEventListener('click', function () {
							confirmDlg(ui, 'Datei entfernen?', '„' + m.name + '“ wird von der Revision ' + rev.version + ' gelöst und vom Server gelöscht. Danach gilt das Produkt als „ohne 3MF“.', 'Entfernen', function () {
								kit.run(rm, msg, function () { return kit.post('produkte/' + d.id + '/revisionen/' + rev.id + '/datei/entfernen'); }).then(function (res) { if (res) { ui.toast(res.message); load(res.message); } });
							});
						});
						fileBox.querySelector('.pf-actions').appendChild(rm);
					}
				}

				var actions = h('div', { class: 'pf-actions' });
				if (canEdit) {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { openForm(r, rev); } }, 'Bearbeiten'));
					if (!rev.active) {
						var act = h('button', { class: 'btn primary', type: 'button' }, 'Aktiv setzen');
						act.addEventListener('click', function () {
							kit.run(act, msg, function () { return kit.post('produkte/' + d.id + '/revisionen/' + rev.id + '/aktivieren'); }).then(function (res) {
								if (res) { ui.toast(res.message); load(res.message, /Achtung/.test(res.message)); }
							});
						});
						actions.appendChild(act);
					}
				}

				return h('div', { class: 'card pf-rev' + (rev.active ? ' on' : '') },
					h('div', { class: 'pf-head' }, h('h3', null, rev.version), tag),
					kv, fileBox, actions, msg);
			}

			function download(r, rev, btn, msg) {
				var C = ui.config;
				kit.run(btn, msg, function () {
					return fetch(C.rest + 'produkte/' + d.id + '/revisionen/' + rev.id + '/datei', { credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } }).then(function (res) {
						if (!res.ok) {
							return res.json().catch(function () { return null; }).then(function (j) { throw new Error((j && j.message) || 'Die Datei konnte nicht geladen werden.'); });
						}
						return res.blob();
					}).then(function (blob) {
						var url = URL.createObjectURL(blob);
						var a = document.createElement('a');
						a.href = url;
						a.download = (r.article.sku + '-' + rev.version + '.3mf').replace(/[^A-Za-z0-9._-]+/g, '-');
						document.body.appendChild(a);
						a.click();
						a.remove();
						setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
						return true;
					});
				});
			}

			function openForm(r, rev) {
				var dlg;
				var form = revisionForm(ui, kit, rev ? {
					id: rev.id, version: rev.version, material: rev.material, color: rev.color, weight_g: rev.weight_g,
					parts_per_plate: rev.parts_per_plate, print_time_plate_h: rev.print_time_plate_h, nozzle_mm: rev.nozzle_mm, layer_mm: rev.layer_mm,
					perimeters: rev.perimeters, infill_pct: rev.infill_pct, infill_pattern: rev.infill_pattern, nozzle_temp: rev.nozzle_temp,
					bed_temp: rev.bed_temp, cooling_pct: rev.cooling_pct, supports: rev.supports, orientation_note: rev.orientation_note
				} : BLANK_REVISION, r, !rev, function (res) {
					dlg.close();
					ui.toast(res.message, !!res.warn);
					load(res.message, !!res.warn);
				});
				dlg = ui.dlg(rev ? 'Revision ' + rev.version + ' bearbeiten' : 'Revision anlegen', h('div', { class: 'sheet-body' }, form), true);
			}

			load();
		});
	};

	/* ====================================================================
	 * Stückliste
	 * ================================================================== */

	UI.productTabs['bom'] = function (d, box, ui) {
		shell(d, box, ui, 'bom', function (s) {
			var kit = s.kit, h = ui.h;
			var canEdit = !!d.edit;
			s.loading();

			function load(note) {
				ui.api('produkte/' + d.id + '/stueckliste').then(function (r) { render(r, note); }).catch(s.fail);
			}

			function render(r, note) {
				var out = h('div');
				out.appendChild(h('h3', { class: 'pf-h' }, 'Stückliste von ' + r.article.sku + ' – ' + r.article.name));
				if (note) { out.appendChild(kit.notice(note)); }

				r.problems.forEach(function (p) { out.appendChild(kit.notice(p, true)); });

				if (!r.rows.length) {
					out.appendChild(kit.notice('Noch keine Positionen. Ohne Stückliste fehlen die Kleinteilkosten in der Kalkulation.'));
				} else {
					var list = h('div', { class: 'pf-list' });
					r.rows.forEach(function (row) { list.appendChild(rowCard(r, row)); });
					out.appendChild(list);
				}

				if (canEdit) { out.appendChild(addForm(r)); }
				s.paint(out);
			}

			function rowCard(r, row) {
				var msg = kit.msg();
				var card = h('div', { class: 'card pf-bom' },
					h('div', { class: 'pf-bom-main' },
						h('span', { class: 'pf-pos' }, String(row.position)),
						h('div', null,
							h('b', null, row.label),
							h('div', { class: 'muted', style: 'font-size:13px' }, row.type_txt + ' · Menge ' + dec(row.qty, 3) + (row.note ? ' · ' + row.note : '')))));

				if (canEdit) {
					var rm = h('button', { class: 'btn pf-danger', type: 'button' }, 'Entfernen');
					rm.addEventListener('click', function () {
						var reason = kit.text('', { placeholder: 'Begründung (optional, kommt ins Protokoll)' });
						var dlg;
						var ok = h('button', { class: 'btn primary', type: 'button' }, 'Position entfernen');
						var no = h('button', { class: 'btn', type: 'button' }, 'Abbrechen');
						ok.addEventListener('click', function () {
							var why = reason.value;
							dlg.close();
							kit.run(rm, msg, function () { return kit.post('produkte/' + d.id + '/stueckliste/' + row.id + '/entfernen', { reason: why }); }).then(function (res) { if (res) { ui.toast(res.message); load(res.message); } });
						});
						no.addEventListener('click', function () { dlg.close(); });
						dlg = ui.dlg('Position entfernen?', h('div', { class: 'sheet-body' }, h('p', null, row.label + ' wird aus der Stückliste von ' + r.article.sku + ' entfernt. Die Kalkulation rechnet danach ohne diese Position.'), kit.field('Begründung', reason), h('div', { class: 'pf-actions' }, ok, no)));
					});
					card.appendChild(h('div', { class: 'pf-actions' }, rm));
				}
				card.appendChild(msg);
				return card;
			}

			function addForm(r) {
				var search = kit.text('', { type: 'search', placeholder: 'Position suchen (Name oder Nummer)' });
				var pick = kit.select(r.options, '', '— bitte wählen —');
				var qty = kit.num('1');
				var pos = kit.num(String(r.next), { inputmode: 'numeric' });
				var note = kit.text('');
				var msg = kit.msg();
				var add = h('button', { class: 'btn primary', type: 'submit' }, 'Hinzufügen');

				function fill() {
					var q = search.value.trim().toLowerCase();
					var cur = pick.value;
					pick.textContent = '';
					pick.appendChild(h('option', { value: '' }, '— bitte wählen —'));
					r.options.forEach(function (o) {
						if (q && o.label.toLowerCase().indexOf(q) < 0 && o.value !== cur) { return; }
						var op = h('option', { value: o.value }, o.label);
						if (o.value === cur) { op.selected = true; }
						pick.appendChild(op);
					});
				}
				search.addEventListener('input', fill);

				var form = h('form', { class: 'pf-form', novalidate: true },
					kit.field('Position', search),
					kit.field('Auswahl', pick, 'Ein Artikel als Position bildet eine Unterbaugruppe mit eigenem Druckprofil und eigener Charge. Ein Artikel darf sich nicht selbst enthalten; die Stückliste darf höchstens ' + r.depth + ' Ebenen tief sein.'),
					h('div', { class: 'pf-grid3' }, kit.field('Menge', qty), kit.field('Reihenfolge', pos), kit.field('Bemerkung', note)),
					msg,
					h('div', { class: 'pf-actions' }, add));

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					kit.run(add, msg, function () {
						return kit.post('produkte/' + d.id + '/stueckliste', { child: pick.value, qty: qty.value, position: pos.value, note: note.value });
					}, 'Wird gespeichert …').then(function (res) { if (res) { ui.toast(res.message); load(res.message); } });
				});

				return h('div', { class: 'card', style: 'margin-top:14px' }, h('h3', null, 'Position hinzufügen'), form);
			}

			load();
		});
	};

	/* ====================================================================
	 * Kalkulation
	 * ================================================================== */

	UI.productTabs['calc'] = function (d, box, ui) {
		shell(d, box, ui, 'calc', function (s) {
			var kit = s.kit, h = ui.h;
			var state = { stichtag: '', marge: '' };

			if (!d.money) {
				s.paint(kit.notice('Die Kalkulation zeigt Kosten und Preise. Dafür brauchst du das Recht „Preise, Kosten und Löhne sehen“. Bitte bei der Leitung nachfragen.', true));
				return;
			}

			s.loading();

			function load(note) {
				var q = [];
				if (state.stichtag) { q.push('stichtag=' + encodeURIComponent(state.stichtag)); }
				if (state.marge !== '') { q.push('marge=' + encodeURIComponent(state.marge)); }
				ui.api('produkte/' + d.id + '/kalkulation' + (q.length ? '?' + q.join('&') : '')).then(function (r) {
					state.stichtag = r.stichtag;
					state.marge = String(r.margin);
					render(r, note);
				}).catch(s.fail);
			}

			function tile(label, value, sub) {
				return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value), sub ? h('div', { class: 'muted', style: 'font-size:12px' }, sub) : null);
			}

			function render(r, note) {
				var out = h('div');
				out.appendChild(h('h3', { class: 'pf-h' }, 'Kalkulation ' + r.article.sku + ' – ' + r.article.name));
				if (note) { out.appendChild(kit.notice(note)); }

				var date = kit.text(r.stichtag, { type: 'date' });
				var again = h('button', { class: 'btn', type: 'submit' }, 'Neu rechnen');
				var form = h('form', { class: 'pf-inline' }, kit.field('Stichtag', date), again);
				form.addEventListener('submit', function (e) { e.preventDefault(); state.stichtag = date.value; s.loading(); load(); });
				out.appendChild(form);

				if (r.errors.length) {
					var ul = h('ul', { class: 'pf-ul' });
					r.errors.forEach(function (m) { ul.appendChild(h('li', null, m)); });
					out.appendChild(h('div', { class: 'notice warn', style: 'margin:10px 0' }, h('b', null, 'Die Kalkulation ist unvollständig:'), ul));
				}

				out.appendChild(h('p', { class: 'muted' }, 'Gerechnet mit dem Maschinenstundensatz aus den Gerätedaten: ', h('b', null, dec(r.machine_rate, 4) + ' €/h')));

				var b = r.breakdown;
				var tiles = h('div', { class: 'grid tiles', style: 'margin:12px 0' },
					tile('Herstellkosten', eur(r.cost, 2), 'je Stück'),
					tile('Eigenmaterial', eur(b.material, 2)),
					tile('Maschine', eur(b.machine, 2)),
					tile('Komponenten', eur(b.component, 2)));
				if (b.assembly > 0) { tiles.appendChild(tile('Unterbaugruppen', eur(b.assembly, 2))); }
				out.appendChild(tiles);

				var parts = h('div', { class: 'card' }, h('h3', null, 'Kostenanteile je Position'));
				var table = h('table', { class: 'list' });
				table.appendChild(h('thead', null, h('tr', null, h('th', null, 'Position'), h('th', null, 'Art'), h('th', { class: 'num' }, 'Menge'), h('th', { class: 'num' }, 'Stück'), h('th', { class: 'num' }, 'Kosten'), h('th', { class: 'num' }, 'Anteil'))));
				var tb = h('tbody');
				r.parts.forEach(function (p) {
					var indent = '';
					for (var i = 0; i < p.depth; i++) { indent += '— '; }
					tb.appendChild(h('tr', null,
						h('td', null, indent + p.label), h('td', null, p.kind),
						h('td', { class: 'num' }, p.qty === null ? '—' : dec(p.qty, 2)),
						h('td', { class: 'num' }, p.unit === null ? '—' : eur(p.unit, 4)),
						h('td', { class: 'num' }, eur(p.cost, 4)),
						h('td', { class: 'num' }, dec(p.share, 1) + ' %')));
				});
				tb.appendChild(h('tr', null, h('td', { colspan: '4' }, h('b', null, 'Herstellkosten')), h('td', { class: 'num' }, h('b', null, eur(r.cost, 4))), h('td', { class: 'num' }, '100,0 %')));
				table.appendChild(tb);
				parts.appendChild(h('div', { class: 'pf-scroll' }, table));
				out.appendChild(parts);

				out.appendChild(h('h3', { class: 'pf-h', style: 'margin-top:18px' }, 'Preisebenen im Vergleich'));
				var lv = h('div', { class: 'pf-levels' });
				r.levels.forEach(function (l) {
					var good = l.vs_direct >= 0;
					lv.appendChild(h('div', { class: 'card pf-level' },
						h('h3', null, l.label),
						h('div', { class: 'kv' },
							h('span', null, 'Brutto'), h('b', null, eur(l.gross)),
							h('span', null, 'Netto'), h('b', null, eur(l.net)),
							h('span', null, 'Herstellkosten'), h('b', null, eur(l.cost)),
							h('span', null, 'Ausschuss'), h('b', null, eur(l.scrap)),
							h('span', null, 'Verpackung'), h('b', null, eur(l.pack)),
							h('span', null, 'Zahlungsgebühr'), h('b', null, eur(l.payment)),
							h('span', null, 'DB I'), h('b', null, eur(l.db1) + ' · ' + pct(l.db1_pct)),
							h('span', null, 'Handling'), h('b', null, eur(l.handling)),
							h('span', null, 'DB II'), h('b', { class: l.db2 < 0 ? 'pf-neg' : '' }, eur(l.db2) + ' · ' + pct(l.db2_pct)),
							h('span', null, 'gegen Direktverkauf'), h('b', { class: good ? 'pf-pos-ok' : 'pf-neg' }, (good ? '+' : '') + eur(l.vs_direct)))));
				});
				out.appendChild(lv);
				out.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, 'Der Vergleich stellt jede Ebene gegen einen beworbenen Direktverkauf zum UVP abzüglich ' + eur(r.acquisition) + ' Kundengewinnungskosten. Grün heißt: Diese Stufe trägt sich besser als der Direktverkauf.'));

				out.appendChild(priceBox(r));
				s.paint(out);
			}

			function priceBox(r) {
				var marge = kit.num(r.margin, { inputmode: 'numeric' });
				var calc = h('button', { class: 'btn', type: 'submit' }, 'Neu rechnen');
				var form = h('form', { class: 'pf-inline' }, kit.field('Zielmarge (DB II vom Nettoerlös, %)', marge), calc);
				form.addEventListener('submit', function (e) { e.preventDefault(); state.marge = marge.value; s.loading(); load(); });

				var msg = kit.msg();
				var card = h('div', { class: 'card', style: 'margin-top:14px' }, h('h3', null, 'Preis festlegen'), form,
					h('div', { class: 'kv', style: 'margin-top:10px' },
						h('span', null, 'Vorschlag bei dieser Marge'), h('b', null, r.suggested === null ? 'mit den aktuellen Annahmen nicht erreichbar' : eur(r.suggested)),
						h('span', null, 'Preis am Artikel'), h('b', null, r.price === null ? '—' : eur(r.price)),
						h('span', null, 'Stand im Shop'), h('b', null, r.shop)));

				if (r.suggested !== null && r.can_adopt) {
					var adopt = h('button', { class: 'btn primary', type: 'button' }, eur(r.suggested) + ' als Artikelpreis übernehmen');
					adopt.addEventListener('click', function () {
						confirmDlg(ui, 'Preis übernehmen?', 'Der Artikelpreis ändert sich von ' + (r.price === null ? '—' : eur(r.price)) + ' auf ' + eur(r.suggested) + '. Ein verknüpftes Shop-Produkt bekommt ihn als Normalpreis, ein laufendes Angebot bleibt unberührt.', 'Preis übernehmen', function () {
							kit.run(adopt, msg, function () { return kit.post('produkte/' + d.id + '/kalkulation/preis', { marge: r.margin, stichtag: r.stichtag }); }).then(function (res) { if (res) { ui.toast(res.message); load(res.message); } });
						});
					});
					card.appendChild(h('div', { class: 'pf-actions' }, adopt));
				} else if (r.suggested !== null) {
					card.appendChild(h('p', { class: 'muted' }, 'Den Preis übernehmen darf nur, wer „Stammdaten ändern“ hat.'));
				}

				card.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, 'Der Artikelpreis ist der eine Preis: Beim Speichern bekommt das verknüpfte Shop-Produkt ihn als Normalpreis. Von Hand setzen geht im Reiter Stammdaten.'));

				if (r.can_adopt) {
					var push = h('button', { class: 'btn', type: 'button' }, 'Alle Preise in den Shop übertragen');
					push.addEventListener('click', function () {
						confirmDlg(ui, 'Alle Preise übertragen?', 'Alle verkäuflichen Artikel werden in den Shop übertragen. Nur nötig, wenn ein Shop-Preis von Hand verstellt wurde – sonst zieht der Shop bei jedem Speichern von selbst nach.', 'Übertragen', function () {
							kit.run(push, msg, function () { return kit.post('preise/shop'); }).then(function (res) { if (res) { ui.toast(res.message); kit.say(msg, res.message, false); } });
						});
					});
					card.appendChild(h('div', { class: 'pf-actions' }, push));
				}

				card.appendChild(msg);
				return card;
			}

			load();
		});
	};

	/* ====================================================================
	 * Beschaffung
	 * ================================================================== */

	UI.productTabs['beschaffung'] = function (d, box, ui) {
		shell(d, box, ui, 'beschaffung', function (s) {
			var kit = s.kit, h = ui.h;
			var canEdit = !!d.edit;
			s.loading();

			function load(note) {
				ui.api('produkte/' + d.id + '/beschaffung').then(function (r) { render(r, note); }).catch(s.fail);
			}

			function dateTxt(v) {
				var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v || '');
				return m ? m[3] + '.' + m[2] + '.' + m[1] : (v || '');
			}

			function render(r, note) {
				var out = h('div');
				out.appendChild(h('h3', { class: 'pf-h' }, 'Beschaffung von ' + r.article.sku + ' – ' + r.article.name));
				if (note) { out.appendChild(kit.notice(note)); }

				if (!r.applies) {
					out.appendChild(kit.notice('Dieser Artikel wird selbst gefertigt. Beschaffungsdaten gibt es nur für Zukauf- und Hybridartikel. Den Beschaffungsweg stellst du im Reiter Stammdaten um.'));
					s.paint(out);
					return;
				}

				out.appendChild(h('p', { class: 'muted' }, 'Ein Preiswechsel des Lieferanten erzeugt eine neue Zeile. Alte Kalkulationen rechnen weiter mit dem damaligen Preis.'));

				if (!r.money) {
					out.appendChild(kit.notice('Einkaufspreise siehst du nur mit dem Recht „Preise, Kosten und Löhne sehen“. Lieferant, Mindestmenge und Lieferzeit stehen unten.'));
				}

				if (!r.history.length) {
					out.appendChild(kit.notice('Noch keine Beschaffungsdaten. Ohne sie rechnet die Kalkulation diesen Artikel mit 0 € Material.', true));
				} else {
					var list = h('div', { class: 'pf-list' });
					r.history.forEach(function (row, i) {
						list.appendChild(h('div', { class: 'card pf-src' },
							h('div', { class: 'pf-head' }, h('h3', null, row.supplier || '(ohne Lieferant)'), i === 0 ? h('span', { class: 'tag now' }, 'gilt zuletzt') : null),
							h('div', { class: 'kv' },
								h('span', null, 'Gültig ab'), h('b', null, dateTxt(row.valid_from)),
								h('span', null, 'EK netto'), h('b', null, row.price === null ? 'verborgen' : eur(row.price)),
								h('span', null, 'Mindestmenge'), h('b', null, dec(row.min_qty, 0)),
								h('span', null, 'Lieferzeit'), h('b', null, row.lead_days + ' Tage'),
								h('span', null, 'Eigene Nacharbeit'), h('b', null, row.own_min + ' min'),
								row.contact ? h('span', null, 'Kontakt') : null, row.contact ? h('b', null, row.contact) : null,
								row.note ? h('span', null, 'Ausführung') : null, row.note ? h('b', null, row.note) : null)));
					});
					out.appendChild(list);
				}

				if (canEdit) { out.appendChild(form(r)); }
				s.paint(out);
			}

			function form(r) {
				var f = {
					valid: kit.text(r.today, { type: 'date' }),
					supplier: kit.text(''), contact: kit.text(''),
					price: kit.num(''), min: kit.num('1'), lead: kit.num('0', { inputmode: 'numeric' }), own: kit.num('0', { inputmode: 'numeric' }),
					note: kit.area('', 2)
				};
				f.note.setAttribute('placeholder', 'Holzart, Oberfläche, Querschnitt, Toleranz');
				var msg = kit.msg();
				var save = h('button', { class: 'btn primary', type: 'submit' }, 'Festschreiben');

				var el = h('form', { class: 'pf-form', novalidate: true },
					h('div', { class: 'pf-grid' }, kit.field('Gültig ab', f.valid), kit.field('Lieferant', f.supplier)),
					h('div', { class: 'pf-grid' }, kit.field('Kontakt', f.contact), kit.field('Einkaufspreis netto (€)', f.price, 'Pflicht, größer als 0 – sonst rechnet die Kalkulation den Artikel kostenlos.')),
					h('div', { class: 'pf-grid3' },
						kit.field('Mindestbestellmenge', f.min),
						kit.field('Lieferzeit (Tage)', f.lead, 'Grundlage des Beschaffungsalarms.'),
						kit.field('Eigene Nacharbeit (min)', f.own, 'Ölen, Bohren, Ablängen – zählt als Handling.')),
					kit.field('Ausführung', f.note),
					msg,
					h('div', { class: 'pf-actions' }, save));

				el.addEventListener('submit', function (e) {
					e.preventDefault();
					kit.run(save, msg, function () {
						return kit.post('produkte/' + d.id + '/beschaffung', {
							valid_from: f.valid.value, supplier_name: f.supplier.value, supplier_contact: f.contact.value, purchase_price_net: f.price.value,
							min_order_qty: f.min.value, lead_time_days: f.lead.value, own_processing_min: f.own.value, unit_note: f.note.value
						});
					}, 'Wird gespeichert …').then(function (res) { if (res) { ui.toast(res.message); load(res.message); } });
				});

				return h('div', { class: 'card', style: 'margin-top:14px' }, h('h3', null, 'Neue Konditionen festschreiben'), el);
			}

			load();
		});
	};
})();
