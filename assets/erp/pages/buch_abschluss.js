/*
 * ERP-Bereich Buchhaltung: Reiter Anlagen, EÜR, GoBD-Prüfung, Export und
 * Jahresabschluss. Server: includes/Erp/Pages/BuchAbschluss.php.
 * Alle Daten gelangen über textContent bzw. setAttribute in die Seite, nie als HTML-Text.
 * Die Reiter melden sich im Gerüst der Buchhaltungsseite an (UI.buchTabs).
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.buchTabs = UI.buchTabs || {};

	var MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

	/* ---------- Hilfen ---------- */

	function eur(ui, v) {
		if (v === null || v === undefined || isNaN(v)) { return '—'; }
		return ui.money ? ui.money(v, 'EUR') : (Number(v).toFixed(2).replace('.', ',') + ' €');
	}

	function pct(v) {
		if (v === null || v === undefined) { return 'neu'; }
		var s = (Math.round(v * 10) / 10).toString().replace('.', ',');
		return (v > 0 ? '+' : '') + s + ' %';
	}

	function de(date) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : (date || '');
	}

	function qs(o) {
		return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] !== null && o[k] !== undefined; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
	}

	function download(name, mime, text) {
		var blob = new Blob([text], { type: mime });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url; a.download = name;
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
	}

	function canWrite(ctx, ui) {
		if (typeof ctx.canWrite === 'function') { return !!ctx.canWrite(); }
		if (ctx.canWrite !== undefined) { return !!ctx.canWrite; }
		return ui.can ? ui.can('finanzen.buchhaltung') : true;
	}

	function clear(node) { node.textContent = ''; return node; }

	function note(ui, text, warn) { return ui.h('div', { class: 'notice' + (warn ? ' warn' : '') }, text); }

	function fail(box, ui, e) {
		clear(box);
		box.appendChild(note(ui, (e && e.message) || 'Das hat leider nicht geklappt. Bitte noch einmal versuchen.', true));
	}

	function busy(box, ui, text) { clear(box); box.appendChild(ui.h('div', { class: 'card' }, text || 'Wird geladen …')); }

	function chipRow(ui, items, current, onPick) {
		var row = ui.h('div', { class: 'presets ba-chips' });
		items.forEach(function (it) {
			row.appendChild(ui.h('button', {
				class: 'chip ba-chip', type: 'button', 'aria-pressed': String(it.key) === String(current) ? 'true' : 'false',
				onclick: function () { onPick(it.key); }
			}, it.label));
		});
		return row;
	}

	function yearChips(ui, current, onPick) {
		var now = new Date().getFullYear();
		var items = [];
		for (var y = now - 3; y <= now; y++) { items.push({ key: y, label: String(y) }); }
		return chipRow(ui, items, current, onPick);
	}

	function lampLabel(s) { return { gruen: 'Erledigt', gelb: 'Prüfen', rot: 'Offen', grau: 'Wird geprüft' }[s] || s; }

	function lamp(ui, status) {
		return ui.h('span', { class: 'ba-lamp ' + status, role: 'img', 'aria-label': lampLabel(status) });
	}

	function tag(ui, text, kind) { return ui.h('span', { class: 'tag ' + (kind || '') }, text); }

	function field(ui, label, input) {
		return ui.h('label', { class: 'ba-field' }, ui.h('span', { class: 'muted' }, label), input);
	}

	function tile(ui, label, value, sub, tone) {
		return ui.h('div', { class: 'tile ba-tile' + (tone ? ' ' + tone : '') },
			ui.h('div', { class: 'lab' }, label),
			ui.h('div', { class: 'val' }, value),
			sub ? ui.h('div', { class: 'muted ba-sub' }, sub) : null);
	}

	function gotoTab(ctx, key) {
		var fn = ctx.goTab || ctx.go || ctx.gotoTab || ctx.openTab;
		if (typeof fn === 'function') { fn(key); return; }
		// Rückfall: den Knopf des Reiters in der Reiterleiste drücken.
		var def = UI.buchTabs[key];
		var label = def && def.label;
		var btns = document.querySelectorAll('button.chip');
		for (var i = 0; i < btns.length; i++) {
			if (label && btns[i].textContent === label) { btns[i].click(); return; }
		}
		if (ctx.ui && ctx.ui.toast) { ctx.ui.toast('Bitte den Reiter „' + (label || key) + '“ oben wählen.'); }
	}

	function post(ui, path, body) { return ui.api(path, { method: 'POST', body: body || {} }); }

	/* ====================================================================
	 * Reiter Anlagen
	 * ================================================================== */

	UI.buchTabs['anlagen'] = {
		label: 'Anlagen',
		order: 60,
		render: function (host, ctx) {
			var ui = ctx.ui, h = ui.h;
			var write = canWrite(ctx, ui);
			var year = new Date().getFullYear();
			var box = h('div');
			host.appendChild(box);

			function load() {
				busy(box, ui);
				ui.api('buchabschluss/anlagen').then(paint).catch(function (e) { fail(box, ui, e); });
			}

			function paint(d) {
				clear(box);
				var aktiv = d.anlagen.filter(function (a) { return !a.ausgeschieden; });
				box.appendChild(h('div', { class: 'grid tiles' },
					tile(ui, 'Anlagevermögen', eur(ui, d.buchwerte), 'Summe der Restbuchwerte'),
					tile(ui, 'Aktive Anlagen', String(aktiv.length), d.anlagen.length - aktiv.length > 0 ? (d.anlagen.length - aktiv.length) + ' ausgeschieden' : null)));

				box.appendChild(h('h3', { class: 'ba-h' }, 'Abschreibung (AfA) buchen'));
				var afaBox = h('div');
				box.appendChild(h('div', { class: 'card' },
					h('p', { class: 'muted' }, 'Die jährliche Abschreibung verteilt die Anschaffungskosten gleichmäßig auf die Nutzungsdauer. Sie wird als festgeschriebener Beleg zum 31.12. gebucht und mindert den Gewinn. Gebuchtes lässt sich nicht ändern, nur stornieren. Darum zeigt die Vorschau zuerst, was passiert, und pro Anlage und Jahr geht es nur einmal.'),
					yearChips(ui, year, function (y) { year = y; load(); }),
					afaBox));
				afaPreview(afaBox);

				box.appendChild(h('h3', { class: 'ba-h' }, 'Anlagenverzeichnis'));
				if (!d.anlagen.length) {
					box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Noch keine Anlagen erfasst. Dazu gehören Dinge mit längerer Nutzung, zum Beispiel Drucker, Laptop oder Werkbank.')));
				} else {
					var rows = h('div', { class: 'rows' });
					d.anlagen.forEach(function (a) { rows.appendChild(anlageCard(a, d)); });
					box.appendChild(rows);
				}

				if (write) { box.appendChild(newForm(d)); }
			}

			function afaPreview(target) {
				busy(target, ui, 'Vorschau wird berechnet …');
				ui.api('buchabschluss/afa-vorschau?jahr=' + year).then(function (v) {
					clear(target);
					target.appendChild(h('h4', { class: 'ba-h4' }, 'Vorschau ' + v.jahr));
					if (!v.zeilen.length) { target.appendChild(h('p', { class: 'muted' }, 'Noch keine Anlagen.')); return; }
					v.zeilen.forEach(function (z) {
						var kind = z.status === 'buchen' ? 'later' : (z.status === 'gebucht' ? 'now' : '');
						var label = { buchen: 'wird gebucht', gebucht: 'gebucht', ausgeschieden: 'ausgeschieden', abgeschrieben: 'abgeschrieben', ausserhalb: 'nicht in diesem Jahr' }[z.status] || z.status;
						target.appendChild(h('div', { class: 'ba-line' },
							h('div', { class: 'ba-line-main' },
								h('b', null, z.nummer + ' · ' + z.bezeichnung),
								h('div', { class: 'muted ba-sub' }, z.hinweis + (z.status === 'buchen' ? ' Buchwert danach ' + eur(ui, z.buchwert_neu) + '.' : ''))),
							h('div', { class: 'ba-line-side' },
								tag(ui, label, kind),
								z.status === 'buchen' ? h('b', null, eur(ui, z.betrag)) : null,
								(write && z.status === 'buchen') ? h('button', { class: 'btn', type: 'button', onclick: function (ev) { bookAfa(ev.target, z.id, eur(ui, z.betrag) + ' für ' + z.bezeichnung); } }, 'Nur diese buchen') : null)));
					});
					if (v.offen > 0 && write) {
						target.appendChild(h('div', { class: 'ba-actions' },
							h('button', { class: 'btn primary', type: 'button', onclick: function (ev) { bookAfa(ev.target, 0, v.offen + ' Abschreibung(en), zusammen ' + eur(ui, v.summe)); } },
								'AfA ' + v.jahr + ' für ' + v.offen + ' Anlage(n) buchen (' + eur(ui, v.summe) + ')')));
					} else if (v.offen === 0) {
						target.appendChild(h('p', { class: 'muted' }, v.gebucht > 0 ? 'Für ' + v.jahr + ' ist alles gebucht.' : 'Für ' + v.jahr + ' ist nichts abzuschreiben.'));
					}
				}).catch(function (e) { fail(target, ui, e); });
			}

			function bookAfa(btn, id, what) {
				if (!window.confirm('Jetzt buchen: ' + what + '?\nGebuchte Abschreibungen sind festgeschrieben und lassen sich nur stornieren.')) { return; }
				btn.disabled = true;
				post(ui, 'buchabschluss/afa-buchen', { jahr: year, id: id }).then(function (r) {
					ui.toast(r.message);
					load();
				}).catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
			}

			function anlageCard(a, d) {
				var detail = h('div', { class: 'ba-detail', hidden: true });
				var open = false;
				var card = h('div', { class: 'row-card ba-anlage' + (a.ausgeschieden ? ' ba-off' : '') });
				var tog = h('button', { class: 'btn ba-wide', type: 'button', 'aria-expanded': 'false', onclick: function () {
					open = !open;
					tog.setAttribute('aria-expanded', open ? 'true' : 'false');
					tog.textContent = open ? 'Weniger anzeigen' : 'Plan und Aktionen';
					if (open) { detail.removeAttribute('hidden'); } else { detail.setAttribute('hidden', ''); }
				} }, 'Plan und Aktionen');

				var plan = h('table', { class: 'list ba-plan' });
				plan.appendChild(h('thead', null, h('tr', null, h('th', null, 'Jahr'), h('th', { class: 'num' }, 'AfA'), h('th', { class: 'num' }, 'Restbuchwert'), h('th', null, 'Stand'))));
				var tb = h('tbody');
				a.plan.forEach(function (p) {
					tb.appendChild(h('tr', null, h('td', null, String(p.jahr)), h('td', { class: 'num' }, eur(ui, p.afa)), h('td', { class: 'num' }, eur(ui, p.buchwert)), h('td', null, p.gebucht ? 'gebucht' : 'geplant')));
				});
				plan.appendChild(tb);
				detail.appendChild(h('div', { class: 'zt-scroll' }, plan));
				detail.appendChild(h('p', { class: 'muted ba-sub' }, 'Der Plan zeigt die rechnerische Verteilung. Maßgeblich ist der gebuchte Restbuchwert oben.'));

				if (a.ausgeschieden) {
					detail.appendChild(h('p', null, 'Ausgeschieden am ' + de(a.ausgeschieden_am) + '. Der Restbuchwert wird nicht mehr abgeschrieben.'));
				} else if (write) {
					detail.appendChild(scrapForm(a, d));
				}

				card.appendChild(h('div', { class: 'row-main' },
					h('div', null, h('b', null, a.bezeichnung), h('div', { class: 'muted ba-sub' }, a.nummer + ' · ' + a.kategorie + ' · gekauft ' + de(a.datum))),
					h('div', { class: 'row-tags' }, tag(ui, a.ausgeschieden ? 'ausgeschieden' : 'aktiv', a.ausgeschieden ? '' : 'now'))));
				card.appendChild(h('div', { class: 'kv ba-kv' },
					h('div', null, h('span', { class: 'muted' }, 'Kosten'), h('b', null, eur(ui, a.kosten))),
					h('div', null, h('span', { class: 'muted' }, a.dauer + ' Jahre, je'), h('b', null, eur(ui, a.afa_jahr))),
					h('div', null, h('span', { class: 'muted' }, 'Restbuchwert'), h('b', null, eur(ui, a.buchwert)))));
				card.appendChild(tog);
				card.appendChild(detail);
				return card;
			}

			function scrapForm(a, d) {
				var why = h('input', { class: 'field', type: 'text', maxlength: '200', placeholder: 'z. B. verkauft, defekt entsorgt, privat entnommen', 'aria-label': 'Grund' });
				var when = h('input', { class: 'field', type: 'date', value: d.heute, max: d.heute, 'aria-label': 'Datum' });
				var out = h('div');
				return h('div', { class: 'ba-form' },
					h('h4', { class: 'ba-h4' }, 'Ausscheiden'),
					h('p', { class: 'muted ba-sub' }, 'Wenn die Anlage verkauft, entsorgt oder privat genutzt wird. Der Grund wird im Protokoll festgehalten. Ein Verkaufserlös gehört als Einnahme verbucht; das passiert nicht automatisch.'),
					field(ui, 'Grund', why), field(ui, 'Datum', when),
					h('button', { class: 'btn', type: 'button', onclick: function (ev) {
						if (!window.confirm(a.nummer + ' wirklich als ausgeschieden markieren? Restbuchwert: ' + eur(ui, a.buchwert) + '.')) { return; }
						var btn = ev.target; btn.disabled = true;
						post(ui, 'buchabschluss/anlagen-ausscheiden', { id: a.id, grund: why.value, datum: when.value }).then(function (r) {
							ui.toast(r.message); load();
						}).catch(function (e) { btn.disabled = false; clear(out); out.appendChild(note(ui, e.message, true)); });
					} }, 'Als ausgeschieden vermerken'), out);
			}

			function newForm(d) {
				var name = h('input', { class: 'field', type: 'text', maxlength: '190', placeholder: 'z. B. 3D-Drucker', 'aria-label': 'Bezeichnung' });
				var date = h('input', { class: 'field', type: 'date', value: d.heute, 'aria-label': 'Anschaffungsdatum' });
				var cost = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '1.299,00', 'aria-label': 'Anschaffungskosten' });
				var life = h('input', { class: 'field', type: 'number', min: '1', max: '50', value: '3', 'aria-label': 'Nutzungsdauer in Jahren' });
				var cat = h('select', { class: 'field', 'aria-label': 'Kategorie' });
				d.kategorien.forEach(function (k) { cat.appendChild(h('option', { value: k }, k)); });
				var out = h('div');
				var wrap = h('div', { class: 'card ba-form' },
					h('p', { class: 'muted ba-sub' }, 'Die Nummer (AN-Jahr-Zähler) und der Anfangs-Buchwert werden automatisch vergeben. Die Nutzungsdauer steht in den amtlichen AfA-Tabellen, für Computer zum Beispiel 1 Jahr, für viele Maschinen 5 bis 10 Jahre.'),
					field(ui, 'Bezeichnung', name), field(ui, 'Anschaffungsdatum', date), field(ui, 'Anschaffungskosten (€)', cost),
					field(ui, 'Nutzungsdauer (Jahre)', life), field(ui, 'Kategorie', cat),
					h('button', { class: 'btn primary', type: 'button', onclick: function (ev) {
						var btn = ev.target; btn.disabled = true; clear(out);
						post(ui, 'buchabschluss/anlagen', { bezeichnung: name.value, anschaffungsdatum: date.value, anschaffungskosten: cost.value, nutzungsdauer_jahre: parseInt(life.value, 10) || 0, kategorie: cat.value }).then(function (r) {
							ui.toast(r.message); load();
						}).catch(function (e) { btn.disabled = false; out.appendChild(note(ui, e.message, true)); });
					} }, 'Anlage erfassen'), out);
				var panel = h('div', { hidden: true }, wrap);
				var shown = false;
				var btn = h('button', { class: 'btn primary ba-wide', type: 'button', 'aria-expanded': 'false', onclick: function () {
					shown = !shown;
					btn.setAttribute('aria-expanded', shown ? 'true' : 'false');
					btn.textContent = shown ? 'Formular schließen' : 'Neue Anlage erfassen';
					if (shown) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
				} }, 'Neue Anlage erfassen');
				return h('div', { class: 'ba-new' }, h('h3', { class: 'ba-h' }, 'Neue Anlage'), btn, panel);
			}

			load();
		}
	};

	/* ====================================================================
	 * Reiter EÜR
	 * ================================================================== */

	UI.buchTabs['euer'] = {
		label: 'EÜR',
		order: 70,
		render: function (host, ctx) {
			var ui = ctx.ui, h = ui.h;
			var now = new Date();
			var q = { modus: 'jahr', jahr: now.getFullYear(), quartal: Math.ceil((now.getMonth() + 1) / 3), monat: now.getMonth() + 1, von: '', bis: '', vergleich: '1' };
			var bar = h('div');
			var box = h('div');
			host.appendChild(bar);
			host.appendChild(box);

			function controls() {
				clear(bar);
				var card = h('div', { class: 'card' });
				card.appendChild(chipRow(ui, [{ key: 'jahr', label: 'Jahr' }, { key: 'quartal', label: 'Quartal' }, { key: 'monat', label: 'Monat' }, { key: 'frei', label: 'Frei wählen' }], q.modus, function (k) { q.modus = k; controls(); load(); }));
				var line = h('div', { class: 'ba-controls' });
				if (q.modus === 'frei') {
					var a = h('input', { class: 'field', type: 'date', value: q.von || (q.jahr + '-01-01'), 'aria-label': 'Von' });
					var b = h('input', { class: 'field', type: 'date', value: q.bis || (q.jahr + '-12-31'), 'aria-label': 'Bis' });
					line.appendChild(field(ui, 'Von', a));
					line.appendChild(field(ui, 'Bis', b));
					line.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { q.von = a.value; q.bis = b.value; load(); } }, 'Berechnen'));
				} else {
					var y = h('input', { class: 'field', type: 'number', min: '2000', max: '2100', value: String(q.jahr), 'aria-label': 'Jahr' });
					y.onchange = function () { q.jahr = parseInt(y.value, 10) || q.jahr; load(); };
					line.appendChild(field(ui, 'Jahr', y));
					if (q.modus === 'quartal') {
						line.appendChild(chipRow(ui, [1, 2, 3, 4].map(function (n) { return { key: n, label: n + '. Quartal' }; }), q.quartal, function (n) { q.quartal = n; controls(); load(); }));
					}
					if (q.modus === 'monat') {
						var m = h('select', { class: 'field', 'aria-label': 'Monat' });
						MONTHS.forEach(function (n, i) { m.appendChild(h('option', { value: String(i + 1) }, n)); });
						m.value = String(q.monat);
						m.onchange = function () { q.monat = parseInt(m.value, 10); load(); };
						line.appendChild(field(ui, 'Monat', m));
					}
				}
				card.appendChild(line);
				var cmp = h('input', { type: 'checkbox' });
				cmp.checked = q.vergleich === '1';
				cmp.onchange = function () { q.vergleich = cmp.checked ? '1' : '0'; load(); };
				card.appendChild(h('label', { class: 'ba-check' }, cmp, ' Mit demselben Zeitraum des Vorjahres vergleichen'));
				bar.appendChild(card);
			}

			function delta(v, goodUp) {
				if (!v) { return null; }
				var diff = v.diff;
				var good = Math.abs(diff) < 0.005 ? '' : ((diff > 0) === goodUp ? 'ba-good' : 'ba-bad');
				return h('span', { class: 'ba-delta ' + good }, (diff > 0 ? '▲ ' : (diff < 0 ? '▼ ' : '= ')) + (diff > 0 ? '+' : (diff < 0 ? '−' : '')) + eur(ui, Math.abs(diff)) + ' (' + pct(v.prozent) + ')');
			}

			function load() {
				busy(box, ui);
				ui.api('buchabschluss/euer?' + qs(q)).then(paint).catch(function (e) {
					fail(box, ui, e);
				});
			}

			function paint(d) {
				clear(box);
				var cmp = d.vergleich;
				box.appendChild(h('h3', { class: 'ba-h' }, 'Einnahmenüberschussrechnung ' + d.label));
				box.appendChild(h('div', { class: 'grid tiles' },
					tile(ui, 'Einnahmen', eur(ui, d.einnahmen), d.vorjahr ? 'Vorjahr: ' + eur(ui, d.vorjahr.einnahmen) : null),
					tile(ui, 'Ausgaben', eur(ui, d.ausgaben), d.vorjahr ? 'Vorjahr: ' + eur(ui, d.vorjahr.ausgaben) : null),
					tile(ui, 'Gewinn', eur(ui, d.gewinn), d.vorjahr ? 'Vorjahr: ' + eur(ui, d.vorjahr.gewinn) : null, d.gewinn >= 0 ? 'ok' : 'bad')));
				if (cmp) {
					box.appendChild(h('div', { class: 'card ba-cmp' },
						h('b', null, 'Gegenüber ' + d.vorjahr.label),
						h('div', null, 'Einnahmen ', delta(cmp.einnahmen, true)),
						h('div', null, 'Ausgaben ', delta(cmp.ausgaben, false)),
						h('div', null, 'Gewinn ', delta(cmp.gewinn, true))));
				}

				box.appendChild(h('h3', { class: 'ba-h' }, 'Ausgaben nach Kontengruppe'));
				if (!d.gruppen.length) {
					box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'In diesem Zeitraum gibt es keine festgeschriebenen Ausgaben.')));
				}
				d.gruppen.forEach(function (g) { box.appendChild(group(g, d)); });
				if (d.kuerzung > 0) {
					box.appendChild(h('p', { class: 'muted ba-sub' }, 'Darin sind ' + eur(ui, d.kuerzung) + ' nicht abziehbarer Anteil bereits abgezogen (z. B. 30 % der Bewirtung).'));
				}
				if (d.kleinunternehmer) {
					box.appendChild(note(ui, 'Kleinunternehmer nach §19 UStG: Es wird keine Umsatzsteuer ausgewiesen, alle Beträge sind brutto.'));
				}

				var acts = h('div', { class: 'ba-actions' });
				if (d.darf_export) {
					acts.appendChild(h('a', { class: 'btn primary', href: d.pdf_url, target: '_blank', rel: 'noopener' }, ui.ico ? ui.ico('download') : null, 'EÜR als PDF (mit Belegfotos)'));
					acts.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (ev) {
						var btn = ev.target; btn.disabled = true;
						ui.api('buchabschluss/euer-csv?' + qs(q)).then(function (r) { btn.disabled = false; download(r.filename, r.mime, r.text); })
							.catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
					} }, 'EÜR als CSV'));
				} else {
					acts.appendChild(h('span', { class: 'muted' }, 'Für PDF und CSV fehlt dir das Recht „Daten exportieren“.'));
				}
				box.appendChild(acts);
			}

			function group(g, d) {
				var body = h('div', { class: 'ba-detail', hidden: true });
				var loaded = false, open = false;
				var cmp = g.vergleich;
				var btn = h('button', { class: 'btn ba-wide', type: 'button', 'aria-expanded': 'false', onclick: function () {
					open = !open;
					btn.setAttribute('aria-expanded', open ? 'true' : 'false');
					btn.textContent = open ? 'Zuklappen' : 'Buchungen anzeigen';
					if (open) { body.removeAttribute('hidden'); } else { body.setAttribute('hidden', ''); }
					if (open && !loaded) { loaded = true; fill(); }
				} }, 'Buchungen anzeigen');

				function fill() {
					busy(body, ui, 'Belege werden geladen …');
					ui.api('buchabschluss/euer-buchungen?' + qs({ von: d.von, bis: d.bis, gruppe: g.name })).then(function (r) {
						clear(body);
						if (g.konten.length) {
							g.konten.forEach(function (k) {
								body.appendChild(h('div', { class: 'ba-konto' }, h('span', null, k.label), h('b', null, eur(ui, k.abziehbar)), k.brutto !== k.abziehbar ? h('span', { class: 'muted ba-sub' }, 'Belege: ' + eur(ui, k.brutto)) : null));
							});
						}
						if (!r.rows.length) { body.appendChild(h('p', { class: 'muted' }, 'Keine Belege in diesem Zeitraum.')); return; }
						var tb = h('tbody');
						r.rows.forEach(function (b) {
							tb.appendChild(h('tr', null, h('td', null, b.nummer), h('td', null, de(b.datum)), h('td', null, b.haendler), h('td', null, b.konto), h('td', { class: 'num' }, eur(ui, b.betrag))));
						});
						body.appendChild(h('div', { class: 'zt-scroll' }, h('table', { class: 'list' },
							h('thead', null, h('tr', null, h('th', null, 'Nr.'), h('th', null, 'Datum'), h('th', null, 'Händler'), h('th', null, 'Konto'), h('th', { class: 'num' }, 'Betrag'))), tb)));
						if (r.gekuerzt) { body.appendChild(h('p', { class: 'muted ba-sub' }, 'Es werden nicht alle Belege angezeigt. Bitte den Zeitraum verkleinern.')); }
					}).catch(function (e) { fail(body, ui, e); });
				}

				return h('div', { class: 'card ba-group' },
					h('div', { class: 'ba-group-head' },
						h('b', null, g.name),
						h('b', { class: 'ba-num' }, eur(ui, g.summe))),
					cmp ? h('div', { class: 'ba-sub muted' }, 'Vorjahr ' + eur(ui, cmp.vorjahr) + ' ', delta(cmp, false)) : null,
					btn, body);
			}

			controls();
			load();
		}
	};

	/* ====================================================================
	 * Reiter GoBD-Prüfung
	 * ================================================================== */

	function gobdPoints(ui, punkte) {
		var h = ui.h;
		var wrap = h('div', { class: 'ba-points' });
		punkte.forEach(function (p) {
			var tech = p.technisch ? h('details', { class: 'ba-tech' }, h('summary', null, 'Technische Angabe'), h('div', { class: 'ba-mono' }, p.technisch)) : null;
			wrap.appendChild(h('div', { class: 'ba-point' },
				lamp(ui, p.status),
				h('div', { class: 'ba-point-body' }, h('b', null, p.titel), h('div', null, p.erklaerung), tech)));
		});
		return wrap;
	}

	function gobdBadge(ui, ergebnis) {
		var map = { ok: ['in Ordnung', 'now'], warnung: ['Hinweise', 'later'], fehler: ['Fehler', ''] };
		var m = map[ergebnis] || [ergebnis, ''];
		return tag(ui, m[0], m[1]);
	}

	UI.buchTabs['gobd'] = {
		label: 'GoBD-Prüfung',
		order: 80,
		render: function (host, ctx) {
			var ui = ctx.ui, h = ui.h;
			var write = canWrite(ctx, ui);
			var box = h('div');
			host.appendChild(box);

			function load() {
				busy(box, ui);
				ui.api('buchabschluss/gobd').then(paint).catch(function (e) { fail(box, ui, e); });
			}

			function paint(d, last) {
				clear(box);
				var year = h('input', { class: 'field', type: 'number', min: '2020', max: '2099', value: String(last ? last.jahr : d.jahr), 'aria-label': 'Jahr' });
				var out = h('div');
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Die Prüfung kontrolliert, ob Belege, Rechnungen, Buchungen, Kassenbuch und Protokoll noch genauso dastehen, wie sie angelegt wurden (Unveränderbarkeit), ob die Nummern lückenlos laufen und ob Belege zeitnah erfasst wurden. Sie verändert nichts. Empfehlung: einmal im Jahr vor dem Abschluss und nach größeren Importen.'),
					write ? h('div', { class: 'ba-controls' }, field(ui, 'Jahr', year), h('button', { class: 'btn primary', type: 'button', onclick: function (ev) {
						var btn = ev.target; btn.disabled = true;
						busy(out, ui, 'Die Prüfung läuft. Das kann einen Moment dauern …');
						post(ui, 'buchabschluss/gobd', { jahr: parseInt(year.value, 10) }).then(function (r) {
							btn.disabled = false;
							var verdict = r.status === 'ok' ? 'Alles in Ordnung.' : (r.status === 'fehler' ? r.fehler + ' Fehler gefunden. Bitte die roten Punkte lesen.' : 'Keine Fehler, aber ' + r.warnungen + ' Hinweis(e).');
							paint({ verlauf: r.verlauf, jahr: r.jahr }, r);
							var res = h('div', { class: 'card' }, h('h3', { class: 'ba-h4' }, 'Ergebnis für ' + r.jahr + ': ' + verdict), gobdPoints(ui, r.punkte));
							box.insertBefore(res, box.children[1] || null);
							ui.toast('GoBD-Prüfung ' + r.jahr + ' abgeschlossen.');
						}).catch(function (e) { btn.disabled = false; clear(out); out.appendChild(note(ui, e.message, true)); });
					} }, 'Prüfung starten')) : null,
					out));

				box.appendChild(h('h3', { class: 'ba-h' }, 'Verlauf'));
				if (!d.verlauf.length) { box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Es wurde noch keine Prüfung durchgeführt.'))); return; }
				d.verlauf.forEach(function (v) {
					var det = h('details', { class: 'card ba-hist' },
						h('summary', null, h('span', { class: 'ba-hist-line' }, h('b', null, 'Jahr ' + v.jahr), ' · ' + v.datum + ' ', gobdBadge(ui, v.ergebnis), h('span', { class: 'muted ba-sub' }, v.fehler + ' Fehler, ' + v.warnungen + ' Hinweise'))),
						v.punkte.length ? gobdPoints(ui, v.punkte) : h('p', { class: 'muted' }, 'Zu dieser Prüfung sind keine Einzelpunkte gespeichert.'));
					box.appendChild(det);
				});
			}

			load();
		}
	};

	/* ====================================================================
	 * Reiter Export
	 * ================================================================== */

	UI.buchTabs['export'] = {
		label: 'Export',
		order: 90,
		render: function (host, ctx) {
			var ui = ctx.ui, h = ui.h;
			var box = h('div');
			host.appendChild(box);

			if (ui.can && !ui.can('finanzen.export')) {
				box.appendChild(note(ui, 'Für den Export fehlt dir das Recht „Daten exportieren“. Bitte bei der Verwaltung nachfragen.', true));
				return;
			}

			busy(box, ui);
			ui.api('buchabschluss/export').then(function (d) {
				clear(box);
				function hidden(n, v) { return h('input', { type: 'hidden', name: n, value: v }); }
				var von = h('input', { class: 'field', type: 'date', name: 'from', value: d.jahr + '-01-01', 'aria-label': 'Von' });
				var bis = h('input', { class: 'field', type: 'date', name: 'to', value: d.jahr + '-12-31', 'aria-label': 'Bis' });
				var now = new Date().getFullYear();
				var years = h('div', { class: 'presets ba-chips' });
				[now - 2, now - 1, now].forEach(function (y) {
					years.appendChild(h('button', { class: 'chip ba-chip', type: 'button', onclick: function () { von.value = y + '-01-01'; bis.value = y + '-12-31'; } }, 'Ganzes Jahr ' + y));
				});
				var files = h('ul', { class: 'ba-files' });
				d.dateien.forEach(function (f) { files.appendChild(h('li', null, h('b', null, f.name), ' – ' + f.text)); });

				box.appendChild(h('div', { class: 'card' },
					h('h3', { class: 'ba-h4' }, 'Export für die Betriebsprüfung (ZIP)'),
					h('p', null, 'Bei einer Betriebsprüfung darf das Finanzamt die Daten maschinell auswerten (§147 Abs. 6 AO, „Z3“: Datenträgerüberlassung). Dieser Export packt alles Nötige für einen Zeitraum in eine ZIP-Datei. Er verändert nichts und wird im Protokoll vermerkt. Das ZIP enthält:'),
					files,
					d.zip_ok ? null : note(ui, 'Auf diesem Server fehlt die ZIP-Erweiterung von PHP. Der Export geht erst, wenn der Hoster sie freischaltet.', true),
					h('form', { method: 'post', action: d.admin_post, class: 'zt-form' },
						hidden('action', 'lw16_bp_export'), hidden('_wpnonce', d.bp_nonce),
						years,
						h('div', { class: 'ba-controls' }, field(ui, 'Von', von), field(ui, 'Bis', bis)),
						h('button', { class: 'btn primary', type: 'submit', disabled: !d.zip_ok }, ui.ico ? ui.ico('download') : null, 'ZIP-Export herunterladen'))));

				box.appendChild(h('div', { class: 'card' },
					h('h3', { class: 'ba-h4' }, 'Verfahrensdokumentation (PDF)'),
					h('p', null, 'Beschreibt, wie in diesem System Belege erfasst, verbucht, festgeschrieben, storniert und archiviert werden. Die GoBD verlangen so eine Beschreibung; der Prüfer fragt zuerst danach. Sie ist ein PDF mit dem Firmennamen und dem heutigen Stand und wird bei jedem Abruf neu erzeugt.'),
					h('a', { class: 'btn', href: d.doku_url, target: '_blank', rel: 'noopener' }, ui.ico ? ui.ico('download') : null, 'Verfahrensdokumentation herunterladen')));

				if (d.frueher.length) {
					var list = h('ul', { class: 'ba-files' });
					d.frueher.slice(0, 8).forEach(function (x) { list.appendChild(h('li', null, de(x.von) + ' bis ' + de(x.bis) + ' · erstellt ' + x.zeit)); });
					box.appendChild(h('div', { class: 'card' }, h('h3', { class: 'ba-h4' }, 'Bisherige Exporte'), list));
				}
			}).catch(function (e) { fail(box, ui, e); });
		}
	};

	/* ====================================================================
	 * Reiter Jahresabschluss
	 * ================================================================== */

	UI.buchTabs['abschluss'] = {
		label: 'Jahresabschluss',
		order: 85,
		render: function (host, ctx) {
			var ui = ctx.ui, h = ui.h;
			var write = canWrite(ctx, ui);
			var year = new Date().getFullYear();
			var bar = h('div');
			var box = h('div');
			var token = 0;
			host.appendChild(bar);
			host.appendChild(box);

			function drawBar() {
				clear(bar);
				bar.appendChild(h('div', { class: 'card' }, h('b', null, 'Geschäftsjahr'), yearChips(ui, year, function (y) { year = y; drawBar(); load(); })));
			}

			function load() {
				var mine = ++token;
				busy(box, ui);
				ui.api('buchabschluss/abschluss?jahr=' + year).then(function (d) {
					if (mine !== token) { return; }
					paint(d);
					// Die Gebührenprüfung dauert länger und kommt in einem zweiten Schritt.
					return ui.api('buchabschluss/abschluss?jahr=' + year + '&gebuehren=1').then(function (d2) {
						if (mine === token) { paint(d2); }
					});
				}).catch(function (e) { if (mine === token) { fail(box, ui, e); } });
			}

			function summary(d) {
				var n = { rot: 0, gelb: 0, grau: 0, gruen: 0 };
				d.zeilen.forEach(function (z) { n[z.status]++; });
				var text = d.gesamt === 'gruen' ? 'Alles erledigt. Das Jahr ' + d.jahr + ' kann abgeschlossen werden.'
					: (d.gesamt === 'grau' ? 'Die Prüfung läuft noch …'
						: (d.gesamt === 'rot' ? n.rot + ' Punkt(e) sind noch offen, bevor das Jahr ' + d.jahr + ' sauber abgeschlossen ist.'
							: 'Fast fertig: ' + n.gelb + ' Punkt(e) solltest du noch ansehen.'));
				return h('div', { class: 'card ba-sum ' + d.gesamt }, lamp(ui, d.gesamt), h('div', null, h('b', null, text), h('div', { class: 'muted ba-sub' }, n.gruen + ' von ' + d.zeilen.length + ' Punkten sind erledigt.')));
			}

			function paint(d) {
				clear(box);
				box.appendChild(summary(d));
				var rows = h('div', { class: 'rows' });
				d.zeilen.forEach(function (z) { rows.appendChild(line(z)); });
				box.appendChild(rows);
				box.appendChild(h('p', { class: 'muted ba-sub' }, 'Die Ampel ist eine Hilfe, kein Steuerberater: Sie zeigt, was sich im System prüfen lässt. Ob alle Belege überhaupt erfasst sind, weißt nur du.'));
			}

			function line(z) {
				var out = h('div');
				var actions = h('div', { class: 'ba-actions' });
				var a = z.aktion;

				if (a && a.art === 'reiter') {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { gotoTab(ctx, a.ziel); } }, a.label));
				} else if (a && a.art === 'post' && write) {
					actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (ev) {
						var btn = ev.target; btn.disabled = true; btn.textContent = 'Bitte warten …';
						post(ui, a.route, { jahr: year }).then(function (r) { ui.toast(r.message); load(); })
							.catch(function (e) { btn.disabled = false; btn.textContent = a.label; ui.toast(e.message, true); });
					} }, a.label));
				} else if (a && a.art === 'kassensturz' && write) {
					actions.appendChild(cashForm(z));
				}

				if (z.key === 'kasse' && z.saldo !== undefined && z.anzahl > 0) {
					out.appendChild(h('div', { class: 'muted ba-sub' }, 'Buchbestand am 31.12.' + year + ': ' + eur(ui, z.saldo)));
				}
				if (z.key === 'gebuehren' && z.geprueft !== null && z.geprueft !== undefined) {
					out.appendChild(h('div', { class: 'muted ba-sub' }, z.geprueft + ' Bestellungen geprüft.'));
				}

				return h('div', { class: 'row-card ba-check-row ' + z.status },
					h('div', { class: 'ba-check-head' }, lamp(ui, z.status), h('div', { class: 'ba-check-title' }, h('b', null, z.titel), h('span', { class: 'muted ba-sub' }, lampLabel(z.status)))),
					h('div', null, z.text), out, actions);
			}

			function cashForm(z) {
				var inp = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: 'Gezähltes Bargeld, z. B. 125,50', 'aria-label': 'Gezähltes Bargeld in Euro' });
				var wrap = h('div', { class: 'ba-cash' }, inp,
					h('button', { class: 'btn', type: 'button', onclick: function (ev) {
						var btn = ev.target; btn.disabled = true;
						post(ui, 'buchabschluss/kassenbuch-bestaetigen', { jahr: year, bargeld: inp.value }).then(function (r) { ui.toast(r.message); load(); })
							.catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
					} }, 'Kassensturz eintragen'));
				return wrap;
			}

			drawBar();
			load();
		}
	};
})();
