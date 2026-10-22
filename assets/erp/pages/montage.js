/*
 * Montage im Workflow: Karten für Halbzeuge und Module, die aus Rohlingen und
 * Kleinteilen gebaut werden, die offenen Aufträge und die Arbeitsseite
 * #/montage/<id> (Material, Uhr, Fertigmelden). Server: includes/Erp/Assembly.php.
 * Die Spur wird vom Workflow (erp.js) über UI.assemblyLane eingehängt.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var STATE = {
		da: ['da', 'tag now'],
		druck: ['im Druck', 'tag later'],
		fehlt: ['fehlt', 'tag warn']
	};

	function h() { return UI.h.apply(null, arguments); }

	function seconds(sec) {
		sec = Math.max(0, Math.floor(sec));
		var hh = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60), ss = sec % 60;
		return hh + ':' + ('0' + mm).slice(-2) + ':' + ('0' + ss).slice(-2);
	}

	function partRows(parts) {
		var box = h('div', { class: 'mt-parts' });
		parts.forEach(function (p) {
			var st = STATE[p.state] || STATE.fehlt;
			box.appendChild(h('div', { class: 'mt-part' },
				h('span', { class: 'mt-need' }, UI.qty(p.need) + '×'),
				h('span', { class: 'mt-name' }, h('b', null, p.label), p.name ? ' ' + p.name : ''),
				h('span', { class: 'muted small' }, 'Lager ' + UI.qty(p.have)),
				h('span', { class: st[1] }, st[0])
			));
		});
		return box;
	}

	function createDialog(card, reload) {
		var qty = h('input', { class: 'field', type: 'number', min: '1', step: '1', value: String(card.qty), 'aria-label': 'Stückzahl', style: 'width:110px' });
		var go = h('button', { class: 'btn primary', type: 'submit' }, 'Montage anlegen');
		var err = h('div', { 'aria-live': 'polite' });
		var form = h('form', { class: 'sheet-body' },
			h('p', { class: 'muted', style: 'margin-top:0' }, card.sku + ' ' + card.name + ': Beim Anlegen entsteht die Materialliste samt Pickliste. Gebucht wird erst beim Fertigmelden.'),
			partRows(card.parts),
			h('div', { style: 'display:flex;gap:10px;align-items:center;margin-top:12px' }, h('label', null, 'Stück'), qty, go),
			err);
		var d = UI.dlg('Montage anlegen', form);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			go.disabled = true;
			UI.api('workflow/montage', { method: 'POST', body: { article_id: card.article_id, qty: parseInt(qty.value, 10) || 0 } }).then(function (r) {
				UI.toast(r.message);
				if (d && d.close) { d.close(); }
				reload();
				if (r.id) { location.hash = '#/montage/' + r.id; }
			}).catch(function (x) {
				go.disabled = false;
				err.textContent = '';
				err.appendChild(h('div', { class: 'notice warn' }, x.message));
			});
		});
	}

	function demandCard(c, plan, reload) {
		var wait = c.parts.filter(function (p) { return p.state !== 'da'; });
		return h('article', { class: 'run-card demand' },
			h('div', { class: 'row-top' }, h('b', { class: 'run-title' }, c.name), h('span', { class: 'muted small' }, UI.qty(c.qty) + ' Stk')),
			h('div', { class: 'muted', style: 'font-size:13px;margin-top:3px' }, c.sku + ' · Lager ' + UI.qty(c.stock)),
			h('div', { class: 'chips' }, c.chips.map(function (x) { return h('span', { class: 'chip' }, x); })),
			c.has_bom ? partRows(c.parts) : h('div', { class: 'notice warn', style: 'margin-top:8px' }, 'Keine Stückliste hinterlegt – dann gibt es nichts zu montieren.'),
			plan && c.has_bom ? h('button', { class: 'btn' + (c.ready ? ' primary' : ''), type: 'button', disabled: !c.ready, style: 'margin-top:11px;width:100%', title: c.ready ? '' : 'Es sind noch nicht alle Teile da.', onclick: function () { createDialog(c, reload); } }, c.ready ? 'Montage anlegen' : 'Wartet auf ' + wait.length + ' Teil' + (wait.length === 1 ? '' : 'e')) : null
		);
	}

	function orderCard(o) {
		var run = o.running;
		return h('article', { class: 'run-card st-' + (run ? 'druckt' : 'x') },
			h('div', { class: 'row-top' }, h('a', { class: 'run-title', href: '#/montage/' + o.id }, o.name), h('span', { class: 'muted small' }, UI.qty(o.qty) + ' Stk')),
			h('div', { class: 'muted', style: 'font-size:13px;margin-top:3px' }, o.code + ' · ' + o.sku + (o.color ? ' · ' + o.color : '')),
			h('div', { class: 'chips' }, h('span', { class: run ? 'tag now' : 'tag later' }, o.status_text), o.elapsed ? h('span', { class: 'chip' }, seconds(o.elapsed + (run ? Math.floor(Date.now() / 1000) - o.fetched : 0))) : null),
			h('a', { class: 'btn primary', href: '#/montage/' + o.id, style: 'margin-top:11px;width:100%;text-align:center' }, run ? 'Weiterarbeiten' : 'Montage starten')
		);
	}

	function column(title, count, cards, empty) {
		return h('section', { class: 'col' },
			h('header', null, h('h3', null, title), h('span', { class: 'count' }, String(count))),
			h('div', { class: 'col-cards' }, cards.length ? cards : h('div', { class: 'empty muted' }, empty))
		);
	}

	// Spur „Montage“ unter dem Druckbrett des Workflows.
	UI.assemblyLane = function (host, d) {
		host.textContent = '';
		var board = d.board || { open: [], done: [] };
		var cards = d.cards || [];

		if (!board.open.length && !board.done.length && !cards.length && d.demandLoaded) { return; }
		if (!board.open.length && !board.done.length && !d.plan) { return; }

		host.appendChild(h('div', { class: 'lane-head', style: 'margin:22px 0 10px' },
			h('h3', { style: 'margin:0' }, 'Montage'),
			h('p', { class: 'muted', style: 'margin:2px 0 0' }, 'Aus Rohlingen und Kleinteilen die Halbzeuge und Module bauen. Der Auftrag entsteht, sobald alle Teile im Lager sind.')));

		var grid = h('div', { class: 'board', style: 'grid-template-columns:repeat(auto-fit,minmax(260px,1fr))' });
		grid.appendChild(column('Zu montieren', d.plan ? cards.length : 0, cards.map(function (c) { return demandCard(c, d.plan, d.reload); }), d.plan ? (d.demandLoaded ? 'Nichts zu montieren.' : 'Wird berechnet …') : 'Den Bedarf sieht, wer Pläne freigeben darf.'));
		grid.appendChild(column('In Montage', board.open.length, board.open.map(orderCard), 'Kein Auftrag offen.'));
		host.appendChild(grid);

		var done = h('section', { class: 'done-strip' }, h('b', null, 'Heute montiert'));
		if (!board.done.length) { done.appendChild(h('span', { class: 'muted' }, 'noch nichts')); }
		board.done.forEach(function (o) { done.appendChild(h('a', { class: 'muted', href: '#/montage/' + o.id }, UI.qty(o.done) + '× ' + o.name)); });
		host.appendChild(done);
	};

	// Arbeitsseite eines Montageauftrags.
	UI.routes['montage'] = function (r, ui) {
		var id = parseInt(r.id, 10) || 0;
		var box = h('div', { class: 'run mt-run' });
		var order = null;
		var canPlan = false;
		ui.view().appendChild(box);
		ui.setHead('Montage', 'Workflow');

		function load() {
			return ui.api('montage/' + id).then(function (x) { order = x.order; canPlan = x.plan; paint(); }).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		function act(action, body) {
			return ui.api('montage/' + id + '/' + action, { method: 'POST', body: body || {} }).then(function (x) {
				ui.toast(x.message);
				if (x.order) { order = x.order; paint(); } else { load(); }
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function elapsed() { return order.elapsed + (order.running ? Math.floor(Date.now() / 1000) - order.fetched : 0); }

		function paint() {
			box.textContent = '';
			var o = order;
			box.appendChild(h('div', { class: 'run-head' },
				h('div', { class: 'eyebrow' }, [o.code, o.status_text, o.color].filter(Boolean).join(' · ')),
				h('h2', null, o.name),
				h('div', { class: 'run-items' }, UI.qty(o.qty) + '× ' + o.sku)));

			if (!o.open) {
				box.appendChild(h('div', { class: 'card' }, o.status === 'erledigt' ? UI.qty(o.done) + ' Stück montiert und eingelagert, das Material ist abgebucht.' : 'Dieser Auftrag wurde storniert. Es wurde nichts gebucht.'));
				return;
			}

			box.appendChild(h('div', { class: 'card' },
				h('div', { class: 'stepcard' }, h('div', { class: 'stepno' }, '1'), h('div', null, h('b', null, 'Material holen'), h('div', { class: 'muted small' }, 'Gebucht wird erst beim Fertigmelden.'))),
				partRows(o.parts),
				o.picklist ? h('a', { class: 'btn', href: '#/seite/picken', style: 'margin-top:10px' }, 'Pickliste ' + o.picklist + ' öffnen') : null));

			var clockEl = h('div', { class: 'tnum' }, seconds(elapsed()));
			box.appendChild(h('div', { class: 'timegrid' }, h('div', { class: 'card time' }, h('div', { class: 'eyebrow' }, 'Arbeitszeit'), clockEl, h('div', { class: 'muted small' }, o.running ? 'läuft' : 'angehalten'))));
			if (o.running) { ui.every(function () { clockEl.textContent = seconds(elapsed()); }, 1000); }

			var qty = h('input', { class: 'field', type: 'number', min: '0', step: '1', inputmode: 'numeric', value: String(o.qty), 'aria-label': 'Fertige Stückzahl', style: 'width:110px;text-align:center' });
			var ready = !o.parts.some(function (p) { return p.state !== 'da'; });

			box.appendChild(h('div', { class: 'run-foot' },
				h('button', { class: 'bigbtn', type: 'button', onclick: function () { act(o.running ? 'pause' : 'start'); } }, o.running ? 'Pause ▮▮' : (o.elapsed ? 'Weiter ▶' : 'Montage starten ▶')),
				h('div', { class: 'card', style: 'display:flex;gap:10px;align-items:center;justify-content:space-between' },
					h('div', null, h('b', null, 'Fertig melden'), h('div', { class: 'muted small' }, ready ? 'Material geht ab, die Stücke kommen ins Lager.' : 'Achtung: Im Lager fehlen Teile, die Buchung kann scheitern.')),
					h('div', { style: 'display:flex;gap:8px;align-items:center' }, qty, h('button', { class: 'btn primary', type: 'button', onclick: function () { act('fertig', { qty_done: qty.value }); } }, 'Fertig ✓')))));

			if (canPlan) {
				box.appendChild(h('div', { class: 'run-more' }, h('button', { class: 'btn', type: 'button', style: 'color:var(--error)', onclick: function () { if (window.confirm('Montage abbrechen? Es wird nichts gebucht.')) { act('abbrechen'); } } }, 'Abbrechen')));
			}
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load();
	};
})();
