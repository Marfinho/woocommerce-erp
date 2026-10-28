/*
 * ERP-Seite „Kasse“ (nativ): Kassatag starten, verkaufen, stornieren, abschließen.
 * Die Beträge werden als Text zum Server geschickt; dort liest POSModule::parse_amount()
 * sowohl „12,50“ als auch „12.50“.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['kasse'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Kasse', 'Verkauf vor Ort: Tag starten, kassieren, am Ende abschließen – der Umsatz geht in Beleg und Kassenbuch.', null);

		var box = h('div');
		ui.view().appendChild(box);

		function eur(n) { return ui.money(n, 'EUR'); }
		function datum(s) { var p = String(s).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : s; }
		var preselect = '';     // Auswahlwert einer frisch angelegten Kachel
		var bon = [];           // Positionen auf dem Bon; bleiben beim Neuzeichnen erhalten

		function field(label, input) { return h('div', null, h('label', null, label), input); }

		// Eine Aktion senden, Knopf währenddessen sperren (Doppeltipp bucht sonst zweimal).
		function post(path, body, btn, done) {
			if (btn) { btn.disabled = true; }
			return ui.api('kasse/' + path, { method: 'POST', body: body }).then(function (res) {
				ui.toast(res.message);
				return done ? done(res) : load();
			}).catch(function (e) {
				ui.toast(e.message, true);
			}).then(function () { if (btn) { btn.disabled = false; } });
		}

		// Foto verkleinern, bevor es hochgeladen wird: am Stand zählt das Mobilfunknetz.
		function shrink(file, done) {
			var img = new Image();
			var url = URL.createObjectURL(file);
			img.onload = function () {
				var max = 800, f = Math.min(1, max / Math.max(img.width, img.height));
				var c = document.createElement('canvas');
				c.width = Math.round(img.width * f);
				c.height = Math.round(img.height * f);
				c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
				URL.revokeObjectURL(url);
				done(c.toDataURL('image/jpeg', 0.82));
			};
			img.onerror = function () { URL.revokeObjectURL(url); done(''); };
			img.src = url;
		}

		// Schnellanlage: Foto, Name, Preis, Bestand – danach ist die Kachel gleich gewählt.
		function productPanel(onDone, edit) {
			var foto = '';
			var pic = h('input', { type: 'file', accept: 'image/*', capture: 'environment', hidden: true });
			var preview = h('span', { class: 'ks-bild' }, '📷');
			var take = h('button', { class: 'ks-kachel', type: 'button', style: 'width:120px' }, preview, h('span', { class: 'ks-name' }, 'Foto aufnehmen'));
			take.addEventListener('click', function () { pic.click(); });
			pic.addEventListener('change', function () {
				if (!pic.files || !pic.files[0]) { return; }
				shrink(pic.files[0], function (data) {
					foto = data;
					preview.textContent = '';
					if (data) { preview.appendChild(h('img', { src: data, alt: '' })); } else { preview.textContent = '📷'; ui.toast('Das Foto ließ sich nicht lesen.', true); }
				});
			});
			var name = h('input', { class: 'field', type: 'text', placeholder: 'z. B. Handyhalterung blau' });
			var preis = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
			var bestand = h('input', { class: 'field', type: 'number', min: '0', step: '1', inputmode: 'numeric', placeholder: '0' });
			if (edit) {
				name.value = edit.name;
				preis.value = edit.preis === '' ? '' : edit.preis.replace('.', ',');
				if (edit.bild) { preview.textContent = ''; preview.appendChild(h('img', { src: edit.bild, alt: '' })); }
			}
			var save = h('button', { class: 'btn primary', type: 'button' }, edit ? 'Speichern' : 'Produkt anlegen');
			save.addEventListener('click', function () {
				if (!name.value.trim()) { ui.toast('Bitte einen Namen eintragen.', true); name.focus(); return; }
				if (!preis.value.trim()) { ui.toast('Bitte einen Preis eintragen.', true); preis.focus(); return; }
				var path = edit ? 'produkt-aendern' : 'produkt';
				var body = { name: name.value, preis: preis.value, foto: foto };
				if (edit) { body.id = edit.id; } else { body.bestand = bestand.value; }
				post(path, body, save, function (res) {
					preselect = res.value || '';
					return load();
				});
			});
			var hide = null;
			if (edit) {
				hide = h('button', { class: 'btn', type: 'button' }, 'Ausblenden');
				hide.addEventListener('click', function () {
					if (!window.confirm('Produkt aus der Kasse ausblenden? Bestand und bisherige Verkäufe bleiben erhalten.')) { return; }
					post('produkt-aendern', { id: edit.id, ausblenden: true }, hide);
				});
			}
			var cancel = h('button', { class: 'btn', type: 'button' }, 'Abbrechen');
			cancel.addEventListener('click', onDone);
			return h('div', { class: 'card ks-form' },
				h('h3', null, edit ? 'Kassenprodukt ändern' : 'Neues Produkt nur für die Kasse'),
				h('p', { class: 'muted' }, edit ? 'Bestand ändern geht im Lager; ausgeblendete Produkte bleiben in den Büchern.' : 'Erscheint nicht im Shop. Der Bestand läuft wie bei jedem Artikel mit; verkauft die Kasse etwas, geht er ab.'),
				pic, take,
				field('Name', name),
				h('div', { class: 'ks-row' }, field('Preis (€)', preis), edit ? '' : field('Anfangsbestand (Stück)', bestand)),
				h('div', { style: 'margin-top:14px;display:flex;gap:8px;flex-wrap:wrap' }, save, cancel, hide));
		}

		/* ---------- Kein offener Tag ---------- */

		function paintStart(d) {
			var name = h('input', { class: 'field', type: 'text', placeholder: 'z. B. Weihnachtsmarkt Altstadt' });
			var dat = h('input', { class: 'field', type: 'date', value: d.heute });
			var anf = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: '0,00' });
			var kamp = null;
			if (d.kampagnen.length) {
				kamp = h('select', { class: 'field' }, h('option', { value: '0' }, '– keine –'));
				d.kampagnen.forEach(function (k) { kamp.appendChild(h('option', { value: String(k.id) }, k.label)); });
			}
			var go = h('button', { class: 'btn primary', type: 'button' }, 'Kassatag starten');
			go.addEventListener('click', function () {
				if (!name.value.trim()) { ui.toast('Bitte Markt oder Anlass angeben.', true); name.focus(); return; }
				post('start', { markt_name: name.value, datum: dat.value, kassenbestand_anfang: anf.value, kampagne_id: kamp ? parseInt(kamp.value, 10) : 0 }, go);
			});
			box.appendChild(h('div', { class: 'card ks-form', style: 'max-width:520px' },
				h('h3', null, 'Neuen Kassatag starten'),
				field('Markt / Anlass', name),
				kamp ? field('Kostenstelle', kamp) : null,
				kamp ? h('p', { class: 'muted' }, 'Der Tagesumsatz zählt dann in der Marketing-Auswertung gegen die Kosten dieses Events.') : null,
				h('div', { class: 'ks-row' }, field('Datum', dat), field('Kassenbestand Anfang (€)', anf)),
				h('div', { style: 'margin-top:14px' }, go)
			));
		}

		// Zahl aus einer getippten Summe (Komma oder Punkt) – nur für die Anzeige; der Server rechnet maßgeblich.
		function num(txt) {
			var x = String(txt).replace(/[^0-9,.\-]/g, '');
			if (x.indexOf(',') !== -1) { x = x.replace(/\./g, '').replace(',', '.'); }
			var n = parseFloat(x);
			return isNaN(n) || n < 0 ? 0 : n;
		}

		// Vollbild: Kopf und Menü ausblenden (wie die Druckerfarm) und, wo der Browser es kann, echtes Vollbild.
		function setFull(on) {
			var app = ui.app();
			if (!app) { return; }
			app.classList.toggle('kiosk', on);
			try {
				if (on && document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen(); }
				else if (!on && document.fullscreenElement) { document.exitFullscreen(); }
			} catch (e) { /* Das Vollbild ist nur ein Zusatz; die große Ansicht gilt auch ohne. */ }
		}

		function onFullscreenChange() {
			if (!document.body.contains(box)) { document.removeEventListener('fullscreenchange', onFullscreenChange); return; }
			if (!document.fullscreenElement && ui.app()) { ui.app().classList.remove('kiosk'); }
		}
		document.addEventListener('fullscreenchange', onFullscreenChange);

		// Kassenprodukte pflegen, ohne dass ein Tag offen ist (am Schreibtisch vorbereiten).
		function paintProdukte(d) {
			var panelBox = h('div');
			var newProd = h('button', { class: 'btn', type: 'button' }, '+ Neues Produkt');
			newProd.addEventListener('click', function () {
				panelBox.textContent = '';
				panelBox.appendChild(productPanel(function () { panelBox.textContent = ''; }));
			});

			var rows = h('tbody');
			if (!d.optionen.length) { rows.appendChild(h('tr', null, h('td', { colspan: '4' }, 'Noch keine Kassenprodukte.'))); }
			d.optionen.forEach(function (o) {
				var bild = h('span', { class: 'ks-bild ks-mini' }, o.bild ? h('img', { src: o.bild, alt: '', loading: 'lazy' }) : o.kuerzel);
				var edit = h('button', { class: 'btn', type: 'button' }, 'Ändern');
				edit.addEventListener('click', function () {
					panelBox.textContent = '';
					panelBox.appendChild(productPanel(function () { panelBox.textContent = ''; }, o));
					panelBox.scrollIntoView({ block: 'nearest' });
				});
				rows.appendChild(h('tr', null, h('td', null, bild), h('td', null, o.name, h('div', { class: 'ks-sku' }, o.sku)),
					h('td', { class: 'num' }, o.preis === '' ? 'Preis offen' : eur(parseFloat(o.preis))), h('td', { class: 'num' }, edit)));
			});

			box.appendChild(h('div', { class: 'card ks-form', style: 'margin-top:14px' },
				h('h3', null, 'Kassenprodukte'),
				h('p', { class: 'muted' }, 'Nur für die Kasse, nicht im Shop. Hier legst du sie vorab an, ändern und ausblenden geht auch ohne offenen Kassatag.'),
				h('div', { style: 'margin-bottom:10px' }, newProd), panelBox,
				h('div', { style: 'overflow-x:auto' }, h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th'), h('th', null, 'Produkt'), h('th', { class: 'num' }, 'Preis'), h('th'))),
					rows))));
		}

		/* ---------- Offener Tag ---------- */

		function paintTag(d) {
			var t = d.tag;
			var kacheln = [];
			var panelBox = h('div');

			var suche = h('input', { class: 'field', type: 'search', placeholder: 'Artikel filtern …', autocomplete: 'off' });
			suche.addEventListener('input', function () {
				var q = suche.value.toLowerCase().replace(/\s+/g, ' ').trim();
				kacheln.forEach(function (k) { k.cell.hidden = q !== '' && k.o.suche.indexOf(q) === -1; });
			});

			/* --- Bon: jeder Tipp auf eine Kachel legt ein Stück dazu --- */
			var bonBox = h('div');

			function updateBadges() {
				kacheln.forEach(function (k) {
					var line = bon.filter(function (l) { return l.value === k.o.value; })[0];
					k.badge.textContent = line ? String(line.menge) : '';
					k.badge.hidden = !line;
				});
			}

			function addLine(o) {
				var line = bon.filter(function (l) { return l.value === o.value; })[0];
				if (line) { line.menge += 1; }
				else { bon.push({ value: o.value, name: o.name, preis: o.preis === '' ? '' : o.preis.replace('.', ','), menge: 1 }); }
				paintBon();
			}

			function paintBon() {
				bonBox.textContent = '';
				var sum = 0;
				var list = h('div', { class: 'ks-lines' });

				if (!bon.length) { list.appendChild(h('p', { class: 'muted' }, 'Kacheln antippen – jeder Tipp legt ein Stück auf den Bon.')); }

				bon.forEach(function (l, i) {
					var pr = h('input', { class: 'field ks-linepreis', type: 'text', inputmode: 'decimal', value: l.preis, placeholder: 'Preis', 'aria-label': 'Einzelpreis ' + l.name });
					var sumEl = h('b', { class: 'ks-linesum' });
					function refresh() { sumEl.textContent = eur(num(l.preis) * l.menge); }
					pr.addEventListener('input', function () { l.preis = pr.value; refresh(); paintTotal(); });
					refresh();

					var minus = h('button', { class: 'btn ks-step', type: 'button', 'aria-label': 'ein Stück weniger' }, '−');
					minus.addEventListener('click', function () { if (l.menge > 1) { l.menge -= 1; } else { bon.splice(i, 1); } paintBon(); });
					var plus = h('button', { class: 'btn ks-step', type: 'button', 'aria-label': 'ein Stück mehr' }, '+');
					plus.addEventListener('click', function () { l.menge += 1; paintBon(); });
					var del = h('button', { class: 'btn ks-step', type: 'button', 'aria-label': l.name + ' entfernen' }, '×');
					del.addEventListener('click', function () { bon.splice(i, 1); paintBon(); });

					list.appendChild(h('div', { class: 'ks-line' },
						h('span', { class: 'ks-linename' }, l.name),
						h('span', { class: 'ks-qty' }, minus, h('b', null, String(l.menge)), plus),
						pr, sumEl, del));
				});

				var total = h('b', { class: 'ks-bontotal' });
				function paintTotal() {
					var x = 0;
					bon.forEach(function (l) { x += num(l.preis) * l.menge; });
					total.textContent = eur(x);
				}
				paintTotal();

				var pay = h('button', { class: 'btn primary ks-pay', type: 'button' }, 'Kassieren');
				pay.disabled = !bon.length;
				pay.addEventListener('click', function () {
					for (var i = 0; i < bon.length; i++) {
						if (!String(bon[i].preis).trim()) { ui.toast('Bitte bei „' + bon[i].name + '“ einen Preis eintragen.', true); return; }
					}
					var zeilen = bon.map(function (l) { return { produkt_id: l.value, artikel_name: l.name, menge: l.menge, einzelpreis: l.preis }; });
					post('bon', { tag_id: t.id, zeilen: zeilen }, pay, function (res) { bon = []; return load(); });
				});
				var clear = h('button', { class: 'btn', type: 'button' }, 'Leeren');
				clear.disabled = !bon.length;
				clear.addEventListener('click', function () { bon = []; paintBon(); });

				// Freier Betrag: kommt als Zeile auf den Bon, ohne Artikel, ohne Lagerabgang.
				var fname = h('input', { class: 'field', type: 'text', placeholder: 'Freier Betrag: Bezeichnung' });
				var fbetrag = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
				var fadd = h('button', { class: 'btn', type: 'button' }, 'Auf den Bon');
				fadd.addEventListener('click', function () {
					if (!fbetrag.value.trim()) { ui.toast('Bitte einen Betrag eintragen.', true); fbetrag.focus(); return; }
					bon.push({ value: '', name: fname.value.trim() || 'Freier Betrag', preis: fbetrag.value, menge: 1 });
					paintBon();
				});

				bonBox.appendChild(h('div', { class: 'card ks-form ks-bon' },
					h('h3', null, 'Bon'),
					list,
					h('div', { class: 'ks-bonfoot' }, h('span', null, 'Summe'), total),
					h('div', { class: 'ks-payrow' }, pay, clear),
					h('details', { class: 'ks-free' }, h('summary', null, 'Freier Betrag (ohne Artikel, kein Lagerabgang)'),
						h('div', { class: 'ks-row' }, fname, fbetrag), h('div', { style: 'margin-top:8px' }, fadd))));
				updateBadges();
			}

			/* --- Kacheln --- */
			var grid = h('div', { class: 'ks-grid' });
			d.optionen.forEach(function (o) {
				var bild = o.bild
					? h('span', { class: 'ks-bild' }, h('img', { src: o.bild, alt: '', loading: 'lazy' }))
					: h('span', { class: 'ks-bild' }, o.kuerzel);
				var el = h('button', { class: 'ks-kachel', type: 'button' },
					bild,
					h('span', { class: 'ks-name' }, o.name),
					o.sku ? h('span', { class: 'ks-sku' }, o.sku) : null,
					h('span', { class: 'ks-preis' }, o.preis === '' ? 'Preis offen' : eur(parseFloat(o.preis))));
				el.addEventListener('click', function () { addLine(o); });

				var badge = h('span', { class: 'ks-badge', hidden: true });
				var cell = h('div', { class: 'ks-cell' }, el, badge);
				if (o.pos) {
					// Stift neben der Kachel (nicht darin: ein Button im Button ist ungültig).
					var pen = h('button', { class: 'ks-edit', type: 'button', 'aria-label': o.name + ' ändern' }, '✎');
					pen.addEventListener('click', function () {
						panelBox.textContent = '';
						panelBox.appendChild(productPanel(function () { panelBox.textContent = ''; }, o));
						panelBox.scrollIntoView({ block: 'nearest' });
					});
					cell.appendChild(pen);
				}
				kacheln.push({ el: el, cell: cell, badge: badge, o: o });
				grid.appendChild(cell);
			});

			var newProd = h('button', { class: 'btn', type: 'button' }, '+ Neues Produkt');
			newProd.addEventListener('click', function () {
				panelBox.textContent = '';
				panelBox.appendChild(productPanel(function () { panelBox.textContent = ''; }));
			});

			/* --- Tagesabschluss und Liste --- */
			var ende = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
			var notiz = h('textarea', { class: 'field', placeholder: 'Anmerkungen zum Markttag …' });
			var close = h('button', { class: 'btn primary', type: 'button' }, 'Tag abschließen & in Buchhaltung übernehmen');
			close.addEventListener('click', function () {
				if (!ende.value.trim()) { ui.toast('Bitte den gezählten Kassenbestand eintragen.', true); ende.focus(); return; }
				if (bon.length && !window.confirm('Auf dem Bon liegen noch nicht kassierte Positionen. Trotzdem abschließen?')) { return; }
				if (!window.confirm('Kassatag abschließen? Der Umsatz wird als Beleg und Kassenbucheintrag gebucht.')) { return; }
				post('abschluss', { tag_id: t.id, kassenbestand_ende: ende.value, notiz: notiz.value }, close, function (res) { bon = []; return load(); });
			});

			var rows = h('tbody');
			if (!t.positionen.length) { rows.appendChild(h('tr', null, h('td', { colspan: '4' }, 'Noch keine Verkäufe.'))); }
			t.positionen.slice().reverse().forEach(function (p) {
				var act = '';
				if (p.storniert) { act = h('span', { class: 'muted' }, 'storniert'); }
				else {
					act = h('button', { class: 'btn', type: 'button' }, 'Storno');
					act.addEventListener('click', function () {
						if (!window.confirm('Verkauf stornieren? Der Bestand wird zurückgebucht.')) { return; }
						post('storno', { id: p.id }, act);
					});
				}
				rows.appendChild(h('tr', { class: 'ks-pos' + (p.storniert ? ' storno' : '') },
					h('td', null, p.zeit), h('td', null, p.artikel + (p.menge > 1 ? ' × ' + p.menge : '')),
					h('td', { class: 'num' }, eur(p.betrag)), h('td', { class: 'num', style: 'text-decoration:none' }, act)));
			});

			/* --- Zusammenbau --- */
			var full = h('button', { class: 'btn', type: 'button' }, '⛶ Vollbild');
			full.addEventListener('click', function () { setFull(!(ui.app() && ui.app().classList.contains('kiosk'))); });

			box.appendChild(h('button', { class: 'kiosk-exit btn', type: 'button', onclick: function () { setFull(false); } }, 'Vollbild beenden (Esc)'));
			box.appendChild(h('div', { class: 'ks-total' },
				h('span', { class: 'big' }, eur(t.umsatz)),
				h('span', { class: 'muted' }, t.markt + ' · ' + datum(t.datum) + ' · Kassenbestand Anfang ' + eur(t.anfang)),
				h('span', { class: 'ks-grow' }), full));

			box.appendChild(h('div', { class: 'ks-main' },
				h('div', { class: 'card ks-form' },
					h('div', { style: 'margin-bottom:10px' }, newProd), panelBox,
					d.optionen.length ? [suche, grid] : h('p', { class: 'muted' }, 'Noch kein Kassenprodukt – oben ein Produkt anlegen.')),
				bonBox));

			box.appendChild(h('div', { class: 'two', style: 'margin-top:14px' },
				h('div', { class: 'card' },
					h('h3', null, 'Heutige Verkäufe'),
					h('div', { style: 'overflow-x:auto' }, h('table', { class: 'list' },
						h('thead', null, h('tr', null, h('th', null, 'Zeit'), h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Betrag'), h('th'))),
						rows))),
				h('div', { class: 'card ks-form' },
					h('h3', null, 'Tagesabschluss'),
					field('Kassenbestand Ende (€) – Bargeld zählen und eintragen', ende),
					field('Notiz', notiz),
					h('div', { style: 'margin-top:14px' }, close))));

			paintBon();
			if (preselect) {
				var neu = d.optionen.filter(function (o) { return o.value === preselect; })[0];
				preselect = '';
				if (neu) { addLine(neu); }
			}
		}

		/* ---------- Abgeschlossene Tage ---------- */

		function diffEl(x) {
			var cls = Math.abs(x) < 0.01 ? 'ok' : 'bad';
			return h('b', { class: 'ks-diff ' + cls }, (x >= 0 ? '+' : '') + eur(x));
		}

		function paintTage(d) {
			var tb = h('tbody');
			if (!d.tage.length) { tb.appendChild(h('tr', null, h('td', { colspan: '6' }, 'Noch keine abgeschlossenen Kassatage.'))); }
			d.tage.forEach(function (t) {
				var det = h('button', { class: 'btn', type: 'button' }, 'Details');
				det.addEventListener('click', function () { openDetail(t.id); });
				tb.appendChild(h('tr', null, h('td', null, datum(t.datum)), h('td', null, t.markt),
					h('td', { class: 'num' }, eur(t.umsatz)), h('td', { class: 'num' }, eur(t.ende)),
					h('td', { class: 'num' }, diffEl(t.differenz)), h('td', { class: 'num' }, det)));
			});
			box.appendChild(h('div', { class: 'card', style: 'margin-top:14px' },
				h('h3', null, 'Abgeschlossene Kassatage'),
				h('div', { style: 'overflow-x:auto' }, h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th', null, 'Datum'), h('th', null, 'Markt'), h('th', { class: 'num' }, 'Umsatz'),
						h('th', { class: 'num' }, 'Kasse Ende'), h('th', { class: 'num' }, 'Differenz'), h('th'))),
					tb)),
				h('p', { class: 'muted' }, 'Differenz = Kasse Ende − Kasse Anfang − Umsatz. Null heißt: Es liegt genau so viel in der Kasse, wie es sein soll.')));
		}

		function openDetail(id) {
			ui.api('kasse/tag?id=' + id).then(function (t) {
				var rows = h('tbody');
				t.positionen.forEach(function (p) {
					rows.appendChild(h('tr', { class: 'ks-pos' + (p.storniert ? ' storno' : '') },
						h('td', null, p.zeit), h('td', null, p.artikel), h('td', { class: 'num' }, String(p.menge)), h('td', { class: 'num' }, eur(p.betrag))));
				});
				box.textContent = '';
				var back = h('button', { class: 'btn', type: 'button' }, '← Zurück');
				back.addEventListener('click', load);
				box.appendChild(h('div', { style: 'margin-bottom:12px' }, back));
				box.appendChild(h('div', { class: 'grid tiles', style: 'margin-bottom:14px' },
					h('div', { class: 'tile' }, h('div', { class: 'lab' }, 'Kasse Anfang'), h('div', { class: 'val' }, eur(t.anfang))),
					h('div', { class: 'tile' }, h('div', { class: 'lab' }, 'Umsatz'), h('div', { class: 'val' }, eur(t.umsatz))),
					h('div', { class: 'tile' }, h('div', { class: 'lab' }, 'Kasse Ende'), h('div', { class: 'val' }, eur(t.ende))),
					h('div', { class: 'tile' }, h('div', { class: 'lab' }, 'Differenz'), h('div', { class: 'val' }, diffEl(t.differenz)))));
				box.appendChild(h('div', { class: 'card' },
					h('h3', null, t.markt + ' – ' + datum(t.datum)),
					t.notiz ? h('p', null, h('b', null, 'Notiz: '), t.notiz) : null,
					t.beleg_id ? h('p', { class: 'muted' }, 'Beleg-Nr. in der Buchhaltung: #' + t.beleg_id) : null,
					h('div', { style: 'overflow-x:auto' }, h('table', { class: 'list' },
						h('thead', null, h('tr', null, h('th', null, 'Zeit'), h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Menge'), h('th', { class: 'num' }, 'Betrag'))),
						rows))));
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function load() {
			return ui.api('kasse').then(function (d) {
				box.textContent = '';
				if (d.tag) { paintTag(d); } else { paintStart(d); paintProdukte(d); }
				paintTage(d);
			}).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
