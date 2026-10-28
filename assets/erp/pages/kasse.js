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

		/* ---------- Offener Tag ---------- */

		function paintTag(d) {
			var t = d.tag;
			var wahl = '';          // Auswahlwert der Kachel (a:/p:), leer = freier Betrag
			var kacheln = [];

			var name = h('input', { class: 'field', type: 'text', placeholder: 'Artikelbezeichnung' });
			var menge = h('input', { class: 'field', type: 'number', min: '1', step: '1', value: '1', inputmode: 'numeric' });
			var preis = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });

			var suche = h('input', { class: 'field', type: 'search', placeholder: 'Artikel filtern …', autocomplete: 'off' });
			suche.addEventListener('input', function () {
				var q = suche.value.toLowerCase().replace(/\s+/g, ' ').trim();
				kacheln.forEach(function (k) { k.el.hidden = q !== '' && k.o.suche.indexOf(q) === -1; });
			});

			var grid = h('div', { class: 'ks-grid' });
			d.optionen.forEach(function (o) {
				var bild = o.bild
					? h('span', { class: 'ks-bild' }, h('img', { src: o.bild, alt: '', loading: 'lazy' }))
					: h('span', { class: 'ks-bild' }, o.kuerzel);
				var el = h('button', { class: 'ks-kachel', type: 'button', 'aria-pressed': 'false' },
					bild,
					h('span', { class: 'ks-name' }, o.name),
					o.sku ? h('span', { class: 'ks-sku' }, o.sku) : null,
					h('span', { class: 'ks-preis' }, o.preis === '' ? 'Preis offen' : eur(parseFloat(o.preis))));
				el.addEventListener('click', function () {
					// Zweiter Tipp auf dieselbe Kachel hebt die Auswahl auf – sonst hängt am
					// nächsten freien Betrag noch das vorige Produkt und die Ware geht doppelt ab.
					var war = el.getAttribute('aria-pressed') === 'true';
					kacheln.forEach(function (k) { k.el.setAttribute('aria-pressed', 'false'); });
					if (war) { wahl = ''; return; }
					el.setAttribute('aria-pressed', 'true');
					wahl = o.value;
					name.value = o.name;
					if (o.preis !== '') { preis.value = o.preis.replace('.', ','); } else { preis.value = ''; preis.focus(); }
				});
				kacheln.push({ el: el, o: o });
				grid.appendChild(el);
			});

			var sell = h('button', { class: 'btn primary', type: 'button' }, 'Verkauf buchen');
			sell.addEventListener('click', function () {
				if (!preis.value.trim()) { ui.toast('Bitte einen Preis eintragen.', true); preis.focus(); return; }
				post('verkauf', { tag_id: t.id, produkt_id: wahl, artikel_name: name.value, menge: parseInt(menge.value, 10) || 1, einzelpreis: preis.value }, sell);
			});

			var fname = h('input', { class: 'field', type: 'text', placeholder: 'z. B. Sonderverkauf' });
			var fbetrag = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
			var free = h('button', { class: 'btn', type: 'button' }, 'Betrag buchen');
			free.addEventListener('click', function () {
				if (!fbetrag.value.trim()) { ui.toast('Bitte einen Betrag eintragen.', true); fbetrag.focus(); return; }
				// produkt_id bleibt leer: ein freier Betrag bucht kein Lager ab.
				post('verkauf', { tag_id: t.id, produkt_id: '', artikel_name: fname.value, menge: 1, einzelpreis: fbetrag.value }, free);
			});

			var ende = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0,00' });
			var notiz = h('textarea', { class: 'field', placeholder: 'Anmerkungen zum Markttag …' });
			var close = h('button', { class: 'btn primary', type: 'button' }, 'Tag abschließen & in Buchhaltung übernehmen');
			close.addEventListener('click', function () {
				if (!ende.value.trim()) { ui.toast('Bitte den gezählten Kassenbestand eintragen.', true); ende.focus(); return; }
				if (!window.confirm('Kassatag abschließen? Der Umsatz wird als Beleg und Kassenbucheintrag gebucht.')) { return; }
				post('abschluss', { tag_id: t.id, kassenbestand_ende: ende.value, notiz: notiz.value }, close);
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

			box.appendChild(h('div', { class: 'ks-total' },
				h('span', { class: 'big' }, eur(t.umsatz)),
				h('span', { class: 'muted' }, t.markt + ' · ' + datum(t.datum) + ' · Kassenbestand Anfang ' + eur(t.anfang))));

			var left = h('div', { class: 'ks-stack' },
				h('div', { class: 'card ks-form' },
					h('h3', null, 'Neuer Verkauf'),
					d.optionen.length ? [suche, grid] : h('p', { class: 'muted' }, 'Noch kein verkäuflicher Artikel angelegt – unten einen freien Betrag buchen.'),
					field('Artikelname', name),
					h('div', { class: 'ks-row' }, field('Menge', menge), field('Einzelpreis (€)', preis)),
					h('div', { style: 'margin-top:14px' }, sell)),
				h('div', { class: 'card ks-form' },
					h('h3', null, 'Freier Betrag'),
					h('p', { class: 'muted' }, 'Für alles ohne Artikel im Stamm – Restposten, Spende, Reparatur. Der Betrag geht in Umsatz und Kassenbuch, es wird kein Lagerbestand abgebucht.'),
					h('div', { class: 'ks-row' }, field('Bezeichnung', fname), field('Betrag (€)', fbetrag)),
					h('div', { style: 'margin-top:14px' }, free)),
				h('div', { class: 'card ks-form' },
					h('h3', null, 'Tagesabschluss'),
					field('Kassenbestand Ende (€) – Bargeld zählen und eintragen', ende),
					field('Notiz', notiz),
					h('div', { style: 'margin-top:14px' }, close)));

			var right = h('div', { class: 'card' },
				h('h3', null, 'Heutige Verkäufe'),
				h('div', { style: 'overflow-x:auto' }, h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th', null, 'Zeit'), h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Betrag'), h('th'))),
					rows)));

			box.appendChild(h('div', { class: 'two' }, left, right));
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
				if (d.tag) { paintTag(d); } else { paintStart(d); }
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
