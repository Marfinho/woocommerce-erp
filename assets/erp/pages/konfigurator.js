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
			h('p', null, 'Höhe, Breite und Tiefe liest der Konfigurator aus dem Modell, du musst sie nicht eintragen. Preis, Lieferbarkeit und Kurzbeschreibung kommen aus dem Shop-Produkt des Artikels. Nur ohne Modell zeigt der Konfigurator einen Ersatzkörper aus den eingetragenen Maßen – bestellen lässt sich damit alles, es sieht nur nicht nach dem Haken aus.'),
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
			if (p.model_kind === 'demo') { return p.model_file === 'demo:holzstange' || (p.role === 'basis' && p.model_file === 'demo:schiene') ? 'Standardmodell' : 'Demo-Modell'; }
			return p.role === 'basis' ? 'Standardmodell' : 'Ersatzkörper';
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
				var warn = !p.article_ok ? 'Artikel fehlt – das Teil erscheint im Shop nicht.' : (p.is_active && !p.article_active ? 'Der Artikel ist stillgelegt – das Teil erscheint im Shop nicht.' : (p.is_active && !p.shop_found ? 'Der Artikel ist mit keinem Shop-Produkt verknüpft – ohne Preis erscheint das Teil im Shop nicht.' : ''));
				var tags = h('div', { class: 'row-tags' },
					tag(p.role === 'basis' ? 'Basismodul' : 'Modul'),
					tag(live ? 'Angeboten' : 'Ausgeblendet', live ? 'now' : 'later'),
					tag(modelText(p)),
					p.shop_found && !p.shop_in_stock ? tag('Nicht lieferbar', 'later') : null,
					p.role === 'basis' ? tag(p.blocks.length + (p.blocks.length === 1 ? ' Sperrbereich' : ' Sperrbereiche')) : null);
				listBox.appendChild(h('button', { class: 'row-card kf-row', type: 'button', onclick: function () { openPart(p.id); } },
					h('div', { class: 'row-main' },
						h('strong', null, p.name || 'Artikel fehlt'),
						h('div', { class: 'muted' }, (p.sku ? p.sku + ' · ' : '') + (p.role === 'basis' ? 'Länge ' : 'Höhe ') + mm(p.height_mm) + (p.shop_price ? ' · ' + p.shop_price : '')),
						p.teaser || p.teaser_shop ? h('div', { class: 'muted' }, p.teaser || p.teaser_shop) : null,
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
				kv('Preis im Shop', p.shop_found ? p.shop_price + (p.shop_in_stock ? '' : ' – gerade nicht lieferbar') : 'Kein Shop-Produkt verknüpft'),
				kv('Kurzbeschreibung', p.teaser ? p.teaser : (p.teaser_shop ? p.teaser_shop + ' (aus dem Shop-Produkt)' : '–')),
				p.model_measure ? kv('Das Modell misst', mm(p.model_measure.width) + ' breit × ' + mm(p.model_measure.height) + ' hoch × ' + mm(p.model_measure.depth) + ' tief') : null,
				kv('Schaumodell', p.model_kind === 'demo' ? (p.role === 'basis' && p.model_file === 'demo:schiene' ? 'Standard: Holzstange mit zwei Haltern (die Demo-Schiene ersetzt sie)' : p.model_demo) : (p.model_kind === 'upload' ? 'Eigene Datei: ' + p.model_file : (p.role === 'basis' ? 'Standard: Holzstange mit zwei Haltern' : 'Keines – maßhaltiger Ersatzkörper'))));
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
				var row = h('div', { class: 'kf-block' });
				row.appendChild(h('div', null, h('strong', null, mm(b.from_mm) + ' bis ' + mm(b.to_mm)), b.label ? h('div', { class: 'muted' }, b.label) : null));
				if (edit) { row.appendChild(h('div', { class: 'kf-actions' }, changeBtn(b, row), del)); }
				box.appendChild(row);
			});

			function changeBtn(b, row) {
				var btn = h('button', { class: 'btn', type: 'button' }, 'Ändern');
				btn.addEventListener('click', function () {
					var f = h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', value: String(b.from_mm).replace('.', ','), 'aria-label': 'Von (mm)' });
					var t = h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', value: String(b.to_mm).replace('.', ','), 'aria-label': 'Bis (mm)' });
					var l = h('input', { class: 'field', type: 'text', maxlength: '120', value: b.label || '', 'aria-label': 'Bezeichnung' });
					var ok = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');
					var no = h('button', { class: 'btn', type: 'button', onclick: function () { openPart(p.id); } }, 'Abbrechen');
					var form = h('form', { class: 'kf-form kf-blockform' },
						h('div', { class: 'kf-grid' },
							h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Von (mm)'), f),
							h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Bis (mm)'), t)),
						h('label', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Bezeichnung (optional)'), l),
						h('div', { class: 'kf-actions' }, ok, no));
					form.addEventListener('submit', function (e) {
						e.preventDefault();
						if (!f.value.trim() || !t.value.trim()) { say(msg, 'Bitte Anfang und Ende des Sperrbereichs in Millimetern eintragen.', true); return; }
						run(ok, msg, function () {
							return post('konfigurator/teile/' + p.id + '/sperrbereiche/' + b.id, { from_mm: f.value, to_mm: t.value, label: l.value }).then(function (r) {
								return load().then(function () { openPart(p.id, r.message); });
							});
						});
					});
					row.textContent = '';
					row.appendChild(form);
				});
				return btn;
			}

			if (edit && p.model_kind !== 'upload' && p.height_mm >= 150) {
				var holders = h('button', { class: 'btn', type: 'button' }, 'Sperrbereiche der Halter neu berechnen');
				holders.addEventListener('click', function () {
					if (p.blocks.length && !window.confirm('Alle vorhandenen Sperrbereiche dieses Basismoduls werden durch die berechneten ersetzt: zwei Halter (je 50 mm hoch) im Abstand von 900 mm, mittig auf der Stange. Fortfahren?')) { return; }
					run(holders, msg, function () {
						return post('konfigurator/teile/' + p.id + '/halter').then(function (r) {
							return load().then(function () { openPart(p.id, r.message); });
						});
					});
				});
				box.appendChild(h('p', { class: 'muted' }, 'Mit dem Standardmodell (Holzstange) sitzen zwei Halter im Abstand von 900 mm mittig auf der Stange, je 50 mm hoch. Daraus berechnet das Plugin die Sperrbereiche. Du kannst sie danach einzeln ändern.'));
				box.appendChild(h('p', null, holders));
			}

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

		/* ---------- Vorschau der Lage ---------- */

		function importMap(v) {
			if (document.querySelector('script[data-lw16-konf-map]')) { return; }
			var map = document.createElement('script');
			map.type = 'importmap';
			map.setAttribute('data-lw16-konf-map', '1');
			var imports = { three: v.three };
			imports['three/addons/'] = v.addons;
			map.textContent = JSON.stringify({ imports: imports });
			document.head.appendChild(map);
		}

		/*
		 * Die Vorschau zeigt das Modell an der Wand und an der Stange, gerechnet mit
		 * derselben Datei wie die Ansicht im Shop (assets/js/konfigurator-lage.js).
		 * Ziehen dreht die Ansicht, das Mausrad zoomt.
		 */
		function Preview(viewer) {
			var wrap = h('div', { class: 'kf-preview' });
			var note = h('p', { class: 'kf-preview-msg' }, 'Vorschau wird geladen …');
			wrap.appendChild(note);
			var st = { libs: null, raw: null, rawUrl: '', obj: null, az: 0.6, el: 0.12, dist: 0.5, target: null, req: 0, dead: false, want: null };

			function libs() {
				if (!st.libs) {
					importMap(viewer);
					st.libs = Promise.all([import(viewer.three), import(viewer.addons + 'loaders/GLTFLoader.js'), import(viewer.lage)]).then(function (m) {
						return { THREE: m[0], Loader: m[1].GLTFLoader, lage: m[2] };
					});
				}
				return st.libs;
			}

			function build(L) {
				var T = L.THREE;
				st.renderer = new T.WebGLRenderer({ antialias: true, alpha: true });
				st.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
				wrap.insertBefore(st.renderer.domElement, note);
				st.scene = new T.Scene();
				st.camera = new T.PerspectiveCamera(32, 1, 0.01, 20);
				st.scene.add(new T.HemisphereLight(0xffffff, 0x8a8478, 1.5));
				var sun = new T.DirectionalLight(0xffffff, 2.2);
				sun.position.set(-0.6, 1.2, 1.4);
				st.scene.add(sun);

				var wall = new T.Mesh(new T.PlaneGeometry(2, 2), new T.MeshStandardMaterial({ color: 0xddd4c4, roughness: 0.95 }));
				wall.position.set(0, 0.5, -0.001);
				st.scene.add(wall);

				// Die Stange: Ø 20 mm, Achse 45 mm von der Wand. Sie zeigt, wohin das Teil gehört.
				var r = viewer.rod_diameter / 2000;
				st.rod = new T.Mesh(new T.CylinderGeometry(r, r, 1, 32), new T.MeshStandardMaterial({ color: 0xc9a57a, roughness: 0.7 }));
				st.rod.position.set(0, 0.5, viewer.rod_depth / 1000);
				st.scene.add(st.rod);

				var down = null;
				var el = st.renderer.domElement;
				el.addEventListener('pointerdown', function (e) { down = { x: e.clientX, y: e.clientY }; el.setPointerCapture(e.pointerId); });
				el.addEventListener('pointermove', function (e) {
					if (!down) { return; }
					st.az -= (e.clientX - down.x) * 0.01;
					st.el = Math.max(-0.6, Math.min(1.2, st.el + (e.clientY - down.y) * 0.01));
					down = { x: e.clientX, y: e.clientY };
					draw();
				});
				el.addEventListener('pointerup', function () { down = null; });
				el.addEventListener('wheel', function (e) { e.preventDefault(); st.dist = Math.max(0.1, Math.min(4, st.dist * (e.deltaY > 0 ? 1.1 : 0.9))); draw(); }, { passive: false });
			}

			function draw() {
				if (st.req) { return; }
				st.req = requestAnimationFrame(function () {
					st.req = 0;
					if (!st.renderer || st.dead) { return; }
					if (!document.body.contains(wrap)) { st.dead = true; st.renderer.dispose(); return; }
					var w = wrap.clientWidth || 1;
					var hgt = wrap.clientHeight || 1;
					st.renderer.setSize(w, hgt, false);
					st.camera.aspect = w / hgt;
					st.camera.updateProjectionMatrix();
					var t = st.target || { x: 0, y: 0.1, z: 0.03 };
					st.camera.position.set(
						t.x + st.dist * Math.sin(st.az) * Math.cos(st.el),
						t.y + st.dist * Math.sin(st.el),
						t.z + st.dist * Math.cos(st.az) * Math.cos(st.el));
					st.camera.lookAt(t.x, t.y, t.z);
					st.renderer.render(st.scene, st.camera);
				});
			}

			function place(L, o) {
				if (st.obj) { st.scene.remove(st.obj); st.obj = null; }
				var T = L.THREE;
				var raw = st.raw.clone(true);
				raw.updateMatrixWorld(true);
				var box = new T.Box3().setFromObject(raw);
				// Ohne Höhenangabe: Einheit raten wie PHP (über 20 Einheiten = Millimeter).
				var ext = Math.max(box.max.x - box.min.x, box.max.y - box.min.y, box.max.z - box.min.z);
				if (ext > 20) { raw.scale.setScalar(0.001); }
				st.obj = L.lage.platziere(T, raw, o.height, o.lage);
				st.scene.add(st.obj);

				var fit = new T.Box3().setFromObject(st.obj);
				var top = Math.max(fit.max.y, 0.05);
				var len = top + 0.06;
				st.rod.scale.y = len + 0.04;
				st.rod.position.y = (len - 0.04) / 2;
				st.target = { x: 0, y: top / 2, z: 0.03 };
				st.dist = Math.max(0.12, top * 2.6);
				note.hidden = true;
				draw();
			}

			return {
				el: wrap,
				show: function (o) {
					st.want = o;
					note.hidden = false;
					note.textContent = o.url ? 'Vorschau wird geladen …' : (o.empty || 'Kein Modell gewählt.');
					if (!o.url) { if (st.obj) { st.scene.remove(st.obj); st.obj = null; draw(); } return; }
					libs().then(function (L) {
						if (!st.renderer) { build(L); }
						if (st.rawUrl === o.url && st.raw) { place(L, o); return; }
						new L.Loader().loadAsync(o.url).then(function (g) {
							if (st.want !== o && st.want.url !== o.url) { return; }
							st.raw = g.scene;
							st.rawUrl = o.url;
							place(L, st.want);
						}, function () { note.hidden = false; note.textContent = 'Die Modelldatei ließ sich nicht laden.'; });
					}, function () { note.hidden = false; note.textContent = 'Die Vorschau lässt sich in diesem Browser nicht anzeigen.'; });
				}
			};
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
			var teaser = h('input', { class: 'field', type: 'text', maxlength: '200', value: cur.teaser || '', placeholder: cur.teaser_shop || '', 'aria-label': 'Kurzbeschreibung' });
			var fromModel = h('input', { type: 'checkbox' });
			var fromModelRow = h('label', { class: 'kf-check' }, fromModel, h('span', null, 'Maße aus dem Modell neu übernehmen (ersetzt die Angaben oben)'));
			var sort = h('input', { class: 'field', type: 'text', inputmode: 'numeric', autocomplete: 'off', value: String(cur.sort_order || 0), 'aria-label': 'Reihenfolge' });
			var active = h('input', { type: 'checkbox', checked: !!cur.is_active });

			var heightLab = h('span', { class: 'kf-lab' }, 'Höhe bzw. Länge (mm)');
			function syncRole() {
				var basis = role() === 'basis';
				if (none) { none.textContent = basis ? 'Standard: Holzstange mit zwei Haltern' : 'Kein Modell – maßhaltiger Ersatzkörper'; }
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
				if (d.value === 'demo:holzstange') { return; }
				var o = h('option', { value: d.value }, d.label);
				if (cur.model_kind === 'demo' && cur.model_file === d.value) { o.selected = true; }
				choice.appendChild(o);
			});
			syncRole();
			var file = h('input', { type: 'file', accept: '.glb', class: 'kf-file', 'aria-label': 'Eigenes Modell hochladen (.glb)' });

			function hasModel() {
				var f = file.files && file.files[0];
				return !!f || choice.value.indexOf('demo:') === 0 || (cur.model_kind === 'upload' && choice.value === '');
			}
			function syncModel() { fromModelRow.hidden = !hasModel(); }
			choice.addEventListener('change', syncModel);
			file.addEventListener('change', syncModel);
			syncModel();

			/* Lage des Modells an der Stange */
			var cp = cur.placement || { align: 'auto', rot: [0, 0, 0], off: [0, 0, 0] };
			var align = h('select', { class: 'field', 'aria-label': 'Ausrichtung' },
				h('option', { value: 'auto' }, 'Automatisch: auf die Unterkante, mittig, Rückseite an die Wand'),
				h('option', { value: 'asis' }, 'Wie konstruiert (nur auf die Unterkante stellen)'));
			align.value = cp.align === 'asis' ? 'asis' : 'auto';
			function axisInput(v, label) {
				return h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', value: String(v || 0).replace('.', ','), 'aria-label': label });
			}
			var rot = [axisInput(cp.rot[0], 'Drehung um X in Grad'), axisInput(cp.rot[1], 'Drehung um Y in Grad'), axisInput(cp.rot[2], 'Drehung um Z in Grad')];
			var off = [axisInput(cp.off[0], 'Verschiebung X in Millimetern'), axisInput(cp.off[1], 'Verschiebung Y in Millimetern'), axisInput(cp.off[2], 'Verschiebung Z in Millimetern')];
			var preview = null;
			var objUrl = '';
			var objFile = null;
			var timer2 = null;

			function nums(list) { return list.map(function (i) { var n = parseFloat(String(i.value).replace(',', '.')); return isFinite(n) ? n : 0; }); }
			function turnBtn(i) {
				return h('button', { class: 'btn', type: 'button', onclick: function () {
					var n = (nums([rot[i]])[0] + 90) % 360;
					rot[i].value = String(n).replace('.', ',');
					refresh();
				} }, '+ 90°');
			}
			function modelUrl() {
				var f = file.files && file.files[0];
				if (f) {
					if (objFile !== f) { if (objUrl) { URL.revokeObjectURL(objUrl); } objUrl = URL.createObjectURL(f); objFile = f; }
					return objUrl;
				}
				if (choice.value.indexOf('demo:') === 0) {
					var hit = meta.demos.filter(function (d) { return d.value === choice.value; })[0];
					return hit ? hit.url : '';
				}
				if (cur.model_kind === 'upload' && choice.value === '') { return cur.model_url || ''; }
				return '';
			}
			function ownModel() { return hasModel() && !(role() === 'basis' && !(file.files && file.files[0])); }
			function refresh() {
				placeSet.hidden = !ownModel();
				if (placeSet.hidden) { return; }
				if (!preview) { preview = Preview(meta.viewer); previewBox.appendChild(preview.el); }
				var hv = parseFloat(String(height.value).replace(',', '.'));
				preview.show({ url: modelUrl(), height: hv > 0 ? hv : 0, lage: { align: align.value, rot: nums(rot), off: nums(off) }, empty: 'Kein Modell gewählt.' });
			}
			function later() { clearTimeout(timer2); timer2 = setTimeout(refresh, 120); }
			rot.concat(off).concat([height]).forEach(function (i) { i.addEventListener('input', later); });
			align.addEventListener('change', refresh);
			choice.addEventListener('change', refresh);
			file.addEventListener('change', refresh);
			roleInputs.forEach(function (i) { i.addEventListener('change', refresh); });
			var previewBox = h('div', null);
			function axisRow(label, list, extra) {
				return h('div', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, label),
					h('div', { class: 'kf-axis' }, list.map(function (inp, i) {
						return h('div', { class: 'kf-axis-cell' }, h('span', { class: 'kf-hint' }, ['X', 'Y', 'Z'][i]), inp, extra ? extra(i) : null);
					})));
			}
			var placeSet = h('fieldset', { class: 'kf-set' }, h('legend', null, '5 · Lage an der Stange'),
				h('p', { class: 'kf-hint' }, 'Die Datei bringt ihr eigenes Koordinatensystem mit. Hier stellst du ein, was oben ist und wo das Teil an die Stange gehört. Die Vorschau zeigt die Wand (beige) und die Holzstange; ziehen dreht die Ansicht.'),
				previewBox,
				field('Ausrichtung', align, 'Automatisch passt für die meisten Modelle. Danach nur noch drehen und verschieben.'),
				axisRow('Drehung in Grad (X, Y, Z)', rot, turnBtn),
				axisRow('Verschiebung in Millimetern (X nach rechts, Y nach oben, Z von der Wand weg)', off),
				h('p', { class: 'kf-hint' }, 'Die Stangenachse liegt bei X 0 und Z ' + meta.viewer.rod_depth + ' mm (Ø ' + meta.viewer.rod_diameter + ' mm). Ein Teil, das die Stange umgreift, braucht dort seine Öffnung.'));
			placeSet.hidden = true;

			var save = h('button', { class: 'btn primary', type: 'submit' }, p ? 'Teil speichern' : 'Teil anlegen');
			var back = h('button', { class: 'btn', type: 'button', onclick: function () { if (p) { openPart(p.id); } else { closeDialog(); } } }, 'Abbrechen');

			var form = h('form', { class: 'kf-form', novalidate: true },
				h('fieldset', { class: 'kf-set' }, h('legend', null, '1 · Artikel und Rolle'),
					field('Artikel', article, 'Nur verkäufliche, aktive Artikel. Preis, Lieferbarkeit und Kurzbeschreibung kommen vom Shop-Produkt, das am Artikel hängt.'),
					h('div', { class: 'kf-field' }, h('span', { class: 'kf-lab' }, 'Rolle'), roleBox)),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '2 · Maße'),
					h('label', { class: 'kf-field' }, heightLab, height, h('span', { class: 'kf-hint' }, 'Leer lassen, wenn ein Modell hinterlegt ist: Dann liest der Konfigurator die Maße aus der Datei. Ohne Modell ist die Angabe Pflicht – sie entscheidet, wie viel Platz ein Modul belegt und wie lang die Schiene ist.')),
					h('div', { class: 'kf-grid' }, field('Breite (mm)', width), field('Tiefe (mm)', depth)),
					h('p', { class: 'kf-hint' }, 'Breite und Tiefe braucht nur der Ersatzkörper, solange kein Modell hinterlegt ist.'),
					fromModelRow,
					maxField),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '3 · Darstellung'),
					field('Kurzbeschreibung (optional)', teaser, 'Leer lassen: Es gilt die Kurzbeschreibung des Shop-Produkts (grau angezeigt). Ein hier eingetragener Text ersetzt sie nur im Konfigurator.'),
					field('Reihenfolge', sort, 'Kleinere Zahlen stehen weiter vorn.'),
					h('label', { class: 'kf-check' }, active, h('span', null, 'Im Konfigurator anbieten'))),
				h('fieldset', { class: 'kf-set' }, h('legend', null, '4 · Schaumodell'),
					field('Modell wählen', choice, 'Die Demo-Modelle gehören zum Plugin und zeigen nicht den Haster Haken. Sie sind zum Einrichten da – vor dem Verkauf gehört das eigene Modell hierher.'),
					field('Eigenes Modell hochladen (.glb, höchstens ' + mb(meta.max_bytes) + ')', file, (cur.model_kind === 'upload' ? 'Hinterlegt: ' + cur.model_file + '. ' : '') + 'Ursprung an der Unterkante der Montagefläche, Y nach oben. Die Einheit ist gleichgültig – die Ansicht skaliert das Modell auf die gepflegte Höhe. Eine hochgeladene Datei sticht die Auswahl darüber.')),
				placeSet,
				msg,
				h('div', { class: 'kf-actions' }, save, back));

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var err = '';
				var f = file.files && file.files[0];
				var hv = parseFloat(String(height.value).replace(',', '.'));

				if (!article.value) { err = 'Bitte einen Artikel aus dem Artikelstamm wählen.'; }
				else if (!(hv > 0) && !hasModel()) { err = role() === 'basis' ? 'Das Basismodul braucht seine Länge in Millimetern – sie ist der Weg, auf dem die Module sitzen.' : 'Das Modul braucht seine Bauhöhe in Millimetern.'; }
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
				if (fromModel.checked && hasModel()) { fd.append('measure_from_model', '1'); }
				fd.append('model_align', align.value);
				['x', 'y', 'z'].forEach(function (a, i) {
					fd.append('model_rot_' + a, rot[i].value);
					fd.append('model_off_' + a, off[i].value);
				});
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
			refresh();
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
