/*
 * ERP-Seite „Import & Doku“ (native Fassung): CSV-Import mit Zuordnung und Vorschau,
 * Übernahme aus der alten Lagerverwaltung, Verfahrensdokumentation.
 * Serverseitig: includes/Erp/Pages/Import.php. Klassisches ES5, kein innerHTML mit Daten.
 *
 * Die gewählte Datei bleibt im Browser; jeder Schritt (Lesen, Vorschau, Import) lädt sie
 * neu hoch, auf dem Server bleibt nichts liegen.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var STEPS = ['Datei', 'Zuordnung', 'Vorschau', 'Ergebnis'];

	function mb(bytes) { return (bytes / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB'; }

	function fmt(n, d) {
		var v = Number(n);
		return isFinite(v) ? v.toLocaleString('de-DE', { minimumFractionDigits: d || 0, maximumFractionDigits: d === undefined ? 2 : d }) : '';
	}

	// Multipart-Anfrage (Datei + Felder) mit lesbaren Fehlern.
	function send(ui, path, fields, file) {
		var C = ui.config;
		var fd = new FormData();
		Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		fd.append('csv', file, file.name);

		return fetch(C.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce },
			body: fd
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok || (json && json.ok === false)) {
					var text;
					if (json && json.code === 'rest_cookie_invalid_nonce') { text = 'Die Sitzung ist abgelaufen – bitte die Seite neu laden und noch einmal beginnen.'; }
					else if (json && (json.message || json.error)) { text = json.message || json.error; }
					else if (res.status === 413) { text = 'Die Datei ist zu groß für den Server. Bitte in mehrere Dateien teilen.'; }
					else if (res.status === 403) { text = 'Dafür fehlt dir die Berechtigung.'; }
					else { text = 'Der Upload hat nicht geklappt (der Server antwortet mit ' + res.status + '). Bitte noch einmal versuchen.'; }
					throw new Error(text);
				}
				return json;
			});
		}, function () {
			throw new Error('Der Upload ist abgebrochen, weil die Verbindung weg war. Bitte noch einmal versuchen.');
		});
	}

	UI.pages['import'] = function (item, ui) {
		var h = ui.h;
		var view = ui.view();
		var tab = 'csv';

		ui.pageHead('Import & Doku', 'Daten aus der bisherigen Excel-Mappe übernehmen, Altbestände übertragen, Verfahrensdokumentation.', ui.classicBtn(item));

		var pills = h('div', { class: 'pills', role: 'tablist' });
		var body = h('div', { class: 'im' });
		view.appendChild(pills);
		view.appendChild(body);

		function notice(text, warn) { return h('div', { class: 'notice' + (warn ? ' warn' : '') }, text); }

		function drawPills() {
			pills.textContent = '';
			[['csv', 'CSV-Import'], ['alt', 'Übernahme Altbestand'], ['doku', 'Verfahrensdokumentation']].forEach(function (t) {
				pills.appendChild(h('button', {
					class: 'pill' + (tab === t[0] ? ' on' : ''), type: 'button', role: 'tab',
					'aria-selected': tab === t[0] ? 'true' : 'false',
					onclick: function () { tab = t[0]; show(); }
				}, t[1]));
			});
		}

		function show() {
			drawPills();
			body.textContent = '';
			if (tab === 'csv') { csvPanel(body); }
			else if (tab === 'alt') { legacyPanel(body); }
			else { dokuPanel(body); }
		}

		/* ------------------------------------------------------------
		 * CSV-Import
		 * ---------------------------------------------------------- */
		function csvPanel(box) {
			var st = { step: 0, sheets: null, sheet: '', file: null, read: null, map: {}, result: null, done: null, busy: false };
			var holder = h('div', { class: 'im-wizard' });
			box.appendChild(holder);
			holder.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));

			ui.api('import').then(function (d) {
				st.sheets = d.sheets;
				st.sheet = d.sheets[0].key;
				st.limits = { bytes: d.max_bytes, rows: d.max_rows };
				paint();
			}).catch(function (e) { holder.textContent = ''; holder.appendChild(notice(e.message, true)); });

			function sheetOf(key) {
				for (var i = 0; i < st.sheets.length; i++) { if (st.sheets[i].key === key) { return st.sheets[i]; } }
				return st.sheets[0];
			}

			function stepper() {
				var ol = h('ol', { class: 'im-steps', 'aria-label': 'Schritte' });
				STEPS.forEach(function (name, i) {
					ol.appendChild(h('li', { class: i === st.step ? 'on' : (i < st.step ? 'past' : ''), 'aria-current': i === st.step ? 'step' : null },
						h('span', { class: 'im-no' }, String(i + 1)), h('span', { class: 'im-name' }, name)));
				});
				return ol;
			}

			function paint() {
				holder.textContent = '';
				holder.appendChild(stepper());
				if (st.step === 0) { stepFile(); }
				else if (st.step === 1) { stepMap(); }
				else if (st.step === 2) { stepPreview(); }
				else { stepDone(); }
			}

			function errorBox(msg) { return h('div', { class: 'notice warn', role: 'alert' }, msg); }

			function reset() {
				st.step = 0; st.file = null; st.read = null; st.map = {}; st.result = null; st.done = null;
				paint();
			}

			/* ---- Schritt 1: Datei ---- */
			function stepFile() {
				var sel = h('select', { class: 'field', id: 'im-sheet' });
				st.sheets.forEach(function (s) {
					var o = h('option', { value: s.key }, s.label);
					if (s.key === st.sheet) { o.selected = true; }
					sel.appendChild(o);
				});
				var fileIn = h('input', { type: 'file', id: 'im-file', class: 'field im-file', accept: '.csv,.txt,text/csv,text/plain' });
				var msg = h('div', { class: 'im-msg', 'aria-live': 'polite' });
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Datei lesen');

				var form = h('form', { class: 'card im-form', novalidate: true },
					h('h3', null, 'Blatt und Datei wählen'),
					h('p', { class: 'muted' }, 'Jedes Blatt der Mappe als CSV speichern (in Excel: „CSV (Trennzeichen-getrennt)“; Semikolon oder Komma, deutsche Zahlen und Daten wie 31.12.2025 sind in Ordnung) und hier einzeln hochladen. Reihenfolge: Artikel, Drucker, Filament, danach das Druckjournal.'),
					h('label', { class: 'im-f', for: 'im-sheet' }, h('span', null, 'Blatt'), sel),
					h('label', { class: 'im-f', for: 'im-file' }, h('span', null, 'CSV-Datei'), fileIn,
						h('small', { class: 'muted' }, 'Höchstens ' + mb(st.limits.bytes) + ' und ' + fmt(st.limits.rows) + ' Zeilen je Datei.')),
					msg,
					h('div', { class: 'im-actions' }, go));
				holder.appendChild(form);

				sel.addEventListener('change', function () { st.sheet = sel.value; });
				form.addEventListener('submit', function (ev) {
					ev.preventDefault();
					var file = fileIn.files && fileIn.files[0];
					msg.textContent = '';
					if (!file) { msg.appendChild(errorBox('Bitte zuerst eine CSV-Datei wählen.')); return; }
					if (file.size < 1) { msg.appendChild(errorBox('Die Datei ist leer.')); return; }
					if (file.size > st.limits.bytes) { msg.appendChild(errorBox('Die Datei ist ' + mb(file.size) + ' groß, der Server nimmt höchstens ' + mb(st.limits.bytes) + ' an.')); return; }
					go.disabled = true;
					go.textContent = 'Wird gelesen …';
					send(ui, 'import/lesen', { sheet: st.sheet }, file).then(function (r) {
						st.file = file; st.read = r; st.map = {};
						Object.keys(r.mapping || {}).forEach(function (k) { if (r.mapping[k] !== '') { st.map[k] = r.mapping[k]; } });
						st.step = 1;
						paint();
					}).catch(function (e) {
						go.disabled = false;
						go.textContent = 'Datei lesen';
						msg.textContent = '';
						msg.appendChild(errorBox(e.message));
					});
				});
			}

			/* ---- Schritt 2: Zuordnung ---- */
			function stepMap() {
				var sheet = sheetOf(st.sheet);
				var r = st.read;
				var selects = {};
				var msg = h('div', { class: 'im-msg', 'aria-live': 'polite' });

				var list = h('div', { class: 'im-map' });
				sheet.fields.forEach(function (f) {
					var sel = h('select', { class: 'field', id: 'im-map-' + f.key });
					sel.appendChild(h('option', { value: '' }, '– nicht importieren –'));
					r.columns.forEach(function (name, idx) {
						var o = h('option', { value: String(idx) }, name !== '' ? name : 'Spalte ' + (idx + 1));
						if (st.map[f.key] !== undefined && Number(st.map[f.key]) === idx) { o.selected = true; }
						sel.appendChild(o);
					});
					selects[f.key] = sel;
					var sampleTxt = h('small', { class: 'muted im-sample' });
					function sample() {
						var v = sel.value;
						sampleTxt.textContent = '';
						if (v === '') { sampleTxt.textContent = f.required ? 'Pflichtfeld – bitte eine Spalte wählen.' : ''; return; }
						var vals = [];
						(r.sample || []).forEach(function (row) { if (row[Number(v)] !== undefined && row[Number(v)] !== '') { vals.push(row[Number(v)]); } });
						sampleTxt.textContent = vals.length ? 'z. B. ' + vals.join(' · ') : 'in den ersten Zeilen leer';
					}
					sel.addEventListener('change', sample);
					sample();
					list.appendChild(h('div', { class: 'im-maprow' },
						h('label', { for: 'im-map-' + f.key }, f.label, f.required ? h('span', { class: 'tag now im-req' }, 'Pflicht') : null),
						sel, sampleTxt));
				});

				var back = h('button', { class: 'btn', type: 'button', onclick: function () { st.step = 0; paint(); } }, 'Zurück');
				var go = h('button', { class: 'btn primary', type: 'button' }, 'Vorschau erstellen');

				holder.appendChild(h('div', { class: 'card im-form' },
					h('h3', null, 'Spalten zuordnen'),
					h('p', { class: 'muted' }, '„' + st.file.name + '“: ' + fmt(r.count) + ' Datenzeilen, ' + r.columns.length + ' Spalten. Gleichnamige Spalten sind schon vorgewählt; bitte prüfen.'),
					list, msg,
					h('div', { class: 'im-actions' }, back, go)));

				go.addEventListener('click', function () {
					msg.textContent = '';
					var map = {};
					var missing = [];
					sheet.fields.forEach(function (f) {
						var v = selects[f.key].value;
						if (v !== '') { map[f.key] = Number(v); }
						else if (f.required) { missing.push(f.label); }
					});
					if (missing.length) { msg.appendChild(errorBox('Diese Pflichtfelder brauchen noch eine Spalte: ' + missing.join(', ') + '.')); return; }
					st.map = map;
					go.disabled = true;
					go.textContent = 'Wird geprüft …';
					send(ui, 'import/pruefen', { sheet: st.sheet, map: JSON.stringify(map) }, st.file).then(function (res) {
						st.result = res; st.step = 2; paint();
					}).catch(function (e) {
						go.disabled = false;
						go.textContent = 'Vorschau erstellen';
						msg.appendChild(errorBox(e.message));
					});
				});
			}

			function tile(label, value, cls) {
				return h('div', { class: 'tile' + (cls ? ' ' + cls : '') }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value));
			}

			function errorList(res) {
				if (!res.errors.length) { return null; }
				var ul = h('ul', { class: 'im-errors' });
				res.errors.forEach(function (e) {
					ul.appendChild(h('li', null, h('b', null, 'Zeile ' + e.row + ': '), e.message));
				});
				if (res.more) { ul.appendChild(h('li', { class: 'muted' }, '… und ' + fmt(res.more) + ' weitere Zeilen mit Fehlern.')); }
				return h('div', { class: 'card im-errcard' }, h('h3', null, 'Zeilen mit Fehlern'), ul);
			}

			function previewTable(res) {
				if (!res.preview.length) { return null; }
				var head = h('tr', null, h('th', null, 'Zeile'));
				res.columns.forEach(function (c) { head.appendChild(h('th', null, c.label)); });
				head.appendChild(h('th', null, 'Ergebnis'));
				var tb = h('tbody');
				res.preview.forEach(function (row) {
					var tr = h('tr', { class: row.ok ? '' : 'im-bad' }, h('td', { class: 'num' }, String(row.row)));
					res.columns.forEach(function (c) { tr.appendChild(h('td', null, row.values[c.key] === undefined ? '' : String(row.values[c.key]))); });
					tr.appendChild(h('td', null, row.ok ? h('span', { class: 'tag im-ok' }, 'ok' + (row.note ? ' – ' + row.note : '')) : h('span', { class: 'im-errtxt' }, row.note)));
					tb.appendChild(tr);
				});
				return h('div', { class: 'card' },
					h('h3', null, 'Die ersten ' + res.preview.length + ' Zeilen'),
					h('div', { class: 'im-scroll' }, h('table', { class: 'list' }, h('thead', null, head), tb)));
			}

			/* ---- Schritt 3: Vorschau ---- */
			function stepPreview() {
				var res = st.result;
				var tiles = h('div', { class: 'grid tiles' },
					tile('Zeilen mit Inhalt', fmt(res.total)),
					tile('Gültig', fmt(res.valid)),
					tile('Mit Fehlern', fmt(res.failed), res.failed ? 'im-tile-bad' : ''));
				if (res.updated) { tiles.appendChild(tile('Davon vorhandene Artikel', fmt(res.updated))); }

				var msg = h('div', { class: 'im-msg', 'aria-live': 'polite' });
				var back = h('button', { class: 'btn', type: 'button', onclick: function () { st.step = 1; paint(); } }, 'Zuordnung ändern');
				var other = h('button', { class: 'btn', type: 'button', onclick: reset }, 'Andere Datei');
				var go = h('button', { class: 'btn primary', type: 'button' }, res.valid ? fmt(res.valid) + ' Datensätze jetzt importieren' : 'Nichts zu importieren');
				if (!res.valid) { go.disabled = true; }

				holder.appendChild(h('div', { class: 'card im-form' },
					h('h3', null, 'Vorschau – es wurde noch nichts gespeichert'),
					h('p', { class: 'muted' }, res.failed
						? 'Beim Import werden die ' + fmt(res.valid) + ' gültigen Zeilen übernommen; die ' + fmt(res.failed) + ' fehlerhaften werden übersprungen. Du kannst die Datei auch verbessern und neu laden.'
						: 'Alle Zeilen sind gültig.'),
					tiles));
				var errs = errorList(res);
				if (errs) { holder.appendChild(errs); }
				var tbl = previewTable(res);
				if (tbl) { holder.appendChild(tbl); }
				holder.appendChild(h('div', { class: 'card im-form' }, msg, h('div', { class: 'im-actions' }, back, other, go)));

				go.addEventListener('click', function () {
					msg.textContent = '';
					var d = ui.dlg('Import durchführen?', h('div', { class: 'im-dlg' },
						h('p', null, fmt(res.valid) + ' Datensätze werden in „' + sheetOf(st.sheet).label + '“ geschrieben' + (res.updated ? ' (davon ' + fmt(res.updated) + ' vorhandene Artikel, die aktualisiert werden)' : '') + '. Das lässt sich nicht mit einem Klick zurücknehmen.'),
						h('div', { class: 'im-actions' },
							h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen'),
							h('button', { class: 'btn primary', type: 'button', onclick: function () { d.close(); runImport(go, msg); } }, 'Ja, importieren'))));
				});
			}

			function runImport(go, msg) {
				go.disabled = true;
				go.textContent = 'Wird importiert … bitte nicht schließen';
				send(ui, 'import/ausfuehren', { sheet: st.sheet, map: JSON.stringify(st.map), confirm: '1' }, st.file).then(function (res) {
					st.done = res; st.step = 3; paint();
					ui.toast(res.message);
				}).catch(function (e) {
					go.disabled = false;
					go.textContent = fmt(st.result.valid) + ' Datensätze jetzt importieren';
					msg.textContent = '';
					msg.appendChild(errorBox(e.message));
				});
			}

			/* ---- Schritt 4: Ergebnis ---- */
			function stepDone() {
				var res = st.done;
				holder.appendChild(h('div', { class: 'card im-form' },
					h('h3', null, 'Import abgeschlossen'),
					h('div', { class: 'notice', role: 'status' }, res.message),
					h('div', { class: 'grid tiles' }, tile('Übernommen', fmt(res.valid)), tile('Übersprungen', fmt(res.failed), res.failed ? 'im-tile-bad' : '')),
					h('div', { class: 'im-actions' }, h('button', { class: 'btn primary', type: 'button', onclick: reset }, 'Weiteres Blatt importieren'))));
				var errs = errorList(res);
				if (errs) { holder.appendChild(errs); }
			}
		}

		/* ------------------------------------------------------------
		 * Übernahme aus der alten Lagerverwaltung
		 * ---------------------------------------------------------- */
		function legacyPanel(box) {
			var card = h('div', { class: 'card im-form' }, 'Wird geladen …');
			box.appendChild(card);

			function load() {
				ui.api('import/uebernahme').then(function (d) {
					card.textContent = '';
					card.appendChild(h('h3', null, 'Übernahme aus der alten Lagerverwaltung'));
					if (!d.rows.length) {
						card.appendChild(h('p', null, 'In der alten Lagerverwaltung stehen keine Produkte. Es gibt nichts zu übernehmen.'));
						return;
					}
					card.appendChild(h('p', { class: 'muted' }, 'Gelöscht wird dabei nichts: Die alten Tabellen bleiben als Beleg stehen, und der Bestand kommt als eine einzige Anfangsbuchung je Artikel ins Bewegungskonto. Zweimal ausgeführt entsteht kein doppelter Bestand – schon übernommene Produkte werden erkannt und übersprungen.'));
					var tb = h('tbody');
					d.rows.forEach(function (r) {
						tb.appendChild(h('tr', null,
							h('td', null, r.name), h('td', null, r.typ), h('td', { class: 'num' }, fmt(r.stock, 0)), h('td', null, r.target),
							h('td', null, h('code', null, r.sku)),
							h('td', null, h('span', { class: 'tag' + (r.status === 'neu' ? ' now' : '') }, r.status === 'neu' ? 'neu' : 'schon übernommen'))));
					});
					card.appendChild(h('div', { class: 'im-scroll' }, h('table', { class: 'list' },
						h('thead', null, h('tr', null, h('th', null, 'Altes Produkt'), h('th', null, 'Typ'), h('th', { class: 'num' }, 'Bestand'), h('th', null, 'wird zu'), h('th', null, 'Artikelnummer'), h('th', null, 'Zustand'))), tb)));
					if (!d['new']) {
						card.appendChild(h('p', null, 'Alles bereits übernommen.'));
						return;
					}
					var go = h('button', { class: 'btn primary', type: 'button' }, d['new'] + ' Produkte jetzt übernehmen');
					go.addEventListener('click', function () {
						var dd = ui.dlg('Produkte übernehmen?', h('div', { class: 'im-dlg' },
							h('p', null, d['new'] + ' Produkte werden als Artikel angelegt und ihr Bestand als Anfangsbuchung gebucht.'),
							h('div', { class: 'im-actions' },
								h('button', { class: 'btn', type: 'button', onclick: function () { dd.close(); } }, 'Abbrechen'),
								h('button', { class: 'btn primary', type: 'button', onclick: function () {
									dd.close();
									go.disabled = true;
									ui.api('import/uebernahme', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); card.textContent = 'Wird geladen …'; load(); }).catch(function (e) { go.disabled = false; ui.toast(e.message, true); });
								} }, 'Ja, übernehmen'))));
					});
					card.appendChild(h('div', { class: 'im-actions' }, go));
				}).catch(function (e) { card.textContent = ''; card.appendChild(notice(e.message, true)); });
			}

			load();
		}

		/* ------------------------------------------------------------
		 * Verfahrensdokumentation
		 * ---------------------------------------------------------- */
		function dokuPanel(box) {
			var card = h('div', { class: 'card im-form' }, 'Wird geladen …');
			box.appendChild(card);

			ui.api('import/doku').then(function (d) {
				card.textContent = '';
				card.appendChild(h('h3', null, 'Verfahrensdokumentation'));
				card.appendChild(h('p', { class: 'muted' }, 'Beschreibt Konfiguration, Datenmodell, Berechnungsformeln und Prozesse des Moduls. Das Dokument liest die Werte beim Erzeugen aus dem System – es ist keine Abschrift, sondern der tatsächliche Stand.'));
				card.appendChild(h('div', { class: 'im-actions' }, h('a', { class: 'btn primary', href: d.pdf_url, target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'Als PDF erzeugen')));
				var doc = h('div', { class: 'im-doku' });
				d.lines.forEach(function (l) {
					if (l[0] === 'h1') { doc.appendChild(h('h4', null, l[1])); }
					else if (l[0] === 'h2') { doc.appendChild(h('h5', null, l[1])); }
					else { doc.appendChild(h('p', null, l[1])); }
				});
				card.appendChild(h('h4', null, 'Vorschau'));
				card.appendChild(doc);
			}).catch(function (e) { card.textContent = ''; card.appendChild(notice(e.message, true)); });
		}

		show();
	};
})();
