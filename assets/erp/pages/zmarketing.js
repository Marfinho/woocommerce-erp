/*
 * Marketing im ERP: Kampagnen ansehen UND bearbeiten.
 *
 * Die Datei heißt zmarketing.js, damit sie nach verkauf.js geladen wird und die
 * nur lesende Fassung von UI.pages['marketing'] / UI.routes['kampagne'] ersetzt.
 * Lesen: erp/marketing (Verkauf.php). Schreiben: includes/Erp/Pages/Marketing.php.
 * Alle Texte aus dem Server gelangen über textContent bzw. h() in die Seite, nie über innerHTML.
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

			var form = h('form', { class: 'mk-form', novalidate: 'novalidate' },
				existing ? h('p', { class: 'muted', style: 'margin:0' }, 'Kostenstelle ' + existing.kst + ' (bleibt unverändert)') : null,
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

	/* ---------- Einzelansicht ---------- */

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
			Promise.all([ui.api('marketing/' + id), ui.api('marketing/' + id + '/stamm'), options(ui)]).then(function (res) {
				draw(res[0], res[1], res[2]);
			}).catch(function (e) { errBox(ui, box, e); });
		}

		function after(res) {
			ui.toast(res.message);
			load();
		}

		function draw(k, m, o) {
			box.textContent = '';
			ui.setHead(k.name, 'Marketing');
			var closed = k.status === 'abgeschlossen';
			box.appendChild(h('div', { class: 'page-head' },
				h('div', null, h('h2', null, k.kst + ' – ' + k.name), h('p', { class: 'muted', style: 'margin:4px 0 0' }, [k.type, k.period, k.status].filter(Boolean).join(' · '))),
				canEdit ? h('div', { class: 'mk-actions' },
					h('button', { class: 'btn', type: 'button', onclick: function () { campaignDialog(ui, m, function () { load(); }); } }, 'Ändern'),
					closed ? null : h('button', { class: 'btn', type: 'button', onclick: function () {
						confirmDlg(ui, 'Kampagne beenden', 'Die Kampagne „' + k.name + '“ wird auf „abgeschlossen“ gesetzt. Belege, Fahrtkosten und Auswertung bleiben unverändert; über „Ändern“ lässt sie sich wieder öffnen.', 'Kampagne beenden', function () {
							return ui.api('marketing/' + id + '/beenden', { method: 'POST', body: {} }).then(after);
						});
					} }, 'Beenden')) : null));

			box.appendChild(h('div', { class: 'grid tiles' },
				tile(ui, 'Einsatz gesamt', ui.money(k.effort, 'EUR'), k.rebate > 0 ? 'inkl. ' + ui.money(k.rebate, 'EUR') + ' Rabatt' : ''),
				tile(ui, 'Umsatz gesamt', ui.money(k.revenue, 'EUR')),
				tile(ui, 'Ergebnis', ui.money(k.result, 'EUR'), 'Umsatz − Einsatz'),
				tile(ui, 'Umsatz je Euro Einsatz', k.roas === null ? '–' : String(Math.round(k.roas * 100) / 100).replace('.', ','))));

			box.appendChild(h('div', { class: 'card mk-gap' }, h('h3', null, 'Zahlen im Einzelnen'),
				kv(ui, 'Kosten (gebucht)', ui.money(k.costs, 'EUR')),
				kv(ui, 'Rabatt (nicht gebucht)', ui.money(k.rebate, 'EUR')),
				kv(ui, 'Budget', k.budget.ampel === 'kein_budget' ? 'kein Budget gesetzt' : ui.money(k.budget.plan, 'EUR') + ' · ' + pct(k.budget.usage) + ' genutzt · ' + (k.budget.rest < 0 ? ui.money(-k.budget.rest, 'EUR') + ' über Budget' : ui.money(k.budget.rest, 'EUR') + ' frei')),
				kv(ui, 'Umsatz Marktkasse', ui.money(k.market, 'EUR')),
				kv(ui, 'Umsatz Shop (nachgewiesen)', ui.money(k.shop.sum, 'EUR') + (k.shop.count ? ' · ' + k.shop.count + ' Bestellungen (' + k.shop.utm + ' über Kampagnenkennung, ' + k.shop.voucher + ' über Gutschein)' : '')),
				kv(ui, 'Einsatz je Bestellung', k.cpo === null ? '–' : ui.money(k.cpo, 'EUR'))));

			if (k.rebate > 0) {
				box.appendChild(h('p', { class: 'muted' }, 'Der Rabatt steht bewusst nicht in der Buchhaltung: Ein gewährter Nachlass ist Erlösschmälerung, keine Betriebsausgabe – es gibt keinen Beleg, und der Bestellwert ist bereits gemindert. Für die Frage, was die Kampagne gekostet hat, zählt er trotzdem. Deshalb misst sich das Budget nur an den gebuchten Kosten, Ergebnis und Kennzahlen dagegen am Einsatz gesamt.'));
			}
			box.appendChild(h('p', { class: 'muted' }, 'Umsatz ausgewertet von ' + ymd(k.from) + ' bis ' + ymd(k.to) + ' (inklusive Nachlauffenster). Der Rohertrag lässt den Wareneinsatz außen vor – der hängt am Artikel, nicht an der Kampagne.'));

			if (canEdit) { box.appendChild(kmCard(m, o)); }
			box.appendChild(belegCard(m));
			if (canEdit) { box.appendChild(assignCard(m)); }
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
				h('p', { class: 'muted' }, 'Pauschale ' + decimal(o.km_rate) + ' € je km (in den Einstellungen änderbar)' + (m.km.booked ? ' – gebucht sind derzeit ' + ui.money(m.km.amount, 'EUR') : '') + ' als Eigenbeleg auf Konto ' + o.km_account + '. Beim Ändern wird der bisherige Beleg storniert und neu gebucht; mit 0 wird die Fahrt zurückgenommen.'));
		}

		function belegCard(m) {
			var card = h('div', { class: 'card mk-gap' }, h('h3', null, 'Zugeordnete Belege'));
			if (!m.belege.length) {
				card.appendChild(h('p', { class: 'muted' }, 'Noch nichts zugeordnet.'));
				return card;
			}
			card.appendChild(h('div', { class: 'rows' }, m.belege.map(function (b) {
				return h('div', { class: 'row-card mk-beleg' },
					h('div', { class: 'row-main' }, h('b', null, b.number + ' · ' + (b.vendor || 'ohne Händler')),
						h('small', null, [ymd(b.date), b.account, ui.money(b.amount, 'EUR') + ' Beleg', pct(b.share) + ' Anteil'].filter(Boolean).join(' · '))),
					h('div', { class: 'row-num' }, ui.money(b.part, 'EUR')),
					canEdit ? h('div', { class: 'row-tags' }, b.is_km
						? h('span', { class: 'tag' }, 'Fahrtkosten')
						: h('button', { class: 'btn mk-small', type: 'button', onclick: function () {
							confirmDlg(ui, 'Zuordnung lösen', 'Beleg ' + b.number + ' wird von dieser Kampagne gelöst. Der Beleg selbst bleibt in der Buchhaltung unverändert.', 'Zuordnung lösen', function () {
								return ui.api('marketing/' + id + '/loesen', { method: 'POST', body: { beleg_id: b.id } }).then(after);
							});
						} }, 'lösen')) : null);
			})));
			return card;
		}

		function assignCard(m) {
			var sel = h('select', { class: 'field' }, h('option', { value: '' }, '– Beleg wählen –'));
			m.pickable.forEach(function (b) {
				sel.appendChild(h('option', { value: String(b.id) }, [b.number, ymd(b.date), b.vendor, ui.money(b.amount, 'EUR') + ' (' + Math.round(b.free) + ' % frei)'].join(' · ')));
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
			return h('div', { class: 'card mk-gap' }, h('h3', null, 'Beleg zuordnen'),
				m.pickable.length ? null : h('p', { class: 'muted' }, 'Keine Belege mit freiem Anteil unter den letzten 100.'),
				field(ui, 'Beleg', sel),
				field(ui, 'Anteil (%)', share),
				h('div', { class: 'mk-actions' }, btn),
				err,
				h('p', { class: 'muted' }, 'Ein Beleg lässt sich auf mehrere Kampagnen aufteilen – etwa eine Flyerrechnung für zwei Märkte. Über alle Kampagnen zusammen sind nie mehr als 100 % möglich.'));
		}

		load();
	};
})();
