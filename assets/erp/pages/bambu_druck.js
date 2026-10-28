/*
 * Bambu-Lab nativ, zweiter Teil: Druckaufträge einreihen und hochladen,
 * Platten anordnen, Slicing-Profile. Die Reiter hängen an der Seite „bambu“
 * (produktion.js) über UI.bambuTabs[<reiter>](box, ctx).
 * Server: includes/Erp/Pages/BambuDruck.php. Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.bambuTabs = UI.bambuTabs || {};

	var SVGNS = 'http://www.w3.org/2000/svg';

	/* ---------- Hilfen ---------- */

	function h() { return UI.h.apply(null, arguments); }

	function field(label, control, hint) {
		return h('div', { class: 'pf' }, h('label', null, label), control, hint ? h('small', { class: 'muted' }, hint) : null);
	}

	function input(type, value, attrs) {
		var a = { class: 'field', type: type, value: value === null || value === undefined ? '' : String(value) };
		Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
		return h('input', a);
	}

	function select(options, value, attrs) {
		var s = h('select', Object.assign({ class: 'field' }, attrs || {}));
		options.forEach(function (o) {
			var opt = h('option', { value: String(o[0]) }, o[1]);
			if (String(o[0]) === String(value)) { opt.selected = true; }
			s.appendChild(opt);
		});
		return s;
	}

	function pills(items, current, onpick) {
		var wrap = h('div', { class: 'presets', style: 'margin-bottom:12px' });
		items.forEach(function (i) {
			wrap.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': i[0] === current ? 'true' : 'false', onclick: function () { onpick(i[0]); } }, i[1]));
		});
		return wrap;
	}

	function mb(bytes) {
		if (bytes >= 1048576) { return (bytes / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB'; }
		return Math.max(1, Math.round(bytes / 1024)).toLocaleString('de-DE') + ' KB';
	}

	function errBox(box, e) {
		box.textContent = '';
		box.appendChild(h('div', { class: 'notice warn' }, e.message));
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

	// Multipart-Upload mit Fortschritt (große Druckdateien, oft per Handy).
	function upload(path, fd, onProgress) {
		var C = UI.config;

		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', C.rest + path);
			xhr.withCredentials = true;
			xhr.setRequestHeader('X-WP-Nonce', C.nonce);
			xhr.setRequestHeader('Accept', 'application/json');
			if (xhr.upload && onProgress) {
				xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(Math.round(e.loaded / e.total * 100)); } };
			}
			xhr.onerror = function () { reject(new Error('Der Upload ist abgebrochen, weil die Verbindung weg war. Bitte noch einmal versuchen, am besten per WLAN.')); };
			xhr.onload = function () {
				var json = null;
				try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
				if (xhr.status >= 200 && xhr.status < 300 && !(json && json.ok === false)) { resolve(json || {}); return; }
				var text;
				if (json && json.code === 'rest_cookie_invalid_nonce') { text = 'Die Sitzung ist abgelaufen – bitte die Seite neu laden und die Datei noch einmal hochladen.'; }
				else if (json && (json.message || json.error)) { text = json.message || json.error; }
				else if (xhr.status === 413) { text = 'Die Datei ist zu groß für den Server. Bitte beim Hoster das Upload-Limit erhöhen lassen.'; }
				else if (xhr.status === 403) { text = 'Dafür fehlt dir die Berechtigung.'; }
				else { text = 'Der Upload hat nicht geklappt (der Server antwortet mit ' + xhr.status + '). Bitte noch einmal versuchen.'; }
				reject(new Error(text));
			};
			xhr.send(fd);
		});
	}

	// Gruppe → Kennzeichnung der Etiketten.
	var GROUP_TAG = { wartend: 'later', laeuft: 'now', fertig: '', fehler: 'later', storniert: '' };

	function trayOptions(printer) {
		var opts = [['', '– aktuell eingelegtes Filament (kein AMS) –']];
		(printer && printer.trays ? printer.trays : []).forEach(function (t) { opts.push([t.value, t.label]); });
		return opts;
	}

	// Fach-Auswahl, die dem gewählten Drucker folgt. Liefert {el, read()}.
	function trayPicker(printerSelect, printers) {
		var holder = h('div', { class: 'pf' });
		var sel = null;

		function byId(id) {
			var found = null;
			printers.forEach(function (p) { if (p.printer_id === id) { found = p; } });
			return found;
		}

		function paint() {
			holder.textContent = '';
			var p = byId(printerSelect.value);
			if (!p || !p.trays.length) { sel = null; holder.style.display = 'none'; return; }
			holder.style.display = '';
			sel = select(trayOptions(p), '');
			holder.appendChild(h('label', null, 'Filament (AMS-Fach)'));
			holder.appendChild(sel);
			holder.appendChild(h('small', { class: 'muted' }, 'Gilt für den ganzen Auftrag. Leer = der Drucker nimmt das eingelegte Filament.'));
		}

		printerSelect.addEventListener('change', paint);
		paint();

		return { el: holder, read: function () { return sel ? sel.value : ''; } };
	}

	function printerSelect(printers) {
		return select(printers.map(function (p) { return [p.printer_id, p.name]; }), printers.length ? printers[0].printer_id : '');
	}

	/* ====================================================================
	 * Reiter „Aufträge“
	 * ================================================================== */

	UI.bambuTabs['auftraege'] = function (host) {
		var root = h('div');
		host.appendChild(root);
		var data = null;
		var group = '';
		var busy = false;
		var listHost = null;

		function alive() { return root.isConnected; }

		// listOnly: nur die Liste auffrischen, damit eingegebene Werte im Formular bleiben.
		function load(listOnly) {
			return UI.api('bambu/auftraege').then(function (d) { data = d; if (alive()) { paint(listOnly === true); } }).catch(function (e) { if (alive()) { errBox(root, e); } });
		}

		function paint(listOnly) {
			if (listOnly && listHost && listHost.isConnected) {
				listHost.textContent = '';
				listHost.appendChild(listBlock());
				return;
			}
			root.textContent = '';
			if (!data.printers.length) {
				root.appendChild(h('div', { class: 'notice' }, 'Zuerst einen Bambu-Drucker anlegen (Reiter „Drucker“). Dann lassen sich hier Aufträge einreihen.'));
				return;
			}
			root.appendChild(enqueueCard());
			if (data.files.length) { root.appendChild(modelCard()); }
			listHost = h('div', null, listBlock());
			root.appendChild(listHost);
		}

		function enqueueCard() {
			var printer = printerSelect(data.printers);
			var tray = trayPicker(printer, data.printers);
			var file = h('input', { type: 'file', accept: '.3mf', class: 'field' });
			var status = h('small', { class: 'muted', role: 'status' });
			var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
				var f = file.files && file.files[0];
				if (!f) { UI.toast('Bitte eine geslicte .3mf-Datei wählen.', true); return; }
				if (!/\.3mf$/i.test(f.name)) { UI.toast('„' + f.name + '“ ist keine .3mf-Datei. In Bambu Studio: erst slicen, dann „Platte exportieren → Geslicte Plattendatei“ (.gcode.3mf).', true); return; }
				if (f.size < 1) { UI.toast('Die Datei ist leer.', true); return; }
				if (f.size > data.max_bytes) { UI.toast('Die Datei ist ' + mb(f.size) + ' groß, der Server nimmt höchstens ' + mb(data.max_bytes) + ' an.', true); return; }
				var fd = new FormData();
				fd.append('printer_id', printer.value);
				fd.append('ams_tray_id', tray.read());
				fd.append('datei', f, f.name);
				go.disabled = true; busy = true;
				status.textContent = 'Wird hochgeladen … 0 %';
				upload('bambu/auftraege/hochladen', fd, function (p) { status.textContent = p >= 100 ? 'Wird geprüft …' : 'Wird hochgeladen … ' + p + ' %'; }).then(function (r) {
					go.disabled = false; busy = false;
					UI.toast(r.message);
					load();
				}).catch(function (e) {
					go.disabled = false; busy = false;
					status.textContent = '';
					UI.toast(e.message, true);
				});
			} }, 'Hochladen & einreihen');

			return h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', { style: 'margin-top:0' }, 'Druckauftrag einreihen'),
				h('p', { class: 'muted' }, 'Nur bereits geslicte Druckdateien (.gcode.3mf aus Bambu Studio), höchstens ' + mb(data.max_bytes) + '. Der Drucker kann keine reinen Projektdateien ausführen.'),
				data.limit_note ? h('div', { class: 'notice warn' }, 'Der Server begrenzt Uploads auf ' + mb(data.max_bytes) + ' (PHP-Einstellung). Größere Dateien bitte beim Hoster freischalten lassen.') : null,
				h('div', { class: 'form-grid' }, field('Drucker', printer), tray.el, field('Druckdatei (.3mf)', file)),
				h('div', { style: 'margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap' }, go, status));
		}

		function modelCard() {
			var printer = printerSelect(data.printers);
			var tray = trayPicker(printer, data.printers);
			var model = select(data.files.map(function (f) { return [f.file, f.label]; }), data.files[0].file);
			var go = h('button', { class: 'btn', type: 'button', onclick: function () {
				post(go, 'bambu/auftraege/modell', { printer_id: printer.value, model_file: model.value, ams_tray_id: tray.read() }, function (r) { UI.toast(r.message); load(); });
			} }, 'Einreihen');
			return h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', { style: 'margin-top:0' }, 'Aus der Modellablage einreihen'),
				h('p', { class: 'muted' }, 'Für Artikel, deren geslicte Datei schon an der aktiven Revision hängt. Kein erneuter Upload nötig.'),
				h('div', { class: 'form-grid' }, field('Drucker', printer), tray.el, field('Artikel', model)),
				h('div', { style: 'margin-top:12px' }, go));
		}

		function listBlock() {
			var wrap = h('div');
			var c = data.counts;
			wrap.appendChild(h('h3', null, 'Aufträge'));
			var items = [['', 'Alle (' + data.jobs.length + ')'], ['wartend', 'Wartend (' + c.wartend + ')'], ['laeuft', 'Läuft (' + c.laeuft + ')'], ['fertig', 'Fertig (' + c.fertig + ')'], ['fehler', 'Fehler (' + c.fehler + ')']];
			if (c.storniert) { items.push(['storniert', 'Storniert (' + c.storniert + ')']); }
			wrap.appendChild(pills(items, group, function (g) { group = g; paint(true); }));

			var shown = data.jobs.filter(function (j) { return !group || j.group === group; });
			if (!shown.length) {
				wrap.appendChild(h('div', { class: 'notice' }, data.jobs.length ? 'Keine Aufträge in dieser Ansicht.' : 'Noch keine Druckaufträge.'));
				return wrap;
			}

			// Je Drucker eine Gruppe, in der Reihenfolge der Drucker; unbekannte (gelöschte) zuletzt.
			var order = data.printers.map(function (p) { return p.printer_id; });
			shown.forEach(function (j) { if (order.indexOf(j.printer_id) < 0) { order.push(j.printer_id); } });
			order.forEach(function (pid) {
				var mine = shown.filter(function (j) { return j.printer_id === pid; });
				if (!mine.length) { return; }
				wrap.appendChild(h('h4', { style: 'margin:14px 0 6px' }, mine[0].printer + ' ', h('small', { class: 'muted' }, '(' + mine.length + ')')));
				var rows = h('div', { class: 'rows' });
				mine.forEach(function (j) { rows.appendChild(jobRow(j)); });
				wrap.appendChild(rows);
			});
			wrap.appendChild(h('small', { class: 'muted' }, 'Gezeigt werden die letzten 200 Aufträge, neueste zuerst.'));
			return wrap;
		}

		function jobRow(j) {
			var meta = ['#' + j.id, 'angelegt ' + UI.when(j.created)];
			if (j.order) { meta.push('Fertigungsauftrag #' + j.order); }
			if (j.completed) { meta.push('fertig ' + UI.when(j.completed)); }
			if (j.actual_min !== null) { meta.push('Ist-Druckzeit ' + UI.minTxt(Math.round(j.actual_min))); }

			var main = h('div', { class: 'row-main' },
				h('b', null, j.file),
				h('div', { class: 'row-tags' }, h('span', { class: 'tag ' + (j.status === 'unklar' ? 'later' : (GROUP_TAG[j.group] || '')) }, j.text)),
				h('small', { class: 'muted' }, meta.join(' · ')));

			if (j.progress !== null && j.group === 'laeuft') {
				main.appendChild(h('div', { class: 'bar', role: 'progressbar', 'aria-valuenow': j.progress, 'aria-valuemin': 0, 'aria-valuemax': 100 }, h('i', { style: 'width:' + j.progress + '%' })));
				main.appendChild(h('small', null, j.progress + ' %' + (j.remaining !== null ? ' · noch ' + UI.minTxt(j.remaining) : '')));
			}
			if (j.status === 'unklar') {
				main.appendChild(h('div', { class: 'notice warn' }, (j.error ? j.error + ' ' : '') + 'Bitte am Drucker nachsehen, wie der Druck ausging, und den Auftrag klären. Bis dahin druckt dieser Drucker keinen weiteren Auftrag aus der Warteschlange.'));
			} else if (j.error) { main.appendChild(h('div', { class: 'notice warn' }, 'Fehler: ' + j.error)); }

			var row = h('div', { class: 'row-card' }, main);
			if (j.can_resolve) {
				var hint = j.status === 'unklar' ? '' : '\n\nAchtung: Die Bridge verfolgt diesen Auftrag womöglich noch. Nur klären, wenn sie sich nicht mehr meldet.';
				var okBtn = h('button', { class: 'btn', type: 'button', onclick: function () {
					if (!window.confirm('Auftrag #' + j.id + ' als fertig buchen? Das bucht wie ein normaler Abschluss (Ist-Druckzeit, ggf. Druckjournal).' + hint)) { return; }
					post(okBtn, 'bambu/auftraege/' + j.id + '/klaeren', { outcome: 'completed' }, function (r) { UI.toast(r.message); }).then(function () { load(true); });
				} }, 'Als fertig buchen');
				var failBtn = h('button', { class: 'btn', type: 'button', onclick: function () {
					var note = window.prompt('Auftrag #' + j.id + ' als fehlgeschlagen markieren. Was ist passiert? (optional)' + hint, '');
					if (note === null) { return; }
					post(failBtn, 'bambu/auftraege/' + j.id + '/klaeren', { outcome: 'failed', note: note }, function (r) { UI.toast(r.message); }).then(function () { load(true); });
				} }, 'Als fehlgeschlagen markieren');
				row.appendChild(h('div', { class: 'label-area' }, okBtn, failBtn));
			}
			if (j.can_cancel) {
				var cancel = h('button', { class: 'btn', type: 'button', onclick: function () {
					if (!window.confirm('Diesen wartenden Auftrag abbrechen? Die hochgeladene Datei wird gelöscht.')) { return; }
					post(cancel, 'bambu/auftraege/' + j.id + '/abbrechen', {}, function (r) { UI.toast(r.message); }).then(function () { load(true); });
				} }, 'Abbrechen');
				row.appendChild(cancel);
			}
			return row;
		}

		load();
		UI.every(function () { if (alive() && !busy && !document.querySelector('dialog[open]')) { load(true); } }, 10000);
	};

	/* ====================================================================
	 * Reiter „Platten“
	 * ================================================================== */

	var PALETTE = ['#2563eb', '#d97706', '#16a34a', '#db2777', '#7c3aed', '#0891b2', '#dc2626', '#65a30d'];

	function svg(tag, attrs) {
		var el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); });
		for (var i = 2; i < arguments.length; i++) { if (arguments[i]) { el.appendChild(arguments[i]); } }
		return el;
	}

	// Bett von oben: Ursprung unten links wie im Slicer, deshalb y spiegeln.
	function plateSvg(d) {
		var W = d.width, H = d.height;
		var colors = {};
		var next = 0;
		var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'bd-plate', role: 'img', 'aria-label': 'Anordnung auf dem Druckbett, ' + d.items.length + ' Teile' },
			svg('rect', { x: 0, y: 0, width: W, height: H, class: 'bd-bed' }));
		var legend = [];

		d.items.forEach(function (it) {
			if (!(it.article_id in colors)) { colors[it.article_id] = next % PALETTE.length; next++; legend.push([colors[it.article_id], it.label]); }
			var c = PALETTE[colors[it.article_id]];
			var shape = d.shapes && d.shapes[it.article_id];
			if (shape && it.tx !== undefined) {
				// Echter Umriss mit Einbuchtungen: gedreht um die Ecke, dann auf die Platte gesetzt; y spiegeln.
				var rad = (it.rotation || 0) * Math.PI / 180;
				var cs = Math.cos(rad), sn = Math.sin(rad);
				root.appendChild(svg('path', { d: shape, fill: c, 'fill-opacity': '0.35', stroke: c, 'stroke-width': Math.max(W, H) / 400, transform: 'matrix(' + [cs, -sn, -sn, -cs, it.tx, H - it.ty].join(' ') + ')' }));
			} else if (it.points.length >= 3) {
				var pts = it.points.map(function (p) { return p[0] + ',' + (H - p[1]); }).join(' ');
				root.appendChild(svg('polygon', { points: pts, fill: c, 'fill-opacity': '0.35', stroke: c, 'stroke-width': Math.max(W, H) / 300 }));
			} else {
				root.appendChild(svg('circle', { cx: it.x, cy: H - it.y, r: Math.max(W, H) / 60, fill: c, 'fill-opacity': '0.6' }));
			}
		});
		return { el: root, legend: legend };
	}

	// Anordnung samt Legende und Hinweis – auch für den Workflow (Plan-Vorschau, Plattenseite).
	UI.plateView = function (d) {
		var s = plateSvg(d);
		var wrap = h('div', { class: 'bd-layout' });
		wrap.appendChild(h('p', { class: 'muted', style: 'margin:0 0 8px' }, 'Druckbett ' + d.width + ' × ' + d.height + ' mm, ' + d.items.length + ' Teile. Ursprung unten links, wie im Slicer.'));
		wrap.appendChild(s.el);
		var ul = h('ul', { class: 'pf-list' });
		s.legend.forEach(function (l) {
			ul.appendChild(h('li', null, h('span', { class: 'pf-swatch', style: 'background:' + PALETTE[l[0]] }), l[1]));
		});
		wrap.appendChild(ul);
		if (d.missing) { wrap.appendChild(h('div', { class: 'notice warn' }, 'Bei ' + d.missing + ' Teil(en) ist die Form nicht auslesbar, sie sind nur als Punkt eingezeichnet.')); }
		return wrap;
	};

	function openPlate(row) {
		var body = h('div', { class: 'sheet-body' }, h('p', { class: 'muted' }, 'Wird geladen …'));
		UI.dlg('Platte #' + row.id + ' · ' + row.printer, body, true);
		UI.api('bambu/platten/' + row.id).then(function (d) {
			body.textContent = '';
			var s = plateSvg(d);
			body.appendChild(h('p', { class: 'muted' }, 'Druckbett ' + d.width + ' × ' + d.height + ' mm, ' + d.items.length + ' Teile. Ursprung unten links, wie im Slicer.'));
			body.appendChild(s.el);
			var ul = h('ul', { class: 'pf-list' });
			s.legend.forEach(function (l) {
				ul.appendChild(h('li', null, h('span', { class: 'pf-swatch', style: 'background:' + PALETTE[l[0]] }), l[1]));
			});
			body.appendChild(ul);
			if (d.missing) { body.appendChild(h('div', { class: 'notice warn' }, 'Bei ' + d.missing + ' Teil(en) ist die Form nicht mehr auslesbar (die Projektdatei des Artikels wurde ersetzt oder fehlt). Sie sind nur als Punkt eingezeichnet. Die Plattendatei selbst ist davon nicht betroffen.')); }
		}).catch(function (e) { errBox(body, e); });
	}

	UI.bambuTabs['platten'] = function (host) {
		var root = h('div');
		host.appendChild(root);
		var data = null;
		var warnings = [];
		var qtys = {};
		var filter = '';
		var listHost = null;

		function alive() { return root.isConnected; }

		// listOnly: nur die Plattenliste auffrischen, damit das Formular unberührt bleibt.
		function load(listOnly) {
			return UI.api('bambu/platten').then(function (d) { data = d; if (alive()) { paint(listOnly === true); } }).catch(function (e) { if (alive()) { errBox(root, e); } });
		}

		function paint(listOnly) {
			if (listOnly && listHost && listHost.isConnected) {
				listHost.textContent = '';
				listHost.appendChild(listBlock());
				return;
			}
			root.textContent = '';
			if (!data.printers.length) { root.appendChild(h('div', { class: 'notice' }, 'Zuerst einen Bambu-Drucker anlegen (Reiter „Drucker“).')); return; }
			root.appendChild(buildCard());
			listHost = h('div', null, listBlock());
			root.appendChild(listHost);
		}

		function buildCard() {
			var card = h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', { style: 'margin-top:0' }, 'Neue Platte anordnen'),
				h('p', { class: 'muted' }, 'Artikel und Mengen wählen; sie werden automatisch auf dem Druckbett des Druckers angeordnet. Daraus entsteht eine gemeinsame, noch ungeslicte Projekt-3MF.'));

			if (!data.articles.length) {
				card.appendChild(h('div', { class: 'notice' }, 'Kein Artikel mit ungeslicter Projekt-3MF und aktiver Revision gefunden. Die Projektdatei wird beim Artikel in der Revision hochgeladen.'));
				return card;
			}

			var printer = select(data.printers.map(function (p) { return [p.printer_id, p.name + (p.bed ? ' (' + p.bed + ')' : ' (Bettmaß unbekannt)')]; }), data.printers[0].printer_id);
			var margin = input('text', String(data.margin).replace('.', ','), { inputmode: 'decimal' });
			var search = input('search', filter, { placeholder: 'Artikel suchen …' });
			var sum = h('b', null, '');
			var list = h('div', { class: 'pf-qty' });
			var go = h('button', { class: 'btn primary', type: 'button' }, 'Automatisch anordnen');

			function total() {
				var n = 0;
				Object.keys(qtys).forEach(function (k) { n += qtys[k]; });
				return n;
			}

			function refreshSum() { sum.textContent = total() + ' Teile gewählt'; }

			function drawList() {
				list.textContent = '';
				var q = filter.toLowerCase();
				data.articles.forEach(function (a) {
					if (q && (a.sku + ' ' + a.name).toLowerCase().indexOf(q) < 0) { return; }
					var qty = input('number', qtys[a.id] || '', { min: 0, step: 1, inputmode: 'numeric', placeholder: '0', 'aria-label': 'Menge ' + a.sku });
					qty.addEventListener('input', function () {
						var n = parseInt(qty.value, 10);
						if (n > 0) { qtys[a.id] = n; } else { delete qtys[a.id]; }
						refreshSum();
					});
					list.appendChild(h('label', { class: 'pf-qline' }, h('span', null, h('b', null, a.sku), ' ', a.name), qty));
				});
				if (!list.firstChild) { list.appendChild(h('small', { class: 'muted' }, 'Kein Artikel passt zur Suche.')); }
			}

			search.addEventListener('input', function () { filter = search.value; drawList(); });
			go.addEventListener('click', function () {
				var items = Object.keys(qtys).map(function (k) { return { article_id: parseInt(k, 10), qty: qtys[k] }; });
				if (!items.length) { UI.toast('Bitte bei mindestens einem Artikel eine Menge eintragen.', true); return; }
				if (total() > data.max_parts) { UI.toast('Das sind zu viele Teile auf einmal (höchstens ' + data.max_parts + ').', true); return; }
				go.textContent = 'Wird angeordnet … das kann etwas dauern';
				post(go, 'bambu/platten', { printer_id: printer.value, margin_mm: margin.value, items: items }, function (r) {
					go.textContent = 'Automatisch anordnen';
					UI.toast(r.message);
					warnings = r.warnings || [];
					qtys = {};
					load().then(function () {
						var first = null;
						data.plates.forEach(function (p) { if (r.plate_ids.indexOf(p.id) > -1 && (!first || p.id < first.id)) { first = p; } });
						if (first) { openPlate(first); }
					});
				}).then(function () { go.textContent = 'Automatisch anordnen'; });
			});

			card.appendChild(h('div', { class: 'form-grid' }, field('Drucker', printer), field('Sicherheitsabstand (mm)', margin, 'Zwischen den Teilen und zum Bettrand.')));
			card.appendChild(h('div', { style: 'margin-top:12px' }, field('Artikel', search)));
			card.appendChild(h('div', { style: 'margin-top:8px' }, list));
			card.appendChild(h('div', { style: 'margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap' }, go, sum));
			if (warnings.length) {
				var box = h('div', { class: 'notice warn', style: 'margin-top:12px' }, h('b', null, 'Nicht alles ließ sich anordnen:'));
				warnings.forEach(function (w) { box.appendChild(h('p', null, w)); });
				card.appendChild(box);
			}
			drawList();
			refreshSum();
			return card;
		}

		function listBlock() {
			var wrap = h('div', null, h('h3', null, 'Angeordnete Platten'));
			if (!data.plates.length) { wrap.appendChild(h('div', { class: 'notice' }, 'Noch keine Platte angeordnet.')); return wrap; }
			var rows = h('div', { class: 'rows' });
			data.plates.forEach(function (p) { rows.appendChild(plateRow(p)); });
			wrap.appendChild(rows);
			wrap.appendChild(h('small', { class: 'muted' }, 'Gezeigt werden die letzten 50 Platten. „Projekt-3MF“ ist die ungeslicte Datei zum Öffnen und Slicen in Bambu Studio.'));
			return wrap;
		}

		function plateRow(p) {
			var parts = h('ul', { class: 'pf-list' });
			p.parts.forEach(function (x) { parts.appendChild(h('li', null, x.qty + ' × ' + x.label)); });

			var main = h('div', { class: 'row-main' },
				h('b', null, 'Platte #' + p.id + ' · ' + p.printer),
				h('div', { class: 'row-tags' }, h('span', { class: 'tag ' + (p.status === 'geslict' ? 'now' : 'later') }, p.text)),
				h('small', { class: 'muted' }, p.width + ' × ' + p.height + ' mm · ' + p.total + ' Teile · ' + UI.when(p.created)),
				parts);

			if (p.note) {
				main.appendChild(h('div', { class: p.note.kind === 'error' ? 'notice warn' : 'notice' }, p.note.text + (p.note.since ? ' (seit ' + UI.when(p.note.since) + ')' : '')));
			}
			if (p.queued) { main.appendChild(h('small', { class: 'muted' }, 'Liegt in der Bambu-Warteschlange (Reiter „Aufträge“).')); }
			if (!p.file_ok) { main.appendChild(h('div', { class: 'notice warn' }, 'Die Plattendatei liegt nicht mehr auf dem Server. Bitte die Platte neu anordnen.')); }

			var actions = h('div', { class: 'label-area' }, h('button', { class: 'btn', type: 'button', onclick: function () { openPlate(p); } }, 'Ansehen'));
			if (p.file_ok) { actions.appendChild(h('a', { class: 'btn', href: p.download }, 'Projekt-3MF')); }
			if (p.can_slice) {
				var slice = h('button', { class: 'btn primary', type: 'button', onclick: function () {
					post(slice, 'bambu/platten/' + p.id + '/slicen', {}, function (r) { UI.toast(r.message); }).then(function () { load(true); });
				} }, p.status === 'fehlgeschlagen' ? 'Erneut slicen' : 'Automatisch slicen');
				actions.appendChild(slice);
			}
			return h('div', { class: 'row-card' }, main, actions);
		}

		load();
		UI.every(function () {
			// Nur solange eine Platte geslict wird, ändert sich von selbst etwas.
			var running = data && data.plates.some(function (p) { return p.status === 'wird_geslict'; });
			if (alive() && running && !document.querySelector('dialog[open]')) { load(true); }
		}, 15000);
	};

	/* ====================================================================
	 * Reiter „Profile“ (Slicing)
	 * ================================================================== */

	UI.bambuTabs['profile'] = function (host, ctx) {
		var root = h('div');
		host.appendChild(root);
		var data = null;
		var form = null;

		function alive() { return root.isConnected; }

		function load() {
			return UI.api('bambu/slicing').then(function (d) { data = d; if (alive()) { paint(); } }).catch(function (e) { if (alive()) { errBox(root, e); } });
		}

		function paint() {
			root.textContent = '';
			root.appendChild(h('p', { class: 'muted' }, 'Je Material und Druckermodell braucht das automatische Slicen drei Profildateien aus OrcaSlicer oder Bambu Studio (Rechtsklick auf das Profil → Exportieren). Ein Profil mit gleichem Material, gleicher Farbe und gleichem Modell wird beim Hochladen ersetzt.'));
			root.appendChild(listBlock());
			if (data.manage) { root.appendChild(formCard()); }
			else { root.appendChild(h('div', { class: 'notice' }, 'Profile hochladen oder löschen darf nur, wer Stammdaten ändern darf.')); }
			if (ctx && ctx.classic) {
				root.appendChild(h('div', { class: 'notice', style: 'margin-top:14px' }, 'Worker-Token und die Namen der bekannten Bambu-Filamente gibt es noch in der klassischen Ansicht: ', ctx.classic('&tab=slicing')));
			}
		}

		function listBlock() {
			var wrap = h('div', null, h('h3', null, 'Hinterlegte Profile'));
			if (!data.profiles.length) { wrap.appendChild(h('div', { class: 'notice' }, 'Noch kein Profil hinterlegt. Ohne Profil kann keine Platte automatisch geslict werden.')); return wrap; }
			var rows = h('div', { class: 'rows' });
			data.profiles.forEach(function (p) {
				var color = p.color ? (p.color_label ? p.color_label + ' (' + p.color + ')' : p.color) : 'farblos';
				var main = h('div', { class: 'row-main' },
					h('b', null, p.material + ' · ' + p.model),
					h('small', { class: 'muted' }, 'Farbe: ' + color + (p.updated ? ' · Stand ' + UI.when(p.updated) : '')));
				if (!p.files_ok) { main.appendChild(h('div', { class: 'notice warn' }, 'Mindestens eine Profildatei fehlt auf dem Server. Bitte das Profil neu hochladen.')); }
				var row = h('div', { class: 'row-card' }, main);
				if (data.manage) {
					var actions = h('div', { class: 'label-area' });
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
						form = { material: p.material, model: p.model };
						paint();
						var el = root.querySelector('.bd-profile-form');
						if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'start' }); }
					} }, 'Ersetzen'));
					var del = h('button', { class: 'btn', type: 'button', onclick: function () {
						if (!window.confirm('Profil „' + p.material + ' · ' + p.model + '“ wirklich löschen? Platten für dieses Material lassen sich dann nicht mehr automatisch slicen.')) { return; }
						post(del, 'bambu/slicing/profil/' + p.id + '/loeschen', {}, function (r) { UI.toast(r.message); }).then(function () { load(); });
					} }, 'Löschen');
					actions.appendChild(del);
					row.appendChild(actions);
				}
				rows.appendChild(row);
			});
			wrap.appendChild(rows);
			return wrap;
		}

		function formCard() {
			var pre = form || {};
			var material = select(data.materials.map(function (m) { return [m, m]; }), pre.material || data.materials[0]);
			var model = input('text', pre.model || '', { placeholder: 'P1S', autocapitalize: 'off' });
			var files = {
				printer_profile: h('input', { type: 'file', accept: '.json,application/json', class: 'field' }),
				filament_profile: h('input', { type: 'file', accept: '.json,application/json', class: 'field' }),
				process_profile: h('input', { type: 'file', accept: '.json,application/json', class: 'field' })
			};
			var names = { printer_profile: 'Druckerprofil', filament_profile: 'Filamentprofil', process_profile: 'Prozessprofil' };
			var status = h('small', { class: 'muted', role: 'status' });
			var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
				if (!model.value.trim()) { UI.toast('Bitte das Druckermodell eintragen, z. B. P1S.', true); return; }
				var fd = new FormData();
				fd.append('material', material.value);
				fd.append('printer_model', model.value);
				var bad = '';
				Object.keys(files).forEach(function (k) {
					var f = files[k].files && files[k].files[0];
					if (!f) { bad = bad || 'Das ' + names[k] + ' fehlt. Bitte alle drei Dateien wählen.'; return; }
					if (!/\.json$/i.test(f.name)) { bad = bad || 'Das ' + names[k] + ' muss eine .json-Datei sein.'; return; }
					fd.append(k, f, f.name);
				});
				if (bad) { UI.toast(bad, true); return; }
				go.disabled = true;
				status.textContent = 'Wird hochgeladen …';
				upload('bambu/slicing/profil', fd).then(function (r) {
					go.disabled = false;
					form = null;
					UI.toast(r.message);
					load();
				}).catch(function (e) {
					go.disabled = false;
					status.textContent = '';
					UI.toast(e.message, true);
				});
			} }, 'Profil speichern');

			return h('div', { class: 'card bd-profile-form', style: 'margin-top:14px' },
				h('h3', { style: 'margin-top:0' }, form ? 'Profil ersetzen' : 'Profil hochladen'),
				h('div', { class: 'form-grid' },
					field('Material', material),
					field('Druckermodell', model, 'Muss zum Modell passen, das die Bridge meldet (Teilvergleich: „P1S“ passt zu „Bambu Lab P1S“).'),
					field('Druckerprofil (JSON)', files.printer_profile),
					field('Filamentprofil (JSON)', files.filament_profile, 'Die Farbe wird aus der Bambu-Filament-ID darin erkannt (z. B. GFA01). Ohne gültige ID gilt das Profil als farblos.'),
					field('Prozessprofil (JSON)', files.process_profile)),
				h('div', { style: 'margin-top:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap' }, go,
					form ? h('button', { class: 'btn', type: 'button', onclick: function () { form = null; paint(); } }, 'Abbrechen') : null, status));
		}

		load();
	};
})();
