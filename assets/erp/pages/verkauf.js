/*
 * Verkauf im ERP: Kontakte-Akte, Shop-Bestellungen, Loyalty, Marketing.
 *
 * Server: includes/Erp/Pages/Verkauf.php. Alle Texte aus dem Server gelangen
 * über textContent bzw. h() in die Seite, nie über innerHTML.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	/* ---------- Hilfen ---------- */

	function ymd(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
	}

	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
	}

	function pct(n) {
		return (Math.round(Number(n) * 10) / 10).toString().replace('.', ',') + ' %';
	}

	function errBox(ui, box, e) {
		box.textContent = '';
		box.appendChild(ui.h('div', { class: 'notice warn' }, e.message));
	}

	function loading(ui) {
		return ui.h('div', { class: 'card' }, 'Wird geladen …');
	}

	function opt(ui, value, label, selected) {
		return ui.h('option', { value: value, selected: !!selected }, label);
	}

	function labelled(ui, text, input) {
		return ui.h('label', { class: 'vk-field' }, ui.h('span', null, text), input);
	}

	function textIn(ui, name, value, attrs) {
		var a = { class: 'field', type: 'text', name: name, value: value || '' };
		Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
		return ui.h('input', a);
	}

	function selectIn(ui, name, map, value, blank) {
		var kids = [];
		if (blank) { kids.push(opt(ui, '', blank, !value)); }
		Object.keys(map).forEach(function (k) { kids.push(opt(ui, k, map[k], k === value)); });
		return ui.h('select', { class: 'field', name: name }, kids);
	}

	function collect(form) {
		var body = {};
		Array.prototype.forEach.call(form.elements, function (f) {
			if (!f.name) { return; }
			body[f.name] = f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value;
		});
		return body;
	}

	// Formular im Dialog: sendet die Felder, zeigt Fehler im Dialog, ruft danach done().
	function formDialog(ui, title, fields, submitText, send, done) {
		var err = ui.h('div', { class: 'notice warn', hidden: true, role: 'alert' });
		var btn = ui.h('button', { class: 'btn primary', type: 'submit' }, submitText);
		var form = ui.h('form', { class: 'vk-form' }, fields, err, ui.h('div', { class: 'vk-actions' }, btn));
		var d = ui.dlg(title, ui.h('div', { class: 'vk-dlg' }, form));

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			btn.disabled = true;
			err.hidden = true;
			send(collect(form)).then(function (r) {
				d.close();
				ui.toast(r && r.message ? r.message : 'Gespeichert');
				done(r);
			}).catch(function (er) {
				btn.disabled = false;
				err.textContent = er.message;
				err.hidden = false;
			});
		});

		return d;
	}

	function backLink(ui, href, text) {
		return ui.h('a', { class: 'vk-back', href: href }, '‹ ' + text);
	}

	function tile(ui, lab, val, note) {
		return ui.h('div', { class: 'tile' }, ui.h('div', { class: 'lab' }, lab), ui.h('div', { class: 'val' }, val), note ? ui.h('div', { class: 'note' }, note) : null);
	}

	function kv(ui, label, value) {
		return ui.h('div', { class: 'vk-kv' }, ui.h('span', { class: 'muted' }, label), ui.h('span', null, value || '–'));
	}

	function debounce(fn, ms) {
		var t = null;
		return function () { clearTimeout(t); t = setTimeout(fn, ms); };
	}

	/* ====================================================================
	 * Kontakte
	 * ================================================================== */

	function contactFields(ui, c, types, status) {
		c = c || {};
		return [
			labelled(ui, 'Firma / Name *', textIn(ui, 'company', c.company, { required: true, autocomplete: 'off' })),
			ui.h('div', { class: 'vk-two' },
				labelled(ui, 'Typ', selectIn(ui, 'type', types, c.type || 'lead')),
				labelled(ui, 'Status', selectIn(ui, 'status', status, c.status || 'identifiziert'))),
			labelled(ui, 'Straße und Nr.', textIn(ui, 'street', c.street)),
			ui.h('div', { class: 'vk-two' },
				labelled(ui, 'PLZ', textIn(ui, 'zip', c.zip, { inputmode: 'numeric' })),
				labelled(ui, 'Ort', textIn(ui, 'city', c.city))),
			labelled(ui, 'Ansprechpartner', textIn(ui, 'contact_person', c.contact_person)),
			ui.h('div', { class: 'vk-two' },
				labelled(ui, 'Telefon', textIn(ui, 'phone', c.phone, { type: 'tel' })),
				labelled(ui, 'E-Mail', textIn(ui, 'email', c.email, { type: 'email' }))),
			ui.h('div', { class: 'vk-two' },
				labelled(ui, 'Wiedervorlage', textIn(ui, 'follow_up_on', c.follow_up_on, { type: 'date' })),
				labelled(ui, 'Herkunft', textIn(ui, 'source', c.source))),
			labelled(ui, 'Notiz', ui.h('textarea', { class: 'field', name: 'note', rows: '3' }, c.note || ''))
		];
	}

	UI.pages['kontakte'] = function (item, ui) {
		var h = ui.h;
		var f = { q: '', typ: '', stufe: '' };
		var meta = null;
		var newBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () {
			if (!meta) { return; }
			formDialog(ui, 'Neuer Kontakt', contactFields(ui, null, meta.types, meta.status), 'Anlegen',
				function (body) { return ui.api('kontakte', { method: 'POST', body: body }); },
				function (r) { location.hash = '#/kontakt/' + r.id; });
		} }, 'Kontakt anlegen');

		ui.pageHead('Kontakte / B2B', 'Händler, Vereine und Firmen – mit Konditionen, Bestellungen und Nachbestell-Link.', [newBtn, ui.classicBtn(item)]);

		var search = h('input', { class: 'field', type: 'search', placeholder: 'Suchen: Firma, Ort, Ansprechpartner …', 'aria-label': 'Kontakte suchen' });
		var typ = h('select', { class: 'field', 'aria-label': 'Typ' });
		var stufe = h('select', { class: 'field', 'aria-label': 'Rabattstufe' },
			opt(ui, '', 'Alle Rabattstufen', true), opt(ui, 'standard', 'Standardstaffel'), opt(ui, 'sonder', 'Sonderkondition'));
		var box = h('div');
		var extra = h('div');

		ui.view().appendChild(h('div', { class: 'vk-filter' }, search, typ, stufe));
		ui.view().appendChild(box);
		ui.view().appendChild(extra);

		function query() {
			var p = [];
			if (f.q) { p.push('q=' + encodeURIComponent(f.q)); }
			if (f.typ) { p.push('typ=' + encodeURIComponent(f.typ)); }
			if (f.stufe) { p.push('stufe=' + encodeURIComponent(f.stufe)); }
			return 'kontakte' + (p.length ? '?' + p.join('&') : '');
		}

		function load() {
			box.textContent = '';
			box.appendChild(loading(ui));
			ui.api(query()).then(function (d) {
				if (!meta) {
					meta = d;
					typ.appendChild(opt(ui, '', 'Alle Typen', true));
					Object.keys(d.types).forEach(function (k) { typ.appendChild(opt(ui, k, d.types[k])); });
					paintExtra(d);
				}
				paint(d);
			}).catch(function (e) { errBox(ui, box, e); });
		}

		var reload = debounce(function () { f.q = search.value.trim(); f.typ = typ.value; f.stufe = stufe.value; load(); }, 250);
		search.addEventListener('input', reload);
		typ.addEventListener('change', reload);
		stufe.addEventListener('change', reload);

		function row(c) {
			return h('a', { class: 'row-card vk-row', href: '#/kontakt/' + c.id },
				h('div', { class: 'row-main' },
					h('b', null, c.company),
					h('small', null, [meta.types[c.type] || c.type, c.city, c.contact_person].filter(Boolean).join(' · '))),
				h('div', { class: 'row-num' }, ui.money(c.revenue, 'EUR')),
				h('div', { class: 'row-tags' },
					h('span', { class: 'tag' }, meta.status[c.status] || c.status),
					h('span', { class: 'tag' + (c.tier.kind === 'sonder' ? ' now' : '') }, c.tier.label),
					c.open > 0 ? h('span', { class: 'tag later' }, ui.money(c.open, 'EUR') + ' offen') : null,
					c.follow_up_on ? h('span', { class: 'tag' + (c.due ? ' later' : '') }, (c.due ? 'Nachfassen seit ' : 'Nachfassen ') + ymd(c.follow_up_on)) : null));
		}

		function paint(d) {
			box.textContent = '';
			var due = d.contacts.filter(function (c) { return c.due; });

			if (due.length) {
				box.appendChild(h('div', { class: 'card' }, h('h3', null, 'Fällige Wiedervorlagen (' + due.length + ')'), h('div', { class: 'rows' }, due.map(row))));
			}

			box.appendChild(h('p', { class: 'muted vk-count' }, d.contacts.length === d.total ? d.total + ' Kontakte' : d.contacts.length + ' von ' + d.total + ' Kontakten'));

			if (!d.contacts.length) {
				box.appendChild(h('div', { class: 'notice' }, d.total ? 'Kein Kontakt passt zu dieser Suche.' : 'Noch kein Kontakt angelegt. Mit „Kontakt anlegen“ geht es los.'));
				return;
			}

			box.appendChild(h('div', { class: 'rows' }, d.contacts.map(row)));
		}

		function paintExtra(d) {
			extra.textContent = '';

			if (d.standard.length) {
				extra.appendChild(h('div', { class: 'card' },
					h('h3', null, 'Standardstaffel für Händler ohne Sondervereinbarung'),
					h('div', { class: 'vk-tiers' }, d.standard.map(function (t) {
						return h('span', { class: 'tag' }, (t.name ? t.name + ': ' : '') + 'ab ' + ui.money(t.min, 'EUR') + ' · ' + pct(t.pct));
					}))));
			}

			if (d.b2b.length) {
				var rows = d.b2b.map(function (r) {
					return h('a', { class: 'row-card vk-row', href: '#/kontakt/' + r.id },
						h('div', { class: 'row-main' }, h('b', null, r.company), h('small', null, r.orders + ' Bestellungen' + (r.days_to_first === null ? '' : ' · erste nach ' + r.days_to_first + ' Tagen'))),
						h('div', { class: 'row-num' }, ui.money(r.revenue, 'EUR')),
						h('div', { class: 'row-tags' }, h('span', { class: 'tag ' + (r.reorder ? 'now' : 'later') }, r.reorder ? 'nachbestellt' : 'noch nicht nachbestellt')));
				});
				extra.appendChild(h('details', { class: 'card' },
					h('summary', null, 'Auswertung B2B – Nachbestellquote ' + (d.rate === null ? '–' : Math.round(d.rate) + ' %')),
					h('p', { class: 'muted' }, 'Ein Händler, der einmal bestellt, ist ein Zufall; einer, der nachbestellt, ist ein Kanal.'),
					h('div', { class: 'rows' }, rows)));
			}
		}

		load();
	};

	// Preisliste für einen Händler: druckbar, als PDF speicherbar (Druckdialog des Browsers).
	function priceList(ui, id) {
		var h = ui.h;
		ui.section('kontakte');
		ui.setHead('Preisliste', 'Verkauf');
		var box = h('div');
		ui.view().appendChild(h('div', { class: 'no-print' }, backLink(ui, '#/kontakt/' + id, 'Zur Akte')));
		ui.view().appendChild(box);
		box.appendChild(loading(ui));

		ui.api('kontakte/' + id + '/preisliste').then(function (d) {
			var c = d.contact;
			box.textContent = '';
			box.appendChild(h('div', { class: 'no-print vk-actions', style: 'margin-bottom:12px' },
				h('button', { class: 'btn primary', type: 'button', onclick: function () { window.print(); } }, 'Drucken oder als PDF speichern')));

			var sheet = h('article', { class: 'card vk-sheet' },
				h('header', { class: 'vk-sheet-head' },
					h('div', null, h('h2', null, 'Preisliste'), h('p', { class: 'muted', style: 'margin:2px 0 0' }, d.shop + ' · Stand ' + ymd(d.date))),
					h('div', { class: 'vk-sheet-to' }, h('b', null, c.company), c.person ? h('div', null, c.person) : null, c.address ? h('div', null, c.address) : null, c.vat_id ? h('div', null, 'USt-IdNr. ' + c.vat_id) : null)),
				h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th', null, 'Nr.'), h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Empf. VK brutto'), h('th', { class: 'num' }, 'Ihr Preis netto'))),
					h('tbody', null, d.rows.map(function (r) {
						return h('tr', null, h('td', null, r.sku), h('td', null, r.name), h('td', { class: 'num' }, r.uvp === null ? '–' : ui.money(r.uvp, 'EUR')), h('td', { class: 'num' }, h('b', null, ui.money(r.dealer_net, 'EUR'))));
					}))));

			if (!d.rows.length) { sheet.appendChild(h('p', { class: 'muted' }, 'Es gibt noch keine Artikel mit Händlerpreis. Trage ihn in der Produktakte unter Stammdaten ein.')); }

			var terms = h('div', { class: 'vk-sheet-terms' });
			if (d.tiers.length) {
				terms.appendChild(h('b', null, 'Mengenrabatt auf den Bestellwert'));
				terms.appendChild(h('ul', { class: 'bullets' }, d.tiers.map(function (t) { return h('li', null, 'ab ' + ui.money(t.min, 'EUR') + ' netto: ' + pct(t.pct) + ' Rabatt'); })));
			}
			terms.appendChild(h('p', null, 'Zahlungsziel: ' + (d.days ? d.days + ' Tage netto' : 'nach Vereinbarung') + '. Alle Preise netto zuzüglich ' + pct(d.vat * 100) + ' Umsatzsteuer.'));
			sheet.appendChild(terms);
			box.appendChild(sheet);
		}).catch(function (e) { errBox(ui, box, e); });
	}

	UI.routes['kontakt'] = function (r, ui) {
		var h = ui.h;
		var id = parseInt(r.id, 10) || 0;
		if (r.sub === 'preisliste') { return priceList(ui, id); }
		ui.section('kontakte');
		ui.setHead('Kontakt', 'Verkauf');

		var box = h('div');
		ui.view().appendChild(backLink(ui, '#/seite/kontakte', 'Alle Kontakte'));
		ui.view().appendChild(box);
		box.appendChild(loading(ui));

		var fresh = null; // frisch erzeugter Nachbestell-Link: nur in dieser Ansicht, nie gespeichert

		function load() {
			return ui.api('kontakte/' + id).then(function (d) { paint(d); }).catch(function (e) { errBox(ui, box, e); });
		}

		function post(path, body) { return ui.api('kontakte/' + id + path, { method: 'POST', body: body }); }

		function paint(d) {
			var c = d.contact;
			box.textContent = '';
			ui.setHead(c.company, 'Kontakte');

			var classic = ui.config.adminUrl + 'admin.php?page=lw16-betrieb-kontakte&contact=' + id;
			box.appendChild(h('div', { class: 'page-head' },
				h('div', null, h('h2', null, c.company), h('p', { class: 'muted', style: 'margin:4px 0 0' }, [d.types[c.type] || c.type, d.status[c.status] || c.status].join(' · '))),
				h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' },
					h('a', { class: 'btn', href: '#/kontakt/' + id + '/preisliste' }, 'Preisliste'),
					h('button', { class: 'btn primary', type: 'button', onclick: function () {
						formDialog(ui, 'Stammdaten ändern', contactFields(ui, c, d.types, d.status), 'Speichern', function (body) { return post('', body); }, load);
					} }, 'Stammdaten ändern'))));

			box.appendChild(h('div', { class: 'grid tiles' },
				tile(ui, 'Gesamtumsatz', ui.money(d.tiles.revenue, 'EUR')),
				tile(ui, 'Bestellungen', String(d.tiles.orders)),
				tile(ui, 'Offene Posten', ui.money(d.tiles.open, 'EUR'), d.open.some(function (o) { return o.overdue; }) ? 'davon überfällig' : ''),
				tile(ui, 'Wiedervorlage', c.follow_up_on ? ymd(c.follow_up_on) : '–')));

			box.appendChild(h('div', { class: 'two vk-gap' }, stamm(d), konditionen(d)));
			box.appendChild(profileCard(d));
			box.appendChild(link(d));
			box.appendChild(orders(d));
			if (d.open.length) { box.appendChild(openItems(d)); }
			box.appendChild(commission(d, classic));
			box.appendChild(activities(d));
		}

		function profileFields(p, o) {
			function area(name, text) { return labelled(ui, text, h('textarea', { class: 'field', name: name, rows: '2' }, p[name] || '')); }
			function check(name, text) {
				return h('label', { class: 'chk', style: 'display:flex;gap:10px;align-items:center;min-height:44px' },
					h('input', { type: 'checkbox', name: name, checked: !!p[name], style: 'width:22px;height:22px' }), h('span', null, text));
			}
			return [
				h('div', { class: 'vk-two' },
					labelled(ui, 'Firma für die Rechnung', textIn(ui, 'billing_company', p.billing_company)),
					labelled(ui, 'USt-IdNr.', textIn(ui, 'vat_id', p.vat_id, { placeholder: 'DE123456789', autocomplete: 'off' }))),
				area('billing_address', 'Rechnungsadresse'),
				labelled(ui, 'E-Mail für Rechnungen', textIn(ui, 'billing_email', p.billing_email, { type: 'email' })),
				area('shipping_address', 'Lieferadresse (wenn anders)'),
				area('delivery_notes', 'Lieferhinweise (Rampe, Zeiten, Anmeldung)'),
				h('div', { class: 'vk-two' },
					labelled(ui, 'Entscheider', textIn(ui, 'decision_maker', p.decision_maker)),
					labelled(ui, 'Wareneingang', textIn(ui, 'goods_receiver', p.goods_receiver))),
				h('div', { class: 'vk-two' },
					labelled(ui, 'Bevorzugter Kanal', selectIn(ui, 'preferred_channel', o.preferred_channel, p.preferred_channel, '–')),
					labelled(ui, 'Beste Zeit', textIn(ui, 'best_time', p.best_time))),
				h('div', { class: 'vk-two' },
					labelled(ui, 'Bestellrhythmus', selectIn(ui, 'order_frequency', o.order_frequency, p.order_frequency, '–')),
					labelled(ui, 'Nächste Bestellung erwartet', textIn(ui, 'next_expected_order', p.next_expected_order, { type: 'date' }))),
				labelled(ui, 'Saisonspitze', textIn(ui, 'season_peak', p.season_peak, { placeholder: 'z. B. Oktober bis Dezember' })),
				h('div', { class: 'vk-two' },
					labelled(ui, 'Verpackung', selectIn(ui, 'packaging_preference', o.packaging_preference, p.packaging_preference, '–')),
					labelled(ui, 'Bevorzugte Farben', textIn(ui, 'preferred_colors', p.preferred_colors))),
				labelled(ui, 'Kreditlimit in €', textIn(ui, 'credit_limit', p.credit_limit, { inputmode: 'decimal' })),
				check('commission_allowed', 'Kommission vereinbart'),
				check('display_provided', 'Display beim Händler gestellt'),
				area('shelf_situation', 'Regalsituation'),
				area('competitor_products', 'Wettbewerbsprodukte im Regal')
			];
		}

		function profileCard(d) {
			var p = d.profile;
			var card = h('div', { class: 'card' }, h('h3', null, 'Händlerprofil'));
			var yes = function (b) { return b ? 'ja' : 'nein'; };
			var opts = d.profile_options;
			var label = function (field, key) { return key && opts[field] && opts[field][key] ? opts[field][key] : ''; };

			if (!p.exists) { card.appendChild(h('p', { class: 'muted' }, 'Noch kein Profil. Mit USt-IdNr., Lieferadresse und Kreditlimit ist die Akte für Rechnung und Versand vollständig.')); }

			card.appendChild(kv(ui, 'Rechnung an', [p.billing_company, p.billing_address].filter(Boolean).join(', ')));
			card.appendChild(kv(ui, 'USt-IdNr.', p.vat_id));
			card.appendChild(kv(ui, 'E-Mail Rechnung', p.billing_email));
			card.appendChild(kv(ui, 'Lieferadresse', p.shipping_address));
			card.appendChild(kv(ui, 'Lieferhinweise', p.delivery_notes));
			card.appendChild(kv(ui, 'Entscheider', p.decision_maker));
			card.appendChild(kv(ui, 'Wareneingang', p.goods_receiver));
			card.appendChild(kv(ui, 'Kanal', [label('preferred_channel', p.preferred_channel), p.best_time].filter(Boolean).join(', ')));
			card.appendChild(kv(ui, 'Bestellrhythmus', [label('order_frequency', p.order_frequency), p.season_peak].filter(Boolean).join(' · ')));
			card.appendChild(kv(ui, 'Nächste Bestellung', p.next_expected_order ? ymd(p.next_expected_order) : ''));
			card.appendChild(kv(ui, 'Verpackung', label('packaging_preference', p.packaging_preference)));
			card.appendChild(kv(ui, 'Farben', p.preferred_colors));
			card.appendChild(kv(ui, 'Kommission', yes(p.commission_allowed)));
			card.appendChild(kv(ui, 'Display', yes(p.display_provided)));
			if (p.credit_limit !== '') {
				var limit = parseFloat(p.credit_limit);
				var over = d.tiles.open > limit;
				card.appendChild(kv(ui, 'Kreditlimit', h('span', null, ui.money(limit, 'EUR'), over ? h('span', { class: 'tag later', style: 'margin-left:8px' }, 'überschritten: offen ' + ui.money(d.tiles.open, 'EUR')) : null)));
			}

			if (d.can_profile) {
				card.appendChild(h('button', { class: 'btn', type: 'button', style: 'margin-top:10px', onclick: function () {
					formDialog(ui, 'Händlerprofil', profileFields(p, opts), 'Speichern', function (body) { return post('/profil', body); }, load);
				} }, p.exists ? 'Profil bearbeiten' : 'Profil anlegen'));
			}
			return card;
		}

		function stamm(d) {
			var c = d.contact;
			return h('div', { class: 'card' }, h('h3', null, 'Stammdaten'),
				kv(ui, 'Anschrift', [c.street, [c.zip, c.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')),
				kv(ui, 'Ansprechpartner', c.contact_person),
				kv(ui, 'Telefon', c.phone ? h('a', { href: 'tel:' + c.phone }, c.phone) : ''),
				kv(ui, 'E-Mail', c.email ? h('a', { href: 'mailto:' + c.email }, c.email) : ''),
				kv(ui, 'Herkunft', c.source),
				kv(ui, 'Notiz', c.note));
		}

		function konditionen(d) {
			var special = d.tier.source === 'kontakt';
			var card = h('div', { class: 'card' }, h('h3', null, 'Preisstufe und Rabatt'),
				h('p', { style: 'margin:0 0 8px' }, h('span', { class: 'tag' + (special ? ' now' : '') }, special ? 'Sonderkondition' : 'Standardstaffel'),
					special && d.tier.days ? ' Zahlungsziel ' + d.tier.days + ' Tage' : ''));

			if (d.tier.tiers.length) {
				card.appendChild(h('div', { class: 'vk-tiers' }, d.tier.tiers.map(function (t) {
					return h('span', { class: 'tag' }, 'ab ' + ui.money(t.min, 'EUR') + ' · ' + pct(t.pct));
				})));
			} else {
				card.appendChild(h('p', { class: 'muted' }, 'Keine Staffel hinterlegt.'));
			}

			if (!special) { card.appendChild(h('p', { class: 'muted' }, 'Keine Sonderkonditionen – es gilt die Standardstaffel.')); }

			if (d.terms.length) {
				card.appendChild(h('details', null, h('summary', null, 'Verlauf der Konditionen (' + d.terms.length + ')'),
					h('div', { class: 'rows' }, d.terms.map(function (t) {
						return h('div', { class: 'row-card' },
							h('div', { class: 'row-main' }, h('b', null, 'ab ' + ui.money(t.min, 'EUR') + ' · ' + pct(t.pct)),
								h('small', null, 'Zahlungsziel ' + t.days + ' Tage · gültig ' + ymd(t.from) + (t.until ? ' bis ' + ymd(t.until) : ' bis auf Weiteres') + (t.by ? ' · vereinbart durch ' + t.by : ''))));
					}))));
			}

			card.appendChild(h('button', { class: 'btn', type: 'button', style: 'margin-top:10px', onclick: function () {
				formDialog(ui, 'Kondition festschreiben', [
					h('p', { class: 'muted' }, 'Eine neue Kondition beendet die bisherige zum Vortag; nichts wird überschrieben.'),
					h('div', { class: 'vk-two' },
						labelled(ui, 'Ab Bestellwert (€)', textIn(ui, 'min_order_value', '', { inputmode: 'decimal' })),
						labelled(ui, 'Rabatt (%)', textIn(ui, 'discount_pct', '', { inputmode: 'decimal' }))),
					h('div', { class: 'vk-two' },
						labelled(ui, 'Zahlungsziel (Tage)', textIn(ui, 'payment_terms_days', '', { inputmode: 'numeric' })),
						labelled(ui, 'Gültig ab', textIn(ui, 'valid_from', today(), { type: 'date' }))),
					labelled(ui, 'Vereinbart durch', textIn(ui, 'agreed_by', ''))
				], 'Festschreiben', function (body) { return post('/kondition', body); }, load);
			} }, 'Kondition festschreiben'));

			return card;
		}

		function link(d) {
			var card = h('div', { class: 'card vk-gap' }, h('h3', null, 'Nachbestell-Link'));

			if (d.unlinked.count) {
				card.appendChild(h('div', { class: 'notice warn' },
					d.unlinked.count + (d.unlinked.count === 1 ? ' Shop-Produkt hat' : ' Shop-Produkte haben') + ' keinen Betriebsartikel und fehlen dem Händler deshalb auf der Nachbestellseite: ' + d.unlinked.names.join(', ') + (d.unlinked.count > d.unlinked.names.length ? ' …' : '')));
			}

			if (fresh) {
				var qr = h('div', { class: 'vk-qr' });
				try {
					var doc = new DOMParser().parseFromString(fresh.qr, 'image/svg+xml');
					if (doc.documentElement && doc.documentElement.nodeName === 'svg') { qr.appendChild(document.importNode(doc.documentElement, true)); }
				} catch (e) { /* ohne QR-Code, die Adresse steht daneben */ }

				card.appendChild(h('div', { class: 'notice vk-fresh' },
					h('b', null, 'Neuer Link – jetzt weitergeben:'),
					h('div', { class: 'vk-card' }, qr, h('div', null,
						h('div', null, h('b', null, fresh.name)), h('small', { class: 'muted' }, 'Scannen und nachbestellen'),
						h('code', { class: 'vk-url' }, fresh.readable))),
					h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-top:8px' },
						h('button', { class: 'btn', type: 'button', onclick: function () {
							if (navigator.clipboard) { navigator.clipboard.writeText(fresh.url).then(function () { ui.toast('Link kopiert'); }, function () { ui.toast('Kopieren hat nicht geklappt – bitte den Link von Hand markieren.', true); }); }
						} }, 'Link kopieren'),
						h('button', { class: 'btn', type: 'button', onclick: function () { window.print(); } }, 'Karte drucken')),
					h('small', { class: 'muted' }, 'Er wird nicht erneut angezeigt. Gespeichert ist nur sein Hash.')));
			}

			if (d.link.state === 'active') {
				card.appendChild(h('p', null, 'Aktiver Link vorhanden, gültig bis ' + ymd(d.link.expires) + ' · ' + d.link.uses + ' Aufrufe'));
				card.appendChild(h('p', { class: 'muted' }, 'Die Adresse wird aus Sicherheitsgründen nur bei der Erzeugung angezeigt. Für einen neuen Link den alten ersetzen.'));
			} else {
				card.appendChild(h('p', { class: 'muted' }, 'Kein aktiver Link.'));
			}

			var actions = h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' });
			actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function (e) {
				if (d.link.state === 'active' && !window.confirm('Der bisherige Link wird dadurch ungültig. Neuen Link erzeugen?')) { return; }
				e.target.disabled = true;
				post('/link', {}).then(function (res) { fresh = res; ui.toast(res.message); load(); }).catch(function (er) { e.target.disabled = false; ui.toast(er.message, true); });
			} }, d.link.state === 'active' ? 'Link ersetzen' : 'Neuen Link erzeugen'));

			if (d.link.state === 'active') {
				actions.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (e) {
					if (!window.confirm('Link widerrufen? Der Händler kann damit nicht mehr bestellen.')) { return; }
					e.target.disabled = true;
					post('/link', { revoke: true }).then(function (res) { fresh = null; ui.toast(res.message); load(); }).catch(function (er) { e.target.disabled = false; ui.toast(er.message, true); });
				} }, 'Link widerrufen'));
			}

			card.appendChild(actions);
			return card;
		}

		function orderRow(o) {
			var tags = h('div', { class: 'row-tags' },
				h('span', { class: 'tag' + (o.status === 'bestaetigt' ? ' now' : (o.status === 'entwurf' ? ' later' : '')) }, o.label),
				h('span', { class: 'tag' + (o.overdue ? ' later' : '') }, o.overdue ? 'Rechnung überfällig' : o.ilabel));
			var main = h('div', { class: 'row-main' },
				h('b', null, o.wc ? 'Shop-Bestellung #' + o.wc : 'Auftrag #' + o.id),
				h('small', null, ymd(o.date) + (o.due && o.invoice === 'berechnet' ? ' · fällig ' + ymd(o.due) : '')));
			var num = h('div', { class: 'row-num' }, ui.money(o.value, 'EUR'));

			return o.wc && ui.can('verkauf.versand')
				? h('a', { class: 'row-card vk-row', href: '#/bestellung/' + o.wc }, main, num, tags)
				: h('div', { class: 'row-card' }, main, num, tags);
		}

		function orders(d) {
			var card = h('div', { class: 'card vk-gap' }, h('h3', null, 'Bestellungen'));
			card.appendChild(d.orders.length ? h('div', { class: 'rows' }, d.orders.map(orderRow)) : h('p', { class: 'muted' }, 'Noch keine Bestellungen.'));
			if (d.tiles.orders > d.orders.length) { card.appendChild(h('p', { class: 'muted' }, 'Gezeigt sind die letzten ' + d.orders.length + ' von ' + d.tiles.orders + '.')); }
			return card;
		}

		function openItems(d) {
			return h('div', { class: 'card vk-gap' }, h('h3', null, 'Offene Posten'), h('div', { class: 'rows' }, d.open.map(orderRow)));
		}

		function commission(d, classic) {
			var card = h('div', { class: 'card vk-gap' }, h('h3', null, 'Kommissionsbestand'));

			if (d.commission.length) {
				card.appendChild(h('div', { class: 'rows' }, d.commission.map(function (k) {
					return h('div', { class: 'row-card' },
						h('div', { class: 'row-main' }, h('b', null, k.sku || 'Artikel'), h('small', null, k.color)),
						h('div', { class: 'row-num' }, ui.num(k.qty) + ' Stk.'),
						h('div', { class: 'row-tags' }, h('span', { class: 'tag' + (k.age === 'gruen' ? ' now' : (k.age === 'gelb' ? '' : ' later')) }, k.days + ' Tage')));
				})));
				card.appendChild(h('p', { class: 'muted' }, 'Ware, die seit über sechs Monaten liegt, ist ein Signal – entweder falscher Artikel oder falscher Händler.'));
			} else {
				card.appendChild(h('p', { class: 'muted' }, 'Keine Kommissionsware.'));
			}

			if (ui.can('lager.buchen')) {
				var acts = h('div', { class: 'vk-actions', style: 'margin-top:10px;flex-wrap:wrap' });
				[['kommission_raus', 'Ausliefern', true], ['kommission_abgerechnet', 'Abrechnen', false], ['kommission_retour', 'Zurücknehmen', false]].forEach(function (a) {
					acts.appendChild(h('button', { class: 'btn' + (a[2] ? ' primary' : ''), type: 'button', onclick: function () { commissionDialog(a[0], a[1]); } }, a[1]));
				});
				card.appendChild(acts);
			}
			return card;
		}

		// Kommissionsliste: eine Menge je Artikel und Farbe; leere Felder zählen nicht.
		function commissionDialog(type, title) {
			var body = h('div', { class: 'vk-dlg' }, loading(ui));
			var d = ui.dlg('Kommission ' + title.toLowerCase(), body, true);

			ui.api('kontakte/' + id + '/kommission').then(function (o) {
				var list = type === 'kommission_raus' ? o.stock : o.held;
				var inputs = [];
				var err = h('div', { class: 'notice warn', hidden: true, role: 'alert' });
				var btn = h('button', { class: 'btn primary', type: 'submit' }, title);
				var search = h('input', { class: 'field', type: 'search', placeholder: 'Artikel suchen …', 'aria-label': 'Artikel suchen' });
				var rows = h('div', { class: 'rows' });

				function draw() {
					var q = search.value.trim().toLowerCase();
					Array.prototype.forEach.call(rows.children, function (r) { r.hidden = q && r.getAttribute('data-q').indexOf(q) < 0; });
				}
				search.addEventListener('input', draw);

				list.forEach(function (x) {
					var input = h('input', { class: 'field', type: 'text', inputmode: 'decimal', placeholder: '0', 'aria-label': 'Menge ' + x.sku + ' ' + x.label, style: 'max-width:90px;text-align:right' });
					inputs.push({ input: input, x: x });
					rows.appendChild(h('div', { class: 'row-card', 'data-q': (x.sku + ' ' + x.name + ' ' + x.label).toLowerCase(), style: 'grid-template-columns:minmax(0,1fr) auto auto' },
						h('div', { class: 'row-main' }, h('b', null, x.sku + ' – ' + x.name), h('small', null, x.label)),
						h('div', { class: 'muted' }, ui.num(x.qty) + (type === 'kommission_raus' ? ' im Lager' : ' beim Händler')),
						input));
				});

				var form = h('form', { class: 'vk-form' },
					type === 'kommission_raus' && o.allowed === false ? h('div', { class: 'notice warn' }, 'Für diesen Händler ist laut Profil keine Kommission vereinbart. Bitte vor dem Ausliefern klären.') : null,
					type === 'kommission_abgerechnet' ? h('p', { class: 'muted' }, 'Abrechnen bucht die Ware aus dem Kommissionsbestand aus und erzeugt eine Rechnung über alle Positionen zusammen, damit die Rabattstaffel auf den Gesamtwert greift.') : null,
					list.length ? search : null,
					list.length ? rows : h('p', { class: 'muted' }, type === 'kommission_raus' ? 'Im Lager liegt nichts, was sich ausliefern ließe.' : 'Beim Händler liegt keine Kommissionsware.'),
					err,
					h('div', { class: 'vk-actions' }, btn));

				form.addEventListener('submit', function (e) {
					e.preventDefault();
					var chosen = inputs.filter(function (i) { return i.input.value.trim() !== ''; }).map(function (i) { return { article_id: i.x.article_id, color: i.x.color, qty: i.input.value.trim() }; });
					if (!chosen.length) { err.textContent = 'Trag bei mindestens einem Artikel eine Menge ein.'; err.hidden = false; return; }
					btn.disabled = true;
					err.hidden = true;
					post('/kommission', { type: type, rows: chosen }).then(function (r) { d.close(); ui.toast(r.message); load(); }).catch(function (er) { btn.disabled = false; err.textContent = er.message; err.hidden = false; });
				});

				body.textContent = '';
				body.appendChild(form);
			}).catch(function (e) { errBox(ui, body, e); });
		}

		function activities(d) {
			var types = d.activity_types;
			var card = h('div', { class: 'card vk-gap' }, h('h3', null, 'Aktivitäten'));

			card.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () {
				formDialog(ui, 'Aktivität erfassen', [
					h('p', { class: 'muted' }, 'Ohne Wiedervorlage wird die Aktivität abgelehnt – ein Gespräch ohne nächsten Termin versandet.'),
					h('div', { class: 'vk-two' },
						labelled(ui, 'Datum', textIn(ui, 'activity_date', today(), { type: 'date' })),
						labelled(ui, 'Art', selectIn(ui, 'activity_type', types, 'anruf'))),
					labelled(ui, 'Gesprächsnotiz', h('textarea', { class: 'field', name: 'summary', rows: '3' })),
					labelled(ui, 'Nächster Schritt', textIn(ui, 'next_step', '')),
					labelled(ui, 'Wiedervorlage (Pflicht) *', textIn(ui, 'follow_up_on', '', { type: 'date', required: true }))
				], 'Erfassen', function (body) { return post('/aktivitaet', body); }, load);
			} }, 'Aktivität erfassen'));

			if (!d.activities.length) {
				card.appendChild(h('p', { class: 'muted' }, 'Noch keine Aktivitäten.'));
				return card;
			}

			card.appendChild(h('div', { class: 'timeline vk-gap' }, d.activities.map(function (a) {
				return h('div', { class: 'row-card' },
					h('div', { class: 'row-main' },
						h('b', null, (types[a.type] || a.type) + ' · ' + ymd(a.date)),
						h('small', null, a.note || '–'),
						a.next ? h('small', null, 'Nächster Schritt: ' + a.next) : null),
					h('div', { class: 'row-tags' }, a.follow ? h('span', { class: 'tag' }, 'Wiedervorlage ' + ymd(a.follow)) : null));
			})));
			return card;
		}

		load();
	};

	/* ====================================================================
	 * Shop-Bestellungen
	 * ================================================================== */

	function wooPath(item, id) {
		return item.path && item.path.indexOf('post_type=shop_order') > -1
			? 'post.php?post=' + id + '&action=edit'
			: 'admin.php?page=wc-orders&action=edit&id=' + id;
	}

	UI.pages['shop-bestellungen'] = function (item, ui) {
		var h = ui.h;
		var f = { status: '', q: '', page: 1 };

		ui.pageHead('Shop-Bestellungen', 'Was im Shop bestellt wurde. Bearbeiten und Erstatten bleibt in WooCommerce.',
			item.path ? h('a', { class: 'btn', href: ui.config.adminUrl + item.path, target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'WooCommerce öffnen') : null);

		if (item.locked) {
			ui.view().appendChild(h('div', { class: 'notice warn' }, h('b', null, item.title), h('p', { style: 'margin:6px 0 0' }, item.locked)));
			return;
		}

		var chips = h('div', { class: 'presets' });
		var search = h('input', { class: 'field', type: 'search', placeholder: 'Suchen: Nummer, Name, E-Mail …', 'aria-label': 'Bestellungen suchen' });
		var box = h('div');
		ui.view().appendChild(h('div', { class: 'vk-filter' }, search));
		ui.view().appendChild(chips);
		ui.view().appendChild(box);

		var statuses = null;
		var counts = {};

		function drawChips() {
			chips.textContent = '';
			var all = [{ key: '', label: 'Alle' }].concat(statuses || []);
			all.forEach(function (s) {
				var n = s.key && counts[s.key] ? ' (' + counts[s.key] + ')' : '';
				if (s.key && !counts[s.key] && f.status !== s.key) { return; }
				chips.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': f.status === s.key ? 'true' : 'false', onclick: function () { f.status = s.key; f.page = 1; load(); } }, s.label + n));
			});
		}

		function load() {
			box.textContent = '';
			box.appendChild(loading(ui));
			var p = ['seite=' + f.page];
			if (f.status) { p.push('status=' + encodeURIComponent(f.status)); }
			if (f.q) { p.push('q=' + encodeURIComponent(f.q)); }
			ui.api('shop-bestellungen?' + p.join('&')).then(function (d) {
				statuses = d.statuses;
				counts = d.counts;
				drawChips();
				paint(d);
			}).catch(function (e) { errBox(ui, box, e); });
		}

		search.addEventListener('input', debounce(function () { f.q = search.value.trim(); f.page = 1; load(); }, 300));

		function row(o) {
			var who = o.customer || o.email || 'Gast';
			var tags = h('div', { class: 'row-tags' },
				h('span', { class: 'tag ' + (o.status === 'processing' || o.status === 'on-hold' ? 'later' : (o.status === 'completed' ? 'now' : '')) }, o.label),
				h('span', { class: 'tag' + (o.pay === 'paid' ? ' now' : (o.pay === 'open' || o.pay === 'failed' ? ' later' : '')) }, o.paylabel));
			var main = h('div', { class: 'row-main' },
				h('b', null, '#' + o.number + ' · ' + who),
				h('small', null, [ui.when(o.ts), o.company, o.items + (o.items === 1 ? ' Stück' : ' Stück'), o.method].filter(Boolean).join(' · ')));
			var actions = h('div', { class: 'vk-actions' },
				d_can(o) ? h('a', { class: 'btn', href: '#/bestellung/' + o.id }, o.ship ? 'Versand' : 'Akte') : null,
				h('a', { class: 'btn', href: '#/woo/' + o.id }, 'In WooCommerce öffnen'));
			return h('div', { class: 'row-card vk-shop' }, main,
				h('div', { class: 'row-num' }, ui.money(o.total, o.currency || 'EUR'), o.refunded > 0 ? h('small', { class: 'muted' }, ' −' + ui.money(o.refunded, o.currency || 'EUR')) : null),
				tags, actions);
		}

		var canShip = false;
		function d_can() { return canShip; }

		function paint(d) {
			canShip = d.can_ship;
			box.textContent = '';
			if (!d.orders.length) { box.appendChild(h('div', { class: 'notice' }, f.q || f.status ? 'Keine Bestellung passt zu dieser Auswahl.' : 'Noch keine Bestellungen im Shop.')); return; }
			box.appendChild(h('p', { class: 'muted vk-count' }, d.total + (d.total === 1 ? ' Bestellung' : ' Bestellungen')));
			box.appendChild(h('div', { class: 'rows' }, d.orders.map(row)));

			if (d.pages > 1) {
				box.appendChild(h('div', { class: 'vk-actions vk-pager' },
					h('button', { class: 'btn', type: 'button', disabled: d.page <= 1, onclick: function () { f.page = d.page - 1; load(); } }, '‹ Zurück'),
					h('span', { class: 'muted' }, 'Seite ' + d.page + ' von ' + d.pages),
					h('button', { class: 'btn', type: 'button', disabled: d.page >= d.pages, onclick: function () { f.page = d.page + 1; load(); } }, 'Weiter ›')));
			}
		}

		load();
	};

	// Eine WooCommerce-Bestellung eingebettet öffnen: #/woo/<Bestell-ID>
	UI.routes['woo'] = function (r, ui) {
		var h = ui.h;
		var id = parseInt(r.id, 10) || 0;
		var f = ui.findItem('shop-bestellungen');

		ui.section('shop-bestellungen');
		ui.setHead('Bestellung in WooCommerce', 'Verkauf');
		ui.view().appendChild(backLink(ui, '#/seite/shop-bestellungen', 'Alle Shop-Bestellungen'));

		if (!f || f.item.locked || !id) {
			ui.view().appendChild(h('div', { class: 'notice warn' }, f && f.item.locked ? f.item.locked : 'Diese Bestellung lässt sich hier nicht öffnen.'));
			return;
		}

		ui.view().classList.add('wide');
		var path = wooPath(f.item, id);
		var src = ui.config.adminUrl + path + '&lw16_embed=1';
		var wait = h('div', { class: 'frame-loading' }, 'Wird geladen …');
		var frame = h('iframe', { src: src, title: 'Bestellung #' + id, loading: 'eager', onload: function () { wait.classList.add('hide'); } });

		ui.view().appendChild(h('div', { class: 'frame-wrap' },
			h('div', { class: 'frame-bar' },
				h('span', { class: 'grow' }, 'Bestellung #' + id + ' in WooCommerce'),
				h('a', { class: 'btn', href: ui.config.adminUrl + path, target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'Eigener Tab')),
			h('div', { class: 'frame-box' }, frame, wait)));
	};

	/* ====================================================================
	 * Loyalty
	 * ================================================================== */

	UI.pages['loyalty'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Loyalty', 'Empfehlungen prüfen, Guthaben und Punkte der Kunden ansehen.', ui.classicBtn(item));

		var tiles = h('div', { class: 'grid tiles' });
		var queue = h('div');
		var members = h('div', { class: 'card vk-gap' });
		ui.view().appendChild(tiles);
		ui.view().appendChild(queue);
		ui.view().appendChild(members);

		function load() {
			queue.textContent = '';
			queue.appendChild(loading(ui));
			ui.api('loyalty').then(function (d) {
				tiles.textContent = '';
				tiles.appendChild(tile(ui, 'In Prüfung', String(d.queue.length), d.queue.length ? 'wartet auf dich' : ''));
				['pending', 'completed', 'reversed'].forEach(function (k) {
					tiles.appendChild(tile(ui, d.sums[k].label, String(d.sums[k].count), ui.money(d.sums[k].sum, 'EUR')));
				});
				paintQueue(d);
			}).catch(function (e) { errBox(ui, queue, e); });
		}

		function decide(q, decision, btns) {
			var ask = decision === 'approve'
				? 'Empfehlung freigeben? Sie läuft dann in die normale Wartezeit und wird danach gutgeschrieben.'
				: 'Empfehlung ablehnen? Die Prämie wird nicht ausgezahlt.';
			if (!window.confirm(ask)) { return; }
			btns.forEach(function (b) { b.disabled = true; });
			ui.api('loyalty/pruefung/' + q.id, { method: 'POST', body: { decision: decision } })
				.then(function (r) { ui.toast(r.message); load(); })
				.catch(function (e) { btns.forEach(function (b) { b.disabled = false; }); ui.toast(e.message, true); load(); });
		}

		function paintQueue(d) {
			queue.textContent = '';
			var card = h('div', { class: 'card vk-gap' }, h('h3', null, 'Prüf-Warteschlange'));
			queue.appendChild(card);

			if (!d.queue.length) { card.appendChild(h('p', { class: 'muted' }, 'Keine offenen Prüffälle.')); return; }

			card.appendChild(h('div', { class: 'rows' }, d.queue.map(function (q) {
				var ok = h('button', { class: 'btn primary', type: 'button' }, 'Freigeben');
				var no = h('button', { class: 'btn', type: 'button' }, 'Ablehnen');
				ok.addEventListener('click', function () { decide(q, 'approve', [ok, no]); });
				no.addEventListener('click', function () { decide(q, 'reject', [ok, no]); });
				return h('div', { class: 'row-card vk-shop' },
					h('div', { class: 'row-main' }, h('b', null, q.referrer + ' wirbt Bestellung #' + q.order),
						h('small', null, q.date + (q.reason ? ' · Grund: ' + q.reason : ''))),
					h('div', { class: 'row-num' }, ui.money(q.reward, 'EUR')),
					h('div', { class: 'vk-actions' }, ok, no));
			})));
		}

		// Kundensuche.
		var search = h('input', { class: 'field', type: 'search', placeholder: 'Kunde suchen: Name oder E-Mail (mind. 2 Zeichen)', 'aria-label': 'Kunde suchen' });
		var result = h('div');
		members.appendChild(h('h3', null, 'Kundenkonto ansehen'));
		members.appendChild(search);
		members.appendChild(result);

		search.addEventListener('input', debounce(function () {
			var q = search.value.trim();
			result.textContent = '';
			if (q.length < 2) { return; }
			ui.api('loyalty/kunden?q=' + encodeURIComponent(q)).then(function (d) {
				result.textContent = '';
				if (!d.members.length) { result.appendChild(h('p', { class: 'muted' }, 'Kein Kundenkonto gefunden.')); return; }
				result.appendChild(h('div', { class: 'rows' }, d.members.map(function (m) {
					return h('button', { class: 'row-card vk-row vk-btnrow', type: 'button', onclick: function () { member(m.id); } },
						h('div', { class: 'row-main' }, h('b', null, m.name), h('small', null, m.email)),
						h('div', { class: 'row-num' }, ui.money(m.credit, 'EUR')),
						h('div', { class: 'row-tags' }, h('span', { class: 'tag' }, ui.num(m.points) + ' Punkte')));
				})));
			}).catch(function (e) { errBox(ui, result, e); });
		}, 300));

		function member(id) {
			ui.api('loyalty/kunden/' + id).then(function (m) {
				var body = h('div', { class: 'vk-dlg' },
					h('div', { class: 'grid tiles' }, tile(ui, 'Guthaben', ui.money(m.credit, 'EUR')), tile(ui, 'Punkte', ui.num(m.points))),
					kv(ui, 'E-Mail', m.email), kv(ui, 'Empfehlungscode', m.code),
					h('h3', null, 'Punkteverlauf'),
					m.ledger.length ? h('div', { class: 'rows' }, m.ledger.map(function (l) {
						return h('div', { class: 'row-card' }, h('div', { class: 'row-main' }, h('b', null, (l.points > 0 ? '+' : '') + l.points + ' Punkte'), h('small', null, l.date + ' · ' + l.label + (l.order ? ' · Bestellung #' + l.order : ''))));
					})) : h('p', { class: 'muted' }, 'Noch keine Punkte.'),
					m.ledger_all > m.ledger.length ? h('p', { class: 'muted' }, 'Gezeigt sind die letzten ' + m.ledger.length + ' von ' + m.ledger_all + '.') : null,
					h('h3', null, 'Empfehlungen'),
					m.referrals.length ? h('div', { class: 'rows' }, m.referrals.map(function (r) {
						return h('div', { class: 'row-card' }, h('div', { class: 'row-main' }, h('b', null, 'Bestellung #' + r.order), h('small', null, r.date + ' · ' + r.label)), h('div', { class: 'row-num' }, ui.money(r.reward, 'EUR')));
					})) : h('p', { class: 'muted' }, 'Noch keine Empfehlungen.'));
				ui.dlg(m.name, body, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		load();
	};

	/* ====================================================================
	 * Marketing (nur ansehen; Anlegen und Belege zuordnen in der klassischen Ansicht)
	 * ================================================================== */

	var AMPEL = { im_rahmen: 'now', knapp: '', ueberzogen: 'later' };

	UI.pages['marketing'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Marketing', 'Kampagnen mit Budget, Kosten und Umsatz. Neu anlegen und Belege zuordnen geht in der klassischen Ansicht.', ui.classicBtn(item));
		var box = h('div');
		ui.view().appendChild(box);
		box.appendChild(loading(ui));

		ui.api('marketing').then(function (d) {
			box.textContent = '';
			if (!d.campaigns.length) {
				box.appendChild(h('div', { class: 'notice' }, 'Noch keine Kampagne angelegt. Ein Marktbesuch, eine Anzeigenschaltung oder eine Flyeraktion ist jeweils eine Kampagne – sie bekommt eine Kostenstelle, auf die alle zugehörigen Belege gebucht werden.'));
				return;
			}
			box.appendChild(h('div', { class: 'rows' }, d.campaigns.map(function (k) {
				return h('a', { class: 'row-card vk-row', href: '#/kampagne/' + k.id },
					h('div', { class: 'row-main' }, h('b', null, k.name), h('small', null, [k.kst, k.type, k.period].filter(Boolean).join(' · '))),
					h('div', { class: 'row-num' }, ui.money(k.costs, 'EUR')),
					h('div', { class: 'row-tags' },
						h('span', { class: 'tag' }, k.status),
						k.ampel === 'kein_budget'
							? h('span', { class: 'tag' }, 'kein Budget gesetzt')
							: h('span', { class: 'tag ' + (AMPEL[k.ampel] || '') }, pct(k.usage) + ' von ' + ui.money(k.budget, 'EUR'))));
			})));
			box.appendChild(h('p', { class: 'muted' }, 'Die Summe zeigt die gebuchten Kosten aus Belegen. Gewährte Rabatte sind keine Betriebsausgabe und stehen deshalb nicht darin – den vollständigen Einsatz zeigt die Einzelansicht.'));
		}).catch(function (e) { errBox(ui, box, e); });
	};

	UI.routes['kampagne'] = function (r, ui) {
		var h = ui.h;
		var id = parseInt(r.id, 10) || 0;
		ui.section('marketing');
		ui.setHead('Kampagne', 'Verkauf');
		var box = h('div');
		ui.view().appendChild(backLink(ui, '#/seite/marketing', 'Alle Kampagnen'));
		ui.view().appendChild(box);
		box.appendChild(loading(ui));

		ui.api('marketing/' + id).then(function (k) {
			box.textContent = '';
			ui.setHead(k.name, 'Marketing');
			var classic = ui.config.adminUrl + 'admin.php?page=lw16-marketing&view=detail&id=' + id;
			box.appendChild(h('div', { class: 'page-head' },
				h('div', null, h('h2', null, k.kst + ' – ' + k.name), h('p', { class: 'muted', style: 'margin:4px 0 0' }, [k.type, k.period, k.status].filter(Boolean).join(' · '))),
				h('a', { class: 'btn', href: classic, target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'Bearbeiten (klassische Ansicht)')));

			box.appendChild(h('div', { class: 'grid tiles' },
				tile(ui, 'Einsatz gesamt', ui.money(k.effort, 'EUR'), k.rebate > 0 ? 'inkl. ' + ui.money(k.rebate, 'EUR') + ' Rabatt' : ''),
				tile(ui, 'Umsatz gesamt', ui.money(k.revenue, 'EUR')),
				tile(ui, 'Ergebnis', ui.money(k.result, 'EUR'), 'Umsatz − Einsatz'),
				tile(ui, 'Umsatz je Euro Einsatz', k.roas === null ? '–' : String(Math.round(k.roas * 100) / 100).replace('.', ','))));

			box.appendChild(h('div', { class: 'card vk-gap' }, h('h3', null, 'Zahlen im Einzelnen'),
				kv(ui, 'Kosten (gebucht)', ui.money(k.costs, 'EUR')),
				kv(ui, 'Rabatt (nicht gebucht)', ui.money(k.rebate, 'EUR')),
				kv(ui, 'Budget', k.budget.ampel === 'kein_budget' ? 'kein Budget gesetzt' : ui.money(k.budget.plan, 'EUR') + ' · ' + pct(k.budget.usage) + ' genutzt · ' + (k.budget.rest < 0 ? ui.money(-k.budget.rest, 'EUR') + ' über Budget' : ui.money(k.budget.rest, 'EUR') + ' frei')),
				kv(ui, 'Umsatz Marktkasse', ui.money(k.market, 'EUR')),
				kv(ui, 'Umsatz Shop (nachgewiesen)', ui.money(k.shop.sum, 'EUR') + (k.shop.count ? ' · ' + k.shop.count + ' Bestellungen (' + k.shop.utm + ' über Kampagnenkennung, ' + k.shop.voucher + ' über Gutschein)' : '')),
				kv(ui, 'Einsatz je Bestellung', k.cpo === null ? '–' : ui.money(k.cpo, 'EUR'))));

			box.appendChild(h('p', { class: 'muted' }, 'Umsatz ausgewertet von ' + ymd(k.from) + ' bis ' + ymd(k.to) + ' (inklusive Nachlauffenster). Der Rohertrag lässt den Wareneinsatz außen vor – der hängt am Artikel, nicht an der Kampagne. Das Budget misst sich nur an den gebuchten Kosten.'));
		}).catch(function (e) { errBox(ui, box, e); });
	};
})();
