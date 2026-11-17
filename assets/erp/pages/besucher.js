/*
 * ERP-Seite „Besucher“ (nativ): wie viele Personen gerade auf der Website
 * sind und auf welchen Seiten, wann viel los ist, wie sie sich bewegen. Fragt alle 10 Sekunden nach. Die Zahlen kommen
 * ohne Cookie und ohne IP-Adresse zustande (includes/Erp/Presence.php); gezählt
 * werden geöffnete Seiten, nicht Menschen. Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var SECTIONS = { start: 'Start', shop: 'Shop', warenkorb: 'Warenkorb', kasse: 'Kasse', inhalt: 'Inhalt' };

	function dauer(s) {
		if (s < 60) { return 'unter 1 Min.'; }
		return Math.round(s / 60) + ' Min.';
	}

	UI.pages['besucher'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Besucher', 'Wer gerade auf der Website ist, wann viel los ist und wie sich Besucher bewegen – anonym, ohne Cookie und ohne Einwilligungsbanner.', ui.classicBtn(item));

		var tabs = h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px' });
		var box = h('div');
		var wege = h('div', { style: 'display:none' });
		var ein = h('div', { style: 'display:none' });
		var verl = h('div', { style: 'display:none' });
		var TABS = [['jetzt', 'Jetzt'], ['verlauf', 'Verlauf'], ['wege', 'Wege'], ['produkte', 'Produkte'], ['kampagnen', 'Kampagnen'], ['suche', 'Suche'], ['fehler', 'Fehlerseiten']];
		var btns = {};
		var current = 'jetzt';
		TABS.forEach(function (t) {
			var b = h('button', { class: 'pill' + (t[0] === 'jetzt' ? ' on' : ''), type: 'button' }, t[1]);
			b.addEventListener('click', function () { show(t[0]); });
			btns[t[0]] = b;
			tabs.appendChild(b);
		});
		ui.view().appendChild(tabs);
		ui.view().appendChild(box);
		ui.view().appendChild(wege);
		ui.view().appendChild(ein);
		ui.view().appendChild(verl);

		function show(key) {
			current = key;
			box.style.display = key === 'jetzt' ? '' : 'none';
			verl.style.display = key === 'verlauf' ? '' : 'none';
			wege.style.display = key === 'wege' ? '' : 'none';
			ein.style.display = key === 'jetzt' || key === 'wege' || key === 'verlauf' ? 'none' : '';
			TABS.forEach(function (t) { btns[t[0]].className = 'pill' + (t[0] === key ? ' on' : ''); });
			if (key === 'wege') { loadWege(); } else if (key === 'verlauf') { loadVerlauf(); } else if (key !== 'jetzt') { loadEin(); }
		}

		var days = 30;
		var seite = '';

		function label(p) {
			if (p === '') { return 'Direkt / Neuladen'; }
			if (p.charAt(0) === '@') { return p.slice(1); }
			return p;
		}

		function list(title, rows, empty) {
			var t = h('table', { class: 'list' });
			var b = h('tbody');
			rows.forEach(function (r) {
				var name = h('td', null, r.path.charAt(0) === '/' ? h('a', { href: '#', 'data-p': r.path }, label(r.path)) : label(r.path));
				b.appendChild(h('tr', null, name, h('td', { class: 'num' }, String(r.count))));
			});
			t.appendChild(b);
			var card = h('div', { class: 'card' }, h('h3', null, title), rows.length ? t : h('p', { class: 'muted' }, empty));
			Array.prototype.forEach.call(card.querySelectorAll('a[data-p]'), function (a) {
				a.addEventListener('click', function (e) { e.preventDefault(); seite = a.getAttribute('data-p'); loadWege(); });
			});
			return card;
		}

		function paintWege(d) {
			wege.textContent = '';

			var bar = h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px' });
			[7, 30, 90].forEach(function (n) {
				var b = h('button', { class: 'pill' + (n === d.days ? ' on' : ''), type: 'button' }, n + ' Tage');
				b.addEventListener('click', function () { days = n; loadWege(); });
				bar.appendChild(b);
			});
			var sel = h('select', { class: 'field', 'aria-label': 'Seite wählen', style: 'max-width:22em' }, h('option', { value: '' }, '— alle Wege —'));
			d.pages.forEach(function (p) { sel.appendChild(h('option', { value: p.path, selected: p.path === d.page }, p.path + ' (' + p.count + ')')); });
			sel.addEventListener('change', function () { seite = sel.value; loadWege(); });
			bar.appendChild(sel);
			wege.appendChild(bar);

			if (d.page) {
				var two = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px' });
				two.appendChild(list('Davor: wo kommen sie her?', d.before, 'Zu wenige Fälle (unter 3).'));
				two.appendChild(list('Danach: wohin gehen sie?', d.after, 'Zu wenige Fälle (unter 3).'));
				wege.appendChild(h('h3', { style: 'margin:0 0 8px' }, d.page));
				wege.appendChild(two);
			} else {
				var cart = d.cart.in
					? h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Warenkorb'),
						h('p', null, d.cart.in + ' Aufrufe des Warenkorbs, davon ' + d.cart.to_kasse + ' weiter zur Kasse (' + Math.round(d.cart.to_kasse / d.cart.in * 100) + ' %).'),
						h('p', { class: 'muted' }, 'Die Differenz sind Aufrufe, nach denen niemand zur Kasse ging oder die Kasse über einen anderen Weg erreicht wurde.'))
					: null;
				if (cart) { wege.appendChild(cart); }

				var t = h('table', { class: 'list' });
				t.appendChild(h('thead', null, h('tr', null, h('th', null, 'Von'), h('th', null, 'Nach'), h('th', { class: 'num' }, 'Fälle'))));
				var tb = h('tbody');
				d.transitions.forEach(function (r) { tb.appendChild(h('tr', null, h('td', null, r.from), h('td', null, r.to), h('td', { class: 'num' }, String(r.count)))); });
				t.appendChild(tb);
				wege.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Häufigste Wege'),
					d.transitions.length ? t : h('p', { class: 'muted' }, 'Noch zu wenig Daten: Wege erscheinen ab 3 gleichen Fällen.'),
					d.hidden ? h('p', { class: 'muted', style: 'margin-top:8px' }, d.hidden + ' weitere Aufrufe verteilen sich auf seltene Wege (unter 3 Fälle) und werden nicht einzeln gezeigt.') : null));

				var two2 = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:14px' });
				two2.appendChild(list('Einstiegsseiten', d.entries, 'Noch keine Daten.'));
				two2.appendChild(list('Gekommen von (fremde Seiten)', d.sources, 'Noch keine Daten.'));
				wege.appendChild(two2);
			}

			wege.appendChild(h('p', { class: 'muted' }, 'Gezählt wird nur jeder einzelne Schritt von einer Seite zur nächsten, je Tag. Es wird nicht gespeichert, welche Aufrufe zusammengehören – ein ganzer Weg einer Person lässt sich daraus nicht rekonstruieren. Einstieg heißt: direkt, Lesezeichen oder neu geladen. Aufbewahrung 90 Tage.'));
		}

		function loadWege() {
			return ui.api('besucher/wege?tage=' + days + (seite ? '&seite=' + encodeURIComponent(seite) : '')).then(paintWege).catch(function (e) {
				wege.textContent = '';
				wege.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

		function fmtEur(n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n); }
		function pctTxt(n) { return n === null ? '–' : String(n).replace('.', ',') + ' %'; }

		function rangeBar(reload) {
			var bar = h('div', { style: 'display:flex;gap:8px;margin-bottom:14px' });
			[7, 30, 90].forEach(function (n) {
				var b = h('button', { class: 'pill' + (n === days ? ' on' : ''), type: 'button' }, n + ' Tage');
				b.addEventListener('click', function () { days = n; reload(); });
				bar.appendChild(b);
			});
			return bar;
		}

		function table(head, rows) {
			var t = h('table', { class: 'list' });
			t.appendChild(h('thead', null, h('tr', null, head.map(function (c) { return h('th', { class: c[1] ? 'num' : '' }, c[0]); }))));
			var b = h('tbody');
			rows.forEach(function (r) { b.appendChild(h('tr', null, r.map(function (c, i) { return h('td', { class: head[i][1] ? 'num' : '' }, c); }))); });
			t.appendChild(b);
			return t;
		}

		var HINTS = {
			nie_im_warenkorb: 'Viel angesehen, nie im Warenkorb: Bild, Preis oder Text prüfen.',
			nie_gekauft: 'Im Warenkorb, aber nie gekauft: Versandkosten oder Kasse prüfen.',
			warenkorb_abbruch: 'Wird oft in den Warenkorb gelegt, selten gekauft.'
		};

		var vdays = 30;
		var WD = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

		function fmtDate(iso) {
			var p = iso.split('-');
			return p[2] + '.' + p[1] + '.' + p[0];
		}
		function num1(n) { return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(n); }

		function bars(items, label, opts) {
			var max = 1;
			items.forEach(function (i) { if (i.v > max) { max = i.v; } });
			var wrap = h('div', { style: 'display:flex;align-items:flex-end;gap:' + (opts.gap || 2) + 'px;height:' + (opts.height || 120) + 'px', role: 'img', 'aria-label': label });
			items.forEach(function (i) {
				wrap.appendChild(h('div', {
					title: i.t,
					style: 'flex:1;min-width:1px;border-radius:2px 2px 0 0;background:var(--accent);height:' + Math.max(i.v > 0 ? 2 : 0, Math.round(i.v / max * 100)) + '%' + (i.hot ? '' : ';opacity:.55')
				}));
			});
			return wrap;
		}

		function paintVerlauf(d) {
			verl.textContent = '';

			var bar = h('div', { style: 'display:flex;gap:8px;margin-bottom:14px' });
			[7, 30, 90, 365].forEach(function (n) {
				var b = h('button', { class: 'pill' + (n === d.days ? ' on' : ''), type: 'button' }, n === 365 ? '1 Jahr' : n + ' Tage');
				b.addEventListener('click', function () { vdays = n; loadVerlauf(); });
				bar.appendChild(b);
			});
			verl.appendChild(bar);

			function big(label, value, sub) {
				return h('div', { class: 'card' },
					h('div', { class: 'muted', style: 'font-size:12.5px' }, label),
					h('div', { style: 'font-size:26px;font-weight:700;line-height:1.25' }, value),
					sub ? h('div', { class: 'muted', style: 'font-size:12.5px' }, sub) : null);
			}

			var grid = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px' });
			grid.appendChild(big('Seitenaufrufe', new Intl.NumberFormat('de-DE').format(d.total), 'in ' + d.days + ' Tagen, heute ' + d.today));
			grid.appendChild(big('Ø pro Tag', d.complete_days ? num1(d.avg_day) : '–', d.complete_days ? 'aus ' + d.complete_days + ' abgeschlossenen Tagen' : 'noch kein abgeschlossener Tag'));
			grid.appendChild(big('Bester Tag', d.best_day ? new Intl.NumberFormat('de-DE').format(d.best_day.views) : '–', d.best_day ? fmtDate(d.best_day.date) : null));
			grid.appendChild(big('Stärkster Wochentag', d.best_weekday ? WD[d.best_weekday.w - 1] : '–', d.best_weekday ? 'Ø ' + num1(d.best_weekday.avg) + ' Aufrufe' : null));
			grid.appendChild(big('Stärkste Stunde', d.best_hour ? d.best_hour.h + '–' + (d.best_hour.h + 1) + ' Uhr' : '–', d.best_hour ? 'Ø ' + num1(d.best_hour.avg) + ' Aufrufe je Tag' : null));
			verl.appendChild(grid);

			if (!d.total) {
				verl.appendChild(h('div', { class: 'card' }, h('p', null, 'In diesem Zeitraum wurden noch keine Seitenaufrufe gezählt.'),
					h('p', { class: 'muted' }, 'Angemeldete Personen, Suchmaschinen und Browser mit „Do Not Track“ zählen nicht.')));
				return;
			}

			var dailyItems = d.daily.map(function (x) { return { v: x.views, t: fmtDate(x.date) + ': ' + x.views + ' Aufrufe', hot: !d.best_day || x.views >= d.best_day.views * 0.8 }; });
			var first = d.daily[0].date, last = d.daily[d.daily.length - 1].date;
			verl.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Aufrufe pro Tag'),
				bars(dailyItems, 'Seitenaufrufe pro Tag', { gap: d.days > 90 ? 0 : 2, height: 130 }),
				h('div', { class: 'muted', style: 'display:flex;justify-content:space-between;font-size:12.5px;margin-top:4px' }, h('span', null, fmtDate(first)), h('span', null, fmtDate(last))),
				h('p', { class: 'muted', style: 'margin-top:6px' }, 'Hell hervorgehoben: Tage mit mindestens 80 % des besten Tages. Die Maus über einen Balken zeigt die Zahl.')));

			var two = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;margin-bottom:14px' });

			var wdMax = 0;
			d.weekdays.forEach(function (w) { if (w.avg > wdMax) { wdMax = w.avg; } });
			var wdTable = h('table', { class: 'list' });
			var wdBody = h('tbody');
			d.weekdays.forEach(function (w) {
				wdBody.appendChild(h('tr', null, h('td', null, WD[w.w - 1]),
					h('td', { style: 'width:55%' }, h('div', { style: 'height:12px;border-radius:3px;background:var(--accent);width:' + (wdMax ? Math.round(w.avg / wdMax * 100) : 0) + '%;opacity:' + (w.avg === wdMax && wdMax > 0 ? 1 : 0.55) })),
					h('td', { class: 'num' }, num1(w.avg))));
			});
			wdTable.appendChild(wdBody);
			two.appendChild(h('div', { class: 'card' }, h('h3', null, 'Nach Wochentag'), wdTable,
				h('p', { class: 'muted', style: 'margin-top:8px' }, 'Durchschnittliche Aufrufe pro Tag.')));

			var hourItems = d.hours.map(function (x) { return { v: x.avg, t: x.h + '–' + (x.h + 1) + ' Uhr: Ø ' + num1(x.avg) + ' Aufrufe', hot: d.best_hour && x.avg >= d.best_hour.avg * 0.8 }; });
			var ticks = h('div', { class: 'muted', style: 'display:flex;justify-content:space-between;font-size:12.5px;margin-top:4px' });
			['0', '6', '12', '18', '24'].forEach(function (t) { ticks.appendChild(h('span', null, t + ' Uhr')); });
			two.appendChild(h('div', { class: 'card' }, h('h3', null, 'Nach Tageszeit'), bars(hourItems, 'Aufrufe nach Tageszeit', { gap: 3, height: 150 }), ticks,
				h('p', { class: 'muted', style: 'margin-top:8px' }, 'Durchschnittliche Aufrufe je Stunde und Tag.')));
			verl.appendChild(two);

			var heatMax = 0;
			d.heat.forEach(function (row) { row.forEach(function (v) { if (v > heatMax) { heatMax = v; } }); });
			var heat = h('div', { style: 'display:grid;grid-template-columns:5.5em repeat(24,minmax(0,1fr));gap:2px;font-size:11px' });
			heat.appendChild(h('div'));
			for (var hh = 0; hh < 24; hh++) { heat.appendChild(h('div', { class: 'muted', style: 'text-align:center' }, hh % 3 === 0 ? String(hh) : '')); }
			d.heat.forEach(function (row, wi) {
				heat.appendChild(h('div', { class: 'muted', style: 'align-self:center' }, WD[wi].slice(0, 2)));
				row.forEach(function (v, hi) {
					heat.appendChild(h('div', {
						title: WD[wi] + ', ' + hi + '–' + (hi + 1) + ' Uhr: Ø ' + num1(v) + ' Aufrufe',
						style: 'aspect-ratio:1;min-height:14px;border-radius:2px;background:var(--accent);opacity:' + (v > 0 && heatMax ? (0.12 + 0.88 * v / heatMax).toFixed(2) : '0.05')
					}));
				});
			});
			verl.appendChild(h('div', { class: 'card' }, h('h3', null, 'Wann ist viel los? Wochentag und Uhrzeit'), heat,
				h('p', { class: 'muted', style: 'margin-top:8px' }, 'Je dunkler, desto mehr Aufrufe im Durchschnitt (Uhrzeit in deiner Ortszeit). Die Maus über ein Feld zeigt die Zahl. Gezählt sind Seitenaufrufe, nicht Menschen; Durchschnitte nutzen nur abgeschlossene Tage' + (d.first ? ' ab ' + fmtDate(d.first) : '') + '.')));
		}

		function loadVerlauf() {
			return ui.api('besucher/verlauf?tage=' + vdays).then(paintVerlauf).catch(function (e) {
				verl.textContent = '';
				verl.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

		function paintEin(d) {
			ein.textContent = '';
			ein.appendChild(rangeBar(loadEin));
			var note = function (t) { return h('p', { class: 'muted', style: 'margin-top:8px' }, t); };

			if (current === 'produkte') {
				var rows = d.products.map(function (p) {
					return [h('span', null, h('b', null, p.name), p.hint ? h('div', { class: 'muted', style: 'font-size:12.5px' }, HINTS[p.hint]) : null),
						String(p.views), String(p.carts), String(p.orders), pctTxt(p.cart_rate), pctTxt(p.buy_rate)];
				});
				ein.appendChild(h('div', { class: 'card' }, h('h3', null, 'Produkt-Trichter'),
					rows.length ? table([['Produkt'], ['Ansichten', 1], ['In den Warenkorb', 1], ['Bestellungen', 1], ['Ansicht → Warenkorb', 1], ['Ansicht → Kauf', 1]], rows) : h('p', { class: 'muted' }, 'Noch keine Daten.'),
					note('Ansichten und Warenkorb zählen anonym je Seitenaufruf; Bestellungen kommen aus dem Shop (bezahlt oder in Bearbeitung). Ein Produkt kann auch über andere Wege gekauft werden, die Quoten sind daher Richtwerte. Angemeldete Personen zählen nicht.')));
			} else if (current === 'kampagnen') {
				var kr = d.campaigns.map(function (k) {
					return [h('span', null, h('b', null, k.source), k.campaigns.length ? h('div', { class: 'muted', style: 'font-size:12.5px' }, k.campaigns.slice(0, 5).map(function (c) { return c.name + ' (' + c.visits + ')'; }).join(' · ')) : null),
						String(k.visits), String(k.orders), k.orders ? fmtEur(k.revenue) : '–', pctTxt(k.rate)];
				});
				ein.appendChild(h('div', { class: 'card' }, h('h3', null, 'Besuche und Bestellungen nach Quelle'),
					kr.length ? table([['Quelle'], ['Besuche', 1], ['Bestellungen', 1], ['Umsatz', 1], ['Besuch → Kauf', 1]], kr) : h('p', { class: 'muted' }, 'Noch keine Besuche mit utm_source. Beispiel für einen Link: ?utm_source=instagram&utm_campaign=herbst – die Kurzlinks tragen das für QR-Codes.'),
					note('Besuche zählen den Einstieg über einen Link mit utm_source. Bestellungen mit Quelle liefert WooCommerce selbst; ohne Zustimmung des Kunden erfasst es keine Herkunft, deshalb stehen viele Bestellungen unter „unbekannt“ und die Quote ist zu niedrig angesetzt.')));
			} else if (current === 'suche') {
				var sr = d.search.rows.map(function (r) { return [r.label, String(r.n)]; });
				var mr = d.misses.rows.map(function (r) { return [r.label, String(r.n)]; });
				var two = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px' });
				two.appendChild(h('div', { class: 'card' }, h('h3', null, 'Häufigste Suchen'), sr.length ? table([['Suchbegriff'], ['Mal', 1]], sr) : h('p', { class: 'muted' }, 'Noch keine Daten.')));
				two.appendChild(h('div', { class: 'card' }, h('h3', null, 'Gesucht, nichts gefunden'), mr.length ? table([['Suchbegriff'], ['Mal', 1]], mr) : h('p', { class: 'muted' }, 'Keine – oder zu selten (unter 2 Mal).')));
				ein.appendChild(two);
				ein.appendChild(note('Begriffe erscheinen erst ab 2 gleichen Suchen; Begriffe mit @, Link oder langen Zahlen werden gar nicht gespeichert. ' + (d.search.hidden + d.misses.hidden) + ' seltene Suchen sind nicht einzeln aufgeführt.'));
			} else if (current === 'fehler') {
				var er = d.e404.rows.map(function (r) { return [h('code', null, r.label), r.extra ? (r.extra.charAt(0) === '@' ? r.extra.slice(1) : (r.extra || 'Direkt')) : 'Direkt / unbekannt', String(r.n)]; });
				ein.appendChild(h('div', { class: 'card' }, h('h3', null, 'Adressen, die es nicht gibt'),
					er.length ? table([['Adresse'], ['Link steht auf'], ['Mal', 1]], er) : h('p', { class: 'muted' }, 'Keine Fehlerseiten in diesem Zeitraum (oder erst ab 2 gleichen Aufrufen).'),
					note('„Link steht auf“ ist die Seite, von der der Besucher kam – dort ist der kaputte Link. Direkt oder unbekannt: Tippfehler, Lesezeichen, alte Flyer oder QR-Codes. Dateien wie Bilder und Systemadressen werden ignoriert.')));
			}
		}

		function loadEin() {
			return ui.api('besucher/einblicke?tage=' + days).then(paintEin).catch(function (e) {
				ein.textContent = '';
				ein.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

		function paint(d) {
			box.textContent = '';

			var grid = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px' });
			function big(label, value, sub) {
				return h('div', { class: 'card' },
					h('div', { class: 'muted', style: 'font-size:12.5px' }, label),
					h('div', { style: 'font-size:34px;font-weight:700;line-height:1.2' }, String(value)),
					sub ? h('div', { class: 'muted', style: 'font-size:12.5px' }, sub) : null);
			}
			grid.appendChild(big('Jetzt online', d.online, d.online === 1 ? 'offene Seite' : 'offene Seiten'));
			grid.appendChild(big('Im Warenkorb / Kasse', d.sections.warenkorb + d.sections.kasse, d.sections.kasse + ' in der Kasse'));
			grid.appendChild(big('Am Handy', d.devices.m, d.devices.d + ' am Rechner'));
			box.appendChild(grid);

			if (!d.online) {
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Gerade ist niemand auf der Website.'),
					h('p', { class: 'muted' }, 'Nicht gezählt werden angemeldete Personen (auch du selbst, wenn du im Shop angemeldet bist – zum Testen ein privates Fenster nehmen), Suchmaschinen und Browser mit „Do Not Track“. Hinweis: Eine Seite zählt, bis sie 90 Sekunden nichts mehr gemeldet hat.')));
				return;
			}

			var pages = h('table', { class: 'list' });
			pages.appendChild(h('thead', null, h('tr', null, h('th', null, 'Seite'), h('th', null, 'Bereich'), h('th', { class: 'num' }, 'Jetzt offen'))));
			var pb = h('tbody');
			d.pages.forEach(function (p) {
				pb.appendChild(h('tr', null,
					h('td', null, h('b', null, p.title || p.path), p.title ? h('div', { class: 'muted', style: 'font-size:12.5px' }, p.path) : null),
					h('td', null, SECTIONS[p.section] || p.section),
					h('td', { class: 'num' }, String(p.count))));
			});
			pages.appendChild(pb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Auf diesen Seiten'), pages));

			var who = h('table', { class: 'list' });
			who.appendChild(h('thead', null, h('tr', null, h('th', null, 'Seite'), h('th', null, 'Seit'), h('th', null, 'Gerät'), h('th', null, 'Gekommen von'))));
			var wb = h('tbody');
			d.visitors.forEach(function (v) {
				wb.appendChild(h('tr', null,
					h('td', null, v.title || v.path),
					h('td', null, dauer(v.since)),
					h('td', null, v.dev === 'm' ? 'Handy' : (v.dev === 'd' ? 'Rechner' : '–')),
					h('td', null, v.ref || '–')));
			});
			who.appendChild(wb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Offene Seiten im Einzelnen'), who,
				h('p', { class: 'muted', style: 'margin-top:8px' }, 'Eine Zeile ist eine geöffnete Seite, kein wiedererkannter Mensch: Jeder Seitenaufruf bekommt eine neue Zufallsnummer, die nur im Tab lebt. „Seit“ gilt deshalb für diese eine Seite. „Gekommen von“ steht nur beim ersten Aufruf aus einer fremden Seite (Suchmaschine, soziales Netz); sonst bleibt es leer.')));

			if (d.refs.length) {
				var refs = h('table', { class: 'list' });
				refs.appendChild(h('thead', null, h('tr', null, h('th', null, 'Herkunft'), h('th', { class: 'num' }, 'Offene Seiten'))));
				var rb = h('tbody');
				d.refs.forEach(function (r) { rb.appendChild(h('tr', null, h('td', null, r.host), h('td', { class: 'num' }, String(r.count)))); });
				refs.appendChild(rb);
				box.appendChild(h('div', { class: 'card' }, h('h3', null, 'Woher sie kommen'), refs));
			}
		}

		function load() {
			return ui.api('besucher').then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

		load();
		ui.every(load, 10000);
	};
})();
