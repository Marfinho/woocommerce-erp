/*
 * ERP-Seite „Affiliates“ (nativ): Influencer mit eigenem Rabattcode.
 * Code anlegen, Umsatz und Provision je Partner sehen, Auszahlungen festhalten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	function fmt(n) { return new Intl.NumberFormat('de-DE').format(n); }
	function eur(n) { return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' €'; }
	function pct(n) { return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(n) + ' %'; }
	function dateDe(s) { return s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4) : '–'; }

	// "Anna Müller" -> "ANNA": Vorschlag für den Code aus dem ersten Wort des Namens.
	function suggest(name) {
		var w = String(name || '').trim().split(/\s+/)[0] || '';
		w = w.replace(/ä/gi, 'ae').replace(/ö/gi, 'oe').replace(/ü/gi, 'ue').replace(/ß/g, 'ss').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
		return w.length >= 3 ? w.slice(0, 16) : '';
	}

	function field(ui, label, input, hint) {
		var h = ui.h;
		return h('label', { class: 'label-area', style: 'display:block;margin-bottom:10px' },
			h('span', { style: 'display:block;font-weight:600' }, label), input,
			hint ? h('small', { class: 'muted', style: 'display:block' }, hint) : null);
	}

	function partnerDialog(ui, p, onSaved) {
		var h = ui.h;
		var neu = !p;
		p = p || { name: '', plattform: '', email: '', code: '', rabatt: 10, provision: 10, aktiv: true, notiz: '' };

		var name = h('input', { class: 'field', type: 'text', maxlength: '80', value: p.name, placeholder: 'z. B. Anna Beispiel', required: 'required' });
		var plattform = h('input', { class: 'field', type: 'text', maxlength: '120', value: p.plattform, placeholder: 'z. B. Instagram @anna.rides' });
		var email = h('input', { class: 'field', type: 'email', value: p.email, placeholder: 'für Rückfragen und die Abrechnung' });
		var konto = h('input', { class: 'field', type: 'email', value: p.konto || '', placeholder: 'E-Mail des Shop-Kontos, z. B. anna@example.com' });
		var anlegen = h('input', { type: 'checkbox' });
		var code = h('input', { class: 'field', type: 'text', maxlength: '32', value: p.code, placeholder: 'z. B. ANNA10', style: 'text-transform:uppercase' });
		var gesamt = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: String(p.gesamt != null ? p.gesamt : (p.rabatt + p.provision)).replace('.', ',') });
		var rabatt = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: String(p.rabatt).replace('.', ',') });
		var rest = h('b', null, '');
		var teilen = h('input', { type: 'checkbox', checked: p.teilen !== false });
		function num(v) { var n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? null : n; }
		function refresh() {
			var g = num(gesamt.value), r = num(rabatt.value);
			rest.textContent = (g === null || r === null || r < 0 || r > g) ? '–' : pct(Math.round((g - r) * 100) / 100);
		}
		gesamt.addEventListener('input', refresh);
		rabatt.addEventListener('input', refresh);
		refresh();
		var aktiv = h('input', { type: 'checkbox', checked: !!p.aktiv });
		var notiz = h('textarea', { class: 'field', rows: '3', maxlength: '500', placeholder: 'z. B. vereinbart am …, Auszahlung per PayPal' });
		notiz.value = p.notiz || '';
		var err = h('div', { class: 'notice warn', hidden: true });
		var save = h('button', { class: 'btn primary', type: 'submit' }, neu ? 'Affiliate anlegen' : 'Speichern');

		if (neu) {
			var touched = false;
			code.addEventListener('input', function () { touched = true; });
			name.addEventListener('input', function () { if (!touched) { code.value = suggest(name.value); } });
		}

		var form = h('form', null,
			field(ui, 'Name', name),
			field(ui, 'Kanal und Profil', plattform),
			field(ui, 'E-Mail', email, 'Keine Bankdaten hier eintragen – die gehören nicht ins ERP.'),
			field(ui, 'Konto im Shop (macht ihn zum Ambassador)', h('div', null, konto,
				h('label', { style: 'display:block;margin-top:6px' }, anlegen, ' Konto anlegen und Einladung mailen, falls es noch keins gibt')),
				'Mit dem Konto sieht der Influencer unter „Mein Konto → Ambassador“ seine Zahlen und kann eine Auszahlung anfordern. Leer lassen oder löschen entzieht ihm den Zugang.'),
			neu ? field(ui, 'Rabattcode', code, 'Wird als Gutschein im Shop angelegt. Danach nicht mehr änderbar, nur abschaltbar – er steht in Videos und Beschreibungen.')
				: field(ui, 'Rabattcode', h('div', null, h('code', null, p.code)), 'Der Code bleibt, wie er ist.'),
			field(ui, 'Gesamtanteil (%)', gesamt, 'Was der Shop für diesen Affiliate hergibt, zum Beispiel 20. Er wird zwischen Kunden-Rabatt und Provision aufgeteilt.'),
			field(ui, 'Davon Rabatt für Kunden (%)', rabatt, 'Z. B. 10 bei einem Gesamtanteil von 20. Bei 0 bekommen Kunden nichts, der Code ordnet dann nur Bestellungen zu.'),
			h('p', null, 'Provision für den Affiliate: ', rest, h('small', { class: 'muted', style: 'display:block' }, 'Gesamtanteil minus Rabatt, auf den Warenwert netto nach Rabatt und Erstattungen, ohne Versand.')),
			h('label', { style: 'display:block;margin-bottom:10px' }, teilen, ' Der Affiliate darf die Aufteilung selbst bestimmen (unter „Mein Konto“, braucht ein verknüpftes Konto)'),
			h('label', { style: 'display:block;margin-bottom:10px' }, aktiv, ' Code ist aktiv (Kunden können ihn einlösen)'),
			field(ui, 'Notiz', notiz),
			err,
			h('div', { style: 'display:flex;gap:8px;margin-top:10px' }, save)
		);

		var d = ui.dlg(neu ? 'Neuer Affiliate' : 'Affiliate: ' + p.name, form, true);

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.hidden = true;
			save.disabled = true;
			var body = { name: name.value, plattform: plattform.value, email: email.value, gesamt: gesamt.value, rabatt: rabatt.value, teilen: teilen.checked, aktiv: aktiv.checked, notiz: notiz.value, konto_email: konto.value, konto_anlegen: anlegen.checked };
			if (neu) { body.code = code.value; } else { body.id = p.id; }
			ui.api('affiliates', { method: 'POST', body: body }).then(function (res) {
				ui.toast(res.message);
				d.close();
				onSaved();
			}).catch(function (e) {
				err.textContent = e.message;
				err.hidden = false;
			}).then(function () { save.disabled = false; });
		});
	}

	function payoutDialog(ui, p, data, onSaved, req) {
		var h = ui.h;
		var guthaben = p.stats.guthaben;
		var betrag = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: guthaben > 0 ? String(guthaben).replace('.', ',') : '' });
		var datum = h('input', { class: 'field', type: 'date', value: data.today, max: data.today });
		var notiz = h('input', { class: 'field', type: 'text', maxlength: '190', placeholder: 'z. B. PayPal, Überweisung vom …', value: req ? req.ziel : '' });
		var err = h('div', { class: 'notice warn', hidden: true });
		var save = h('button', { class: 'btn primary', type: 'submit' }, 'Auszahlung festhalten');

		var form = h('form', null,
			h('p', null, 'Auszahlbares Guthaben von ', h('b', null, p.name), ': ', h('b', null, eur(guthaben))),
			h('p', { class: 'muted' }, 'Das ERP überweist nichts. Du überweist selbst und hältst es hier fest, damit das Guthaben stimmt. '
				+ (data.buchen ? 'Die Auszahlung wird zugleich als Beleg gebucht (Werbekosten, eigene Kostenstelle unter Marketing).' : 'Gebucht wird sie nicht – das ist in den Einstellungen so gewählt.')),
			field(ui, 'Betrag (€)', betrag, 'Höchstens das auszahlbare Guthaben. Zu viel eingetragen? Dann später in der Liste stornieren.'),
			field(ui, 'Datum der Überweisung', datum),
			field(ui, 'Notiz', notiz),
			err,
			h('div', { style: 'margin-top:10px' }, save)
		);

		var d = ui.dlg('Auszahlung an ' + p.name, form);

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.hidden = true;
			save.disabled = true;
			ui.api('affiliates/auszahlung', { method: 'POST', body: { partner: p.id, betrag: betrag.value, datum: datum.value, notiz: notiz.value } }).then(function (res) {
				ui.toast(res.message);
				d.close();
				onSaved();
			}).catch(function (e) {
				err.textContent = e.message;
				err.hidden = false;
			}).then(function () { save.disabled = false; });
		});
	}

	function declineDialog(ui, r, onSaved) {
		var h = ui.h;
		var notiz = h('input', { class: 'field', type: 'text', maxlength: '190', placeholder: 'z. B. Betrag noch unter dem Mindestbetrag' });
		var err = h('div', { class: 'notice warn', hidden: true });
		var go = h('button', { class: 'btn primary', type: 'submit' }, 'Ablehnen');
		var form = h('form', null,
			h('p', null, 'Der Ambassador sieht die Begründung unter „Mein Konto“.'),
			field(ui, 'Begründung', notiz), err, h('div', { style: 'margin-top:10px' }, go));
		var d = ui.dlg('Anforderung von ' + r.name + ' ablehnen', form);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.hidden = true;
			go.disabled = true;
			ui.api('affiliates/ablehnen', { method: 'POST', body: { partner: r.partner, notiz: notiz.value } }).then(function (res) {
				ui.toast(res.message);
				d.close();
				onSaved();
			}).catch(function (e) {
				err.textContent = e.message;
				err.hidden = false;
			}).then(function () { go.disabled = false; });
		});
	}

	function stornoDialog(ui, r, onSaved) {
		var h = ui.h;
		var grund = h('input', { class: 'field', type: 'text', maxlength: '190', placeholder: 'z. B. Betrag vertippt' });
		var err = h('div', { class: 'notice warn', hidden: true });
		var go = h('button', { class: 'btn primary', type: 'submit' }, 'Stornieren');
		var form = h('form', null,
			h('p', null, 'Auszahlung von ', h('b', null, eur(r.betrag)), ' an ', h('b', null, r.partner), ' vom ' + dateDe(r.datum) + ' stornieren?'),
			h('p', { class: 'muted' }, r.beleg ? 'Der Beleg ' + r.beleg + ' wird mit einem Gegenbeleg zurückgenommen.' : 'Sie ist nicht gebucht; in der Buchhaltung ändert sich nichts.'),
			field(ui, 'Begründung', grund), err, h('div', { style: 'margin-top:10px' }, go));
		var d = ui.dlg('Auszahlung stornieren', form);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.hidden = true;
			go.disabled = true;
			ui.api('affiliates/storno', { method: 'POST', body: { auszahlung: r.id, grund: grund.value } }).then(function (res) {
				ui.toast(res.message);
				d.close();
				onSaved();
			}).catch(function (e) {
				err.textContent = e.message;
				err.hidden = false;
			}).then(function () { go.disabled = false; });
		});
	}

	UI.pages['affiliates'] = function (item, ui) {
		var h = ui.h;
		var days = 90;
		var box = h('div');

		ui.pageHead('Affiliates', 'Influencer mit eigenem Rabattcode: Kunden sparen, der Affiliate bekommt Provision.',
			ui.can('verkauf.affiliates') ? h('button', { class: 'btn primary', type: 'button', onclick: function () { partnerDialog(ui, null, load); } }, 'Neuer Affiliate') : null);
		ui.view().appendChild(box);

		function tile(label, value, sub) {
			return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value), sub ? h('small', { class: 'muted' }, sub) : null);
		}

		function paint(d) {
			box.textContent = '';

			box.appendChild(h('div', { class: 'grid tiles', style: 'margin-bottom:14px' },
				tile('Bestellungen mit Code', fmt(d.summe.anzahl), d.ranges[d.days]),
				tile('Umsatz', eur(d.summe.umsatz), 'brutto nach Erstattungen'),
				tile('Provision', eur(d.summe.provision), d.ranges[d.days]),
				tile('Auszahlbar', eur(d.summe.guthaben), 'nach ' + d.wartezeit + ' Tagen Wartezeit'),
				tile('In Wartezeit', eur(d.summe.wartend), 'noch nicht auszahlbar')));

			var presets = h('div', { class: 'presets', style: 'margin-bottom:10px' });
			Object.keys(d.ranges).forEach(function (k) {
				presets.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': parseInt(k, 10) === d.days ? 'true' : 'false', onclick: function () { days = parseInt(k, 10); load(); } }, d.ranges[k]));
			});

			if (d.requests.length) {
				var rq = h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Auszahlung angefordert'));
				d.requests.forEach(function (r) {
					var p = d.partners.filter(function (x) { return x.id === r.partner; })[0];
					var row = h('div', { class: 'notice', style: 'margin-bottom:8px' },
						h('b', null, r.name), ' möchte ', h('b', null, eur(r.betrag)), ' (angefordert am ' + dateDe(r.datum) + '). Zahlungsangabe: ', h('code', null, r.ziel), ' ');
					if (d.can_pay && p) {
						row.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { payoutDialog(ui, p, d, load, r); } }, 'Auszahlung festhalten'));
						row.appendChild(document.createTextNode(' '));
						row.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { declineDialog(ui, r, load); } }, 'Ablehnen'));
					}
					rq.appendChild(row);
				});
				box.appendChild(rq);
			}

			var tbl = h('table', { class: 'list' });
			tbl.appendChild(h('thead', null, h('tr', null,
				h('th', null, 'Affiliate'), h('th', null, 'Code'), h('th', { class: 'num' }, 'Rabatt'), h('th', { class: 'num' }, 'Provision'),
				h('th', { class: 'num' }, 'Bestellungen'), h('th', { class: 'num' }, 'Umsatz'), h('th', { class: 'num' }, 'Provision verdient'),
				h('th', { class: 'num' }, 'Auszahlbar'), h('th', null, ''))));
			var tb = h('tbody');
			if (!d.partners.length) {
				tb.appendChild(h('tr', null, h('td', { colspan: '9' }, 'Noch kein Affiliate. Mit „Neuer Affiliate“ legst du einen Influencer mit Code an – der Gutschein entsteht gleichzeitig im Shop.')));
			}
			d.partners.forEach(function (p) {
				var s = p.stats;
				var buttons = h('div', { style: 'display:flex;gap:6px;flex-wrap:wrap' },
					h('button', { class: 'btn', type: 'button', onclick: function () { partnerDialog(ui, p, load); } }, 'Ändern'));
				if (d.can_pay) {
					buttons.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { payoutDialog(ui, p, d, load); } }, 'Auszahlen'));
				}
				tb.appendChild(h('tr', null,
					h('td', null, h('b', null, p.name), h('div', { class: 'muted' }, p.plattform || ''),
						h('div', { class: 'muted' }, p.konto ? 'Konto: ' + p.konto : 'kein Konto'),
						p.aktiv ? null : h('span', { class: 'tag later' }, 'abgeschaltet')),
					h('td', null, h('code', null, p.code), h('div', null, h('a', { href: p.link, target: '_blank', rel: 'noopener', class: 'muted' }, 'Link mit Code'))),
					h('td', { class: 'num' }, pct(p.rabatt)),
					h('td', { class: 'num' }, pct(p.provision), h('div', { class: 'muted' }, 'von ' + pct(p.gesamt))),
					h('td', { class: 'num' }, fmt(s.anzahl), s.letzte_ts ? h('div', { class: 'muted' }, 'zuletzt ' + dateDe(p.letzte)) : null,
						s.eigen ? h('div', { class: 'muted' }, s.eigen + ' Eigenkauf nicht gezählt') : null),
					h('td', { class: 'num' }, eur(s.umsatz)),
					h('td', { class: 'num' }, eur(s.provision)),
					h('td', { class: 'num' }, h('b', null, eur(s.guthaben)), s.wartend > 0 ? h('div', { class: 'muted' }, '+ ' + eur(s.wartend) + ' in Wartezeit') : null),
					h('td', null, buttons)));
			});
			tbl.appendChild(tb);

			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('h3', null, 'Affiliates'), presets,
				h('div', { style: 'overflow-x:auto' }, tbl),
				h('p', { class: 'muted' }, 'Die Provision rechnet auf den Warenwert netto: Positionen nach Rabatt und nach Erstattungen, ohne Versand und Steuer. Sie wird erst nach ' + d.wartezeit + ' Tagen auszahlbar (Widerruf und Rücksendungen; einstellbar unter Einstellungen → Marketing). Der Satz gilt ab dem Moment, in dem die Bestellung bezahlt ist – spätere Änderungen betreffen nur neue Bestellungen. „Auszahlbar“ ist das, was nach Abzug bisheriger Auszahlungen übrig ist.')));

			var pt = h('table', { class: 'list' });
			pt.appendChild(h('thead', null, h('tr', null, h('th', null, 'Datum'), h('th', null, 'Affiliate'), h('th', { class: 'num' }, 'Betrag'), h('th', null, 'Beleg'), h('th', null, 'Notiz'), h('th', null, ''))));
			var pb = h('tbody');
			if (!d.payouts.length) { pb.appendChild(h('tr', null, h('td', { colspan: '6' }, 'Noch keine Auszahlung festgehalten.'))); }
			d.payouts.forEach(function (r) {
				var last = r.storniert ? h('td', null, h('span', { class: 'tag later' }, 'storniert'), h('div', { class: 'muted' }, r.grund))
					: h('td', null, d.can_pay ? h('button', { class: 'btn', type: 'button', onclick: function () { stornoDialog(ui, r, load); } }, 'Stornieren') : null);
				pb.appendChild(h('tr', { style: r.storniert ? 'opacity:.6;text-decoration:line-through' : '' },
					h('td', null, dateDe(r.datum)), h('td', null, r.partner), h('td', { class: 'num' }, eur(r.betrag)),
					h('td', null, r.beleg || (r.storniert ? '' : '–')), h('td', null, r.notiz), last));
			});
			pt.appendChild(pb);
			box.appendChild(h('div', { class: 'card' },
				h('h3', null, 'Auszahlungen'),
				h('div', { style: 'overflow-x:auto' }, pt),
				h('p', { class: 'muted' }, 'Auszahlungen werden nie geändert oder gelöscht. Eine falsche Auszahlung stornierst du mit Begründung: Das Guthaben steigt wieder, und ein gebuchter Beleg bekommt einen Gegenbeleg.')));
		}

		function load() {
			return ui.api('affiliates?tage=' + days).then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
