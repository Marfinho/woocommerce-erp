/*
 * Stempeluhr als Sprechblase an der Uhr in der Kopfzeile: aktueller Stand,
 * Start, Pause, Stoppen und Buchen, ohne die Seite zu wechseln. Erst „Mehr“
 * führt zur Zeiterfassung. Server: includes/Erp/Pages/Verwaltung.php (zeit/*).
 * Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	var bubble = null;
	var anchorEl = null;
	var tick = null;
	var data = null;
	var fetched = 0;
	var stopping = false;
	var message = '';

	function h() { return UI.h.apply(null, arguments); }

	function p(n) { return (n < 10 ? '0' : '') + n; }

	function hms(sec) {
		sec = Math.max(0, Math.floor(sec));
		return p(Math.floor(sec / 3600)) + ':' + p(Math.floor((sec % 3600) / 60)) + ':' + p(sec % 60);
	}

	function fmt(v, d) {
		var n = Number(v);
		return isFinite(n) ? n.toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d }) : '';
	}

	function clock() { return data && data.clock; }

	function elapsed() {
		var c = clock();
		return c ? c.seconds + (c.paused ? 0 : (Date.now() - fetched) / 1000) : 0;
	}

	function todayHours() {
		var sum = 0;
		((data && data.week && data.week.entries) || []).forEach(function (e) { if (e.date === data.today) { sum += e.hours; } });
		return sum;
	}

	function close() {
		if (tick) { clearInterval(tick); tick = null; }
		if (bubble) { bubble.remove(); bubble = null; }
		if (anchorEl) { anchorEl.setAttribute('aria-expanded', 'false'); }
		document.removeEventListener('mousedown', outside, true);
		document.removeEventListener('keydown', escape, true);
		window.removeEventListener('hashchange', close);
		stopping = false;
		message = '';
	}

	function outside(e) {
		if (bubble && !bubble.contains(e.target) && !(anchorEl && anchorEl.contains(e.target))) { close(); }
	}

	function escape(e) {
		if (e.key === 'Escape') { close(); if (anchorEl) { anchorEl.focus(); } }
	}

	function post(path, body) { return UI.api(path, { method: 'POST', body: body || {} }); }

	function reload() {
		return UI.api('zeit').then(function (d) {
			data = d;
			fetched = Date.now();
			if (UI.clockSync) { UI.clockSync(); }
			paint();
		}).catch(function (e) { message = e.message; paint(); });
	}

	function run(btn, fn) {
		btn.disabled = true;
		message = '';
		fn().then(function () { return reload(); }, function (e) { message = e.message; btn.disabled = false; paint(); });
	}

	function stopForm(c) {
		var cat = h('select', { class: 'field', 'aria-label': 'Kategorie' }, data.categories.map(function (k) {
			return h('option', { value: k.key, selected: k.key === c.category }, k.label);
		}));
		var hours = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: fmt(c.hours, 2), 'aria-label': 'Stunden', style: 'width:90px' });
		var desc = h('input', { class: 'field', type: 'text', placeholder: 'Was hast du gemacht?', maxlength: '200', 'aria-label': 'Tätigkeit' });
		var go = h('button', { class: 'btn primary', type: 'submit' }, 'Stoppen und buchen');
		var form = h('form', { class: 'sw-form' },
			c.hint && c.hint.text ? h('div', { class: 'notice warn' }, c.hint.text) : null,
			!c.confident ? h('p', { class: 'muted small', style: 'margin:0' }, 'Die Zeit verteilt sich auf mehrere Bereiche. Such die Tätigkeit selbst aus.') : null,
			h('label', null, 'Kategorie', cat),
			h('div', { class: 'sw-row' }, h('label', null, 'Stunden', hours), h('label', { style: 'flex:1' }, 'Tätigkeit', desc)),
			h('div', { class: 'sw-row' }, go, h('button', { class: 'btn', type: 'button', onclick: function () { stopping = false; paint(); } }, 'Zurück')));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			go.disabled = true;
			post('zeit/stopp', { entry_date: c.entry_date, category: cat.value, hours: hours.value, description: desc.value }).then(function (r) {
				stopping = false;
				UI.toast(r.message);
				return reload();
			}, function (x) { message = x.message; go.disabled = false; paint(); });
		});
		return form;
	}

	function paint() {
		if (!bubble) { return; }
		var c = clock();
		bubble.textContent = '';

		bubble.appendChild(h('div', { class: 'sw-head' },
			h('b', null, 'Stempeluhr'),
			h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Schließen', onclick: close }, '✕')));

		if (!data) {
			bubble.appendChild(h('p', { class: 'muted' }, 'Wird geladen …'));
			return;
		}

		var time = h('div', { class: 'sw-time', role: 'timer', 'aria-live': 'off' }, hms(elapsed()));
		bubble.appendChild(h('div', { class: 'sw-state' },
			h('span', { class: 'sw-dot ' + (c ? (c.paused ? 'pause' : 'on') : '') }),
			h('span', null, c ? (c.paused ? 'Pause' : 'läuft') : 'Uhr steht')));
		bubble.appendChild(c ? time : h('div', { class: 'sw-time idle' }, '00:00:00'));

		var facts = [];
		if (c) { facts.push('Gestartet ' + new Date(c.started_at * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr'); }
		facts.push('Heute gebucht ' + fmt(todayHours(), 1) + ' h');
		facts.push('Woche ' + fmt(data.week.hours, 1) + ' von ' + fmt(data.budget, 0) + ' h');
		bubble.appendChild(h('p', { class: 'muted small sw-facts' }, facts.join(' · ')));
		if (c && c.explanation) { bubble.appendChild(h('p', { class: 'muted small', style: 'margin:0 0 8px' }, 'Währenddessen: ' + c.explanation)); }

		if (message) { bubble.appendChild(h('div', { class: 'notice warn', role: 'alert' }, message)); }

		if (!c) {
			var start = h('button', { class: 'btn primary wide', type: 'button' }, UI.ico('clock'), 'Uhr starten');
			start.addEventListener('click', function () { run(start, function () { return post('zeit/start'); }); });
			bubble.appendChild(start);
		} else if (stopping) {
			bubble.appendChild(stopForm(c));
		} else {
			var pause = h('button', { class: 'btn', type: 'button' }, c.paused ? 'Weiter ▶' : 'Pause ▮▮');
			pause.addEventListener('click', function () { run(pause, function () { return post(c.paused ? 'zeit/weiter' : 'zeit/pause'); }); });
			var stop = h('button', { class: 'btn primary', type: 'button', onclick: function () { stopping = true; message = ''; paint(); } }, 'Stoppen …');
			bubble.appendChild(h('div', { class: 'sw-row' }, pause, stop));
			bubble.appendChild(h('button', { class: 'linklike small', type: 'button', onclick: function () {
				if (window.confirm('Die Uhr verwerfen? Es wird nichts gebucht.')) { post('zeit/verwerfen').then(function (r) { UI.toast(r.message); return reload(); }, function (e) { message = e.message; paint(); }); }
			} }, 'Uhr verwerfen'));
		}

		bubble.appendChild(h('a', { class: 'sw-more', href: '#/seite/zeit', onclick: close }, 'Mehr … Einträge der Woche und Nachträge'));

		if (c && !c.paused) {
			if (tick) { clearInterval(tick); }
			tick = setInterval(function () { if (time.isConnected) { time.textContent = hms(elapsed()); } }, 1000);
		} else if (tick) {
			clearInterval(tick);
			tick = null;
		}
	}

	// Blase an der Uhr der Kopfzeile ein- oder ausblenden.
	UI.clockBubble = function (anchor) {
		if (bubble) { close(); return; }

		anchorEl = anchor;
		anchor.setAttribute('aria-expanded', 'true');
		bubble = h('div', { class: 'sw-bubble', role: 'dialog', 'aria-label': 'Stempeluhr' });
		document.body.appendChild(bubble);
		document.addEventListener('mousedown', outside, true);
		document.addEventListener('keydown', escape, true);
		window.addEventListener('hashchange', close);
		paint();
		reload();
	};
})();
