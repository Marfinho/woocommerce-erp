/*
 * Konfigurator: Teile (Basismodul, Module) und Sperrbereiche, nativ statt der
 * eingebetteten wp-admin-Seite.
 *
 *   Liste    Suche, Rolle, Zustand; Antippen öffnet das Teil
 *   Teil     ansehen, ändern (Felder gruppiert, Modell-Upload .glb), ausblenden, löschen
 *   Sperr-   am Basismodul: anlegen und löschen
 *   bereiche
 *
 * Server: includes/Erp/Pages/Konfigurator.php. Klassisches ES5, kein innerHTML mit Daten.
 * Eine Vorschau gibt es auch in der alten Seite nicht (der Konfigurator läuft über den
 * Shortcode auf einer Shopseite); dorthin führt der Hinweis oben.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	function mm(v) {
		v = Number(v);
		if (!isFinite(v)) { return '–'; }
		return v.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' mm';
	}

	function mb(bytes) {
		bytes = Number(bytes) || 0;
		return bytes >= 1048576 ? (bytes / 1048576).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB' : Math.round(bytes / 1024) + ' KB';
	}

	function upload(ui, path, fd) {
		var C = ui.config;

		return fetch(C.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce },
			body: fd
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok || (json && json.ok === false)) {
					var text;
					if (json && json.code === 'rest_cookie_invalid_nonce') { text = 'Die Sitzung ist abgelaufen – bitte die Seite neu laden und noch einmal speichern.'; }
					else if (json && (json.message || json.error)) { text = json.message || json.error; }
					else if (res.status === 413) { text = 'Die Datei ist zu groß für den Server. Bitte beim Hoster das Upload-Limit erhöhen lassen.'; }
					else if (res.status === 403) { text = 'Dafür fehlt dir die Berechtigung (Konfigurator und Stammdaten ändern).'; }
					else { text = 'Das Speichern hat nicht geklappt (der Server antwortet mit ' + res.status + '). Bitte noch einmal versuchen.'; }
					var err = new Error(text);
					err.saved = !!(json && json.saved);
					throw err;
				}
				return json;
			});
		}, function () {
			throw new Error('Das Speichern ist abgebrochen, weil die Verbindung weg war. Bitte noch einmal versuchen.');
		});
	}

	UI.pages['konfigurator'] = function (item, ui) {
		var h = ui.h;
		var edit = ui.can('fertigung.stammdaten.edit');
		var meta = null;
		var state = { q: '', rolle: '', zustand: '' };
		var timer = null;
		var seq = 0;

		ui.pageHead('Konfigurator', 'Welche Artikel im Produktkonfigurator als Schiene oder Modul erscheinen, wie hoch sie bauen und wo kein Modul sitzen darf.', ui.classicBtn(item));

		var intro = h('details', { class: 'card kf-intro' },
			h('summary', null, 'So funktioniert es'),
			h('p', null, 'Eingebunden wird der Konfigurator mit dem Shortcode ', h('code', { id: 'kf-code' }, '[…]'), ' auf einer Seite im Shop.'),
			h('p', null, 'Die Modelle sind Schaustücke, keine Konstruktionsdaten: aus dem CAD als STL oder 3MF exportieren, im Netz auf wenige tausend Dreiecke reduzieren und als .glb hochladen. Innengeometrie, Gewinde und Toleranzen gehören dort nicht hinein – die Datei ist im Netz öffentlich abrufbar.'),
			h('p', null, 'Ohne hochgeladenes Modell zeigt der Konfigurator einen maßhaltigen Ersatzkörper aus Höhe, Breite und Tiefe. Bestellen lässt sich damit alles, es sieht nur nicht nach dem Haken aus.'),
			h('p', null, 'Zum Ausprobieren liegen drei Demo-Modelle bei: eine Schiene mit zwei Schraubaugen (600 mm), ein Haken (80 mm) und eine Ablage (150 mm). Passend dazu gehören am Basismodul zwei Sperrbereiche von 40 bis 90 mm und von 500 bis 550 mm.'));
		ui.view().appendChild(intro);

		var search = h('input', { class: 'field', type: 'search', placeholder: 'Suchen: Name, Artikelnummer, Kurzbeschreibung', 'aria-label': 'Teile suchen', autocomplete: 'off' });
		search.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () { state.q = search.value; load(); }, 250);
		});

		var chipBox = h('div', { class: 'presets' });
		var addBtn = edit ? h('button', { class: 'btn primary', type: 'button', onclick: function () { openForm(null); } }, 'Teil anlegen') : null;
		var tools = h('div', { class: 'card kf-tools' }, h('div', { class: 'kf-search' }, search, addBtn), chipBox);
		var listBox = h('div', { class: 'rows kf-list' });
		ui.view().appendChild(tools);
		ui.view().appendChild(listBox);

		function chips() {
			chipBox.textContent = '';
			var defs = [
				['rolle', '', 'Alle Rollen'], ['rolle', 'basis', 'Basismodule'], ['rolle', 'modul', 'Module'],
				['zustand', '', 'Alle'], ['zustand', 'aktiv', 'Angeboten'], ['zustand', 'inaktiv', 'Ausgeblendet'], ['zustand', 'ohne-modell', 'Ohne Modell']
			];
			defs.forEach(function (d) {
				chipBox.appendChild(h('button', {
					class: 'chip', type: 'button', 'aria-pressed': state[d[0]] === d[1] ? 'true' : 'false',
					onclick: function () { state[d[0]] = d[1]; chips(); load(); }
				}, d[2]));
			});
		}

		function query() {
			var p = [];
			if (state.q) { p.push('q=' + encodeURIComponent(state.q)); }
			if (state.rolle) { p.push('rolle=' + encodeURIComponent(state.rolle)); }
			if (state.zustand) { p.push('zustand=' + encodeURIComponent(state.zustand)); }
			return 'konfigurator' + (p.length ? '?' + p.join('&') : '');
		}

		function tag(text, cls) { return h('span', { class: 'tag' + (cls ? ' ' + cls : '') }, text); }

		function modelText(p) {
			if (p.model_kind === 'upload') { return 'Eigenes Modell'; }
			if (p.model_kind === 'demo') { return 'Demo-Modell'; }
			return 'Ersatzkörper';
		}

		function paint(d) {
			meta = d;
			var code = document.getElementById('kf-code');
			if (code) { code.textContent = d.shortcode; }
			listBox.textContent = '';

			if (!d.parts.length) {
				listBox.appendChild(h('div', { class: 'card' }, d.total
					? 'Kein Teil passt zu dieser Suche. Suchtext oder Filter ändern.'
					: 'Noch kein Teil angelegt. Zuerst das Basismodul (die Schiene) anlegen, danach die Module.'));
				return;
			}

			d.parts.forEach(function (p) {
				var live = p.is_active && p.article_active;
				var warn = !p.article_ok ? 'Artikel fehlt – das Teil erscheint im Shop nicht.' : (p.is_active && !p.article_active ? 'Der Artikel ist stillgelegt – das Teil erscheint im Shop nicht.' : '');
				var tags = h('div', { class: 'row-tags' },
					tag(p.role === 'basis' ? 'Basismodul' : 'Modul'),
					tag(live ? 'Angeboten' : 'Ausgeblendet', live ? 'now' : 'later'),
					tag(modelText(p)),
					p.role === 'basis' ? tag(p.blocks.length + (p.blocks.length === 1 ? ' Sperrbereich' : ' Sperrbereiche')) : null);
				listBox.appendChild(h('button', { class: 'row-card kf-row', type: 'button', onclick: function () { openPart(p.id); } },
					h('div', { class: 'row-main' },
						h('strong', null, p.name || 'Artikel fehlt'),
						h('div', { class: 'muted' }, (p.sku ? p.sku + ' · ' : '') + (p.role === 'basis' ? 'Länge ' : 'Höhe ') + mm(p.height_mm)),
						p.teaser ? h('div', { class: 'muted' }, p.teaser) : null,
						warn ? h('div', { class: 'kf-warn' }, warn) : null,
						tags),
					h('span', { class: 'row-num' }, ui.ico('chev'))));
			});
		}

		function load() {
			var mine = ++seq;
			return ui.api(query()).then(function (d) {
				if (mine !== seq) { return; }
				paint(d);
			}).catch(function (e) {
				if (mine !== seq) { return; }
				listBox.textContent = '';
				listBox.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		function find(id) {
			return meta ? meta.parts.filter(function (p) { return p.id === id; })[0] : null;
		}

		/* ---------- Dialog ---------- */

		var dlgBody = null;
		var dlgEl = null;

		function openDialog(title) {
			if (dlgEl && dlgEl.open) { dlgEl.close(); }
			dlgBody = h('div', { class: 'sheet-body kf-sheet' });
			dlgEl = ui.dlg(title, dlgBody, true);
			return dlgBody;
		}

		function closeDialog() { if (dlgEl && dlgEl.open) { dlgEl.close(); } }

		function say(box, text, bad) {
			box.textContent = text;
			box.className = 'kf-msg ' + (bad ? 'err' : 'ok');
			box.hidden = !text;
		}

		function run(btn, box, work) {
			var old = btn.textContent;
			btn.disabled = true;
			say(box, '', false);
			return work().then(function (r) { btn.disabled = false; btn.textContent = old; return r; }, function (e) {
				btn.disabled = false;
				btn.textContent = old;
				say(box, e.message, true);
				return null;
			});
		}

		function post(path, body) { return ui.api(path, { method: 'POST', body: body || {} }); }

		/* ---------- Ansehen ---------- */

		function kv(label, value) {
			return h('div', { class: 'kf-kv' }, h('span', { class: 'muted' }, label), h('span', null, value));
		}

		function openPart(id, note) {
			var p = find(id);
			if (!p) { return; }
			var body = openDialog(p.name || 'Teil');
			var msg = h('div', { class: 'kf-msg', role: 'status', 'aria-live': 'polite', hidden: true });
			var live = p.is_active && p.article_active;

			body.appendChild(msg);
			if (note) { say(msg, note, false); }

			body.appendChild(h('div', { class: 'row-tags' },
				tag(p.role === 'basis' ? 'Basismodul (die Schiene)' : 'Modul (hängt an der Schiene)'),
				tag(live ? 'Angeboten' : 'Ausgeblendet', live ? 'now' : 'later')));

			if (!p.article_ok) { body.appendChild(h('div', { class: 'notice warn' }, 'Der Artikel dieses Teils ist im Artikelstamm nicht mehr vorhanden. Das Teil erscheint im Shop nicht; es kann gelöscht werden.')); }
			else if (p.is_active && !p.article_active) { body.appendChild(h('div', { class: 'notice warn' }, 'Der Artikel ist stillgelegt. Solange das so ist, erscheint das Teil im Shop nicht.')); }

			var info = h('div', { class: 'kf-kvs' },
				kv('Artikel', (p.name || '–') + (p.sku ? ' (' + p.sku + ')' : '')),
				kv(p.role === 'basis' ? 'Länge' : 'Bauhöhe', mm(p.height_mm)),
				kv('Breite', p.width_mm ? mm(p.width_mm) : '–'),
				kv('Tiefe', p.depth_mm ? mm(p.depth_mm) : '–'),
				p.role === 'basis' ? kv('Module höchstens', p.max_modules ? String(p.max_modules) : 'so viele, wie der Platz hergibt') : null,
				kv('Reihenfolge', String(p.sort_order)),
				kv('Kurzbeschreibung', p.teaser || '–'),
				kv('Schaumodell', p.model_kind === 'demo' ? p.model_demo : (p.model_kind === 'upload' ? 'Eigene Datei: ' + p.model_file : 'Keines – maßhaltiger Ersatzkörper')));
			body.appendChild(info);

			if (p.model_url) {
				body.appendChild(h('p', null, h('a', { class: 'btn', href: p.model_url, target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'Modelldatei öffnen')));
			}

			if (p.role === 'basis') { body.appendChild(blocksView(p, msg)); }

			if (edit) {
				var toggle = h('button', { class: 'btn', type: 'button' }, p.is_active ? 'Ausblenden' : 'Wieder anbieten');
				toggle.addEventListener('click', function () {
					run(toggle, msg, function () {
						return post('konfigurator/teile/' + p.id + '/aktiv', { aktiv: p.is_active ? 0 : 1 }).then(function (r) {
							ui.toast(r.message);
							return load().then(function () { openPart(p.id, r.message); });
						});
					});
				});
				var del = h('button', { class: 'btn kf-danger', type: 'button' }, 'Aus dem Konfigurator entfernen');
				del.addEventListener('click', function () {
					if (!window.confirm('Teil „' + (p.name || 'ohne Artikel') + '“ wirklich aus dem Konfigurator entfernen? Seine Sperrbereiche und eine hochgeladene Modelldatei werden mit gelöscht. Der Artikel selbst bleibt unverändert.')) { return; }
					run(del, msg, function () {
						return post('konfigurator/teile/' + p.id + '/loeschen').then(function (r) {
							ui.toast(r.message);
							closeDialog();
							return load();
						});
					});
				});
				body.appendChild(h('div', { class: 'kf-actions' },
					h('button', { class: 'btn primary', type: 'button', onclick: function () { openForm(p); } }, 'Bearbeiten'),
					toggle, del));
			}
		}

		function blocksView(p, msg) {
			var box = h('section', { class: 'kf-blocks' },
				h('h4', null, 'Sperrbereiche'),
				h('p', { class: 'muted' }, 'Abschnitte des Basismoduls, auf denen kein Modul sitzen darf – üblicherweise die beiden Stellen, an denen der Haken an die Wand geschraubt wird. Gemessen von der Unterkante.'));

			if (!p.blocks.length) { box.appendChild(h('p', { class: 'muted' }, 'Noch kein Sperrbereich angelegt.')); }

			p.blocks.forEach(function (b) {
				var del = h('button', { class: 'btn kf-danger', type: 'button', 'aria-label': 'Sperrbereich löschen' }, 'Löschen');
				del.addEventListener('click', function () {
					if (!window.confirm('Sperrbereich ' + mm(b.from_mm) + ' bis ' + mm(b.to_mm) + ' wirklich löschen?')) { return; }
					run(del, msg, function () {
						return post('konfigurator/teile/' + p.id + '/sperrbereiche/' + b.id + '/loeschen').then(function (r) {
							return load().then(function () { openPart(p.id, r.message); });
						});
					});
				});
				box.appendChild(h('div', { class: 'kf-block' },
					h('div', null, h('strong', null, mm(b.from_mm) + ' bis ' + mm(b.to_mm)), b.label ? h('div', { class: 'muted' }, b.label) : null),
					edit ? del : null));
			});

			if (edit) {
				var from = h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', 'aria-label': 'Von (mm)' });
				var to = h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', 'aria-label': 'Bis (mm)' });
				var label = h('input', { class: 'field', type: 'text', maxlength: '120', placeholder: 'Wandbefestigung oben', 'aria-label': 'Bezeichnung' });
				var add = h('button', { class: 'btn', type: 'submit' }, 'Sperrbereich anlegen');
				var form = h('form', { class: 'kf-form kf-blockform' },
					h('div', { class: 'kf-grid' },
						h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Von (mm)'), from),
						h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Bis (mm)'), to)),
					h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Bezeichnung (optional)'), label),
					add);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (!from.value.trim() || !to.value.trim()) { say(msg, 'Bitte Anfang und Ende des Sperrbereichs in Millimetern eintragen.', true); return; }
					run(add, msg, function () {
						return post('konfigurator/teile/' + p.id + '/sperrbereiche', { from_mm: from.value, to_mm: to.value, label: label.value }).then(function (r) {
							return load().then(function () { openPart(p.id, r.message); });
						});
					});
				});
				box.appendChild(form);
			}

			return box;
		}

		/* ---------- Anlegen und ändern ---------- */

		function openForm(p) {
			var body = openDialog(p ? 'Teil bearbeiten' : 'Teil anlegen');
			var msg = h('div', { class: 'kf-msg', role: 'alert', hidden: true });
			var cur = p || { role: 'modul', height_mm: '', width_mm: '', depth_mm: '', max_modules: 0, sort_order: 0, is_active: 1, teaser: '', model_kind: 'none', model_file: '', model_demo: '' };

			function field(label, control, hint) {
				return h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, label), control, hint ? h('span', { class: 'kf-hint' }, hint) : null);
			}
			function num(v, label) {
				return h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', value: v === '' || v === null || v === undefined ? '' : String(v).replace('.', ','), 'aria-label': label });
			}

			var article = h('select', { class: 'field', 'aria-label': 'Artikel' });
			article.appendChild(h('option', { value: '' }, '– Artikel wählen –'));
			meta.articles.forEach(function (a) {
				var taken = a.part_id && (!p || a.part_id !== p.id);
				var o = h('option', { value: String(a.id) }, a.name + ' (' + a.sku + ')' + (taken ? ' – schon als Teil angelegt' : ''));
				if (taken) { o.disabled = true; }
				if (p && a.id === p.article_id) { o.selected = true; }
				article.appendChild(o);
			});

			var roleBox = h('div', { class: 'kf-radios', role: 'radiogroup', 'aria-label': 'Rolle' });
			var roleInputs = [];
			meta.roles.forEach(function (r) {
				var input = h('input', { type: 'radio', name: 'kf-role', value: r.value, checked: cur.role === r.value });
				roleInputs.push(input);
				roleBox.appendChild(h('label', { class: 'kf-radio' }, input, h('span', null, r.label)));
			});
			function role() { var v = 'modul'; roleInputs.forEach(function (i) { if (i.checked) { v = i.value; } }); return v; }

			var height = num(cur.height_mm, 'Höhe bzw. Länge in Millimetern');
			var width = num(cur.width_mm, 'Breite in Millimetern');
			var depth = num(cur.depth_mm, 'Tiefe in Millimetern');
			var maxm = h('input', { class: 'field', type: 'text', inputmode: 'numeric', autocomplete: 'off', value: String(cur.max_modules || 0), 'aria-label': 'Module am Basismodul höchstens' });
			var maxField = field('Module am Basismodul höchstens (0 = so viele, wie der Platz hergibt; bis ' + meta.max_modules + ')', maxm);
			var teaser = h('input', { class: 'field', type: 'text', maxlength: '200', value: cur.teaser || '', 'aria-label': 'Kurzbeschreibung' });
			var sort = h('input', { class: 'field', type: 'text', inputmode: 'numeric', autocomplete: 'off', value: String(cur.sort_order || 0), 'aria-label': 'Reihenfolge' });
			var active = h('input', { type: 'checkbox', checked: !!cur.is_active });

			var heightLab = h('span', { class: 'kf-lab' }, 'Höhe bzw. Länge (mm)');
			function syncRole() {
				var basis = role() === 'basis';
				heightLab.textContent = basis ? 'Länge des Basismoduls (mm)' : 'Bauhöhe des Moduls (mm)';
				maxField.hidden = !basis;
			}
			roleInputs.forEach(function (i) { i.addEventListener('change', syncRole); });
			syncRole();

			var choice = h('select', { class: 'field', 'aria-label': 'Schaumodell' });
			if (cur.model_kind === 'upload') { choice.appendChild(h('option', { value: '' }, '– hochgeladene Datei behalten –')); }
			var none = h('option', { value: 'keines' }, 'Kein Modell – maßhaltiger Ersatzkörper');
			if (cur.model_kind === 'none') { none.selected = true; }
			choice.appendChild(none);
			meta.demos.forEach(function (d) {
				var o = h('option', { value: d.value }, d.label);
				if (cur.model_kind === 'demo' && cur.model_file === d.value) { o.selected = true; }
				choice.appendChild(o);
			});
			var file = h('input', { type: 'file', accept: '.glb', class: 'kf-file', 'aria-label': 'Eigenes Modell hochladen (.glb)' });

			var save = h('button', { class: 'btn primary', type: 'submit' }, p ? 'Teil speichern' : 'Teil anlegen');
			var back = h('button', { class: 'btn', type: 'button', onclick: function () { if (p) { openPart(p.id); } else { closeDialog(); } } }, 'Abbrechen');

			var form = h('form', { class: 'kf-form', novalidate: true },
				h('fieldset', { class: 'kf-set' }, h('legend', null, '1 · Artikel und Rolle'),
					field('Artikel', article, 'Nur verkäufliche, aktive Artikel. Preis und Verfügbarkeit kommen aus dem Shop, nicht von hier.'),
					h('div', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Rolle'), roleBox)),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '2 · Maße'),
					h('label', { class: 'kf-field' }, heightLab, height, h('span', { class: 'kf-hint' }, 'Pflicht: Sie entscheidet, wie viel Platz ein Modul belegt und wie lang die Schiene ist.')),
					h('div', { class: 'kf-grid' }, field('Breite (mm)', width), field('Tiefe (mm)', depth)),
					h('p', { class: 'kf-hint' }, 'Breite und Tiefe dienen nur dem Ersatzkörper, solange kein Modell hinterlegt ist.'),
					maxField),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '3 · Darstellung'),
					field('Kurzbeschreibung', teaser, 'Eine Zeile, die im Konfigurator unter dem Namen steht.'),
					field('Reihenfolge', sort, 'Kleinere Zahlen stehen weiter vorn.'),
					h('label', { class: 'kf-check' }, active, h('span', null, 'Im Konfigurator anbieten'))),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '4 · Schaumodell'),
					field('Modell wählen', choice, 'Die Demo-Modelle gehören zum Plugin und zeigen nicht den Haster Haken. Sie sind zum Einrichten da – vor dem Verkauf gehört das eigene Modell hierher.'),
					field('Eigenes Modell hochladen (.glb, höchstens ' + mb(meta.max_bytes) + ')', file, (cur.model_kind === 'upload' ? 'Hinterlegt: ' + cur.model_file + '. ' : '') + 'Ursprung an der Unterkante der Montagefläche, Y nach oben. Die Einheit ist gleichgültig – die Ansicht skaliert das Modell auf die gepflegte Höhe. Eine hochgeladene Datei sticht die Auswahl darüber.')),
				msg,
				h('div', { class: 'kf-actions' }, save, back));

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var err = '';
				var f = file.files && file.files[0];
				var hv = parseFloat(String(height.value).replace(',', '.'));

				if (!article.value) { err = 'Bitte einen Artikel aus dem Artikelstamm wählen.'; }
				else if (!(hv > 0)) { err = role() === 'basis' ? 'Das Basismodul braucht seine Länge in Millimetern – sie ist der Weg, auf dem die Module sitzen.' : 'Das Modul braucht seine Bauhöhe in Millimetern.'; }
				else if (role() === 'modul' && parseInt(maxm.value, 10) > 0) { err = 'Eine Modulzahl wird am Basismodul gepflegt, nicht am Modul selbst.'; }
				else if (f && !/\.glb$/i.test(f.name)) { err = '„' + f.name + '“ ist keine .glb-Datei. Der Konfigurator lädt ausschließlich .glb – ein glTF-Modell in einer einzigen Datei.'; }
				else if (f && f.size > meta.max_bytes) { err = 'Die Datei ist ' + mb(f.size) + ' groß, erlaubt sind höchstens ' + mb(meta.max_bytes) + '. Für die Produktseite gehört das Modell reduziert exportiert.'; }
				else if (f && f.size < 1) { err = 'Die Datei ist leer.'; }

				if (err) { say(msg, err, true); return; }

				var fd = new FormData();
				fd.append('article_id', article.value);
				fd.append('role', role());
				fd.append('height_mm', height.value);
				fd.append('width_mm', width.value);
				fd.append('depth_mm', depth.value);
				fd.append('max_modules', role() === 'basis' ? maxm.value : '0');
				fd.append('sort_order', sort.value);
				fd.append('teaser', teaser.value);
				if (active.checked) { fd.append('is_active', '1'); }
				fd.append('model_choice', choice.value);
				if (f) { fd.append('model', f, f.name); }

				run(save, msg, function () {
					return upload(ui, 'konfigurator/teile' + (p ? '/' + p.id : ''), fd).then(function (r) {
						ui.toast(r.message);
						return load().then(function () { openPart(r.id, r.message); });
					}, function (e2) {
						if (e2.saved) { load(); }
						throw e2;
					});
				});
			});

			body.appendChild(form);
		}

		chips();
		ui.api('konfigurator').then(function (d) {
			paint(d);
		}).catch(function (e) {
			listBox.textContent = '';
			listBox.appendChild(h('div', { class: 'notice warn' }, e.message));
		});
	};
})();
