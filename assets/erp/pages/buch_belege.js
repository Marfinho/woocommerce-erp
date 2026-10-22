/*
 * ERP Buchhaltung, Reiter „Belege“ und die Beleg-Erfassung per Kamera oder Datei.
 * Server: includes/Erp/Pages/BuchBelege.php (Routen unter erp/buch/belege).
 *
 * - UI.buchTabs['belege']: Liste mit Filtern, Detail, Festschreiben, Stornieren.
 * - UI.belegCapture({ mode: 'camera'|'upload'|'choose', campaignId: 0, onSaved: function (beleg) {} })
 *   Dialog zum Erfassen. Wird auch vom Marketing benutzt: Name und Optionen nicht ändern.
 *
 * Alle Daten gelangen über textContent bzw. setAttribute in die Seite, nie als HTML-Text.
 * Klassisches ES5, kein Modulsystem.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.buchTabs = UI.buchTabs || {};

	var MAX_EDGE = 2000;
	var OPT_CACHE = null;

	function H() { return UI.h; }

	/* ---------- Hilfen ---------- */

	function eur(v) { return v === null || v === undefined ? '—' : UI.money(v, 'EUR'); }

	function fmtDate(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
		return m ? m[3] + '.' + m[2] + '.' + m[1] : (s || '');
	}

	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
	}

	function sizeText(b) {
		b = Number(b) || 0;
		return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
	}

	function httpText(status) {
		if (status === 401) { return 'Du bist nicht mehr angemeldet – bitte neu anmelden.'; }
		if (status === 403) { return 'Dafür fehlt dir die Berechtigung.'; }
		if (status === 404) { return 'Das gibt es nicht (mehr) – bitte die Seite neu laden.'; }
		if (status === 413) { return 'Die Datei ist zu groß. Bitte ein kleineres Foto oder PDF wählen.'; }
		if (status >= 500) { return 'Auf dem Server ist etwas schiefgegangen. Bitte gleich noch einmal versuchen.'; }
		return 'Das hat nicht geklappt.';
	}

	/*
	 * Anfrage an erp/<pfad>. body: Objekt (JSON), FormData (Datei-Upload) oder nichts.
	 * Fehler tragen status und data (Antwort des Servers), damit etwa die Doppelbeleg-Warnung
	 * mit ihrer Liste ankommt. Ohne Antwort des Servers: Hinweis auf die fehlende Verbindung.
	 */
	function rq(path, method, body) {
		var C = UI.config || {};
		var headers = { 'X-WP-Nonce': C.nonce };
		var isForm = typeof FormData !== 'undefined' && body instanceof FormData;
		if (body && !isForm) { headers['Content-Type'] = 'application/json'; }

		return fetch(C.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: body ? (isForm ? body : JSON.stringify(body)) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok) {
					var msg = json && json.code === 'rest_cookie_invalid_nonce'
						? 'Die Sitzung ist abgelaufen – bitte die Seite neu laden. Deine Eingaben gehen dabei verloren, das Foto bitte vorher sichern.'
						: (json && (json.message || json.error)) || httpText(res.status);
					var err = new Error(msg);
					err.status = res.status;
					err.data = json || {};
					throw err;
				}
				return json;
			});
		}, function () {
			var e = new Error('Keine Verbindung zum Server. Deine Eingaben und das Foto bleiben hier erhalten – bitte noch einmal versuchen, sobald du wieder Netz hast.');
			e.offline = true;
			e.data = {};
			throw e;
		});
	}

	function qs(o) {
		return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] !== null && o[k] !== undefined; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
	}

	function loadOptions() {
		if (OPT_CACHE) { return Promise.resolve(OPT_CACHE); }
		return rq('buch/belege/optionen').then(function (o) { OPT_CACHE = o; return o; });
	}

	function canWrite(ctx) {
		if (ctx && typeof ctx.canWrite === 'function') { return !!ctx.canWrite(); }
		if (ctx && typeof ctx.canWrite === 'boolean') { return ctx.canWrite; }
		return typeof UI.can === 'function' ? !!UI.can('finanzen.buchhaltung') : true;
	}

	function statusTag(s) {
		var h = H();
		var t = { storniert: 'storniert', festgeschrieben: 'festgeschrieben', offen: 'offen' }[s] || s;
		return h('span', { class: 'tag ' + (s === 'festgeschrieben' ? 'now' : (s === 'offen' ? 'later' : '')) }, t);
	}

	function notice(text, warn) {
		return H()('div', { class: 'notice' + (warn ? ' warn' : ''), role: warn ? 'alert' : 'status' }, text);
	}

	function option(value, label, selected) {
		return H()('option', { value: value, selected: selected ? true : null }, label);
	}

	function kontoSelect(opt, current) {
		var h = H();
		var sel = h('select', { class: 'field', 'aria-label': 'Kategorie' });
		(opt.konten || []).forEach(function (g) {
			var og = h('optgroup', { label: g.gruppe });
			g.konten.forEach(function (k) { og.appendChild(h('option', { value: k.konto }, k.konto + ' ' + k.label)); });
			sel.appendChild(og);
		});
		sel.value = current || opt.standardkonto;
		return sel;
	}

	function findKonto(opt, nr) {
		var found = null;
		(opt.konten || []).forEach(function (g) { g.konten.forEach(function (k) { if (k.konto === nr) { found = k; } }); });
		return found;
	}

	function zahlSelect(opt, current) {
		var h = H();
		var sel = h('select', { class: 'field', 'aria-label': 'Zahlungsart' }, h('option', { value: '' }, '– keine Angabe –'));
		(opt.zahlungsarten || []).forEach(function (z) { sel.appendChild(h('option', { value: z.key }, z.label)); });
		sel.value = current || '';
		return sel;
	}

	function mwstSelect(current) {
		var h = H();
		var sel = h('select', { class: 'field', 'aria-label': 'MwSt-Satz' },
			h('option', { value: '' }, '– keine Angabe –'), h('option', { value: '19' }, '19 %'), h('option', { value: '7' }, '7 %'), h('option', { value: '0' }, '0 %'));
		sel.value = current || '';
		return sel;
	}

	function kampagneSelect(opt, current) {
		var h = H();
		var sel = h('select', { class: 'field', 'aria-label': 'Kostenstelle' }, h('option', { value: '0' }, '– keine –'));
		var seen = false;
		(opt.kampagnen || []).forEach(function (k) {
			if (String(k.id) === String(current)) { seen = true; }
			sel.appendChild(h('option', { value: String(k.id) }, k.label));
		});
		if (current && !seen) { sel.appendChild(h('option', { value: String(current) }, 'Kostenstelle #' + current)); }
		sel.value = current ? String(current) : '0';
		return sel;
	}

	function kontoHint(opt, nr, box) {
		var k = findKonto(opt, nr);
		var text = '';
		if (k) {
			if (k.abzug < 100) { text = 'Achtung: nur ' + k.abzug + ' % dieses Betrags sind abziehbar. '; }
			text += k.hinweis || '';
		}
		box.textContent = text;
	}

	function fld(label, input, extra) {
		var h = H();
		var wrap = h('label', { class: 'bb-f' }, h('span', { class: 'bb-l' }, label), input);
		if (extra) { wrap.appendChild(extra); }
		return wrap;
	}

	function showFieldErrors(map, wraps) {
		Object.keys(wraps).forEach(function (k) {
			var w = wraps[k];
			w.classList.remove('bb-err');
			var old = w.querySelector('.bb-fe');
			if (old) { old.remove(); }
		});
		Object.keys(map || {}).forEach(function (k) {
			var w = wraps[k];
			if (!w) { return; }
			w.classList.add('bb-err');
			if (map[k]) { w.appendChild(H()('span', { class: 'bb-fe' }, map[k])); }
		});
	}

	function dupBox(list, message, onForce) {
		var h = H();
		var ul = h('ul');
		(list || []).forEach(function (d) {
			ul.appendChild(h('li', null, d.nummer + ' · ' + fmtDate(d.datum) + ' · ' + eur(d.betrag) + (d.haendler ? ' · ' + d.haendler : '')));
		});
		var box = h('div', { class: 'notice warn bb-dup', role: 'alert' }, h('b', null, 'Mögliche Doppelerfassung'), h('div', null, message), ul);
		if (onForce) {
			box.appendChild(h('button', { class: 'btn', type: 'button', onclick: onForce }, 'Trotzdem speichern (ist ein anderer Beleg)'));
		}
		return box;
	}

	/* =====================================================================
	 * Reiter „Belege“
	 * =================================================================== */

	UI.buchTabs['belege'] = {
		label: 'Belege',
		order: 10,
		render: function (host, ctx) { renderList(host, ctx || {}); }
	};

	function renderList(host, ctx) {
		var h = H();
		var q = { suche: '', status: '', von: '', bis: '', konto: '', quelle: '', seite: 1 };
		var write = canWrite(ctx);
		var box = h('div');
		host.textContent = '';
		host.appendChild(box);

		function reload() { load(); }

		function load() {
			Promise.all([rq('buch/belege?' + qs(q)), loadOptions()]).then(function (r) {
				paint(r[0], r[1]);
			}).catch(function (e) {
				box.textContent = '';
				box.appendChild(notice(e.message, true));
				box.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: load }, 'Noch einmal laden')));
			});
		}

		function paint(d, opt) {
			box.textContent = '';

			if (write) {
				box.appendChild(h('div', { class: 'bb-head' },
					h('button', { class: 'btn primary', type: 'button', onclick: function () { UI.belegCapture({ mode: 'camera', onSaved: reload }); } }, 'Beleg fotografieren'),
					h('button', { class: 'btn', type: 'button', onclick: function () { UI.belegCapture({ mode: 'upload', onSaved: reload }); } }, 'Datei oder E-Rechnung hochladen'),
					h('button', { class: 'bb-link', type: 'button', onclick: function () { UI.belegCapture({ mode: 'choose', onSaved: reload }); } }, 'Ohne Datei erfassen')));
			}

			if (write && d.offen_anzahl > 0) { box.appendChild(openBanner(d)); }

			var search = h('input', { class: 'field', type: 'search', placeholder: 'Suchen: Händler, Nummer, Betrag, Notiz', value: q.suche, 'aria-label': 'Suchen' });
			function go() { q.suche = search.value; q.seite = 1; load(); }
			search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });

			var chips = h('div', { class: 'presets' });
			[['', 'Alle'], ['offen', 'Offen'], ['festgeschrieben', 'Festgeschrieben'], ['storniert', 'Storniert']].forEach(function (s) {
				chips.appendChild(h('button', { class: 'chip', type: 'button', style: 'min-height:44px', 'aria-pressed': q.status === s[0] ? 'true' : 'false', onclick: function () { q.status = s[0]; q.seite = 1; load(); } }, s[1]));
			});

			var von = h('input', { class: 'field', type: 'date', value: q.von, 'aria-label': 'Von' });
			var bis = h('input', { class: 'field', type: 'date', value: q.bis, 'aria-label': 'Bis' });
			var konto = h('select', { class: 'field', 'aria-label': 'Kategorie' }, h('option', { value: '' }, 'Alle Kategorien'));
			(opt.konten || []).forEach(function (g) {
				var og = h('optgroup', { label: g.gruppe });
				g.konten.forEach(function (k) { og.appendChild(h('option', { value: k.konto }, k.konto + ' ' + k.label)); });
				konto.appendChild(og);
			});
			konto.value = q.konto;
			var quelle = h('select', { class: 'field', 'aria-label': 'Quelle' }, h('option', { value: '' }, 'Alle Quellen'),
				h('option', { value: 'manuell' }, 'manuell'), h('option', { value: 'erechnung' }, 'E-Rechnung'), h('option', { value: 'wareneingang' }, 'Wareneingang'), h('option', { value: 'storno' }, 'Storno'));
			quelle.value = q.quelle;

			var more = h('details', { class: 'bb-more' }, h('summary', null, 'Zeitraum, Kategorie, Quelle'),
				h('div', { class: 'bb-grid' }, fld('Von', von), fld('Bis', bis), fld('Kategorie', konto), fld('Quelle', quelle),
					h('button', { class: 'btn primary', type: 'button', style: 'min-height:46px;align-self:end', onclick: function () {
						q.von = von.value; q.bis = bis.value; q.konto = konto.value; q.quelle = quelle.value; q.suche = search.value; q.seite = 1; load();
					} }, 'Filter anwenden')));
			if (q.von || q.bis || q.konto || q.quelle) { more.open = true; }

			box.appendChild(h('div', { class: 'bb-filter' },
				h('div', { class: 'bb-search' }, search, h('button', { class: 'btn primary', type: 'button', style: 'min-height:46px', onclick: go }, 'Suchen')),
				chips, more));

			box.appendChild(h('div', { class: 'bb-sum' }, d.gesamt + (d.gesamt === 1 ? ' Beleg' : ' Belege') + ' · Summe ohne Stornos ' + eur(d.summe)));

			if (!d.rows.length) {
				box.appendChild(h('div', { class: 'card' }, h('p', { class: 'muted' }, (q.suche || q.status || q.von || q.bis || q.konto || q.quelle)
					? 'Zu diesem Filter gibt es keine Belege. Probier einen weiteren Zeitraum oder lösche die Suche.'
					: 'Noch keine Belege. Mit „Beleg fotografieren“ legst du den ersten an.')));
			} else {
				var list = h('div', { class: 'bb-list' });
				d.rows.forEach(function (b) { list.appendChild(rowCard(b)); });
				box.appendChild(list);
			}

			if (d.seiten > 1) {
				box.appendChild(h('div', { class: 'bb-pager' },
					h('button', { class: 'btn', type: 'button', disabled: d.seite <= 1, onclick: function () { q.seite = d.seite - 1; load(); } }, 'Zurück'),
					h('span', { class: 'muted' }, 'Seite ' + d.seite + ' von ' + d.seiten),
					h('button', { class: 'btn', type: 'button', disabled: d.seite >= d.seiten, onclick: function () { q.seite = d.seite + 1; load(); } }, 'Weiter')));
			}
		}

		function rowCard(b) {
			return h('button', { class: 'bb-row' + (b.status === 'storniert' ? ' storno' : ''), type: 'button', onclick: function () { openDetail(b.id, ctx, load); } },
				h('div', { class: 't' }, (b.haendler || 'ohne Händler')),
				h('div', { class: 'v' }, eur(b.betrag)),
				h('div', { class: 's' }, b.nummer + ' · ' + fmtDate(b.datum) + ' · ' + b.kategorie + (b.hat_datei ? ' · Foto' : '')),
				h('div', { class: 'g' }, statusTag(b.status)));
		}

		function openBanner(d) {
			var holder = h('div', { class: 'notice warn bb-open' });
			function idle() {
				holder.textContent = '';
				holder.appendChild(h('p', null, d.offen_anzahl + (d.offen_anzahl === 1 ? ' Beleg ist' : ' Belege sind') + ' noch offen (zusammen ' + eur(d.offen_summe) + '). Offene Belege zählen erst nach dem Festschreiben in den Büchern.'));
				holder.appendChild(h('button', { class: 'btn', type: 'button', onclick: ask }, 'Alle offenen festschreiben …'));
			}
			function ask() {
				holder.textContent = '';
				holder.appendChild(h('p', null, 'Wirklich alle ' + d.offen_anzahl + ' offenen Belege festschreiben? Danach lassen sie sich nicht mehr ändern (nur noch stornieren). Bitte vorher prüfen.'));
				var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
					go.disabled = true;
					rq('buch/belege/festschreiben-alle', 'POST', {}).then(function (r) { UI.toast(r.message); load(); })
						.catch(function (e) { go.disabled = false; UI.toast(e.message, true); });
				} }, 'Ja, alle festschreiben');
				holder.appendChild(go);
				holder.appendChild(h('button', { class: 'btn', type: 'button', onclick: idle }, 'Abbrechen'));
			}
			idle();
			return holder;
		}

		load();
	}

	/* =====================================================================
	 * Detail
	 * =================================================================== */

	function kv(rows) {
		var h = H();
		var g = h('div', { class: 'kv' });
		rows.forEach(function (r) {
			if (r[1] === '' || r[1] === null || r[1] === undefined) { return; }
			g.appendChild(h('span', null, r[0]));
			g.appendChild(h('div', null, r[1]));
		});
		return g;
	}

	function openDetail(id, ctx, onChange) {
		var h = H();
		var body = h('div', { class: 'bb-detail' }, h('div', { class: 'bb-busy' }, h('span', { class: 'bb-spin' }), 'Wird geladen …'));
		var dlg = UI.dlg('Beleg', body, true);
		dlg.classList.add('bb-sheet');
		var write = canWrite(ctx);

		function load() {
			rq('buch/belege/' + id).then(paint).catch(function (e) {
				body.textContent = '';
				body.appendChild(notice(e.message, true));
			});
		}

		function changed(msg) {
			if (msg) { UI.toast(msg); }
			if (onChange) { onChange(); }
			load();
		}

		function paint(d) {
			var b = d.beleg;
			body.textContent = '';

			body.appendChild(h('div', { class: 'bb-top' }, h('span', { class: 'nr' }, b.nummer), statusTag(b.status), h('span', { class: 'amt' }, eur(b.betrag))));

			body.appendChild(preview(d.datei));

			var mw = b.mwst_satz !== '' ? b.mwst_satz + ' %' + (b.mwst_betrag !== null && b.mwst_betrag !== undefined ? ' (darin ' + eur(b.mwst_betrag) + ')' : '') : '';
			body.appendChild(h('div', { class: 'bb-box' }, kv([
				['Datum', fmtDate(b.datum)], ['Händler', b.haendler], ['Kategorie', b.kategorie],
				['Zahlungsart', zahlLabel(b.zahlungsart)], ['Beleg-Nr. (extern)', b.externe_nr], ['MwSt', mw],
				['Quelle', b.quelle_label],
				['Erfasst', fmtDate(b.erstellt_am.slice(0, 10)) + (b.erstellt_von ? ' von ' + b.erstellt_von : '')],
				['Festgeschrieben', b.festgeschrieben_am ? fmtDate(b.festgeschrieben_am.slice(0, 10)) : ''],
				['Storno-Grund', b.storno_grund],
				['Storno-Beleg', d.storno_beleg ? d.storno_beleg.nummer : ''],
				['Storno von', d.storno_von ? d.storno_von.nummer : '']
			])));

			if (b.notiz_frei) { body.appendChild(h('div', { class: 'bb-box' }, h('h4', null, 'Notiz'), h('p', { class: 'bb-notiz' }, b.notiz_frei))); }

			if (d.kampagnen.length) {
				var ul = h('ul');
				d.kampagnen.forEach(function (k) { ul.appendChild(h('li', null, k.kostenstelle + ' – ' + k.name + ' (' + String(k.anteil).replace('.', ',') + ' %)')); });
				body.appendChild(h('div', { class: 'bb-box' }, h('h4', null, 'Kostenstellen'), ul));
			}

			if (d.buchungen.length) {
				var bl = h('ul');
				d.buchungen.forEach(function (bu) { bl.appendChild(h('li', null, bu.nummer + ' · ' + fmtDate(bu.datum) + ' · ' + bu.typ + ' · ' + eur(bu.betrag) + (bu.storniert ? ' · storniert' : ''))); });
				body.appendChild(h('div', { class: 'bb-box' }, h('h4', null, 'Buchungen im Journal'), bl));
			} else if (b.status === 'offen') {
				body.appendChild(h('div', { class: 'bb-box muted' }, 'Noch nicht gebucht: Erst das Festschreiben legt die Buchung im Journal an.'));
			}

			var tech = h('details', { class: 'bb-box' }, h('summary', null, 'Technische Angaben (GoBD)'),
				h('p', { class: 'bb-mono' }, 'Prüfsumme Datensatz: ' + b.hash),
				h('p', { class: 'bb-mono' }, 'Vorgänger: ' + (b.vorheriger_hash || '(erster Eintrag)')));
			if (d.datei && d.datei.vorhanden) { tech.appendChild(h('p', { class: 'bb-mono' }, 'Prüfsumme Datei (SHA-256): ' + d.datei.sha256 + ' · ' + sizeText(d.datei.groesse))); }
			body.appendChild(tech);

			if (write) { body.appendChild(actions(d)); }
		}

		function zahlLabel(key) {
			var l = { bar: 'Bar', karte: 'Karte (EC/Kredit)', ueberweisung: 'Überweisung', lastschrift: 'Lastschrift', paypal: 'PayPal', privat: 'Privat ausgelegt' };
			return l[key] || '';
		}

		function preview(f) {
			if (!f) { return h('p', { class: 'muted' }, 'Zu diesem Beleg gibt es keine Datei.'); }
			if (!f.vorhanden) { return notice(f.meldung, true); }
			if (f.art === 'pdf') {
				return h('div', null,
					h('p', null, h('a', { class: 'btn', href: f.url, target: '_blank', rel: 'noopener' }, 'PDF öffnen')),
					h('iframe', { class: 'bb-pdf', src: f.url, title: 'Beleg als PDF' }));
			}
			if (f.art === 'xml') { return h('p', null, h('a', { class: 'btn', href: f.url, target: '_blank', rel: 'noopener' }, 'E-Rechnung (XML) ansehen')); }
			return h('a', { href: f.url, target: '_blank', rel: 'noopener', 'aria-label': 'Foto in voller Größe öffnen' }, h('img', { class: 'bb-img', src: f.url, alt: 'Foto des Belegs' }));
		}

		function actions(d) {
			var b = d.beleg;
			var bar = h('div', { class: 'bb-actions' });
			var panel = h('div');
			var wrap = h('div', { style: 'display:grid;gap:12px' }, bar, panel);

			if (d.erlaubt.aendern) { bar.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { editForm(d, panel); } }, 'Bearbeiten')); }
			else if (d.erlaubt.notiz) { bar.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { noteForm(d, panel); } }, 'Notiz ergänzen')); }
			if (d.erlaubt.festschreiben) { bar.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { freezeAsk(d, panel); } }, 'Festschreiben …')); }
			if (d.erlaubt.stornieren) { bar.appendChild(h('button', { class: 'btn bb-btn-danger', type: 'button', onclick: function () { stornoForm(d, panel); } }, 'Stornieren …')); }
			if (b.status === 'storniert') { wrap.appendChild(h('p', { class: 'muted' }, 'Dieser Beleg ist storniert und bleibt zur Nachvollziehbarkeit erhalten.')); }
			if (b.status === 'festgeschrieben') { wrap.appendChild(h('p', { class: 'muted' }, 'Festgeschrieben: Betrag, Datum, Händler und Datei sind gesichert. Bei einem Fehler bitte stornieren und neu erfassen.')); }
			return wrap;
		}

		function freezeAsk(d, panel) {
			panel.textContent = '';
			var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
				go.disabled = true;
				rq('buch/belege/' + id + '/festschreiben', 'POST', {}).then(function (r) { changed(r.message); })
					.catch(function (e) { go.disabled = false; UI.toast(e.message, true); });
			} }, 'Ja, festschreiben');
			panel.appendChild(h('div', { class: 'bb-panel' },
				h('b', null, 'Beleg ' + d.beleg.nummer + ' festschreiben?'),
				h('span', null, 'Er wird im Journal gebucht und lässt sich danach nicht mehr ändern, nur noch stornieren.'),
				h('div', { class: 'bb-actions' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { panel.textContent = ''; } }, 'Abbrechen'))));
		}

		function stornoForm(d, panel) {
			panel.textContent = '';
			var grund = h('textarea', { class: 'field', rows: '3', placeholder: 'Zum Beispiel: Betrag falsch erfasst, Beleg doppelt', 'aria-label': 'Grund der Stornierung' });
			var err = h('div', { class: 'bb-fe', role: 'alert' });
			var go = h('button', { class: 'btn bb-btn-danger primary', type: 'button', onclick: function () {
				var g = grund.value.trim();
				err.textContent = '';
				if (g.length < 5) { err.textContent = 'Bitte einen Grund angeben (mindestens ein kurzer Satz). Er wird im Protokoll festgehalten.'; grund.focus(); return; }
				go.disabled = true;
				rq('buch/belege/' + id + '/stornieren', 'POST', { grund: g }).then(function (r) { changed(r.message); })
					.catch(function (e) { go.disabled = false; err.textContent = e.message; });
			} }, 'Beleg stornieren');
			panel.appendChild(h('div', { class: 'bb-panel danger' },
				h('b', null, 'Beleg ' + d.beleg.nummer + ' stornieren'),
				h('span', null, 'Es wird ein Gegenbeleg angelegt, nichts wird gelöscht. Das lässt sich nicht rückgängig machen.'),
				h('label', { class: 'bb-f' }, h('span', { class: 'bb-l' }, 'Grund (Pflicht)'), grund), err,
				h('div', { class: 'bb-actions' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { panel.textContent = ''; } }, 'Abbrechen'))));
			grund.focus();
		}

		function noteForm(d, panel) {
			panel.textContent = '';
			var t = h('textarea', { class: 'field', rows: '4', 'aria-label': 'Notiz' });
			t.value = d.beleg.notiz_frei || '';
			var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
				go.disabled = true;
				rq('buch/belege/' + id, 'POST', { notiz: t.value }).then(function (r) { changed(r.message); })
					.catch(function (e) { go.disabled = false; UI.toast(e.message, true); });
			} }, 'Notiz speichern');
			panel.appendChild(h('div', { class: 'bb-panel' }, h('label', { class: 'bb-f' }, h('span', { class: 'bb-l' }, 'Notiz'), t),
				h('div', { class: 'bb-actions' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { panel.textContent = ''; } }, 'Abbrechen'))));
		}

		function editForm(d, panel) {
			panel.textContent = '';
			loadOptions().then(function (opt) {
				var b = d.beleg;
				var konto = kontoSelect(opt, b.konto);
				var khint = h('span', { class: 'bb-hint' });
				kontoHint(opt, konto.value, khint);
				konto.addEventListener('change', function () { kontoHint(opt, konto.value, khint); });
				var zahl = zahlSelect(opt, b.zahlungsart);
				var extern = h('input', { class: 'field', type: 'text', value: b.externe_nr || '', maxlength: '60' });
				var mwst = mwstSelect(b.mwst_satz);
				var kid = d.kampagnen.length ? d.kampagnen[0].id : 0;
				var kamp = kampagneSelect(opt, kid);
				var anteil = h('input', { class: 'field', type: 'number', min: '0', max: '100', step: '0.1', value: d.kampagnen.length ? String(d.kampagnen[0].anteil) : '100', 'aria-label': 'Anteil in Prozent' });
				var notiz = h('textarea', { class: 'field', rows: '3' });
				notiz.value = b.notiz_frei || '';
				var err = h('div', { class: 'bb-fe', role: 'alert' });
				var go = h('button', { class: 'btn primary', type: 'button', onclick: function () {
					go.disabled = true;
					err.textContent = '';
					rq('buch/belege/' + id, 'POST', {
						konto: konto.value, zahlungsart: zahl.value, externe_nr: extern.value, mwst_satz: mwst.value,
						kampagne_id: parseInt(kamp.value, 10) || 0, kampagne_anteil: anteil.value, notiz: notiz.value
					}).then(function (r) { changed(r.message); }).catch(function (e) { go.disabled = false; err.textContent = e.message; });
				} }, 'Änderungen speichern');
				var grid = h('div', { class: 'bb-grid' }, fld('Kategorie', konto, khint), fld('Zahlungsart', zahl), fld('Beleg-Nr. (extern)', extern), fld('MwSt-Satz', mwst));
				if ((opt.kampagnen || []).length || kid) { grid.appendChild(fld('Kostenstelle', kamp)); grid.appendChild(fld('Anteil in %', anteil)); }
				panel.appendChild(h('div', { class: 'bb-panel' },
					h('span', { class: 'muted' }, 'Betrag, Datum, Händler und Datei sind durch die Hash-Kette gesichert und lassen sich nicht ändern. Bei einem Fehler dort: stornieren und neu erfassen.'),
					grid, fld('Notiz', notiz), err,
					h('div', { class: 'bb-actions' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { panel.textContent = ''; } }, 'Abbrechen'))));
			}).catch(function (e) { panel.appendChild(notice(e.message, true)); });
		}

		load();
	}

	/* =====================================================================
	 * Erfassen: Kamera oder Datei, Auslesen, Formular
	 * =================================================================== */

	function fileKind(f) {
		var n = (f.name || '').toLowerCase();
		var t = (f.type || '').toLowerCase();
		if (t === 'application/pdf' || /\.pdf$/.test(n)) { return 'pdf'; }
		if (t.indexOf('xml') > -1 || /\.xml$/.test(n)) { return 'xml'; }
		if (t.indexOf('image/') === 0 || /\.(jpe?g|png|webp|gif|heic|heif)$/.test(n)) { return 'image'; }
		return '';
	}

	function shrinkImage(source) {
		return new Promise(function (resolve, reject) {
			var url = source instanceof Blob ? URL.createObjectURL(source) : null;
			var img = new Image();
			img.onload = function () {
				var w = img.naturalWidth, hh = img.naturalHeight;
				if (!w || !hh) { if (url) { URL.revokeObjectURL(url); } reject(new Error('leer')); return; }
				var s = Math.min(1, MAX_EDGE / Math.max(w, hh));
				var cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(hh * s));
				var c = document.createElement('canvas');
				c.width = cw; c.height = ch;
				var ctx = c.getContext('2d');
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, cw, ch);
				ctx.drawImage(img, 0, 0, cw, ch);
				if (url) { URL.revokeObjectURL(url); }
				c.toBlob(function (b) { if (b) { resolve({ blob: b, w: cw, h: ch }); } else { reject(new Error('leer')); } }, 'image/jpeg', 0.85);
			};
			img.onerror = function () { if (url) { URL.revokeObjectURL(url); } reject(new Error('unlesbar')); };
			img.src = url;
		});
	}

	function readBytes(blob) {
		return new Promise(function (resolve, reject) {
			var r = new FileReader();
			r.onload = function () { resolve(new Uint8Array(r.result)); };
			r.onerror = function () { reject(new Error('Datei konnte nicht gelesen werden.')); };
			r.readAsArrayBuffer(blob);
		});
	}

	function asciiBytes(s) {
		var a = new Uint8Array(s.length);
		for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i) & 255; }
		return a;
	}

	// Mehrere Fotos zu einem PDF zusammenfassen (je Seite ein JPEG), damit ein Beleg genau eine Datei bleibt.
	function imagesToPdf(pages) {
		return Promise.all(pages.map(function (p) { return readBytes(p.blob); })).then(function (datas) {
			var parts = [], offsets = [], len = 0;
			function push(x) { var b = typeof x === 'string' ? asciiBytes(x) : x; parts.push(b); len += b.length; }
			function obj(n, text) { offsets[n] = len; push(n + ' 0 obj\n' + text + '\nendobj\n'); }

			push('%PDF-1.4\n');
			var kids = [];
			for (var i = 0; i < pages.length; i++) { kids.push((3 + i * 3) + ' 0 R'); }
			obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
			obj(2, '<< /Type /Pages /Kids [' + kids.join(' ') + '] /Count ' + pages.length + ' >>');

			pages.forEach(function (p, i) {
				var pn = 3 + i * 3, im = pn + 1, ct = pn + 2;
				var pw = 595, ph = Math.max(100, Math.round(595 * p.h / p.w));
				obj(pn, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pw + ' ' + ph + '] /Resources << /XObject << /Im0 ' + im + ' 0 R >> >> /Contents ' + ct + ' 0 R >>');
				offsets[im] = len;
				push(im + ' 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + p.w + ' /Height ' + p.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + datas[i].length + ' >>\nstream\n');
				push(datas[i]);
				push('\nendstream\nendobj\n');
				var content = 'q ' + pw + ' 0 0 ' + ph + ' 0 0 cm /Im0 Do Q';
				obj(ct, '<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream');
			});

			var count = 3 + pages.length * 3;
			var xref = len;
			var x = 'xref\n0 ' + count + '\n0000000000 65535 f \n';
			for (var n = 1; n < count; n++) { x += ('0000000000' + offsets[n]).slice(-10) + ' 00000 n \n'; }
			push(x + 'trailer\n<< /Size ' + count + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');
			return new Blob(parts, { type: 'application/pdf' });
		});
	}

	UI.belegCapture = function (opts) {
		opts = opts || {};
		var h = H();
		var mode = opts.mode || 'choose';
		var S = {
			pages: [],          // { kind, blob|file, url, name, w, h }
			stream: null,
			opt: null,
			fields: {},         // vom Auslesen vorgeschlagen
			found: [],
			ocrMessage: '',
			ocrWarn: false,
			hints: [],
			erechnung: null,    // { id, format }
			similar: [],
			busy: false
		};

		var body = h('div', { class: 'bb-cap' });
		var dlg = UI.dlg('Beleg erfassen', body, true);
		dlg.classList.add('bb-sheet');
		dlg.addEventListener('close', function () { stopCamera(); S.pages.forEach(function (p) { if (p.url) { URL.revokeObjectURL(p.url); } }); });

		var optPromise = loadOptions().then(function (o) { S.opt = o; return o; });

		function stopCamera() {
			if (S.stream) {
				S.stream.getTracks().forEach(function (t) { t.stop(); });
				S.stream = null;
			}
		}

		function clear() { stopCamera(); body.textContent = ''; }

		function busyView(text) {
			clear();
			body.appendChild(h('div', { class: 'bb-busy', role: 'status' }, h('span', { class: 'bb-spin' }), text));
		}

		/* ----- Seiten verwalten ----- */

		function pagesView(onChange) {
			var wrap = h('div', { class: 'bb-thumbs' });
			S.pages.forEach(function (p, i) {
				var t = h('div', { class: 'bb-thumb' });
				if (p.kind === 'image') { t.appendChild(h('img', { src: p.url, alt: 'Seite ' + (i + 1) })); }
				else { t.appendChild(h('span', null, p.kind.toUpperCase() + ' · ' + sizeText((p.file || p.blob).size))); }
				t.appendChild(h('button', { type: 'button', 'aria-label': 'Seite ' + (i + 1) + ' entfernen', onclick: function () {
					if (p.url) { URL.revokeObjectURL(p.url); }
					S.pages.splice(i, 1);
					onChange();
				} }, '×'));
				wrap.appendChild(t);
			});
			return wrap;
		}

		function addBlobPage(blob, w, hh, name) {
			S.pages.push({ kind: 'image', blob: blob, url: URL.createObjectURL(blob), name: name || 'beleg-seite-' + (S.pages.length + 1) + '.jpg', w: w, h: hh });
		}

		// Gewählte Dateien übernehmen: Fotos werden verkleinert und dürfen mehrere sein, PDF und XML nur einzeln.
		function addFiles(files) {
			var list = [].slice.call(files || []);
			var single = list.filter(function (f) { var k = fileKind(f); return k === 'pdf' || k === 'xml'; })[0];
			var msgs = [];

			if (single) {
				S.pages.forEach(function (p) { if (p.url) { URL.revokeObjectURL(p.url); } });
				S.pages = [{ kind: fileKind(single), file: single, name: single.name }];
				if (list.length > 1) { msgs.push('Ein PDF oder eine E-Rechnung gilt für sich allein. Die anderen Dateien wurden nicht übernommen.'); }
				return Promise.resolve(msgs);
			}

			S.pages = S.pages.filter(function (p) { return p.kind === 'image'; });
			return list.reduce(function (chain, f) {
				return chain.then(function () {
					if (fileKind(f) !== 'image') { msgs.push('„' + f.name + '“ ist kein Foto, PDF oder XML und wurde ausgelassen.'); return null; }
					return shrinkImage(f).then(function (r) { addBlobPage(r.blob, r.w, r.h, 'beleg-seite-' + (S.pages.length + 1) + '.jpg'); }, function () {
						msgs.push('„' + f.name + '“ kann der Browser nicht lesen (zum Beispiel HEIC). Bitte das Foto direkt aufnehmen oder als JPEG speichern.');
					});
				});
			}, Promise.resolve()).then(function () { return msgs; });
		}

		/* ----- Schritt: Auswahl ----- */

		function stepChoose() {
			clear();
			body.appendChild(h('p', { class: 'muted' }, 'Foto, PDF oder E-Rechnung: Die Felder werden, soweit möglich, automatisch ausgelesen. Du prüfst sie danach.'));
			body.appendChild(h('div', { class: 'bb-choose' },
				h('button', { class: 'btn primary bb-big', type: 'button', onclick: stepCamera }, 'Foto aufnehmen'),
				h('button', { class: 'btn bb-big', type: 'button', onclick: stepFile }, 'Datei wählen (Foto, PDF, E-Rechnung)'),
				h('button', { class: 'bb-link', type: 'button', onclick: function () { S.pages = []; stepForm(); } }, 'Ohne Datei von Hand erfassen')));
		}

		/* ----- Schritt: Kamera ----- */

		function cameraFallback(message) {
			clear();
			body.appendChild(notice(message, true));
			var input = h('input', { type: 'file', accept: 'image/*,application/pdf', capture: 'environment', 'aria-label': 'Foto aufnehmen' });
			input.addEventListener('change', function () {
				busyView('Foto wird vorbereitet …');
				addFiles(input.files).then(function (msgs) { filesDone(msgs); });
			});
			body.appendChild(h('div', { class: 'btn primary bb-big bb-file' }, 'Mit der Kamera-App aufnehmen', input));
			body.appendChild(h('button', { class: 'btn bb-big', type: 'button', onclick: stepFile }, 'Stattdessen Datei wählen'));
		}

		function stepCamera() {
			clear();
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				cameraFallback('Dieser Browser kann die Kamera nicht direkt öffnen (oder die Seite läuft nicht über https). Nimm das Foto mit der Kamera-App auf.');
				return;
			}

			var video = h('video', { class: 'bb-video', autoplay: true, playsinline: true, muted: true });
			video.muted = true;
			video.setAttribute('playsinline', '');
			var shot = h('img', { class: 'bb-shot', alt: 'Aufgenommenes Foto', hidden: true });
			var wrap = h('div', { class: 'bb-video-wrap' }, video, shot);
			var thumbs = h('div');
			var controls = h('div');
			body.appendChild(wrap);
			body.appendChild(thumbs);
			body.appendChild(controls);

			function paintThumbs() { thumbs.textContent = ''; if (S.pages.length) { thumbs.appendChild(pagesView(function () { paintThumbs(); live(); })); } }

			function live() {
				shot.hidden = true; video.hidden = false;
				controls.textContent = '';
				controls.appendChild(h('div', { class: 'bb-bar' },
					h('button', { class: 'btn primary bb-shutter', type: 'button', onclick: take }, 'Foto aufnehmen'),
					S.pages.length ? h('button', { class: 'btn', type: 'button', onclick: finish }, 'Fertig – weiter (' + S.pages.length + (S.pages.length === 1 ? ' Seite' : ' Seiten') + ')') : null,
					h('button', { class: 'btn', type: 'button', onclick: stepChoose }, 'Zurück')));
			}

			function take() {
				if (!video.videoWidth) { UI.toast('Die Kamera ist noch nicht bereit. Einen Moment bitte.', true); return; }
				var vw = video.videoWidth, vh = video.videoHeight;
				var s = Math.min(1, MAX_EDGE / Math.max(vw, vh));
				var cw = Math.round(vw * s), ch = Math.round(vh * s);
				var c = document.createElement('canvas');
				c.width = cw; c.height = ch;
				c.getContext('2d').drawImage(video, 0, 0, cw, ch);
				c.toBlob(function (b) {
					if (!b) { UI.toast('Das Foto ließ sich nicht erzeugen. Bitte noch einmal.', true); return; }
					var url = URL.createObjectURL(b);
					shot.src = url;
					shot.hidden = false; video.hidden = true;
					controls.textContent = '';
					controls.appendChild(h('div', { class: 'bb-bar' },
						h('button', { class: 'btn', type: 'button', onclick: function () { URL.revokeObjectURL(url); paintThumbs(); live(); } }, 'Wiederholen'),
						h('button', { class: 'btn', type: 'button', onclick: function () { keep(b, cw, ch, url); live(); } }, 'Noch eine Seite'),
						h('button', { class: 'btn primary', type: 'button', onclick: function () { keep(b, cw, ch, url); finish(); } }, 'Übernehmen und weiter')));
				}, 'image/jpeg', 0.85);
			}

			function keep(b, cw, ch, url) {
				URL.revokeObjectURL(url);
				addBlobPage(b, cw, ch);
				paintThumbs();
			}

			function finish() { stopCamera(); analyse(); }

			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 }, height: { ideal: 1440 } }, audio: false }).then(function (stream) {
				if (!dlg.open) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
				S.stream = stream;
				video.srcObject = stream;
				var p = video.play();
				if (p && p.catch) { p.catch(function () { /* Nutzer startet per Tippen */ }); }
				paintThumbs();
				live();
			}).catch(function (err) {
				var name = err && err.name;
				var msg = name === 'NotAllowedError' || name === 'SecurityError'
					? 'Der Zugriff auf die Kamera ist nicht erlaubt. Erlaube die Kamera in den Browser-Einstellungen, oder nimm das Foto mit der Kamera-App auf.'
					: (name === 'NotFoundError' || name === 'OverconstrainedError'
						? 'Es wurde keine Kamera gefunden. Du kannst eine Datei wählen oder die Kamera-App benutzen.'
						: 'Die Kamera lässt sich gerade nicht öffnen (vielleicht nutzt sie eine andere App). Nimm das Foto mit der Kamera-App auf.');
				cameraFallback(msg);
			});
		}

		/* ----- Schritt: Datei ----- */

		function stepFile() {
			clear();
			var msgBox = h('div');
			var list = h('div');
			var next = h('div');

			function paintList() {
				list.textContent = '';
				next.textContent = '';
				if (S.pages.length) {
					list.appendChild(pagesView(paintList));
					next.appendChild(h('div', { class: 'bb-bar' },
						h('button', { class: 'btn primary', type: 'button', onclick: analyse }, 'Weiter (' + S.pages.length + (S.pages.length === 1 ? ' Datei' : ' Seiten') + ')'),
						h('button', { class: 'btn', type: 'button', onclick: stepChoose }, 'Zurück')));
				}
			}

			var input = h('input', { type: 'file', accept: 'image/*,application/pdf,.xml,text/xml,application/xml', multiple: true, 'aria-label': 'Datei wählen' });
			input.addEventListener('change', function () {
				msgBox.textContent = '';
				addFiles(input.files).then(function (msgs) {
					msgs.forEach(function (m) { msgBox.appendChild(notice(m, true)); });
					input.value = '';
					paintList();
				});
			});

			body.appendChild(h('p', { class: 'muted' }, 'Foto (auch mehrere Seiten), PDF oder E-Rechnung (XML oder ZUGFeRD-PDF). Erlaubt bis ca. 15 MB.'));
			body.appendChild(h('div', { class: 'btn primary bb-big bb-file' }, S.pages.length ? 'Weitere Seiten hinzufügen' : 'Datei wählen', input));
			body.appendChild(msgBox);
			body.appendChild(list);
			body.appendChild(next);
			paintList();
		}

		function filesDone(msgs) {
			if (!S.pages.length) {
				stepChoose();
				(msgs || []).forEach(function (m) { body.insertBefore(notice(m, true), body.firstChild); });
				return;
			}
			analyse();
		}

		/* ----- Schritt: Auslesen ----- */

		function sendFile(path, page) {
			var fd = new FormData();
			if (page.file) { fd.append('datei', page.file, page.name); } else { fd.append('datei', page.blob, page.name); }
			return rq(path, 'POST', fd);
		}

		function mergeOcr(results) {
			var out = { felder: {}, gefunden: [], meldung: '', hinweise: [], tesseract: true, erkannt: false };
			var keys = ['betrag', 'datum', 'haendler', 'externe_nr', 'mwst_satz', 'konto'];
			results.forEach(function (r) {
				if (r.tesseract === false) { out.tesseract = false; }
				if (r.erkannt) { out.erkannt = true; }
				if (!out.meldung || r.erkannt) { out.meldung = r.meldung || out.meldung; }
				(r.hinweise || []).forEach(function (t) { if (out.hinweise.indexOf(t) < 0) { out.hinweise.push(t); } });
				var f = r.felder || {};
				keys.forEach(function (k) {
					var v = f[k];
					if (!v) { return; }
					// Die Summe steht auf dem letzten Blatt, Händler und Datum auf dem ersten.
					if (k === 'betrag' || !out.felder[k]) { out.felder[k] = v; }
				});
			});
			Object.keys(out.felder).forEach(function (k) { if (out.felder[k]) { out.gefunden.push(k); } });
			return out;
		}

		function analyse() {
			if (!S.pages.length) { stepForm(); return; }
			busyView('Beleg wird gelesen …');
			var first = S.pages[0];

			var run;
			if (first.kind === 'xml') {
				run = sendFile('buch/belege/erechnung', first).then(function (r) { return { er: r }; });
			} else if (first.kind === 'pdf') {
				run = sendFile('buch/belege/erechnung', first).then(function (r) {
					if (r.erechnung) { return { er: r }; }
					return sendFile('buch/belege/ocr', first).then(function (o) { return { ocr: mergeOcr([o]) }; });
				});
			} else {
				var pages = S.pages.slice(0, 4);
				run = pages.reduce(function (chain, p) {
					return chain.then(function (acc) { return sendFile('buch/belege/ocr', p).then(function (o) { acc.push(o); return acc; }); });
				}, Promise.resolve([])).then(function (list) { return { ocr: mergeOcr(list) }; });
			}

			run.then(function (res) {
				if (res.er) {
					if (!res.er.erechnung) { S.ocrMessage = res.er.meldung; S.ocrWarn = true; stepForm(); return; }
					S.erechnung = { id: res.er.erechnung_id, format: res.er.format };
					S.fields = res.er.felder || {};
					S.found = [];
					S.similar = res.er.aehnliche || [];
					S.ocrMessage = res.er.meldung;
					S.ocrWarn = false;
				} else {
					var o = res.ocr;
					S.fields = o.felder;
					S.found = o.gefunden;
					S.hints = o.hinweise;
					S.ocrWarn = !o.erkannt;
					S.ocrMessage = !o.erkannt && o.tesseract === false
						? 'Fotos werden auf diesem Server noch nicht automatisch gelesen (die Texterkennung Tesseract ist nicht eingerichtet). Bitte die Felder von Hand ausfüllen. PDFs mit Text werden gelesen.'
						: o.meldung;
				}
				stepForm();
			}).catch(function (e) {
				clear();
				body.appendChild(notice(e.message, true));
				body.appendChild(h('div', { class: 'bb-bar' },
					h('button', { class: 'btn primary', type: 'button', onclick: analyse }, 'Noch einmal versuchen'),
					h('button', { class: 'btn', type: 'button', onclick: function () { S.ocrMessage = ''; stepForm(); } }, 'Von Hand ausfüllen'),
					h('button', { class: 'btn', type: 'button', onclick: stepChoose }, 'Zurück')));
			});
		}

		/* ----- Schritt: Formular ----- */

		function stepForm() {
			clear();
			var promise = S.opt ? Promise.resolve(S.opt) : optPromise;
			promise.then(paintForm).catch(function (e) {
				body.appendChild(notice(e.message, true));
				body.appendChild(h('button', { class: 'btn', type: 'button', onclick: stepForm }, 'Noch einmal versuchen'));
			});
		}

		function paintForm(opt) {
			clear();
			var locked = !!S.erechnung;
			var f = S.fields || {};
			var wraps = {};

			function mark(key, wrap, input) {
				var hit = S.found.indexOf(key) > -1 && f[key];
				if (hit) {
					wrap.classList.add('bb-check');
					wrap.querySelector('.bb-l').appendChild(h('span', { class: 'bb-pruefen' }, 'bitte prüfen'));
					var drop = function () {
						wrap.classList.remove('bb-check');
						var tag = wrap.querySelector('.bb-pruefen');
						if (tag) { tag.remove(); }
					};
					input.addEventListener('input', drop);
					input.addEventListener('change', drop);
				}
			}

			function lockTag(wrap) { wrap.querySelector('.bb-l').appendChild(h('span', { class: 'bb-lock' }, 'aus der E-Rechnung')); }

			var betrag = h('input', { class: 'field', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '0,00', value: f.betrag ? String(f.betrag).replace('.', ',') : '', readonly: locked ? true : null });
			var datum = h('input', { class: 'field', type: 'date', max: opt.heute, value: f.datum || opt.heute, readonly: locked ? true : null });
			var haendler = h('input', { class: 'field', type: 'text', maxlength: '255', autocomplete: 'off', value: f.haendler || '', readonly: locked ? true : null });
			var extern = h('input', { class: 'field', type: 'text', maxlength: '60', value: f.externe_nr || '' });
			var konto = kontoSelect(opt, f.konto || opt.standardkonto);
			var khint = h('span', { class: 'bb-hint' });
			var zahl = zahlSelect(opt, '');
			var mwst = mwstSelect(f.mwst_satz || '');
			var kamp = kampagneSelect(opt, opts.campaignId || 0);
			var anteil = h('input', { class: 'field', type: 'number', min: '0', max: '100', step: '0.1', value: '100', 'aria-label': 'Anteil in Prozent' });
			var notiz = h('textarea', { class: 'field', rows: '3', placeholder: 'Wofür war das? (optional)' });

			wraps.betrag = fld('Betrag in €', betrag);
			wraps.datum = fld('Belegdatum', datum);
			wraps.haendler = fld('Händler', haendler);
			wraps.externe_nr = fld('Beleg-/Rechnungsnummer (optional)', extern);
			wraps.konto = fld('Kategorie / Konto', konto, khint);
			wraps.zahlungsart = fld('Zahlungsart', zahl);
			wraps.mwst_satz = fld('MwSt-Satz', mwst);
			wraps.kampagne_id = fld('Kostenstelle (optional)', kamp);
			wraps.kampagne_anteil = fld('Anteil in %', anteil);
			wraps.notiz = fld('Notiz', notiz);

			mark('betrag', wraps.betrag, betrag); mark('datum', wraps.datum, datum); mark('haendler', wraps.haendler, haendler);
			mark('externe_nr', wraps.externe_nr, extern); mark('konto', wraps.konto, konto); mark('mwst_satz', wraps.mwst_satz, mwst);
			if (locked) { lockTag(wraps.betrag); lockTag(wraps.datum); lockTag(wraps.haendler); }

			kontoHint(opt, konto.value, khint);
			konto.addEventListener('change', function () { kontoHint(opt, konto.value, khint); });

			var anteilWrap = wraps.kampagne_anteil;
			function syncAnteil() { anteilWrap.hidden = (parseInt(kamp.value, 10) || 0) === 0; }
			kamp.addEventListener('change', syncAnteil);
			syncAnteil();

			var status = h('div');
			var dupHost = h('div');
			var forceDup = false;

			function showDup(list, msg) {
				dupHost.textContent = '';
				if (list && list.length) { dupHost.appendChild(dupBox(list, msg || 'Es gibt schon einen ähnlichen Beleg.', null)); }
			}
			if (S.similar && S.similar.length) { showDup(S.similar, 'Es gibt schon einen Beleg mit gleichem Betrag, gleichem Datum und ähnlichem Händler.'); }

			function checkDup() {
				if (locked) { return; }
				var b = betrag.value.trim(), d = datum.value;
				if (!b || !d) { return; }
				rq('buch/belege/doppelt?' + qs({ betrag: b, datum: d, haendler: haendler.value })).then(function (r) { showDup(r.aehnliche, r.message); }).catch(function () { /* nur ein Hinweis */ });
			}
			[betrag, datum, haendler].forEach(function (el) { el.addEventListener('change', checkDup); });
			if (!locked && f.betrag && f.datum) { checkDup(); }

			var buttons = [];
			function busy(on) { S.busy = on; buttons.forEach(function (b) { b.disabled = on; }); }

			function validate() {
				var errs = {};
				if (!locked) {
					if (!betrag.value.trim()) { errs.betrag = 'Bitte den Betrag eintragen.'; }
					if (!datum.value) { errs.datum = 'Bitte das Belegdatum angeben.'; }
				}
				showFieldErrors(errs, wraps);
				var keys = Object.keys(errs);
				if (keys.length) { var w = wraps[keys[0]]; w.scrollIntoView({ block: 'center' }); return false; }
				return true;
			}

			function payload(freeze) {
				return {
					betrag: betrag.value, datum: datum.value, haendler: haendler.value, externe_nr: extern.value,
					konto: konto.value, zahlungsart: zahl.value, mwst_satz: mwst.value,
					kampagne_id: parseInt(kamp.value, 10) || 0, kampagne_anteil: anteil.value,
					notiz: notiz.value, festschreiben: freeze ? 1 : 0, trotzdem: forceDup ? 1 : 0
				};
			}

			function fileForUpload() {
				if (!S.pages.length) { return Promise.resolve(null); }
				if (S.pages.length === 1) {
					var p = S.pages[0];
					return Promise.resolve({ blob: p.file || p.blob, name: p.name });
				}
				return imagesToPdf(S.pages).then(function (pdf) { return { blob: pdf, name: 'beleg.pdf' }; });
			}

			function submit(freeze) {
				if (S.busy || !validate()) { return; }
				status.textContent = '';
				busy(true);
				var pl = payload(freeze);

				var req;
				if (S.erechnung) {
					req = rq('buch/belege/erechnung/' + S.erechnung.id + '/uebernehmen', 'POST', pl);
				} else {
					req = fileForUpload().then(function (file) {
						var fd = new FormData();
						Object.keys(pl).forEach(function (k) { fd.append(k, pl[k]); });
						if (file) { fd.append('datei', file.blob, file.name); }
						return rq('buch/belege', 'POST', fd);
					});
				}

				req.then(function (res) {
					busy(false);
					UI.toast(res.message);
					var beleg = res.beleg || {};
					beleg.festgeschrieben = !!res.festgeschrieben;
					dlg.close();
					if (typeof opts.onSaved === 'function') {
						try { opts.onSaved(beleg); } catch (e) { if (window.console) { window.console.error(e); } }
					}
				}).catch(function (e) {
					busy(false);
					var data = e.data || {};
					if (e.status === 409 && data.doppelt) {
						dupDialog(data.aehnliche || [], e.message, freeze);
						return;
					}
					showFieldErrors(data.fehler || {}, wraps);
					status.textContent = '';
					status.appendChild(notice(e.message, true));
					status.scrollIntoView({ block: 'nearest' });
				});
			}

			function dupDialog(list, msg, freeze) {
				dupHost.textContent = '';
				dupHost.appendChild(dupBox(list, msg, function () { forceDup = true; submit(freeze); }));
				dupHost.scrollIntoView({ block: 'center' });
			}

			// Kopf: Vorschau und Hinweise vom Auslesen.
			var first = S.pages[0];
			if (first) {
				var pv = h('div', { class: 'bb-prev' });
				if (first.kind === 'image') { pv.appendChild(h('img', { src: first.url, alt: 'Erste Seite' })); }
				pv.appendChild(h('div', null, h('b', null, first.kind === 'image' ? (S.pages.length + (S.pages.length === 1 ? ' Seite' : ' Seiten')) : first.name),
					h('div', { class: 'muted', style: 'font-size:13px' }, S.erechnung ? 'E-Rechnung (' + S.erechnung.format + ')' : 'wird beim Speichern abgelegt')));
				body.appendChild(pv);
			} else {
				body.appendChild(notice('Ohne Datei: Später lässt sich keine Datei mehr anhängen, weil sie zur geschützten Belegprüfsumme gehört. Besser vorher ein Foto machen.', true));
			}
			if (S.ocrMessage) { body.appendChild(notice(S.ocrMessage, S.ocrWarn)); }
			S.hints.forEach(function (t) { body.appendChild(notice(t, true)); });
			if (S.found.length) { body.appendChild(h('p', { class: 'muted', style: 'margin:0;font-size:13px' }, 'Orange markierte Felder wurden automatisch gelesen und sind unsicher. Bitte mit dem Beleg abgleichen.')); }

			body.appendChild(dupHost);
			var grid = h('div', { class: 'bb-grid' }, wraps.betrag, wraps.datum);
			body.appendChild(grid);
			body.appendChild(wraps.haendler);
			body.appendChild(h('div', { class: 'bb-grid' }, wraps.konto, wraps.zahlungsart));
			body.appendChild(h('div', { class: 'bb-grid' }, wraps.externe_nr, wraps.mwst_satz));
			if ((opt.kampagnen || []).length || opts.campaignId) { body.appendChild(h('div', { class: 'bb-grid' }, wraps.kampagne_id, wraps.kampagne_anteil)); }
			body.appendChild(wraps.notiz);
			body.appendChild(status);

			var save = h('button', { class: 'btn primary', type: 'button', onclick: function () { submit(false); } }, 'Speichern');
			var saveFreeze = h('button', { class: 'btn', type: 'button', onclick: function () { askFreeze(); } }, 'Speichern und festschreiben');
			var cancel = h('button', { class: 'btn', type: 'button', onclick: function () { dlg.close(); } }, 'Abbrechen');
			buttons = [save, saveFreeze];
			var bar = h('div', { class: 'bb-bar' }, save, saveFreeze, cancel);
			body.appendChild(bar);

			function askFreeze() {
				if (!validate()) { return; }
				bar.textContent = '';
				var yes = h('button', { class: 'btn primary', type: 'button', onclick: function () { submit(true); } }, 'Ja, festschreiben');
				buttons = [yes];
				bar.appendChild(h('div', { style: 'flex:1 1 100%' }, 'Festschreiben bucht den Beleg im Journal. Danach lässt er sich nicht mehr ändern, nur noch stornieren. Passt alles?'));
				bar.appendChild(yes);
				bar.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					bar.textContent = '';
					buttons = [save, saveFreeze];
					bar.appendChild(save); bar.appendChild(saveFreeze); bar.appendChild(cancel);
				} }, 'Zurück'));
			}
		}

		/* ----- Start ----- */

		if (mode === 'camera') { stepCamera(); }
		else if (mode === 'upload') { stepFile(); }
		else { stepChoose(); }

		return dlg;
	};
})();
