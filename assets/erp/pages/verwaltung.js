/*
 * Verwaltung im ERP: Zeiterfassung, Löhne und Protokoll, Etikettendrucker,
 * Einstellungen. Serverseitig: includes/Erp/Pages/Verwaltung.php.
 * Klassisches ES5, kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var ui = null;
	var h = null;

	/* ---------- Hilfen ---------- */

	function use(u) { ui = u; h = u.h; }

	function post(path, body) { return ui.api(path, { method: 'POST', body: body || {} }); }

	function fmt(v, d) {
		var n = Number(v);
		if (!isFinite(n)) { return ''; }
		return n.toLocaleString('de-DE', { minimumFractionDigits: d || 0, maximumFractionDigits: d === undefined ? 2 : d });
	}

	function eur(v) { return v === null || v === undefined ? '—' : fmt(v, 2) + ' €'; }

	function dmy(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : (iso || '');
	}

	function hms(sec) {
		sec = Math.max(0, Math.floor(sec));
		function p(n) { return (n < 10 ? '0' : '') + n; }
		return Math.floor(sec / 3600) + ':' + p(Math.floor((sec % 3600) / 60)) + ':' + p(sec % 60);
	}

	function ts(unix) { return unix ? ui.when(unix) : '—'; }

	function loading() { return h('div', { class: 'card' }, 'Wird geladen …'); }

	function problem(msg) { return h('div', { class: 'notice warn' }, msg); }

	// Knopf sperren, solange die Anfrage läuft; Fehler als Meldung zeigen.
	function run(btn, fn, done) {
		btn.disabled = true;
		return fn().then(function (r) {
			btn.disabled = false;
			if (done) { done(r); }
			return r;
		}, function (e) {
			btn.disabled = false;
			ui.toast(e.message, true);
		});
	}

	function field(label, input, hint) {
		return h('label', { class: 'vw-f' }, h('span', null, label), input, hint ? h('small', { class: 'muted' }, hint) : null);
	}

	function input(type, value, attrs) {
		var a = { class: 'field', type: type, value: value === undefined || value === null ? '' : String(value) };
		Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
		return h('input', a);
	}

	function select(options, current) {
		var s = h('select', { class: 'field' });
		options.forEach(function (o) {
			var opt = h('option', { value: o[0] }, o[1]);
			if (o[0] === current) { opt.selected = true; }
			s.appendChild(opt);
		});
		return s;
	}

	function pills(items, current, onPick) {
		var box = h('div', { class: 'pills', role: 'tablist' });
		items.forEach(function (it) {
			box.appendChild(h('button', {
				class: 'pill' + (it[0] === current ? ' on' : ''), type: 'button', role: 'tab',
				'aria-selected': it[0] === current ? 'true' : 'false',
				onclick: function () { onPick(it[0]); }
			}, it[1]));
		});
		return box;
	}

	function table(heads, rows, numCols) {
		var thead = h('tr');
		heads.forEach(function (t, i) { thead.appendChild(h('th', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : '' }, t)); });
		var tbody = h('tbody');
		rows.forEach(function (r) {
			var tr = h('tr');
			r.forEach(function (c, i) { tr.appendChild(h('td', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : '' }, c)); });
			tbody.appendChild(tr);
		});
		return h('div', { class: 'vw-scroll' }, h('table', { class: 'list' }, h('thead', null, thead), tbody));
	}

	function head(item, title, sub) {
		ui.pageHead(title, sub, ui.classicBtn(item));
	}

	/* ====================================================================
	 * 1. Zeiterfassung
	 * ================================================================== */

	UI.pages['zeit'] = function (item, u) {
		use(u);
		head(item, 'Zeiterfassung', 'Stempeluhr, deine Einträge der Woche und Nachträge.');

		var box = h('div', { class: 'vw' });
		ui.view().appendChild(box);
		box.appendChild(loading());

		var state = { offset: 0, data: null, base: 0, fetched: 0 };
		var clockText = null;

		function load() {
			return ui.api('zeit?woche=' + state.offset).then(function (d) {
				state.data = d;
				state.base = d.clock ? d.clock.seconds : 0;
				state.fetched = Date.now();
				if (ui.clockSync) { ui.clockSync(); }
				paint();
			}).catch(function (e) { box.textContent = ''; box.appendChild(problem(e.message)); });
		}

		function catOptions(d) { return d.categories.map(function (c) { return [c.key, c.label]; }); }

		function clockCard(d) {
			var c = d.clock;
			if (!c) {
				clockText = null;
				var start = h('button', { class: 'btn primary vw-big', type: 'button' }, ui.ico('clock'), 'Uhr starten');
				start.addEventListener('click', function () {
					run(start, function () { return post('zeit/start'); }, function () { ui.toast('Die Uhr läuft.'); load(); });
				});
				return h('section', { class: 'card' },
					h('h3', null, 'Stempeluhr'),
					h('p', { class: 'muted' }, 'Die Uhr bucht nichts von selbst. Erst beim Stoppen legst du Tätigkeit, Datum und Stunden fest – dann entsteht genau ein Eintrag.'),
					start);
			}

			clockText = h('div', { class: 'vw-clock', role: 'timer', 'aria-live': 'off' }, hms(c.seconds));
			var stop = h('button', { class: 'btn primary', type: 'button' }, ui.ico('check'), 'Stoppen und erfassen');
			var drop = h('button', { class: 'btn', type: 'button' }, 'Verwerfen');
			var pause = h('button', { class: 'btn', type: 'button' }, c.paused ? 'Weiter' : 'Pause');
			pause.addEventListener('click', function () { run(pause, function () { return post(c.paused ? 'zeit/weiter' : 'zeit/pause'); }, function () { load(); }); });

			stop.addEventListener('click', function () { run(stop, function () { return ui.api('zeit?woche=' + state.offset); }, function (fresh) { if (fresh.clock) { stopDialog(fresh); } else { ui.toast('Die Uhr läuft nicht mehr.', true); load(); } }); });
			drop.addEventListener('click', function () {
				if (!window.confirm('Die Uhr verwerfen? Es wird nichts gebucht.')) { return; }
				run(drop, function () { return post('zeit/verwerfen'); }, function (r) { ui.toast(r.message); load(); });
			});

			return h('section', { class: 'card vw-run' },
				h('h3', null, c.paused ? 'Angehalten' : 'Laufende Zeit'),
				clockText,
				h('p', { class: 'muted' }, 'Gestartet ' + ui.when(c.started_at) + ' Uhr.'),
				c.hint && c.hint.text ? h('div', { class: 'notice warn' }, c.hint.text) : null,
				c.explanation ? h('p', { class: 'muted' }, 'Währenddessen warst du hier: ' + c.explanation) : null,
				h('div', { class: 'vw-actions' }, pause, stop, drop));
		}

		function stopDialog(d) {
			var c = d.clock;
			var date = input('date', c.entry_date, { required: 'required' });
			var cat = select(catOptions(d), c.category);
			var hours = input('text', fmt(c.hours, 2), { inputmode: 'decimal' });
			var desc = input('text', '', { placeholder: 'Was hast du gemacht?', maxlength: '200' });
			var err = h('div', { class: 'notice warn', hidden: true, role: 'alert' });
			var go = h('button', { class: 'btn primary', type: 'submit' }, 'Stoppen und erfassen');
			var form = h('form', { class: 'vw-form' },
				c.hint && c.hint.text ? h('div', { class: 'notice warn' }, c.hint.text) : null,
				!c.confident ? h('p', { class: 'muted' }, 'Die Zeit verteilt sich auf mehrere Bereiche – such die Tätigkeit selbst aus, geraten wird hier nicht.') : null,
				field('Datum', date), field('Kategorie', cat), field('Stunden', hours, 'Vorgabe ist die volle Laufzeit, du kannst sie kürzen.'), field('Tätigkeit', desc),
				err, go);
			var dialog = ui.dlg('Zeit erfassen', form);
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				err.hidden = true;
				go.disabled = true;
				post('zeit/stopp', { entry_date: date.value, category: cat.value, hours: hours.value, description: desc.value }).then(function (r) {
					dialog.close();
					ui.toast(r.message);
					load();
				}, function (x) { go.disabled = false; err.textContent = x.message; err.hidden = false; });
			});
		}

		function manualCard(d) {
			var date = input('date', d.today);
			var cat = select(catOptions(d), 'sonstiges');
			var hours = input('text', '', { inputmode: 'decimal', placeholder: 'z. B. 1,5' });
			var desc = input('text', '', { placeholder: 'Was hast du gemacht?', maxlength: '200' });
			var block = input('text', '', { placeholder: 'optional', maxlength: '20' });
			var go = h('button', { class: 'btn primary', type: 'submit' }, 'Erfassen');
			var form = h('form', { class: 'vw-form' }, field('Datum', date), field('Kategorie', cat), field('Stunden', hours), field('Tätigkeit', desc), field('Block', block), go);
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				run(go, function () { return post('zeit/eintrag', { entry_date: date.value, category: cat.value, hours: hours.value, description: desc.value, phase_block: block.value }); }, function (r) { ui.toast(r.message); load(); });
			});
			return h('section', { class: 'card' }, h('h3', null, 'Zeit nachtragen'), form);
		}

		function weekCard(d) {
			var w = d.week;
			var labels = {};
			d.categories.forEach(function (c) { labels[c.key] = c.label; });
			var prev = h('button', { class: 'btn', type: 'button', 'aria-label': 'Vorige Woche', onclick: function () { state.offset -= 1; load(); } }, '‹');
			var next = h('button', { class: 'btn', type: 'button', 'aria-label': 'Nächste Woche', disabled: state.offset >= 0, onclick: function () { state.offset += 1; load(); } }, '›');
			var rows = w.entries.map(function (e) { return [dmy(e.date), labels[e.category] || e.category, e.description || '—', fmt(e.hours, 2) + ' h']; });
			return h('section', { class: 'card' },
				h('div', { class: 'vw-weekhead' }, prev, h('div', null, h('h3', null, 'Meine Woche ' + w.label), h('span', { class: 'muted' }, dmy(w.von) + ' – ' + dmy(w.bis))), next),
				h('p', null, h('span', { class: 'tag vw-' + w.ampel }, 'Summe ' + fmt(w.hours, 2) + ' h'), ' ', h('span', { class: 'muted' }, 'Richtwert ' + fmt(d.budget, 0) + ' h')),
				rows.length ? table(['Datum', 'Kategorie', 'Tätigkeit', 'Stunden'], rows, [3]) : h('p', { class: 'muted' }, 'In dieser Woche hast du noch nichts erfasst.'));
		}

		function weeksCard(d) {
			if (!d.weeks.length) { return null; }
			var rows = d.weeks.map(function (x) {
				var diff = x.hours - d.budget;
				return [x.label, fmt(x.hours, 1) + ' h', h('span', { class: 'tag vw-' + x.ampel }, (diff > 0 ? '+' : '') + fmt(diff, 1) + ' h')];
			});
			return h('section', { class: 'card' }, h('h3', null, 'Wochensummen'), table(['Woche', 'Stunden', 'zum Richtwert'], rows, [1, 2]));
		}

		function paint() {
			var d = state.data;
			box.textContent = '';
			box.appendChild(clockCard(d));
			box.appendChild(manualCard(d));
			box.appendChild(weekCard(d));
			var wk = weeksCard(d);
			if (wk) { box.appendChild(wk); }
		}

		// Die Uhr zählt hier im Browser weiter; der Server kennt nur den Start.
		ui.every(function () {
			if (clockText && clockText.isConnected && state.data && state.data.clock && !state.data.clock.paused) {
				clockText.textContent = hms(state.base + (Date.now() - state.fetched) / 1000);
			}
		}, 1000);

		load();
	};

	/* ====================================================================
	 * 2. Löhne und Protokoll
	 * ================================================================== */

	UI.pages['loehne'] = function (item, u) {
		use(u);
		head(item, 'Löhne & Protokoll', 'Stundenlöhne mit Stichtag, erfasste Zeit und was jede Person getan hat.');

		var box = h('div', { class: 'vw' });
		ui.view().appendChild(box);
		var state = { user: 0, von: '', bis: '' };

		function query() {
			var q = [];
			if (state.von) { q.push('von=' + encodeURIComponent(state.von)); }
			if (state.bis) { q.push('bis=' + encodeURIComponent(state.bis)); }
			return q.join('&');
		}

		function periodForm(d, reload) {
			var von = input('date', d.von), bis = input('date', d.bis);
			var go = h('button', { class: 'btn', type: 'submit' }, 'Zeitraum anzeigen');
			var f = h('form', { class: 'vw-form inline' }, field('Von', von), field('Bis', bis), go);
			f.addEventListener('submit', function (e) { e.preventDefault(); state.von = von.value; state.bis = bis.value; reload(); });
			return f;
		}

		function list() {
			box.textContent = '';
			box.appendChild(loading());
			ui.api('loehne?' + query()).then(function (d) {
				state.von = d.von; state.bis = d.bis;
				box.textContent = '';
				box.appendChild(h('section', { class: 'card' },
					h('p', null, h('a', { class: 'btn primary', href: '#/lohn' }, 'Lohnabrechnungen erstellen')),
					h('p', { class: 'muted' }, 'Wer im Betrieb etwas darf, steht hier – mit Stundenlohn, erfasster Arbeitszeit im Zeitraum und dem, was zuletzt getan wurde. Rechte vergibst du unter „Benutzer & Rechte“.'),
					periodForm(d, list)));

				var rows = h('div', { class: 'rows' });
				d.people.forEach(function (p) {
					var lohn = '—';
					if (p.hours > 0) {
						lohn = eur(p.cost) + (p.hours_unpriced > 0 ? ' (' + fmt(p.hours_unpriced, 2) + ' h ohne Lohn)' : '');
					}
					rows.appendChild(h('button', { class: 'row-card vw-person', type: 'button', onclick: function () { state.user = p.id; detail(); } },
						h('div', { class: 'row-main' }, h('b', null, p.name), h('div', { class: 'muted' }, p.email + ' · ' + p.profile)),
						h('div', { class: 'row-num' }, p.rate === null ? 'kein Lohn' : fmt(p.rate, 2) + ' €/h'),
						h('div', { class: 'row-num' }, fmt(p.hours, 2) + ' h'),
						h('div', { class: 'row-num' }, lohn),
						h('div', { class: 'muted vw-small' }, p.ops + ' Vorgänge · ' + (p.last ? ui.when(p.last) : 'nie'))));
				});
				box.appendChild(rows);
				box.appendChild(compareCard(d.compare));
			}).catch(function (e) { box.textContent = ''; box.appendChild(problem(e.message)); });
		}

		function compareCard(c) {
			var card = h('section', { class: 'card' }, h('h3', null, 'Stundensatz: gerechnet gegen bezahlt'));
			if (!c.known) {
				card.appendChild(h('p', { class: 'muted' }, 'Solange keine bewerteten Stunden im Zeitraum liegen, gibt es nichts zu vergleichen. Trag Stundenlöhne ein und erfasse Zeit – dann steht hier, ob die Kalkulation noch passt.'));
				return card;
			}
			card.appendChild(h('p', null, 'Tatsächlich bezahlt wurden im Zeitraum ' + fmt(c.actual, 2) + ' €/h. Gerechnet wird mit ' + fmt(c.calculated, 2) + ' €/h. Abweichung: ' + fmt(c.diff_pct, 1) + ' %.'));
			card.appendChild(h('p', null, h('span', { class: 'tag vw-' + (c.level === 'gruen' ? 'gruen' : (c.level === 'rot' ? 'rot' : 'gelb')) }, c.level === 'gruen' ? 'Die Kalkulation passt zu den Löhnen.' : 'Der kalkulatorische Stundensatz weicht spürbar ab.')));
			card.appendChild(h('p', { class: 'muted' }, 'Der kalkulatorische Stundensatz wird bewusst nicht automatisch nachgezogen: Er steckt in jedem Preis. Ändern kannst du ihn unter Einstellungen mit einem Stichtag – dann bleiben alte Kalkulationen, wie sie waren.'));
			if (c.hours_unpriced > 0) { card.appendChild(h('p', { class: 'muted' }, 'Achtung: ' + fmt(c.hours_unpriced, 2) + ' Stunden im Zeitraum haben keinen hinterlegten Lohn und stecken in dieser Rechnung nicht drin.')); }
			return card;
		}

		function detail() {
			box.textContent = '';
			box.appendChild(loading());
			ui.api('loehne/nutzer/' + state.user + '?' + query()).then(function (d) {
				state.von = d.von; state.bis = d.bis;
				box.textContent = '';
				box.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { state.user = 0; list(); } }, '← Alle Benutzer'));
				box.appendChild(h('section', { class: 'card' }, h('h3', null, d.user.name), h('p', { class: 'muted' }, d.user.email),
					h('p', null, d.summary.total + ' protokollierte Vorgänge, vor allem: ' + (d.summary.areas || 'noch nichts'))));

				// Lohn
				var rate = input('text', '', { inputmode: 'decimal', placeholder: '€ je Stunde' });
				var from = input('date', d.today.slice(0, 8) + '01');
				var note = input('text', '', { placeholder: 'z. B. Tariferhöhung', maxlength: '120' });
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Lohn festlegen');
				var form = h('form', { class: 'vw-form' }, field('€ je Stunde', rate), field('Gilt ab', from), field('Bemerkung', note), go);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					run(go, function () { return post('loehne/lohn', { user_id: d.user.id, rate_per_h: rate.value, valid_from: from.value, note: note.value }); }, function (r) { ui.toast(r.message); detail(); });
				});
				var wcard = h('section', { class: 'card' }, h('h3', null, 'Stundenlohn'),
					h('p', { class: 'muted' }, 'Ein Lohn gilt ab seinem Stichtag und wird nicht rückwirkend geändert. Eine Erhöhung ist deshalb eine neue Zeile, keine Korrektur. Nur am selben Stichtag wird die vorhandene Zeile geändert – und protokolliert.'),
					form);
				wcard.appendChild(d.wages.length
					? table(['Gültig ab', '€ je Stunde', 'Bemerkung'], d.wages.map(function (w) { return [dmy(w.from), fmt(w.rate, 2), w.note || '—']; }), [1])
					: h('p', { class: 'muted' }, 'Für diesen Nutzer ist kein Stundenlohn hinterlegt. Seine Stunden erscheinen in den Auswertungen dann als unbewertet – nicht als kostenlos.'));
				box.appendChild(wcard);

				// Zeit
				var tcard = h('section', { class: 'card' }, h('h3', null, 'Erfasste Zeit im Zeitraum'), periodForm(d, detail));
				if (d.time.length) {
					var rows = d.time.map(function (t) { return [dmy(t.date), t.category, t.description || '—', fmt(t.hours, 2), t.cost === null ? 'kein Lohn hinterlegt' : eur(t.cost)]; });
					rows.push([h('b', null, 'Summe'), '', '', h('b', null, fmt(d.sum.hours, 2)), h('b', null, eur(d.sum.cost))]);
					tcard.appendChild(table(['Datum', 'Kategorie', 'Beschreibung', 'Stunden', 'Kosten'], rows, [3, 4]));
				} else { tcard.appendChild(h('p', { class: 'muted' }, 'In diesem Zeitraum hat dieser Nutzer keine Zeit erfasst.')); }
				box.appendChild(tcard);

				// Protokoll
				var pcard = h('section', { class: 'card' }, h('h3', null, 'Protokoll'),
					h('p', { class: 'muted' }, 'Die letzten Vorgänge dieses Nutzers aus dem hashverketteten Protokoll. Es sagt, was geschah – nicht, ob es richtig war.'));
				pcard.appendChild(d.log.length
					? table(['Zeit', 'Vorgang', 'Begründung'], d.log.map(function (l) { return [ui.when(l.time), l.text, l.reason || '—']; }))
					: h('p', { class: 'muted' }, 'Für diesen Nutzer ist nichts protokolliert.'));
				box.appendChild(pcard);
			}).catch(function (e) { box.textContent = ''; box.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { state.user = 0; list(); } }, '← Alle Benutzer')); box.appendChild(problem(e.message)); });
		}

		list();
	};

	/* ====================================================================
	 * 3. Etikettendrucker
	 * ================================================================== */

	UI.pages['etiketten'] = function (item, u) {
		use(u);
		head(item, 'Etikettendrucker', 'Raspberry-Pi-Drucker verwalten und Druckaufträge verfolgen.');

		var tab = 'drucker';
		var filter = '';
		var bar = h('div');
		var box = h('div', { class: 'vw' });
		ui.view().appendChild(bar);
		ui.view().appendChild(box);

		function tabs() {
			bar.textContent = '';
			bar.appendChild(pills([['drucker', 'Drucker'], ['auftraege', 'Aufträge']], tab, function (t) { tab = t; tabs(); show(); }));
		}

		function show() { if (tab === 'drucker') { printers(); } else { jobs(); } }

		function tokenDialog(title, r) {
			var code = h('code', { class: 'vw-token' }, r.token);
			var copy = h('button', { class: 'btn', type: 'button' }, 'Kopieren');
			copy.addEventListener('click', function () {
				var done = function () { ui.toast('Token kopiert'); };
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(r.token).then(done, function () { ui.toast('Kopieren ging nicht – bitte von Hand markieren.', true); }); }
				else { var range = document.createRange(); range.selectNodeContents(code); var s = window.getSelection(); s.removeAllRanges(); s.addRange(range); ui.toast('Token markiert – jetzt kopieren.'); }
			});
			ui.dlg(title, h('div', { class: 'vw-form' },
				h('div', { class: 'notice warn' }, 'Das Token wird nur jetzt angezeigt. Trag es jetzt in die .env des Raspberry Pi ein – danach ist es nicht mehr abrufbar, dann hilft nur „Token neu erzeugen“.'),
				code, copy));
		}

		function printers() {
			box.textContent = '';
			box.appendChild(loading());
			ui.api('etiketten').then(function (d) {
				box.textContent = '';

				var pid = input('text', '', { placeholder: 'eg_dhl', pattern: '[a-z0-9_\\-]+', autocapitalize: 'none', required: 'required' });
				var name = input('text', '', { placeholder: 'EG Etikettendrucker', required: 'required', maxlength: '80' });
				var add = h('button', { class: 'btn primary', type: 'submit' }, 'Drucker anlegen');
				var form = h('form', { class: 'vw-form' }, field('Drucker-ID', pid, 'Technischer Schlüssel, z. B. eg_dhl. Trägt der Raspberry Pi in PRINTER_ID ein.'), field('Name', name), add);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					run(add, function () { return post('etiketten/drucker', { printer_id: pid.value, name: name.value }); }, function (r) { tokenDialog('Drucker „' + r.name + '“ angelegt', r); printers(); });
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Neuen Drucker anlegen'), form));

				if (!d.printers.length) { box.appendChild(h('p', { class: 'muted' }, 'Noch kein Drucker angelegt.')); }

				d.printers.forEach(function (p) {
					var fmtSel = select(d.rolls.map(function (r) { return [r.key, r.label]; }), 'dhl');
					var test = h('button', { class: 'btn', type: 'button' }, 'Testetikett drucken');
					test.addEventListener('click', function () { run(test, function () { return post('etiketten/drucker/' + p.id + '/test', { format: fmtSel.value }); }, function (r) { ui.toast(r.message); }); });
					var regen = h('button', { class: 'btn', type: 'button' }, 'Token neu erzeugen');
					regen.addEventListener('click', function () {
						if (!window.confirm('Neues Token erzeugen? Das alte wird sofort ungültig – der Raspberry Pi muss neu eingerichtet werden.')) { return; }
						run(regen, function () { return post('etiketten/drucker/' + p.id + '/token'); }, function (r) { tokenDialog('Neues Token für „' + r.name + '“', r); printers(); });
					});
					box.appendChild(h('section', { class: 'card' },
						h('h3', null, p.name, ' ', h('code', null, p.printer_id)),
						h('p', null, h('span', { class: 'tag ' + (p.online ? 'now' : 'later') }, p.online ? 'Online' : 'Offline'), ' ',
							h('span', { class: 'muted' }, 'Letzter Kontakt: ' + (p.last_seen ? ui.ago(p.last_seen, d.now) : 'nie'))),
						h('div', { class: 'kv' },
							p.model ? [h('span', null, 'Drucker'), h('b', null, p.model)] : null,
							h('span', null, 'Warteschlange'), h('b', null, String(p.queued)),
							h('span', null, 'Token erzeugt'), h('b', null, ts(p.token_created))),
						h('div', { class: 'vw-actions' }, h('div', { class: 'label-area' }, fmtSel, test), regen)));
				});
			}).catch(function (e) { box.textContent = ''; box.appendChild(problem(e.message)); });
		}

		var STATUS = { queued: 'Wartend', claimed: 'Abgeholt', printing: 'Druckt', printed: 'Gedruckt', failed: 'Fehler', cancelled: 'Abgebrochen' };

		function jobs() {
			box.textContent = '';
			var chips = h('div', { class: 'presets' });
			[['', 'Alle'], ['queued', 'Wartend'], ['printed', 'Gedruckt'], ['failed', 'Fehler'], ['cancelled', 'Abgebrochen']].forEach(function (f) {
				chips.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': filter === f[0] ? 'true' : 'false', onclick: function () { filter = f[0]; jobs(); } }, f[1]));
			});
			box.appendChild(chips);
			var list = h('div', { class: 'rows' }, loading());
			box.appendChild(list);

			ui.api('etiketten/auftraege' + (filter ? '?status=' + filter : '')).then(function (d) {
				list.textContent = '';
				if (!d.jobs.length) { list.appendChild(h('p', { class: 'muted' }, 'Keine Druckaufträge in dieser Ansicht.')); return; }
				d.jobs.forEach(function (j) {
					var acts = h('div', { class: 'vw-actions' });
					if (j.actions.erneut) {
						var again = h('button', { class: 'btn', type: 'button' }, 'Erneut senden');
						again.addEventListener('click', function () {
							if (j.status === 'printed' && !window.confirm('Dieser Auftrag wurde schon gedruckt. Wirklich noch einmal drucken?')) { return; }
							run(again, function () { return post('etiketten/auftraege/' + j.id + '/erneut'); }, function (r) { ui.toast(r.message); jobs(); });
						});
						acts.appendChild(again);
					}
					if (j.actions.abbrechen) {
						var cancel = h('button', { class: 'btn', type: 'button' }, 'Abbrechen');
						cancel.addEventListener('click', function () {
							if (!window.confirm('Diesen wartenden Auftrag abbrechen?')) { return; }
							run(cancel, function () { return post('etiketten/auftraege/' + j.id + '/abbrechen'); }, function (r) { ui.toast(r.message); jobs(); });
						});
						acts.appendChild(cancel);
					}
					list.appendChild(h('article', { class: 'row-card vw-job' },
						h('div', { class: 'row-main' },
							h('b', null, 'Auftrag ' + j.id + (j.test ? ' (Test)' : '')),
							h('div', { class: 'muted' }, (j.order ? 'Bestellung #' + j.order + ' · ' : '') + 'Drucker ' + j.printer + ' · ' + j.tries + ' Versuche'),
							h('div', { class: 'muted vw-small' }, 'Erstellt ' + ts(j.created) + (j.printed ? ' · Gedruckt ' + ts(j.printed) : '')),
							j.error ? h('div', { class: 'vw-err' }, j.error) : null),
						h('span', { class: 'tag ' + (j.status === 'printed' ? 'now' : (j.status === 'failed' ? 'later' : '')) }, STATUS[j.status] || j.status),
						acts));
				});
				list.appendChild(h('p', { class: 'muted vw-small' }, 'Gezeigt werden die letzten 200 Einträge, neueste zuerst.'));
			}).catch(function (e) { list.textContent = ''; list.appendChild(problem(e.message)); });
		}

		tabs();
		show();
	};

	/* ====================================================================
	 * 4. Einstellungen
	 * ================================================================== */

	UI.pages['einstellungen'] = function (item, u) {
		use(u);
		head(item, 'Einstellungen', 'Kalkulationsannahmen, Staffel, Lager, Protokoll und Plugin-Einstellungen.');

		var tab = 'annahmen';
		var stichtag = '';
		var bar = h('div');
		var box = h('div', { class: 'vw' });
		ui.view().appendChild(bar);
		ui.view().appendChild(box);

		var TABS = [['annahmen', 'Kalkulation'], ['staffel', 'Rabattstaffel B2B'], ['lager', 'Farben & Lager'], ['protokoll', 'Protokoll'], ['systempruefung', 'Systemprüfung'], ['plugin', 'Plugin (Firma, DHL, …)']];

		function tabs() {
			bar.textContent = '';
			bar.appendChild(pills(TABS, tab, function (t) { tab = t; tabs(); show(); }));
		}

		function fail(e) { box.textContent = ''; box.appendChild(problem(e.message)); }

		function show() {
			box.textContent = '';
			box.appendChild(loading());
			({ annahmen: annahmen, staffel: staffel, lager: lager, protokoll: protokoll, systempruefung: systempruefung, plugin: plugin })[tab]();
		}

		function stichtagForm(d) {
			var date = input('date', d.stichtag);
			var f = h('form', { class: 'vw-form inline' }, field('Gültig zum Stichtag', date), h('button', { class: 'btn', type: 'submit' }, 'Anzeigen'));
			f.addEventListener('submit', function (e) { e.preventDefault(); stichtag = date.value; show(); });
			return f;
		}

		function annahmen() {
			ui.api('einstellungen/annahmen' + (stichtag ? '?stichtag=' + encodeURIComponent(stichtag) : '')).then(function (d) {
				box.textContent = '';
				box.appendChild(h('section', { class: 'card' },
					h('p', { class: 'muted' }, 'Werte werden nie überschrieben. Jede Änderung gilt ab einem Datum und erzeugt eine neue Zeile – so bleibt jede alte Kalkulation nachrechenbar.'),
					stichtagForm(d)));

				d.items.forEach(function (it) {
					var val = input('text', String(it.value).replace('.', ','), { inputmode: 'decimal' });
					var from = input('date', d.today);
					var note = input('text', '', { placeholder: 'Begründung', maxlength: '120' });
					var go = h('button', { class: 'btn primary', type: 'submit' }, 'Festschreiben');
					var form = h('form', { class: 'vw-form' }, field('Neuer Wert', val), field('Gilt ab', from), field('Begründung', note), go);
					form.addEventListener('submit', function (e) {
						e.preventDefault();
						run(go, function () { return post('einstellungen/annahme', { key: it.key, value: val.value, valid_from: from.value, note: note.value }); }, function (r) { ui.toast(r.message); show(); });
					});
					var hist = it.history.length
						? h('ul', { class: 'vw-hist' }, it.history.map(function (x) { return h('li', null, dmy(x.from) + ': ', h('b', null, fmt(x.value, 4)), x.note ? ' – ' + x.note : ''); }))
						: h('p', { class: 'muted vw-small' }, 'Startwert');
					box.appendChild(h('section', { class: 'card' },
						h('div', { class: 'vw-weekhead' }, h('div', null, h('h3', null, it.label), h('code', null, it.key)), h('div', { class: 'tnum' }, fmt(it.value, 4))),
						form, hist));
				});
			}).catch(fail);
		}

		function staffel() {
			ui.api('einstellungen/staffel' + (stichtag ? '?stichtag=' + encodeURIComponent(stichtag) : '')).then(function (d) {
				box.textContent = '';
				box.appendChild(h('section', { class: 'card' },
					h('h3', null, 'Standard-Rabattstaffel'),
					h('p', { class: 'muted' }, 'Gilt für alle Händler ohne Sondervereinbarung. Wer eigene Konditionen hat, bekommt sie beim jeweiligen Kontakt – die gehen dieser Staffel vor. Der Rabatt gilt auf den Bruttopreis der rabattfähigen Positionen.'),
					stichtagForm(d),
					d.tiers.length ? null : h('div', { class: 'notice warn' }, 'Zu diesem Stichtag gilt keine Staffel – Händler zahlen dann den vollen Preis.')));

				var rows = d.tiers.map(function (t) { return { name: t.name, min: String(t.min).replace('.', ','), pct: String(t.pct).replace('.', ',') }; });
				rows.push({ name: '', min: '', pct: '' }, { name: '', min: '', pct: '' });
				var fields = rows.map(function (r) {
					return { name: input('text', r.name, { 'aria-label': 'Bezeichnung', placeholder: 'Bezeichnung' }), min: input('text', r.min, { inputmode: 'decimal', 'aria-label': 'ab Bestellwert in Euro brutto', placeholder: 'ab € brutto' }), pct: input('text', r.pct, { inputmode: 'decimal', 'aria-label': 'Rabatt in Prozent', placeholder: 'Rabatt %' }) };
				});
				var from = input('date', d.today);
				var reason = input('text', '', { placeholder: 'Begründung', maxlength: '120' });
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Festschreiben');
				var form = h('form', { class: 'vw-form' },
					h('div', { class: 'vw-tiers' }, fields.map(function (f) { return h('div', { class: 'vw-tier' }, f.name, f.min, f.pct); })),
					field('Neue Staffel gilt ab', from), field('Begründung', reason), go);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					run(go, function () {
						return post('einstellungen/staffel', { valid_from: from.value, reason: reason.value, tiers: fields.map(function (f) { return { name: f.name.value, min: f.min.value, pct: f.pct.value }; }) });
					}, function (r) { ui.toast(r.message); show(); });
				});
				box.appendChild(h('section', { class: 'card' }, form,
					h('p', { class: 'muted vw-small' }, 'Festschreiben überschreibt nichts: Die bisherigen Stufen werden zum Vortag abgeschlossen und bleiben stehen.')));

				if (d.history.length) {
					box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Verlauf'),
						table(['Gültig ab', 'bis', 'Stufe', 'ab Bestellwert', 'Rabatt', 'Begründung'], d.history.map(function (t) { return [dmy(t.from), t.until ? dmy(t.until) : '—', t.name, eur(t.min), fmt(t.pct, 1) + ' %', t.note || '—']; }), [3, 4])));
				}
			}).catch(fail);
		}

		function lager() {
			ui.api('einstellungen/lager').then(function (d) {
				box.textContent = '';
				var attr = input('text', d.attribute, { autocapitalize: 'none' });
				var def = input('text', d['default']);
				var hoheit = h('input', { type: 'checkbox', checked: d.hoheit });
				var shop = h('input', { type: 'checkbox', checked: d.shop_bestand });
				var mail = h('input', { type: 'checkbox', checked: d.wochenmail });
				var layout = select([['theme', 'In das Website-Design einbetten'], ['schlicht', 'Schlichte eigene Seite mit Logo']], d.layout);
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');

				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Bekannte Farben'),
					d.exists ? null : h('div', { class: 'notice warn' }, 'Das Attribut ' + d.attribute + ' existiert in WooCommerce noch nicht. Solange bucht alles auf die Standardfarbe. Anlegen unter Produkte → Attribute; als Slug genügt „farbe“.'),
					h('p', { class: 'muted' }, 'Die Farbe kommt aus einem globalen WooCommerce-Produktattribut. Intern wird immer der Slug gespeichert, nie der Anzeigename.'),
					table(['Slug (gespeichert)', 'Anzeigename'], d.colors.map(function (c) { return [h('span', null, h('code', null, c.slug), c['default'] ? ' (Standard)' : ''), c.label]; }))));

				var form = h('form', { class: 'vw-form' },
					field('Farbattribut (Taxonomie)', attr, 'Globales Produktattribut, üblicherweise pa_farbe.'),
					field('Standardfarbe', def, 'Gilt für jede Position ohne eigene Farbe.'),
					h('label', { class: 'vw-check' }, hoheit, h('span', null, 'Lagerhoheit: Betrieb bucht die Lagerabgänge der ihm bekannten Artikel. Ist sie aus, bucht das Lager-Modul alle Abgänge und die Bestände im Betrieb stimmen nicht.')),
					h('label', { class: 'vw-check' }, shop, h('span', null, 'Bestand im Shop: Das ERP führt den Bestand und überträgt ihn (Lager minus offene Bestellungen) in die Shop-Produkte; der Lagerabzug von WooCommerce ruht für diese Produkte. Wirkt nur bei aktiver Lagerhoheit.')),
					field('Nachbestellseite', layout, 'Schlicht ist die Rückfallebene, falls das Theme mit dem Formular nicht zurechtkommt.'),
					h('label', { class: 'vw-check' }, mail, h('span', null, 'Wöchentliche Zusammenfassung per E-Mail senden')),
					go);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					run(go, function () { return post('einstellungen/lager', { attribute: attr.value, 'default': def.value, hoheit: hoheit.checked, shop_bestand: shop.checked, wochenmail: mail.checked, layout: layout.value }); }, function (r) { ui.toast(r.message); show(); });
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Lagerhoheit und Farbsystem'), form));

				var sync = h('button', { class: 'btn', type: 'button' }, 'Bestand jetzt in den Shop übertragen');
				sync.addEventListener('click', function () {
					run(sync, function () { return post('einstellungen/lager/shop-bestand', {}); }, function (r) { ui.toast(r.message); });
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Bestand im Shop'),
					h('p', { class: 'muted' }, 'Der Bestand wird nach jeder Buchung und jedem Statuswechsel einer Bestellung übertragen, zusätzlich stündlich. Dieser Knopf gleicht alle Artikel sofort ab – etwa nach der Umstellung.'),
					sync));
			}).catch(fail);
		}

		function protokoll() {
			ui.api('einstellungen/protokoll').then(function (d) {
				box.textContent = '';
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Kettenprüfung'),
					h('p', { class: 'muted' }, 'Jeder Protokolleintrag enthält den Hash seines Vorgängers. Wird ein Datensatz nachträglich geändert, bricht die Kette an dieser Stelle.'),
					d.intact ? h('div', { class: 'notice' }, 'Kette unversehrt.') : h('div', { class: 'notice warn' }, 'Erster Bruch bei Datensatz ' + d.broken.id + ': ' + d.broken.error)));
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Letzte 50 Vorgänge'),
					d.log.length ? table(['Zeit', 'Aktion', 'Tabelle', 'Datensatz', 'Nutzer', 'Begründung'], d.log.map(function (l) { return [ui.when(l.time), l.action, l.object, l.record, l.user || '—', l.reason || '—']; }))
						: h('p', { class: 'muted' }, 'Noch keine Vorgänge protokolliert.')));
			}).catch(fail);
		}

		function systempruefung() {
			ui.api('einstellungen/systempruefung').then(function (d) {
				box.textContent = '';
				var card = h('section', { class: 'card' }, h('h3', null, 'Systemprüfung'),
					h('p', { class: 'muted' }, 'Diese Prüfung sucht Widersprüche, die erst später entstehen: ein Artikel, dessen Beschaffungsweg nachträglich geändert wurde, eine Verknüpfung auf ein gelöschtes Shop-Produkt, ein Bestand unter null.'));
				if (!d.findings.length) { card.appendChild(h('div', { class: 'notice' }, 'Keine Widersprüche gefunden.')); }
				else {
					card.appendChild(h('div', { class: 'notice warn' }, d.errors + ' Fehler, ' + d.warnings + ' Warnungen. Ein Fehler blockiert einen Vorgang; eine Warnung ist etwas, das niemand mehr braucht.'));
					card.appendChild(table(['Schwere', 'Bereich', 'Befund', 'Was zu tun ist'], d.findings.map(function (f) { return [h('span', { class: 'tag vw-' + (f.level === 'fehler' ? 'rot' : 'gelb') }, f.level === 'fehler' ? 'Fehler' : 'Warnung'), f.area, f.text, f.fix]; })));
				}
				box.appendChild(card);
			}).catch(fail);
		}

		function plugin() {
			ui.api('einstellungen/plugin').then(function (d) {
				box.textContent = '';
				if (!d.allowed) { box.appendChild(h('div', { class: 'notice warn' }, 'Für diesen Bereich (Firma, DHL-Zugänge, E-Mail-Vorlagen) fehlt dir die Berechtigung.')); return; }

				box.appendChild(h('div', { class: 'notice' }, 'Jede Gruppe speicherst du für sich. Gespeicherte Passwörter und Schlüssel werden nie angezeigt – ein leeres Feld lässt sie unverändert, zum Ändern trägst du neu ein. Das Logo wählst du weiterhin in der klassischen Ansicht.'));

				d.groups.forEach(function (g, gi) {
					var inputs = {};
					var form = h('form', { class: 'vw-form' });
					g.fields.forEach(function (f) {
						var el;
						if (f.media) { form.appendChild(h('p', { class: 'muted' }, f.label + ': in der klassischen Ansicht änderbar.')); return; }
						if (f.type === 'checkbox') {
							el = h('input', { type: 'checkbox', checked: Number(f.value) === 1 });
							inputs[f.key] = { el: el, check: true };
							form.appendChild(h('label', { class: 'vw-check' }, el, h('span', null, f.label)));
							return;
						}
						if (f.type === 'password') { el = h('input', { class: 'field', type: 'password', value: '', autocomplete: 'new-password', placeholder: f.set ? '— gespeichert, zum Ändern neu eingeben —' : '' }); }
						else if (f.type === 'textarea') { el = h('textarea', { class: 'field vw-area', rows: '3' }); el.value = f.value === null ? '' : String(f.value); }
						else if (f.type === 'color') { el = h('input', { class: 'field vw-color', type: 'color', value: f.value || '#1d2327' }); }
						else { el = h('input', { class: 'field', type: 'text', value: f.value === null || f.value === undefined ? '' : String(f.value), inputmode: f.type === 'number' ? 'decimal' : null }); }
						inputs[f.key] = { el: el };
						form.appendChild(field(f.label, el));
					});
					var go = h('button', { class: 'btn primary', type: 'submit' }, 'Gruppe speichern');
					form.appendChild(go);
					form.addEventListener('submit', function (e) {
						e.preventDefault();
						var values = {};
						Object.keys(inputs).forEach(function (k) { values[k] = inputs[k].check ? inputs[k].el.checked : inputs[k].el.value; });
						run(go, function () { return post('einstellungen/plugin', { group: g.id, values: values }); }, function (r) { ui.toast(r.message); });
					});
					box.appendChild(h('details', { class: 'card vw-group', open: gi === 0 }, h('summary', null, g.title), form));
				});

				// E-Mail-Vorlagen
				var tpl = {};
				var tform = h('form', { class: 'vw-form' }, h('p', { class: 'muted' }, 'Betreff und Text jeder automatischen E-Mail anpassen. Leer lassen = Standardtext verwenden.'));
				d.templates.forEach(function (t) {
					var s = h('input', { class: 'field', type: 'text', value: t.subject, placeholder: t.subject_hint });
					var x = h('textarea', { class: 'field vw-area', rows: '6', placeholder: t.text_hint }); x.value = t.text;
					tpl['mail_' + t.key + '_betreff'] = s; tpl['mail_' + t.key + '_text'] = x;
					tform.appendChild(h('details', { class: 'vw-sub' }, h('summary', null, t.label + (t.subject || t.text ? ' (angepasst)' : '')),
						h('p', { class: 'muted vw-small' }, 'Platzhalter: ' + t.placeholders), field('Betreff', s), field('Text', x)));
				});
				var tgo = h('button', { class: 'btn primary', type: 'submit' }, 'E-Mail-Vorlagen speichern');
				tform.appendChild(tgo);
				tform.addEventListener('submit', function (e) {
					e.preventDefault();
					var values = {};
					Object.keys(tpl).forEach(function (k) { values[k] = tpl[k].value; });
					run(tgo, function () { return post('einstellungen/plugin', { group: 'mail', values: values }); }, function (r) { ui.toast(r.message); });
				});
				box.appendChild(h('details', { class: 'card vw-group' }, h('summary', null, 'E-Mail-Vorlagen'), tform));
			}).catch(fail);
		}

		tabs();
		show();
	};
})();
