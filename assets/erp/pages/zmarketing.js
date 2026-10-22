/*
 * Marketing im ERP: Kampagnen ansehen UND bearbeiten.
 *
 * Die Datei heißt zmarketing.js, damit sie nach verkauf.js geladen wird und die
 * nur lesende Fassung von UI.pages['marketing'] / UI.routes['kampagne'] ersetzt.
 * Lesen: erp/marketing (Verkauf.php). Schreiben: includes/Erp/Pages/Marketing.php.
 * Alle Texte aus dem Server gelangen über textContent bzw. h() in die Seite, nie als HTML-Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var AMPEL = { im_rahmen: 'now', knapp: '', ueberzogen: 'later' };
	var opts = null;

	function ymd(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
	}

	function pct(n) {
		return (Math.round(Number(n) * 10) / 10).toString().replace('.', ',') + ' %';
	}

	function decimal(n) {
		return String(Math.round(Number(n) * 100) / 100).replace('.', ',');
	}

	function errBox(ui, box, e) {
		box.textContent = '';
		box.appendChild(ui.h('div', { class: 'notice warn' }, e.message));
	}

	function loading(ui) {
		return ui.h('div', { class: 'card' }, 'Wird geladen …');
	}

	function tile(ui, lab, val, note) {
		return ui.h('div', { class: 'tile' }, ui.h('div', { class: 'lab' }, lab), ui.h('div', { class: 'val' }, val), note ? ui.h('div', { class: 'note' }, note) : null);
	}

	function kv(ui, label, value) {
		return ui.h('div', { class: 'mk-kv' }, ui.h('span', { class: 'muted' }, label), ui.h('span', null, value || '–'));
	}

	function options(ui) {
		if (opts) { return Promise.resolve(opts); }
		return ui.api('marketing/optionen').then(function (o) { opts = o; return o; });
	}

	// Beschriftetes Eingabefeld.
	function field(ui, label, el, hint) {
		return ui.h('label', { class: 'mk-field' }, ui.h('span', null, label), el, hint ? ui.h('small', { class: 'muted' }, hint) : null);
	}

	function input(ui, type, value, extra) {
		var a = { class: 'field', type: type, value: value === null || value === undefined ? '' : String(value) };
		Object.keys(extra || {}).forEach(function (k) { a[k] = extra[k]; });
		return ui.h('input', a);
	}

	function select(ui, list, current) {
		return ui.h('select', { class: 'field' }, list.map(function (o) {
			return ui.h('option', { value: o.value, selected: o.value === current }, o.label);
		}));
	}

	// Aktion mit Wartezustand: sperrt die Taste, zeigt Fehler im Dialog bzw. als Hinweis.
	function run(ui, btn, label, fn, errTo) {
		btn.disabled = true;
		btn.textContent = 'Bitte warten …';
		return fn().then(function (r) {
			btn.disabled = false;
			btn.textContent = label;
			return r;
		}, function (e) {
			if (errTo) {
				errTo.textContent = e.message;
				errTo.hidden = false;
			} else {
				ui.toast(e.message, true);
			}
			btn.disabled = false;
			btn.textContent = label;
			throw e;
		});
	}

	function confirmDlg(ui, title, text, okLabel, onOk) {
		var h = ui.h;
		var d;
		var err = h('div', { class: 'notice warn', hidden: true });
		var ok = h('button', { class: 'btn primary', type: 'button' }, okLabel);
		ok.addEventListener('click', function () {
			run(ui, ok, okLabel, onOk, err).then(function () { d.close(); }).catch(function () {});
		});
		d = ui.dlg(title, h('div', { class: 'mk-dlg' }, h('p', null, text), err,
			h('div', { class: 'mk-actions' }, ok, h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen'))));
	}

	/* ---------- Formular: Kampagne anlegen / ändern ---------- */

	function campaignDialog(ui, existing, onSaved) {
		var h = ui.h;
		options(ui).then(function (o) {
			var f = existing ? existing.fields : { name: '', typ: 'event', kanal: '', start_datum: o.today, ende_datum: '', event_datum: '', budget_geplant: 0, utm_campaign: '', gutschein_code: '', status: 'geplant', notiz: '' };
			var name = input(ui, 'text', f.name, { required: 'required', maxlength: 190, placeholder: 'z. B. Weihnachtsmarkt Altstadt' });
			var typ = select(ui, o.types, f.typ);
			var kanal = input(ui, 'text', f.kanal, { placeholder: 'z. B. Google Ads, Wochenmarkt, Flyer' });
			var start = input(ui, 'date', f.start_datum);
			var ende = input(ui, 'date', f.ende_datum);
			var event = input(ui, 'date', f.event_datum);
			var budget = input(ui, 'text', f.budget_geplant ? decimal(f.budget_geplant) : '', { inputmode: 'decimal', placeholder: '0,00' });
			var utm = input(ui, 'text', f.utm_campaign);
			var code = input(ui, 'text', f.gutschein_code);
			var status = select(ui, o.status, f.status);
			var notiz = h('textarea', { class: 'field', rows: '3' });
			notiz.value = f.notiz || '';
			var err = h('div', { class: 'notice warn', hidden: true });
			var label = existing ? 'Kampagne speichern' : 'Kampagne anlegen';
			var save = h('button', { class: 'btn primary', type: 'submit' }, label);
			var d;

			var tpl = null;
			if (!existing && o.templates && o.templates.length) {
				tpl = h('select', { class: 'field' }, h('option', { value: '' }, '– ohne Vorlage –'));
				o.templates.forEach(function (t) { tpl.appendChild(h('option', { value: t.key }, t.label)); });
				tpl.addEventListener('change', function () {
					o.templates.forEach(function (t) {
						if (t.key !== tpl.value) { return; }
						typ.value = t.fields.typ;
						kanal.value = t.fields.kanal;
						notiz.value = t.fields.notiz;
					});
				});
			}

			var form = h('form', { class: 'mk-form', novalidate: 'novalidate' },
				existing ? h('p', { class: 'muted', style: 'margin:0' }, 'Kostenstelle ' + existing.kst + ' (bleibt unverändert)') : null,
				tpl ? field(ui, 'Vorlage', tpl, 'Füllt Art, Kanal und Notiz vor. Alles lässt sich danach ändern. Eine bestehende Kampagne kopiert „Duplizieren“ in ihrer Ansicht.') : null,
				field(ui, 'Name', name),
				h('div', { class: 'mk-two' }, field(ui, 'Art', typ), field(ui, 'Status', status)),
				field(ui, 'Kanal', kanal),
				h('div', { class: 'mk-two' }, field(ui, 'Beginn', start), field(ui, 'Ende', ende)),
				field(ui, 'Veranstaltungstag', event, 'Nur bei Events. Die Auswertung ordnet die Kampagne diesem Tag zu – auch wenn die Standgebühr steuerlich schon im Vorjahr abgeflossen ist.'),
				field(ui, 'Geplantes Budget (€)', budget),
				field(ui, 'Kampagnenkennung (utm_campaign)', utm, 'WooCommerce merkt sich diese Kennung bei jeder Bestellung. Genau der Wert, der in der Anzeigen-URL als utm_campaign steht.'),
				field(ui, 'Gutscheincode', code, 'Ein Code, den es nur bei dieser Kampagne gab – die einzige belastbare Brücke von einem Flyer zu einer späteren Shop-Bestellung.'),
				field(ui, 'Notiz', notiz),
				err,
				h('div', { class: 'mk-actions' }, save, h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen')));

			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				err.hidden = true;
				if (!name.value.trim()) {
					err.textContent = 'Die Kampagne braucht einen Namen.';
					err.hidden = false;
					name.focus();
					return;
				}
				run(ui, save, label, function () {
					return ui.api('marketing/speichern', { method: 'POST', body: {
						id: existing ? existing.id : 0, name: name.value, typ: typ.value, kanal: kanal.value,
						start_datum: start.value, ende_datum: ende.value, event_datum: event.value,
						budget_geplant: budget.value, utm_campaign: utm.value, gutschein_code: code.value,
						status: status.value, notiz: notiz.value
					} });
				}, err).then(function (r) {
					d.close();
					ui.toast(r.message);
					onSaved(r.id);
				}).catch(function () {});
			});

			d = ui.dlg(existing ? 'Kampagne ändern' : 'Neue Kampagne', form, true);
		}).catch(function (e) { ui.toast(e.message, true); });
	}

	/* ---------- Liste ---------- */

	UI.pages['marketing'] = function (item, ui) {
		var h = ui.h;
		var filter = 'offen';
		var data = null;
		var canEdit = ui.can('verkauf.marketing');

		ui.pageHead('Marketing', 'Kampagnen mit Budget, Kosten und Umsatz.',
			h('div', { class: 'mk-actions' },
				canEdit ? h('button', { class: 'btn primary', type: 'button', onclick: function () {
					campaignDialog(ui, null, function (id) { location.hash = '#/kampagne/' + id; });
				} }, 'Neue Kampagne') : null,
				ui.classicBtn(item)));

		var chips = h('div', { class: 'presets' });
		var list = h('div');
		var box = h('div');
		box.appendChild(chips);
		box.appendChild(list);
		ui.view().appendChild(box);
		list.appendChild(loading(ui));

		function drawChips() {
			chips.textContent = '';
			[['offen', 'Laufend und geplant'], ['alle', 'Alle'], ['abgeschlossen', 'Abgeschlossen']].forEach(function (c) {
				chips.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': filter === c[0] ? 'true' : 'false', onclick: function () { filter = c[0]; drawChips(); draw(); } }, c[1]));
			});
		}

		function draw() {
			list.textContent = '';
			if (!data.campaigns.length) {
				list.appendChild(h('div', { class: 'notice' }, 'Noch keine Kampagne angelegt. Ein Marktbesuch, eine Anzeigenschaltung oder eine Flyeraktion ist jeweils eine Kampagne – sie bekommt eine Kostenstelle, auf die alle zugehörigen Belege gebucht werden.'));
				return;
			}
			var rows = data.campaigns.filter(function (k) {
				return filter === 'alle' || (filter === 'abgeschlossen' ? k.status === 'abgeschlossen' : k.status !== 'abgeschlossen');
			});
			if (!rows.length) {
				list.appendChild(h('p', { class: 'muted' }, 'In dieser Auswahl ist keine Kampagne.'));
			}
			list.appendChild(h('div', { class: 'rows' }, rows.map(function (k) {
				return h('a', { class: 'row-card mk-row', href: '#/kampagne/' + k.id },
					h('div', { class: 'row-main' }, h('b', null, k.name), h('small', null, [k.kst, k.type, k.period].filter(Boolean).join(' · '))),
					h('div', { class: 'row-num' }, ui.money(k.costs, 'EUR')),
					h('div', { class: 'row-tags' },
						h('span', { class: 'tag' }, k.status),
						k.ampel === 'kein_budget'
							? h('span', { class: 'tag' }, 'kein Budget gesetzt')
							: h('span', { class: 'tag ' + (AMPEL[k.ampel] || '') }, pct(k.usage) + ' von ' + ui.money(k.budget, 'EUR'))));
			})));
			list.appendChild(h('p', { class: 'muted' }, 'Die Summe zeigt die gebuchten Kosten aus Belegen. Gewährte Rabatte sind keine Betriebsausgabe und stehen deshalb nicht darin – den vollständigen Einsatz zeigt die Einzelansicht.'));
		}

		ui.api('marketing').then(function (d) {
			data = d;
			drawChips();
			draw();
		}).catch(function (e) { errBox(ui, list, e); });
	};

	/* ---------- Kampagne duplizieren ---------- */

	function duplicateDialog(ui, k) {
		var h = ui.h;
		var name = input(ui, 'text', 'Kopie von ' + k.name, { maxlength: 190 });
		var err = h('div', { class: 'notice warn', hidden: true });
		var label = 'Kopie anlegen';
		var save = h('button', { class: 'btn primary', type: 'submit' }, label);
		var d;
		var form = h('form', { class: 'mk-form', novalidate: 'novalidate' },
			h('p', { class: 'muted', style: 'margin:0' }, 'Art, Kanal, Budget und Notiz werden übernommen. Beginn ist heute. Termine, Belege, Kilometer, Gutscheincode und Kampagnenkennung werden bewusst nicht übernommen – sonst würden Bestellungen in zwei Kampagnen doppelt zählen.'),
			field(ui, 'Name der Kopie', name),
			err,
			h('div', { class: 'mk-actions' }, save, h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen')));
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.hidden = true;
			run(ui, save, label, function () {
				return ui.api('marketing/' + k.id + '/duplizieren', { method: 'POST', body: { name: name.value } });
			}, err).then(function (r) {
				d.close();
				ui.toast(r.message);
				location.hash = '#/kampagne/' + r.id;
			}).catch(function () {});
		});
		d = ui.dlg('Kampagne duplizieren', form, true);
	}

	/* ---------- Einzelansicht ---------- */

	var STEPS = [['geplant', 'Geplant'], ['aktiv', 'Aktiv'], ['abgeschlossen', 'Abgeschlossen']];

	// Sprung zum Beleg: die Buchhaltung öffnet ihn, wenn sie UI.belegOpen anbietet; sonst
	// geht es zur Buchhaltung, und UI.buchFocus merkt sich den gewünschten Beleg.
	function belegLinks(ui, b) {
		var h = ui.h;
		var out = [];
		out.push(h('a', { class: 'btn mk-small', href: '#/seite/buchhaltung', onclick: function (ev) {
			UI.buchFocus = { tab: 'belege', beleg: b.id };
			if (typeof UI.belegOpen === 'function') {
				ev.preventDefault();
				UI.belegOpen(b.id);
			}
		} }, 'Beleg öffnen'));
		if (b.file) {
			out.push(h('a', { class: 'btn mk-small', href: b.file, target: '_blank', rel: 'noopener' }, 'Foto'));
		}
		return out;
	}

	UI.routes['kampagne'] = function (r, ui) {
		var h = ui.h;
		var id = parseInt(r.id, 10) || 0;
		var canEdit = ui.can('verkauf.marketing');
		ui.section('marketing');
		ui.setHead('Kampagne', 'Verkauf');
		var box = h('div');
		ui.view().appendChild(h('a', { class: 'mk-back', href: '#/seite/marketing' }, '‹ Alle Kampagnen'));
		ui.view().appendChild(box);
		box.appendChild(loading(ui));

		function load() {
			Promise.all([ui.api('marketing/' + id + '/auswertung'), ui.api('marketing/' + id + '/stamm'), options(ui)]).then(function (res) {
				draw(res[0], res[1], res[2]);
			}).catch(function (e) { errBox(ui, box, e); });
		}

		function after(res) {
			ui.toast(res.message);
			load();
		}

		function money(v) { return ui.money(v, 'EUR'); }

		function ratio(n) { return n === null || n === undefined ? '–' : decimal(n); }

		function steps(k) {
			var idx = 0;
			STEPS.forEach(function (s, i) { if (s[0] === k.status) { idx = i; } });
			return h('ol', { class: 'mk-steps', 'aria-label': 'Ablauf der Kampagne' }, STEPS.map(function (s, i) {
				return h('li', { class: i < idx ? 'done' : (i === idx ? 'now' : ''), 'aria-current': i === idx ? 'step' : null }, s[1]);
			}));
		}

		function statusButton(k) {
			var next = k.next;
			if (!next) { return null; }
			var warns = k.hints.filter(function (x) { return x.level === 'warn'; });
			return h('button', { class: 'btn' + (next.value === 'aktiv' ? ' primary' : ''), type: 'button', onclick: function () {
				var text = next.value === 'abgeschlossen'
					? 'Die Kampagne „' + k.name + '“ wird abgeschlossen. Belege, Fahrtkosten und Auswertung bleiben unverändert; über „Wieder öffnen“ geht sie wieder auf.'
					: (k.status === 'abgeschlossen' ? 'Die Kampagne „' + k.name + '“ wird wieder geöffnet.' : 'Die Kampagne „' + k.name + '“ wird auf „aktiv“ gesetzt.');
				var inner = [h('p', null, text)];
				if (next.value === 'abgeschlossen' && warns.length) {
					inner.push(h('p', { class: 'muted', style: 'margin:0' }, 'Vorher noch beachten:'));
					warns.forEach(function (x) { inner.push(h('div', { class: 'notice warn' }, x.text)); });
				}
				statusDlg(next, inner);
			} }, next.label);
		}

		function statusDlg(next, inner) {
			var d;
			var err = h('div', { class: 'notice warn', hidden: true });
			var ok = h('button', { class: 'btn primary', type: 'button' }, next.label);
			ok.addEventListener('click', function () {
				run(ui, ok, next.label, function () {
					return ui.api('marketing/' + id + '/status', { method: 'POST', body: { status: next.value } }).then(after);
				}, err).then(function () { d.close(); }).catch(function () {});
			});
			d = ui.dlg(next.label, h('div', { class: 'mk-dlg' }, inner, err,
				h('div', { class: 'mk-actions' }, ok, h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen'))));
		}

		function why(text) {
			return h('details', { class: 'mk-why' }, h('summary', null, 'Woher kommt das?'), h('p', null, text));
		}

		function numRow(label, value, explain, strong) {
			return h('div', { class: 'mk-num' },
				h('div', { class: 'mk-num-line' }, h('span', null, label), h(strong ? 'b' : 'span', { class: 'mk-num-val' }, value)),
				explain ? why(explain) : null);
		}

		function meter(b) {
			if (b.ampel === 'kein_budget') {
				return h('p', { class: 'muted' }, 'Kein Budget gesetzt – gebuchte Kosten bisher: ' + money(b.used) + '. Unter „Ändern“ lässt sich ein Budget eintragen, dann erscheint hier die Ampel.');
			}
			var w = Math.max(2, Math.min(100, b.usage));
			return h('div', null,
				h('div', { class: 'mk-meter amp-' + b.ampel, role: 'img', 'aria-label': 'Budget ' + pct(b.usage) + ' genutzt, ' + b.label },
					h('i', { style: 'width:' + w + '%' })),
				h('div', { class: 'mk-meter-txt' },
					h('span', { class: 'mk-amp amp-' + b.ampel }, b.label),
					h('span', null, money(b.used) + ' von ' + money(b.plan) + ' (' + pct(b.usage) + ')')),
				h('p', { class: 'muted', style: 'margin:6px 0 0' }, b.rest < 0 ? money(-b.rest) + ' über dem Budget.' : money(b.rest) + ' Budget sind noch frei.'));
		}

		function draw(k, m, o) {
			box.textContent = '';
			ui.setHead(k.name, 'Marketing');
			var kmRow = null;
			k.costs.rows.forEach(function (b) { if (b.is_km) { kmRow = b; } });

			box.appendChild(h('div', { class: 'page-head' },
				h('div', null, h('h2', null, k.kst + ' – ' + k.name), h('p', { class: 'muted', style: 'margin:4px 0 0' }, [k.type, k.channel, k.period].filter(Boolean).join(' · '))),
				canEdit ? h('div', { class: 'mk-actions' },
					statusButton(k),
					h('button', { class: 'btn', type: 'button', onclick: function () { campaignDialog(ui, m, function () { load(); }); } }, 'Ändern'),
					h('button', { class: 'btn', type: 'button', onclick: function () { duplicateDialog(ui, k); } }, 'Duplizieren')) : null));

			box.appendChild(steps(k));
			k.hints.forEach(function (x) {
				box.appendChild(h('div', { class: 'notice' + (x.level === 'warn' ? ' warn' : ''), style: 'margin-top:10px' }, x.text));
			});

			box.appendChild(h('div', { class: 'grid tiles mk-gap' },
				tile(ui, 'Einsatz gesamt', money(k.effort), k.rebate > 0 ? 'inkl. ' + money(k.rebate) + ' Rabatt' : 'gebuchte Kosten'),
				tile(ui, 'Umsatz gesamt', money(k.revenue.sum), 'Markt + Shop (nachgewiesen)'),
				tile(ui, 'Ergebnis', money(k.result), 'Umsatz − Einsatz'),
				tile(ui, 'Umsatz je Euro Einsatz', ratio(k.roas), k.roas === null ? 'noch kein Einsatz' : null),
				tile(ui, 'Einsatz je Bestellung', k.cpo === null ? '–' : money(k.cpo), k.revenue.shop.count ? k.revenue.shop.count + ' Shop-Bestellungen' : 'keine Shop-Bestellung zugeordnet')));

			box.appendChild(h('div', { class: 'card mk-gap' }, h('h3', null, 'Budget'), meter(k.budget), why(k.explain.budget)));

			var shop = k.revenue.shop;
			var shopTxt = money(shop.sum) + (shop.count ? ' · ' + shop.count + ' Bestellungen (' + shop.utm + ' über Kampagnenkennung, ' + shop.voucher + ' über Gutschein)' : '');
			box.appendChild(h('div', { class: 'card mk-gap' }, h('h3', null, 'Zahlen im Einzelnen – und woher sie kommen'),
				numRow('Kosten aus Belegen', money(k.costs.sum), k.explain.kosten),
				numRow('  davon Fahrtkosten', money(k.costs.km.booked ? k.costs.km.amount : 0), k.explain.fahrt),
				numRow('Rabatt (ohne Beleg)', money(k.rebate), k.explain.rabatt),
				numRow('Einsatz gesamt', money(k.effort), k.explain.einsatz, true),
				numRow('Umsatz Marktkasse', money(k.revenue.market.sum) + (k.revenue.market.days.length ? ' · ' + k.revenue.market.days.length + ' Kassentag(e)' : ''), k.explain.markt),
				numRow('Umsatz Shop (nachgewiesen)', shopTxt, k.explain.shop),
				numRow('Umsatz gesamt', money(k.revenue.sum), k.explain.umsatz, true),
				numRow('Ergebnis', money(k.result), k.explain.ergebnis, true),
				numRow('Umsatz je Euro Einsatz', ratio(k.roas), k.explain.roas),
				numRow('Einsatz je Bestellung', k.cpo === null ? '–' : money(k.cpo), k.explain.cpo),
				numRow('Nicht zuordenbar', 'nicht gerechnet', k.explain.offen),
				h('p', { class: 'muted', style: 'margin-bottom:0' }, 'Umsatz ausgewertet von ' + ymd(k.from) + ' bis ' + ymd(k.to) + ' (inklusive Nachlauffenster).')));

			box.appendChild(costCard(k, m));
			box.appendChild(revenueCard(k));
			if (canEdit) {
				box.appendChild(captureCard());
				box.appendChild(kmCard(m, o));
				box.appendChild(assignCard(m));
			}
		}

		function costCard(k, m) {
			var card = h('div', { class: 'card mk-gap' }, h('h3', null, 'Kosten dieser Kampagne'));
			if (!k.costs.rows.length) {
				card.appendChild(h('p', { class: 'muted' }, 'Noch kein Beleg zugeordnet. Unten lässt sich ein vorhandener Beleg zuordnen oder ein neuer direkt für diese Kampagne erfassen.'));
				return card;
			}
			card.appendChild(h('div', { class: 'rows' }, k.costs.rows.map(function (b) {
				return h('div', { class: 'row-card mk-beleg' },
					h('div', { class: 'row-main' }, h('b', null, b.number + ' · ' + (b.vendor || 'ohne Händler')),
						h('small', null, [ymd(b.date), b.account, money(b.amount) + ' Beleg', pct(b.share) + ' Anteil'].filter(Boolean).join(' · '))),
					h('div', { class: 'row-num' }, money(b.part)),
					h('div', { class: 'row-tags mk-beleg-actions' },
						b.is_km ? h('span', { class: 'tag' }, 'Fahrtkosten') : null,
						!b.counts ? h('span', { class: 'tag later' }, 'zählt nicht als Kosten') : null,
						belegLinks(ui, b),
						canEdit && !b.is_km ? h('button', { class: 'btn mk-small', type: 'button', onclick: function () {
							confirmDlg(ui, 'Zuordnung lösen', 'Beleg ' + b.number + ' wird von dieser Kampagne gelöst. Der Beleg selbst bleibt in der Buchhaltung unverändert.', 'Zuordnung lösen', function () {
								return ui.api('marketing/' + id + '/loesen', { method: 'POST', body: { beleg_id: b.id } }).then(after);
							});
						} }, 'lösen') : null));
			})));
			if (k.costs.accounts.length) {
				card.appendChild(h('h4', { class: 'mk-sub' }, 'Nach Kostenart'));
				k.costs.accounts.forEach(function (a) { card.appendChild(kv(ui, a.account, money(a.sum))); });
			}
			return card;
		}

		function revenueCard(k) {
			var card = h('div', { class: 'card mk-gap' }, h('h3', null, 'Umsatz dieser Kampagne'));
			var days = k.revenue.market.days;
			card.appendChild(h('h4', { class: 'mk-sub' }, 'Marktkasse'));
			if (days.length) {
				days.forEach(function (t) { card.appendChild(kv(ui, ymd(t.date) + (t.name ? ' · ' + t.name : ''), money(t.sum))); });
			} else {
				card.appendChild(h('p', { class: 'muted' }, k.explain.markt));
			}
			var s = k.revenue.shop;
			card.appendChild(h('h4', { class: 'mk-sub' }, 'Shop'));
			card.appendChild(kv(ui, 'Kampagnenkennung', k.utm || 'nicht hinterlegt'));
			card.appendChild(kv(ui, 'Gutscheincode', k.code || 'nicht hinterlegt'));
			card.appendChild(kv(ui, 'Bestellungen', s.count ? s.count + ' (' + s.utm + ' über Kennung, ' + s.voucher + ' über Gutschein)' : 'keine zugeordnet'));
			card.appendChild(kv(ui, 'Umsatz', money(s.sum)));
			card.appendChild(h('p', { class: 'muted' }, k.explain.shop));
			return card;
		}

		function captureCard() {
			var card = h('div', { class: 'card mk-gap' }, h('h3', null, 'Beleg zur Kampagne erfassen'));
			if (typeof UI.belegCapture !== 'function') {
				card.appendChild(h('div', { class: 'notice' }, 'Beleg-Erfassung folgt in der Buchhaltung. Bis dahin lässt sich ein vorhandener Beleg unten zuordnen.'));
				return card;
			}
			function open(mode) {
				UI.belegCapture({ mode: mode, campaignId: id, onSaved: function () { ui.toast('Beleg gespeichert und der Kampagne zugeordnet.'); load(); } });
			}
			card.appendChild(h('p', { class: 'muted', style: 'margin-top:0' }, 'Quittung fotografieren oder Datei wählen. Die Kampagne ist schon eingetragen, der Beleg wird beim Speichern zugeordnet.'));
			card.appendChild(h('div', { class: 'mk-actions' },
				h('button', { class: 'btn primary', type: 'button', onclick: function () { open('camera'); } }, 'Foto aufnehmen'),
				h('button', { class: 'btn', type: 'button', onclick: function () { open('upload'); } }, 'Datei hochladen'),
				h('button', { class: 'btn', type: 'button', onclick: function () { open('choose'); } }, 'Beleg von Hand erfassen')));
			return card;
		}

		function kmCard(m, o) {
			var km = input(ui, 'text', m.km.km ? decimal(m.km.km) : '', { inputmode: 'decimal', placeholder: '0' });
			var err = h('div', { class: 'notice warn', hidden: true });
			var label = 'Fahrtkosten buchen';
			var btn = h('button', { class: 'btn primary', type: 'button' }, label);
			btn.addEventListener('click', function () {
				err.hidden = true;
				run(ui, btn, label, function () {
					return ui.api('marketing/' + id + '/km', { method: 'POST', body: { gefahrene_km: km.value } });
				}, err).then(after).catch(function () {});
			});
			return h('div', { class: 'card mk-gap' }, h('h3', null, 'Fahrtkosten'),
				field(ui, 'Gefahrene Kilometer (Hin- und Rückweg)', km),
				h('div', { class: 'mk-actions' }, btn),
				err,
				h('p', { class: 'muted' }, 'Pauschale ' + decimal(o.km_rate) + ' € je km (in den Einstellungen änderbar)' + (m.km.booked ? ' – gebucht sind derzeit ' + money(m.km.amount) : '') + ' als Eigenbeleg auf Konto ' + o.km_account + '. Beim Ändern wird der bisherige Beleg storniert und neu gebucht; mit 0 wird die Fahrt zurückgenommen.'));
		}

		function assignCard(m) {
			var sel = h('select', { class: 'field' }, h('option', { value: '' }, '– Beleg wählen –'));
			m.pickable.forEach(function (b) {
				sel.appendChild(h('option', { value: String(b.id) }, [b.number, ymd(b.date), b.vendor, money(b.amount) + ' (' + Math.round(b.free) + ' % frei)'].join(' · ')));
			});
			var share = input(ui, 'text', '100', { inputmode: 'decimal' });
			var err = h('div', { class: 'notice warn', hidden: true });
			var label = 'Zuordnen';
			var btn = h('button', { class: 'btn primary', type: 'button' }, label);
			btn.addEventListener('click', function () {
				err.hidden = true;
				if (!sel.value) {
					err.textContent = 'Kein Beleg gewählt.';
					err.hidden = false;
					return;
				}
				run(ui, btn, label, function () {
					return ui.api('marketing/' + id + '/zuordnen', { method: 'POST', body: { beleg_id: parseInt(sel.value, 10), anteil: share.value } });
				}, err).then(after).catch(function () {});
			});
			return h('div', { class: 'card mk-gap' }, h('h3', null, 'Vorhandenen Beleg zuordnen'),
				m.pickable.length ? null : h('p', { class: 'muted' }, 'Keine Belege mit freiem Anteil unter den letzten 100.'),
				field(ui, 'Beleg', sel),
				field(ui, 'Anteil (%)', share),
				h('div', { class: 'mk-actions' }, btn),
				err,
				h('p', { class: 'muted' }, 'Ein Beleg lässt sich auf mehrere Kampagnen aufteilen – etwa eine Flyerrechnung für zwei Märkte. Über alle Kampagnen zusammen sind nie mehr als 100 % möglich. Belege, die nach Werbung aussehen und noch keiner Kampagne gehören, stehen auch in der Buchhaltung unter „Marketing-Kosten“.'));
		}

		load();
	};
})();
