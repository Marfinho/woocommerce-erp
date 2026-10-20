/*
 * ERP-Oberfläche (/erp): kleine Einzelseiten-Anwendung ohne Build-Schritt.
 *
 * Navigation, Kennzahlen und Rechte kommen über die REST-Schnittstelle
 * (lw16/v1/erp/*); der Seitenwechsel läuft ohne Neuladen über die
 * Adresse nach dem # (z. B. #/seite/lager). Bestehende wp-admin-Seiten
 * erscheinen eingebettet, bis es native Fassungen gibt.
 *
 * Alle Texte aus dem Server gelangen über textContent in die Seite,
 * nie über innerHTML.
 */
(function () {
	'use strict';

	var C = window.LW16ERP || {};
	var root = document.getElementById('app');

	var S = {
		boot: null,
		dash: null,
		rights: null,
		sel: 0,
		draft: null,
		filter: 'all',
		q: '',
		open: {},
		lastHash: '#/start'
	};

	var el = {}; // Gerüst-Elemente

	/*
	 * Eigene Dateien für Seiten (assets/erp/pages/*.js): Sie melden sich mit
	 * LW16ERP_UI.pages[<Seitenkennung>] = function (item, ui) { … } für eine
	 * Seite der Navigation bzw. mit LW16ERP_UI.routes[<Name>] für eine eigene
	 * Adresse #/<Name>/<id>/<unter>. Die Hilfen (h, api, …) hängen unten an.
	 */
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};
	UI.productTabs = UI.productTabs || {};
	var timers = [];
	var camera = null;

	/* ---------- Hilfen ---------- */

	function add(parent, child) {
		if (child === null || child === undefined || child === false) { return; }
		if (Array.isArray(child)) { child.forEach(function (c) { add(parent, c); }); return; }
		parent.appendChild(child.nodeType ? child : document.createTextNode(String(child)));
	}

	function h(tag, attrs) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { node.className = v; }
				else if (k.slice(0, 2) === 'on') { node.addEventListener(k.slice(2), v); }
				else if (k === 'checked' || k === 'disabled' || k === 'value' || k === 'hidden') { node[k] = v; }
				else { node.setAttribute(k, v === true ? '' : v); }
			});
		}
		for (var i = 2; i < arguments.length; i++) { add(node, arguments[i]); }
		return node;
	}

	var ICONS = {
		home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
		cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6"/>',
		hammer: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
		box: '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7L12 12l8.7-5M12 22V12"/>',
		euro: '<path d="M18 7a7 7 0 1 0 0 10"/><path d="M4 10h9M4 14h9"/>',
		cog: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
		menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
		sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		ext: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		check: '<path d="M5 12l5 5L20 7"/>',
		chev: '<path d="M9 6l6 6-6 6"/>',
		users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
		refresh: '<path d="M20 11a8 8 0 0 0-14.5-4M4 4v4h4M4 13a8 8 0 0 0 14.5 4M20 20v-4h-4"/>',
		logout: '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M16 8l4 4-4 4M20 12H9"/>',
		flow: '<circle cx="5" cy="6" r="2"/><circle cx="19" cy="12" r="2"/><circle cx="5" cy="18" r="2"/><path d="M7 6h6a4 4 0 0 1 4 4M7 18h6a4 4 0 0 0 4-4"/>',
		book: '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M9 7h6"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		dots: '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		download: '<path d="M12 3v12M7 11l5 5 5-5M4 20h16"/>',
		shop: '<path d="M4 9l1.5-5h13L20 9"/><path d="M4 9a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0A2.7 2.7 0 0 0 20 9v11H4z"/>'
	};

	function ico(name, cls) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('class', 'ico' + (cls ? ' ' + cls : ''));
		svg.setAttribute('aria-hidden', 'true');
		svg.innerHTML = ICONS[name] || ''; // feste Zeichenketten aus ICONS
		return svg;
	}

	function store(key, value) {
		try {
			if (value === undefined) { return window.localStorage.getItem(key); }
			window.localStorage.setItem(key, value);
		} catch (e) { return null; }
		return null;
	}

	/* ---------- Schnittstelle, Zwischenspeicher, Warteschlange ---------- */

	var QUEUE_KEY = 'lw16erp-queue';
	var queueBusy = false;
	var stale = false;

	function readJson(key, fallback) {
		try { var raw = window.localStorage.getItem(key); return raw ? JSON.parse(raw) : fallback; } catch (e) { return fallback; }
	}

	function writeJson(key, value) {
		try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* voll oder gesperrt */ }
	}

	function cacheKey(root, path) { return 'lw16erp-c:' + (root ? 'api:' : 'erp:') + path; }
	function cacheSet(root, path, data) { writeJson(cacheKey(root, path), { at: Date.now(), data: data }); }
	function cacheGet(root, path) { return readJson(cacheKey(root, path), null); }

	function queue() { return readJson(QUEUE_KEY, []); }

	function uid() {
		return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2);
	}

	// Eine Beschreibung statt einer Nummer, falls der Server keine mitgibt.
	function httpText(status) {
		if (status === 401) { return 'Du bist nicht mehr angemeldet – bitte neu anmelden.'; }
		if (status === 403) { return 'Dafür fehlt dir die Berechtigung.'; }
		if (status === 404) { return 'Das gibt es nicht (mehr) – bitte die Seite neu laden.'; }
		if (status === 409) { return 'Das geht gerade nicht, weil sich der Stand inzwischen geändert hat.'; }
		if (status === 413) { return 'Die Datei ist zu groß.'; }
		if (status === 422) { return 'Die Eingaben sind unvollständig oder passen nicht zusammen.'; }
		if (status >= 500) { return 'Auf dem Server ist etwas schiefgegangen. Bitte gleich noch einmal versuchen.'; }
		return 'Das hat nicht geklappt.';
	}

	function request(path, opts) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': C.nonce };
		if (opts.body) { headers['Content-Type'] = 'application/json'; }

		return fetch((opts.root ? C.api : C.rest) + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (json) {
				if (!res.ok) {
					var msg = json && json.code === 'rest_cookie_invalid_nonce'
						? 'Die Sitzung ist abgelaufen – bitte die Seite neu laden.'
						: (json && (json.message || json.error)) || httpText(res.status);
					var err = new Error(msg);
					err.status = res.status;
					err.code = json && json.code;
					throw err;
				}
				return json;
			});
		});
	}

	/*
	 * opts.cache  – GET: letzte Antwort merken und bei fehlender Verbindung zeigen.
	 * opts.queue  – POST: bei fehlender Verbindung vormerken und später senden.
	 * Eine Antwort des Servers (auch ein Fehler) ist nie „offline“; nur wenn
	 * gar keine Antwort kommt, greifen Zwischenspeicher und Warteschlange.
	 */
	function api(path, opts) {
		opts = opts || {};
		var method = opts.method || 'GET';

		return request(path, opts).then(function (json) {
			if (method === 'GET' && opts.cache) { cacheSet(opts.root, path, json); }
			if (stale) { stale = false; syncNet(); }
			return json;
		}).catch(function (err) {
			if (err.status) { throw err; }

			if (method === 'GET' && opts.cache) {
				var hit = cacheGet(opts.root, path);
				if (hit) { stale = true; syncNet(); return hit.data; }
			}

			if (method === 'POST' && opts.queue) {
				var body = Object.assign({}, opts.body, { client_ref: (opts.body && opts.body.client_ref) || uid() });
				var list = queue();
				list.push({ id: body.client_ref, path: path, root: !!opts.root, body: body, ts: Date.now() });
				writeJson(QUEUE_KEY, list);
				syncNet();
				return { queued: true };
			}

			throw new Error('Keine Verbindung zum Server.');
		});
	}

	function refreshNonce() {
		return fetch(C.erpUrl, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
			var m = html.match(/"nonce":"([a-f0-9]+)"/);
			if (!m) { throw new Error('Neue Sitzung nötig.'); }
			C.nonce = m[1];
		});
	}

	// Vorgemerktes der Reihe nach senden. Antwortet der Server mit einem
	// Fehler, wird der Eintrag verworfen und gemeldet (er würde nie
	// gelingen); fehlt die Verbindung, bleibt alles stehen.
	function flush() {
		if (queueBusy || !queue().length) { return Promise.resolve(); }
		queueBusy = true;

		function next() {
			var list = queue();
			if (!list.length) { return Promise.resolve(); }
			var item = list[0];
			var done = function () { writeJson(QUEUE_KEY, queue().filter(function (x) { return x.id !== item.id; })); syncNet(); return next(); };

			return request(item.path, { root: item.root, method: 'POST', body: item.body }).then(done).catch(function (err) {
				if (err.code === 'rest_cookie_invalid_nonce') {
					return refreshNonce().then(function () {
						return request(item.path, { root: item.root, method: 'POST', body: item.body }).then(done);
					});
				}
				if (err.status && err.status < 500) {
					toast('Vorgemerkte Buchung abgelehnt: ' + err.message, true);
					return done();
				}
				throw err;
			});
		}

		return next().then(function () { toast('Vorgemerktes gesendet'); }).catch(function () { /* später erneut */ }).then(function () { queueBusy = false; syncNet(); });
	}

	function syncNet() {
		if (!el.net) { return; }
		var n = queue().length;
		var off = !navigator.onLine || stale;
		el.net.hidden = !off && !n;
		el.net.textContent = (off ? 'Offline' : '') + (off && n ? ' · ' : '') + (n ? n + ' vorgemerkt' : '');
		el.net.className = 'net-pill' + (off ? ' off' : '');
	}

	function toast(msg, isErr) {
		var t = h('div', { class: 'toast' + (isErr ? ' err' : ''), role: 'status' }, msg);
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, isErr ? 6000 : 2600);
	}

	function money(v, cur) {
		try { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: cur || 'EUR' }).format(v); }
		catch (e) { return String(v); }
	}

	function when(ts) {
		if (!ts) { return ''; }
		return new Date(ts * 1000).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
	}

	function areaOf(id) {
		return (S.boot.nav.filter(function (a) { return a.id === id; })[0]) || null;
	}

	function findItem(id) {
		for (var i = 0; i < S.boot.nav.length; i++) {
			var items = S.boot.nav[i].items;
			for (var j = 0; j < items.length; j++) {
				if (items[j].id === id) { return { item: items[j], area: S.boot.nav[i] }; }
			}
		}
		return null;
	}

	function route() {
		var parts = (location.hash || '#/start').replace(/^#\/?/, '').split('/');
		return { name: parts[0] || 'start', id: parts[1] || '', sub: parts[2] || '' };
	}

	/*
	 * Menüführung: links die Bereiche, oben die Reiter, darunter bei Bedarf
	 * die Auswahl innerhalb eines Reiters. Zuletzt besuchte Seiten werden
	 * gemerkt, damit ein Klick auf den Bereich dort weitermacht.
	 */
	var LAST_KEY = 'lw16erp-last';

	function lastSeen() { return readJson(LAST_KEY, {}); }

	function remember(section, tab, id) {
		var last = lastSeen();
		last[section] = id;
		last[section + ':' + tab] = id;
		writeJson(LAST_KEY, last);
	}

	function hasItem(items, id) {
		return items.some(function (i) { return i.id === id; });
	}

	function sectionTarget(sec) {
		var last = lastSeen();
		if (last[sec.id] && hasItem(sec.items, last[sec.id])) { return last[sec.id]; }
		return sec.items.length ? sec.items[0].id : '';
	}

	function tabTarget(sec, tab) {
		var last = lastSeen();
		var id = last[sec.id + ':' + tab.label];
		return id && hasItem(tab.items, id) ? id : tab.items[0].id;
	}

	function railAreas() {
		return S.boot.nav.filter(function (s) { return s.rail; });
	}

	/* ---------- Gerüst ---------- */

	function buildShell() {
		var boot = S.boot;
		var theme = store('lw16erp-theme');
		if (theme) { document.documentElement.setAttribute('data-theme', theme); }

		var rail = h('nav', { class: 'nav', 'aria-label': 'Bereiche' });
		el.nav = rail;
		rail.appendChild(h('a', { class: 'rail-link', href: '#/start', 'data-r': 'start' }, ico('home'), h('span', null, 'Heute')));
		railAreas().forEach(function (area) {
			rail.appendChild(h('a', { class: 'rail-link', href: '#/bereich/' + area.id, 'data-area': area.id }, ico(area.icon), h('span', null, area.title)));
		});

		var mail = areaOf('mail');
		el.mailBtn = mail ? h('a', { class: 'rail-mini', href: '#/bereich/mail', 'data-area': 'mail', 'aria-label': 'Mailbox' }, ico('mail'), h('b', { class: 'badge', hidden: true })) : null;
		el.avatarBtn = h('button', { class: 'avatar-btn', type: 'button', 'aria-label': 'Profil und Einstellungen', 'aria-haspopup': 'true', onclick: function (e) { e.stopPropagation(); toggleProfile(); } },
			h('img', { src: boot.user.avatar, alt: '', width: 40, height: 40 }));

		var side = h('aside', { class: 'side rail', id: 'side' },
			h('div', { class: 'brand' }, h('div', { class: 'brand-mark', 'aria-hidden': 'true' }, 'L')),
			rail,
			h('div', { class: 'rail-foot' }, el.mailBtn, el.avatarBtn)
		);

		el.net = h('button', { class: 'net-pill', type: 'button', hidden: true, title: 'Jetzt senden', onclick: flush });
		el.title = h('h1', null, '');
		el.crumb = h('span', { class: 'crumb' }, '');
		el.themeBtn = h('button', { class: 'pop-item', type: 'button', onclick: toggleTheme }, ico('moon'), h('span', null, 'Hell/Dunkel'));

		var time = findItem('zeit');
		var top = h('header', { class: 'top' },
			h('div', { class: 'top-title' }, el.crumb, el.title),
			el.net,
			h('button', { class: 'search-btn', type: 'button', onclick: openPalette }, ico('search'), 'Gehe zu … Seite, Artikel, Bestellung', h('kbd', null, navigator.platform.indexOf('Mac') > -1 ? '⌘K' : 'Strg K')),
			h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Suchen', id: 'search-icon', onclick: openPalette }, ico('search')),
			time ? h('a', { class: 'clock-pill', href: '#/seite/zeit', id: 'clock-pill' }, ico('clock'), el.clockLabel = h('span', null, 'Zeit')) : null,
			el.mailBtn ? h('a', { class: 'icon-btn mobile-only', href: '#/bereich/mail', 'aria-label': 'Mailbox' }, ico('mail')) : null
		);

		el.sub = h('div', { class: 'subnav', hidden: true });
		el.view = h('main', { class: 'view', id: 'view', tabindex: '-1' });

		var tabs = h('nav', { class: 'tabbar', 'aria-label': 'Bereiche' },
			h('a', { class: 'tab', href: '#/start', 'data-r': 'start' }, ico('home'), 'Heute')
		);
		var rails = railAreas();
		rails.slice(0, 3).forEach(function (area) {
			tabs.appendChild(h('a', { class: 'tab', href: '#/bereich/' + area.id, 'data-area': area.id }, ico(area.icon), area.title));
		});
		tabs.appendChild(h('button', { class: 'tab', type: 'button', 'data-more': '1', onclick: openMore }, ico('dots'), 'Mehr'));

		el.pop = h('div', { class: 'pop', hidden: true, role: 'menu' });

		el.app = h('div', { class: 'erp' },
			side,
			h('div', { class: 'main' }, top, el.sub, el.view),
			tabs,
			el.pop
		);

		root.textContent = '';
		root.className = '';
		root.appendChild(el.app);
		el.tabs = tabs;
		el.moreAreas = rails.slice(3);
		syncTheme();

		document.addEventListener('click', function (e) {
			if (!el.pop.hidden && !el.pop.contains(e.target)) { el.pop.hidden = true; }
		});

		// Auf dem Desktop zeigt das große Suchfeld, auf dem Handy nur das Symbol.
		var mq = window.matchMedia('(min-width: 900px)');
		var iconBtn = document.getElementById('search-icon');
		function mqSync() { iconBtn.style.display = mq.matches ? 'none' : 'inline-grid'; }
		mqSync();
		if (mq.addEventListener) { mq.addEventListener('change', mqSync); }
	}

	function profileItems() {
		var sec = areaOf('profil');
		return sec ? sec.items : [];
	}

	function toggleProfile() {
		var boot = S.boot;
		if (!el.pop.hidden) { el.pop.hidden = true; return; }

		el.pop.textContent = '';
		el.pop.appendChild(h('div', { class: 'pop-head' },
			h('img', { src: boot.user.avatar, alt: '', width: 40, height: 40 }),
			h('div', { style: 'min-width:0' }, h('b', null, boot.user.name), h('small', null, boot.user.full ? 'Vollzugriff' : 'ERP-Zugang'))
		));
		profileItems().forEach(function (i) {
			el.pop.appendChild(h('a', { class: 'pop-item', href: '#/seite/' + i.id, role: 'menuitem', onclick: function () { el.pop.hidden = true; } }, ico(i.locked ? 'lock' : 'cog'), h('span', null, i.title)));
		});
		el.pop.appendChild(h('hr'));
		el.pop.appendChild(el.themeBtn);
		el.pop.appendChild(h('a', { class: 'pop-item', href: boot.shop.url }, ico('shop'), h('span', null, 'Zum Shop')));
		if (boot.user.full) { el.pop.appendChild(h('a', { class: 'pop-item', href: C.adminUrl }, ico('ext'), h('span', null, 'WordPress-Verwaltung'))); }
		el.pop.appendChild(h('a', { class: 'pop-item', href: C.logout }, ico('logout'), h('span', null, 'Abmelden')));
		el.pop.hidden = false;
	}

	// Handy: alles, was nicht in die untere Leiste passt.
	function openMore() {
		var body = h('div', { class: 'more' });
		el.moreAreas.forEach(function (a) {
			body.appendChild(h('a', { class: 'more-item', href: '#/bereich/' + a.id, onclick: closeDlg }, ico(a.icon), h('span', null, a.title)));
		});
		if (areaOf('mail')) { body.appendChild(h('a', { class: 'more-item', href: '#/bereich/mail', onclick: closeDlg }, ico('mail'), h('span', null, 'Mailbox'))); }
		profileItems().forEach(function (i) {
			body.appendChild(h('a', { class: 'more-item', href: '#/seite/' + i.id, onclick: closeDlg }, ico('cog'), h('span', null, i.title)));
		});
		body.appendChild(h('a', { class: 'more-item', href: S.boot.shop.url }, ico('shop'), h('span', null, 'Zum Shop')));
		body.appendChild(h('a', { class: 'more-item', href: C.logout }, ico('logout'), h('span', null, 'Abmelden')));
		dlg('Mehr', body);

		function closeDlg() { var d = document.querySelector('dialog.sheet[open]'); if (d) { d.close(); } }
	}

	function isDark() {
		var t = document.documentElement.getAttribute('data-theme');
		if (t) { return t === 'dark'; }
		return window.matchMedia('(prefers-color-scheme: dark)').matches;
	}

	function syncTheme() {
		el.themeBtn.textContent = '';
		el.themeBtn.appendChild(ico(isDark() ? 'sun' : 'moon'));
		el.themeBtn.appendChild(h('span', null, isDark() ? 'Helle Ansicht' : 'Dunkle Ansicht'));
	}

	function toggleTheme() {
		var next = isDark() ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', next);
		store('lw16erp-theme', next);
		syncTheme();
	}

	function syncNav() {
		var r = route();
		var activeArea = '';

		if (r.name === 'bereich') { activeArea = r.id; }
		if (r.name === 'seite') {
			var f = findItem(r.id);
			activeArea = f ? f.area.id : '';
		}
		if (r.name === 'drucker') { activeArea = 'produktion'; }
		if (r.name === 'produkt') { activeArea = 'katalog'; }
		if (r.name === 'bestellung') { activeArea = 'verkauf'; }

		Array.prototype.forEach.call(el.app.querySelectorAll('[data-r]'), function (a) {
			a.classList.toggle('active', a.getAttribute('data-r') === r.name);
		});
		Array.prototype.forEach.call(el.app.querySelectorAll('[data-area]'), function (a) {
			a.classList.toggle('active', activeArea === a.getAttribute('data-area'));
		});
		var more = el.tabs.querySelector('[data-more]');
		var inRail = railAreas().slice(0, 3).some(function (a) { return a.id === activeArea; });
		if (more) { more.classList.toggle('active', !!activeArea && !inRail); }
	}

	// Reiter des Bereichs und, wenn ein Reiter mehrere Seiten hat, deren Auswahl.
	function renderSub(f) {
		el.sub.textContent = '';
		var sec = f ? f.area : null;

		if (!sec || sec.id === 'profil' || !sec.tabs) {
			el.sub.hidden = true;
			el.app.classList.remove('has-sub');
			document.documentElement.style.setProperty('--sub', '0px');
			return;
		}

		var active = null;
		var strip = h('div', { class: 'tabs', role: 'tablist' });

		sec.tabs.forEach(function (t) {
			var on = hasItem(t.items, f.item.id);
			if (on) { active = t; }
			strip.appendChild(h('a', { class: 'tabx' + (on ? ' on' : ''), role: 'tab', 'aria-selected': on ? 'true' : 'false', href: '#/seite/' + tabTarget(sec, t) }, t.label));
		});

		el.sub.appendChild(h('div', { class: 'sec-title' }, h('h2', null, sec.title), h('span', null, sec.hint)));
		if (sec.tabs.length > 1) { el.sub.appendChild(strip); }

		if (active && active.items.length > 1) {
			var pills = h('div', { class: 'pills' });
			active.items.forEach(function (i) {
				pills.appendChild(h('a', { class: 'pill' + (i.id === f.item.id ? ' on' : ''), href: '#/seite/' + i.id }, i.title));
			});
			el.sub.appendChild(pills);
		}

		el.sub.hidden = false;
		el.app.classList.add('has-sub');
		if (active) { remember(sec.id, active.label, f.item.id); }
		window.requestAnimationFrame(function () {
			document.documentElement.style.setProperty('--sub', el.sub.offsetHeight + 'px');
		});
	}

	function setHead(title, crumb) {
		el.title.textContent = title;
		el.crumb.textContent = crumb || '';
		document.title = title + ' · ERP · ' + S.boot.shop.name;
	}

	/* ---------- Seiten ---------- */

	function render() {
		// Ungespeicherte Rechte nicht stillschweigend verwerfen.
		if (S.draft && S.rights && isDirty() && location.hash !== S.lastHash) {
			if (!window.confirm('Es gibt ungespeicherte Änderungen an den Rechten. Seite trotzdem verlassen?')) {
				location.hash = S.lastHash;
				return;
			}
			S.draft = null;
		}

		S.lastHash = location.hash || '#/start';
		timers.forEach(function (t) { clearInterval(t); });
		timers = [];
		stopCamera();
		el.app.classList.remove('drawer-open', 'kiosk');
		syncNav();

		var r = route();
		el.view.classList.remove('wide', 'broad');
		el.view.textContent = '';

		el.sub.hidden = true;
		el.app.classList.remove('has-sub');
		document.documentElement.style.setProperty('--sub', '0px');

		if (r.name === 'bereich' && areaOf(r.id)) {
			var target = sectionTarget(areaOf(r.id));
			if (target) { location.replace('#/seite/' + target); return; }
			return viewArea(areaOf(r.id));
		}
		if (r.name === 'seite') { return viewPage(r.id); }
		if (UI.routes[r.name]) { return UI.routes[r.name](r, UI); }
		if (r.name === 'platte') { return viewRun(parseInt(r.id, 10) || 0); }
		if (r.name === 'drucker') { return viewPrinterFile(r.id); }
		if (r.name === 'produkt') { return viewProduct(parseInt(r.id, 10) || 0, r.sub); }
		if (r.name === 'bestellung') { return viewShipOrder(parseInt(r.id, 10) || 0); }
		return viewStart();
	}

	function viewStart() {
		var boot = S.boot;
		var hour = new Date().getHours();
		var hello = hour < 11 ? 'Guten Morgen' : (hour < 18 ? 'Guten Tag' : 'Guten Abend');
		setHead('Heute', boot.shop.name);

		el.view.appendChild(h('div', { class: 'page-head today-head' },
			h('div', null,
				h('p', { class: 'muted', style: 'margin:0' }, new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })),
				h('h2', null, hello + ', ' + boot.user.name.split(' ')[0]))
		));

		var next = h('div');
		var list = h('div', { class: 'tasks' });
		var tiles = h('div', { class: 'grid tiles' });
		var ordersCard = h('div', { class: 'card' }, h('h3', null, 'Letzte Bestellungen'), h('p', { class: 'muted' }, 'Wird geladen …'));

		el.view.appendChild(h('div', { class: 'today' },
			h('div', { class: 'today-main' }, next, list),
			h('div', { class: 'today-side' }, tiles, ordersCard)
		));

		function paintTasks(d) {
			next.textContent = '';
			list.textContent = '';
			var tasks = d.tasks || [];

			if (el.mailBtn) {
				var badge = el.mailBtn.querySelector('.badge');
				badge.textContent = String(d.mail || 0);
				badge.hidden = !d.mail;
			}

			if (!tasks.length) {
				next.appendChild(h('div', { class: 'next calm' },
					h('small', null, 'Heute'),
					h('b', null, 'Alles erledigt'),
					h('span', null, 'Gerade wartet nichts auf dich.')
				));
				return;
			}

			var first = tasks[0];
			next.appendChild(h('div', { class: 'next' },
				h('small', null, 'Dein Nächstes'),
				h('b', null, first.title),
				h('span', null, first.sub),
				h('a', { class: 'next-go', href: first.href }, first.cta)
			));

			tasks.slice(1).forEach(function (t) {
				list.appendChild(h('a', { class: 'task t-' + t.kind, href: t.href },
					h('div', { class: 'task-main' }, h('b', null, t.title), h('small', null, t.sub)),
					h('span', { class: 'task-cta' }, t.cta, ico('chev'))
				));
			});
		}

		var skel = ['a', 'b', 'c', 'd'].map(function () { return h('div', { class: 'tile skeleton' }, h('div', { class: 'lab' }, ' '), h('div', { class: 'val' })); });
		skel.forEach(function (s) { tiles.appendChild(s); });

		function load() {
			return Promise.all([
				api('aufgaben').then(paintTasks).catch(function (e) { next.textContent = ''; next.appendChild(h('div', { class: 'notice warn' }, e.message)); }),
				api('dashboard').then(paint)
			]);
		}

		function paint(d) {
			S.dash = d;
			tiles.textContent = '';
			d.tiles.forEach(function (t) {
				var tag = t.go ? 'a' : 'div';
				tiles.appendChild(h(tag, { class: 'tile', href: t.go ? '#/seite/' + t.go : null },
					h('div', { class: 'lab' }, t.label),
					h('div', { class: 'val' }, t.type === 'money' ? money(t.value, d.currency) : String(t.value)),
					t.note ? h('div', { class: 'note' }, t.note) : null
				));
			});
			if (!d.tiles.length) {
				tiles.appendChild(h('div', { class: 'notice' }, d.shop ? 'Für dein Profil gibt es keine Kennzahlen.' : 'WooCommerce ist nicht aktiv – keine Shop-Kennzahlen.'));
			}
			ordersCard.textContent = '';
			ordersCard.appendChild(h('h3', null, 'Letzte Bestellungen'));
			if (!d.orders.length) {
				ordersCard.appendChild(h('p', { class: 'muted' }, d.tiles.length || d.shop ? 'Keine Bestellungen oder keine Berechtigung.' : ''));
				return;
			}
			var tb = h('tbody');
			d.orders.forEach(function (o) {
				tb.appendChild(h('tr', null,
					h('td', null, h('b', null, '#' + o.number), h('div', { class: 'muted', style: 'font-size:12px' }, when(o.ts))),
					h('td', null, o.name, h('div', { class: 'muted', style: 'font-size:12px' }, o.status)),
					h('td', { class: 'num' }, money(o.total, d.currency))
				));
			});
			ordersCard.appendChild(h('table', { class: 'list' }, tb));
		}

		function fail(e) {
			tiles.textContent = '';
			tiles.appendChild(h('div', { class: 'notice warn' }, e.message));
		}

		// Erst die zuletzt bekannten Zahlen zeigen, dann frisch laden und
		// danach jede Minute – ein Monitor in der Werkstatt soll nicht
		// veralten. Im Hintergrund-Tab wird nicht nachgeladen.
		if (S.dash) { paint(S.dash); }
		load().catch(fail);
		timers.push(setInterval(function () {
			if (!document.hidden) { load().catch(function () {}); }
		}, 60000));
	}

	function viewArea(area) {
		setHead(area.title, 'Bereich');
		el.view.appendChild(h('div', { class: 'page-head' }, h('div', null, h('h2', null, area.title), h('p', { class: 'muted', style: 'margin:4px 0 0' }, area.hint))));
		var grid = h('div', { class: 'grid cards area-' + area.id });
		area.items.forEach(function (item) {
			grid.appendChild(h('a', { class: 'link-card' + (item.locked ? ' locked' : ''), href: '#/seite/' + item.id },
				h('div', { class: 'badge-ico' }, ico(item.locked ? 'lock' : area.icon)),
				h('div', null, h('b', null, item.title), h('span', { class: 'sub' }, item.hint || ''))
			));
		});
		el.view.appendChild(grid);
	}

	function viewPage(id) {
		var f = findItem(id);

		if (!f) {
			setHead('Nicht gefunden');
			el.view.appendChild(h('div', { class: 'notice warn' }, 'Diese Seite gibt es nicht oder du hast dafür kein Recht.'));
			return;
		}

		setHead(f.item.title, f.area.title);
		renderSub(f);

		if (f.item.kind === 'native') {
			if (UI.pages[f.item.id]) { return UI.pages[f.item.id](f.item, UI); }
			if (f.item.id === 'rechte') { return viewRights(); }
			if (f.item.id === 'scannen') { return viewScan(); }
			if (f.item.id === 'picken') { return viewPick(); }
			if (f.item.id === 'workflow') { return viewWorkflow(); }
			if (f.item.id === 'druckfarm') { return viewFarm(f.item); }
			if (f.item.id === 'auftraege') { return viewOrders(f.item); }
			if (f.item.id === 'versand') { return viewShipping(f.item); }
			if (f.item.id === 'einkauf') { return viewProcurement(); }
			if (f.item.id === 'material') { return viewMaterial(f.item); }
			if (f.item.id === 'artikel') { return viewProducts(f.item); }
			if (f.item.id === 'sollist') { return viewVariance(); }
		}

		if (f.item.locked) {
			el.view.appendChild(h('div', { class: 'notice warn' }, h('b', null, f.item.title), h('p', { style: 'margin:6px 0 0' }, f.item.locked)));
			return;
		}

		el.view.classList.add('wide');

		var path = f.item.path;
		var src = C.adminUrl + path + (path.indexOf('?') > -1 ? '&' : '?') + 'lw16_embed=1';
		var loading = h('div', { class: 'frame-loading' }, 'Wird geladen …');
		var frame = h('iframe', { src: src, title: f.item.title, loading: 'eager', onload: function () { loading.classList.add('hide'); } });

		el.view.appendChild(h('div', { class: 'frame-wrap' },
			h('div', { class: 'frame-bar' },
				h('span', { class: 'grow' }, f.item.hint || f.item.title),
				h('button', { class: 'btn', type: 'button', onclick: function () { loading.classList.remove('hide'); frame.src = src; } }, ico('refresh'), 'Neu laden'),
				h('a', { class: 'btn', href: C.adminUrl + path, target: '_blank', rel: 'noopener' }, ico('ext'), 'Eigener Tab')
			),
			h('div', { class: 'frame-box' }, frame, loading)
		));
	}

	/* ---------- Rechte ---------- */

	function permIndex() {
		var idx = {};
		S.rights.groups.forEach(function (g) { g.perms.forEach(function (p) { p.group = g.id; idx[p.key] = p; }); });
		return idx;
	}

	function person() {
		return S.rights.people.filter(function (p) { return p.id === S.sel; })[0] || null;
	}

	function isDirty() {
		var p = person();
		if (!p || !S.draft) { return false; }
		var a = p.keys.slice().sort().join('|');
		var b = Array.from(S.draft).sort().join('|');
		return a !== b;
	}

	function selectPerson(id) {
		if (S.sel && S.sel !== id && isDirty() && !window.confirm('Ungespeicherte Änderungen verwerfen?')) { return; }
		S.sel = id;
		var p = person();
		S.draft = new Set(p ? p.keys : []);
		paintRights();
	}

	function touchable(p, key, idx) {
		var def = idx[key];
		return !p.full && def.scope !== 'full' && def.mine && !(p.self && key === 'verwaltung.rechte');
	}

	function setKey(key, on, idx) {
		if (on) {
			var addKey = function (k) {
				if (S.draft.has(k) || !idx[k] || idx[k].scope === 'full') { return; }
				S.draft.add(k);
				idx[k].requires.forEach(addKey);
			};
			addKey(key);
		} else {
			var drop = function (k) {
				if (!S.draft.has(k)) { return; }
				S.draft.delete(k);
				Object.keys(idx).forEach(function (o) { if (idx[o].requires.indexOf(k) > -1) { drop(o); } });
			};
			drop(key);
		}
	}

	function applyTarget(keys, idx) {
		var p = person();
		var target = new Set(keys);
		Object.keys(idx).forEach(function (k) {
			if (touchable(p, k, idx)) { if (target.has(k)) { setKey(k, true, idx); } else { setKey(k, false, idx); } }
		});
	}

	function viewRights() {
		el.view.appendChild(h('div', { class: 'page-head' }, h('div', null,
			h('h2', null, 'Benutzer & Rechte'),
			h('p', { class: 'muted', style: 'margin:4px 0 0' }, 'Konten kommen aus WordPress. Hier bestimmst du pro Person, was sie im ERP sieht und darf.'))));

		var box = h('div', { class: 'rights' });
		el.view.appendChild(box);
		el.rights = box;

		if (S.rights) { return paintRights(); }

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		api('users').then(function (d) {
			S.rights = d;
			var first = d.people.filter(function (p) { return !p.full; })[0] || d.people[0];
			S.sel = first ? first.id : 0;
			S.draft = new Set(first ? first.keys : []);
			if (route().id === 'rechte') { paintRights(); }
		}).catch(function (e) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'notice warn' }, e.message));
		});
	}

	function paintRights() {
		if (!el.rights || !el.rights.isConnected) { return; }
		var box = el.rights;
		box.textContent = '';

		var listCard = h('aside', { class: 'card people' });
		box.appendChild(listCard);
		var editor = h('section', { 'aria-live': 'polite' });
		box.appendChild(editor);
		el.people = listCard;
		el.editor = editor;

		paintPeople();
		paintEditor();
	}

	function paintPeople() {
		var d = S.rights;
		var card = el.people;
		card.textContent = '';

		var search = h('input', {
			type: 'search', placeholder: 'Person suchen …', 'aria-label': 'Person suchen', value: S.q,
			oninput: function (e) { S.q = e.target.value; paintPeopleList(); }
		});
		var filters = h('div', { class: 'filters' });
		[['all', 'Alle'], ['erp', 'Mit ERP-Zugriff'], ['none', 'Ohne']].forEach(function (f) {
			filters.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': S.filter === f[0] ? 'true' : 'false', onclick: function () { S.filter = f[0]; paintPeople(); } }, f[1]));
		});

		card.appendChild(search);
		card.appendChild(filters);
		el.peopleList = h('ul');
		card.appendChild(el.peopleList);
		card.appendChild(h('p', { class: 'muted', style: 'font-size:12.5px;margin:12px 0 0' }, 'Neue Person? ', h('a', { href: d.newUser, target: '_blank', rel: 'noopener' }, 'Konto in WordPress anlegen'), ' – danach hier erscheint sie sofort.'));
		paintPeopleList();
	}

	function paintPeopleList() {
		var q = S.q.trim().toLowerCase();
		el.peopleList.textContent = '';
		S.rights.people.filter(function (p) {
			var has = p.full || p.keys.length > 0;
			if (S.filter === 'erp' && !has) { return false; }
			if (S.filter === 'none' && has) { return false; }
			return !q || (p.name + ' ' + p.email).toLowerCase().indexOf(q) > -1;
		}).forEach(function (p) {
			var label = p.full ? 'Vollzugriff' : (p.keys.length ? (presetLabel(p.preset) || 'Individuell') : 'Kein Zugriff');
			el.peopleList.appendChild(h('li', null,
				h('button', { class: 'person', type: 'button', 'aria-current': p.id === S.sel ? 'true' : 'false', onclick: function () { selectPerson(p.id); } },
					h('img', { src: p.avatar, alt: '', width: 38, height: 38 }),
					h('div', { class: 'who' }, h('b', null, p.name + (p.self ? ' (du)' : '')), h('small', null, label))
				)
			));
		});
		if (!el.peopleList.children.length) { el.peopleList.appendChild(h('li', { class: 'muted', style: 'padding:8px' }, 'Niemand gefunden.')); }
	}

	function presetLabel(id) {
		var p = S.rights.presets.filter(function (x) { return x.id === id; })[0];
		return p ? p.label : '';
	}

	function currentPreset() {
		var have = Array.from(S.draft).sort().join('|');
		var hit = S.rights.presets.filter(function (p) { return p.keys.slice().sort().join('|') === have; })[0];
		return hit ? hit.id : '';
	}

	function paintEditor() {
		var d = S.rights;
		var p = person();
		var ed = el.editor;
		ed.textContent = '';

		if (!p) { ed.appendChild(h('div', { class: 'card' }, 'Keine Person gewählt.')); return; }

		if (!S.draft) { S.draft = new Set(p.keys); }

		var idx = permIndex();
		var cur = p.full ? 'vollzugriff' : currentPreset();

		ed.appendChild(h('div', { class: 'card' },
			h('div', { class: 'editor-head' },
				h('img', { src: p.avatar, alt: '', width: 56, height: 56 }),
				h('div', { style: 'flex:1;min-width:0' },
					h('h3', null, p.name),
					h('div', { class: 'muted', style: 'font-size:13px' }, p.email),
					h('div', { style: 'display:flex;gap:6px;margin-top:6px;flex-wrap:wrap' },
						p.roles.map(function (r) { return h('span', { class: 'tag' }, 'WP: ' + r); }),
						p.shop ? h('span', { class: 'tag now' }, 'Shop-Rolle') : null,
						p.stored ? null : (p.full ? null : h('span', { class: 'tag', title: 'Noch nie in dieser Oberfläche eingerichtet – die Rechte stammen aus dem bisherigen Backend.' }, 'aus altem Backend'))
					)
				)
			),
			p.full
				? h('div', { class: 'notice warn', style: 'margin-top:14px' }, h('b', null, 'Vollzugriff. '), 'Diese Person darf alles, auch die Module hinter „Nur Vollzugriff“. Das lässt sich hier nicht beschneiden – so kann sich niemand aussperren.')
				: [
					h('div', { class: 'presets', role: 'group', 'aria-label': 'Vorlagen' },
						d.presets.map(function (pr) {
							return h('button', {
								class: 'chip', type: 'button', 'aria-pressed': cur === pr.id ? 'true' : 'false', title: pr.hint,
								onclick: function () { applyTarget(pr.keys, idx); paintEditor(); }
							}, pr.label);
						})
					),
					h('div', { class: 'preset-hint' },
						cur ? 'Vorlage „' + presetLabel(cur) + '“: ' + d.presets.filter(function (x) { return x.id === cur; })[0].hint
							: 'Individuell – weicht von allen Vorlagen ab. Vorlagen sind nur ein Startpunkt, alles bleibt einzeln änderbar.')
				]
		));

		// Erst das Ergebnis („So sieht … das ERP“), dann die Einzelrechte: Wer eine Vorlage wählt,
		// muss die rund 50 Schalter nicht ansehen. Weicht die Person von allen Vorlagen ab,
		// sind die Einzelrechte gleich offen.
		ed.appendChild(previewCard(p, idx));

		var total = 0;
		d.groups.forEach(function (g) { total += g.perms.filter(function (x) { return x.scope !== 'full'; }).length; });

		var adv = h('details', { class: 'adv' }, h('summary', null, 'Einzelrechte anpassen (' + total + ')'));
		if (S.adv || (!cur && !p.full)) { adv.setAttribute('open', ''); }
		adv.addEventListener('toggle', function () { S.adv = adv.open; });
		d.groups.forEach(function (g) { adv.appendChild(groupCard(g, p, idx)); });
		ed.appendChild(adv);

		if (!p.full) {
			var dirty = isDirty();
			var n = changeCount(p);
			var bar = h('div', { class: 'savebar', hidden: !dirty },
				h('div', { class: 'grow' }, n + (n === 1 ? ' Änderung' : ' Änderungen') + ' für ' + p.name.split(' ')[0] + ' – noch nicht gespeichert'),
				h('button', { class: 'btn', type: 'button', onclick: function () { S.draft = new Set(p.keys); paintEditor(); } }, 'Zurücksetzen'),
				h('button', { class: 'btn primary', type: 'button', id: 'save-rights', onclick: function (e) { save(p, e.target); } }, 'Speichern')
			);
			ed.appendChild(bar);
		}
	}

	function changeCount(p) {
		var a = new Set(p.keys);
		var n = 0;
		S.draft.forEach(function (k) { if (!a.has(k)) { n++; } });
		a.forEach(function (k) { if (!S.draft.has(k)) { n++; } });
		return n;
	}

	function scopeTag(def) {
		if (def.scope === 'page') { return h('span', { class: 'tag now', title: 'Schaltet die Seite frei; wird beim Öffnen geprüft.' }, 'Seite'); }
		if (def.scope === 'cap') { return h('span', { class: 'tag now', title: 'Wirkt auch innerhalb der Seiten (z. B. blendet Preise aus).' }, 'Wirkt in den Seiten'); }
		if (def.scope === 'phase2') { return h('span', { class: 'tag later', title: 'Wird gespeichert, aber erst von nativen ERP-Seiten durchgesetzt. Das bisherige Backend kann das nicht trennen.' }, 'Folgt'); }
		return h('span', { class: 'tag', title: 'Dieses Modul prüft noch das Sammelrecht. Nur Administratoren; wird in einer späteren Stufe aufgeteilt.' }, 'Nur Vollzugriff');
	}

	function groupCard(g, p, idx) {
		var grantable = g.perms.filter(function (x) { return x.scope !== 'full'; });
		var on = grantable.filter(function (x) { return p.full || S.draft.has(x.key); }).length;
		var movable = g.perms.filter(function (x) { return touchable(p, x.key, idx); });

		var head = h('header', { class: 'area-' + g.id },
			ico(g.icon), h('h3', null, g.title), h('span', { class: 'muted', style: 'font-size:13px' }, on + ' von ' + grantable.length)
		);

		if (movable.length) {
			head.appendChild(h('button', { class: 'chip', type: 'button', onclick: function () { movable.forEach(function (x) { setKey(x.key, true, idx); }); paintEditor(); } }, 'Alle'));
			head.appendChild(h('button', { class: 'chip', type: 'button', onclick: function () { movable.forEach(function (x) { setKey(x.key, false, idx); }); paintEditor(); } }, 'Keine'));
		}

		var card = h('div', { class: 'card pgroup area-' + g.id }, head);

		g.perms.forEach(function (def) {
			var can = touchable(p, def.key, idx);
			var checked = p.full || (def.scope !== 'full' && S.draft.has(def.key));
			var id = 'perm-' + def.key.replace(/\W/g, '-');
			var tags = [scopeTag(def)];

			if (def.scope !== 'full' && !def.mine && !p.full) { tags.push(h('span', { class: 'tag', title: 'Du hast dieses Recht selbst nicht und kannst es daher nicht vergeben.' }, 'Nicht vergebbar für dich')); }
			if (p.self && def.key === 'verwaltung.rechte') { tags.push(h('span', { class: 'tag', title: 'Du kannst dir das Recht nicht selbst entziehen.' }, 'Gesperrt für dich selbst')); }
			if (def.requires.length) {
				tags.push(h('span', { class: 'tag', title: 'Schaltet automatisch mit: ' + def.requires.map(function (k) { return idx[k].label; }).join(', ') }, 'braucht ' + def.requires.map(function (k) { return idx[k].label; }).join(', ')));
			}

			card.appendChild(h('div', { class: 'prow' + (def.requires.length ? ' dep' : '') + (!can && !p.full ? ' off' : '') },
				h('label', { class: 'switch', for: id },
					h('input', {
						type: 'checkbox', id: id, checked: checked, disabled: !can,
						onchange: function (e) { setKey(def.key, e.target.checked, idx); paintEditor(); var again = document.getElementById(id); if (again) { again.focus(); } }
					}),
					h('i')
				),
				h('div', { class: 'txt' }, h('label', { for: id }, h('b', null, def.label)), h('small', null, def.hint), h('div', { class: 'tags' }, tags))
			));
		});

		return card;
	}

	function previewCard(p, idx) {
		var has = function (perm) { return p.full || S.draft.has(perm); };
		var nav = S.rights.nav;
		var card = h('div', { class: 'card preview' }, h('h3', null, 'So sieht ' + p.name.split(' ')[0] + ' das ERP'));
		var shown = 0;

		S.rights.groups.forEach(function (g) {
			var items = nav.filter(function (n) { return n.area === g.id && has(n.perm); });
			if (!items.length) { return; }
			shown += items.length;
			card.appendChild(h('h4', null, g.title));
			card.appendChild(h('ul', null, items.map(function (n) { return h('li', null, h('span', { class: 'chip on' }, n.title)); })));
		});

		var actions = Object.keys(idx).filter(function (k) {
			return idx[k].scope !== 'page' && idx[k].scope !== 'full' && has(k);
		}).length;

		if (!shown) { card.appendChild(h('p', { class: 'muted' }, 'Kein Zugriff – ' + p.name.split(' ')[0] + ' sieht beim Öffnen von /erp nur einen Hinweis.')); }
		else {
			card.appendChild(h('p', { class: 'muted', style: 'font-size:13px;margin:12px 0 0' }, shown + (shown === 1 ? ' Seite' : ' Seiten') + (actions ? ', dazu ' + actions + ' Aktions- und Datenrechte' : '') + '.'));
		}

		return card;
	}

	function save(p, btn) {
		btn.disabled = true;
		api('users/' + p.id + '/permissions', { method: 'POST', body: { keys: Array.from(S.draft) } }).then(function (res) {
			p.keys = res.keys;
			p.preset = res.preset;
			p.stored = true;
			S.draft = new Set(res.keys);
			paintPeopleList();
			paintEditor();
			toast('Gespeichert');
		}).catch(function (e) {
			btn.disabled = false;
			toast(e.message, true);
		});
	}

	/* ---------- Scannen ---------- */

	var TYPES = { bin: 'Lagerplatz', article: 'Artikel', batch: 'Charge', component: 'Komponente' };

	function can(key) {
		return S.boot.user.full || S.boot.perms.indexOf(key) > -1;
	}

	function stopCamera() {
		if (!camera) { return; }
		camera.stop = true;
		if (camera.stream) { camera.stream.getTracks().forEach(function (t) { t.stop(); }); }
		if (camera.box) { camera.box.remove(); }
		camera = null;
	}

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			if (window.jsQR) { return resolve(); }
			var s = document.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = function () { reject(new Error('Scanner konnte nicht geladen werden.')); };
			document.head.appendChild(s);
		});
	}

	// Kamera starten; ruft onCode genau einmal mit dem gelesenen Code auf.
	function startCamera(host, onCode) {
		stopCamera();

		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			toast('Die Kamera ist hier nicht verfügbar (nur über https und mit Erlaubnis).', true);
			return;
		}

		var video = h('video', { class: 'cam', playsinline: '', muted: '' });
		var box = h('div', { class: 'cam-box' }, video,
			h('button', { class: 'btn', type: 'button', onclick: stopCamera }, 'Kamera aus'));
		host.appendChild(box);
		camera = { box: box, stop: false, stream: null };
		var mine = camera;

		var detector = 'BarcodeDetector' in window ? new window.BarcodeDetector() : null;
		var canvas = document.createElement('canvas');
		var ctx = canvas.getContext('2d', { willReadFrequently: true });

		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
			if (mine.stop) { stream.getTracks().forEach(function (t) { t.stop(); }); return null; }
			mine.stream = stream;
			video.srcObject = stream;
			return video.play().then(function () { return detector ? null : loadScript(C.jsqr); });
		}).then(function () {
			function tick() {
				if (mine.stop || camera !== mine) { return; }
				if (video.readyState < 2) { return requestAnimationFrame(tick); }

				var done = function (code) { stopCamera(); onCode(code); };

				if (detector) {
					detector.detect(video).then(function (found) {
						if (found.length) { done(found[0].rawValue); } else { requestAnimationFrame(tick); }
					}).catch(function () { requestAnimationFrame(tick); });
					return;
				}

				canvas.width = video.videoWidth;
				canvas.height = video.videoHeight;
				ctx.drawImage(video, 0, 0);
				var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
				var hit = window.jsQR(img.data, img.width, img.height);
				if (hit && hit.data) { done(hit.data); } else { requestAnimationFrame(tick); }
			}
			tick();
		}).catch(function (e) {
			stopCamera();
			toast(e && e.name === 'NotAllowedError' ? 'Kamerazugriff wurde nicht erlaubt.' : (e.message || 'Kamera nicht startbar.'), true);
		});
	}

	function viewScan() {
		el.view.appendChild(h('div', { class: 'page-head' }, h('div', null,
			h('h2', null, 'Scannen'),
			h('p', { class: 'muted', style: 'margin:4px 0 0' }, 'Etikett scannen oder Nummer eintippen – ein Handscanner schreibt direkt ins Feld.'))));

		var out = h('div', { 'aria-live': 'polite' });
		var camHost = h('div');
		var input = h('input', { class: 'field scan-input', type: 'text', placeholder: 'Code scannen oder eintippen …', 'aria-label': 'Code', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false' });

		function scan(code) {
			code = String(code || '').trim();
			if (!code) { return; }
			input.value = '';
			out.textContent = '';

			// Etikett einer Platte: direkt zur Platte.
			var plate = code.match(/\/p\/(\d+)\/?$/) || code.match(/^LW16P:(\d+)$/i);
			if (plate) { location.hash = '#/platte/' + plate[1]; return; }
			out.appendChild(h('div', { class: 'card' }, 'Suche nach „' + code + '“ …'));

			api('scan?code=' + encodeURIComponent(code), { root: true, cache: true }).then(function (r) {
				showScan(out, r, scan);
			}).catch(function (e) {
				out.textContent = '';
				out.appendChild(h('div', { class: 'notice warn' }, e.status === 404 ? 'Zu „' + code + '“ gibt es nichts – weder Lagerplatz noch Artikel, Charge oder Komponente.' : e.message));
			}).then(function () { input.focus(); });
		}

		var form = h('form', { class: 'scan-form', onsubmit: function (e) { e.preventDefault(); scan(input.value); } },
			input,
			h('button', { class: 'btn primary', type: 'submit' }, 'Suchen'),
			h('button', { class: 'btn', type: 'button', onclick: function () { startCamera(camHost, scan); } }, 'Kamera')
		);

		el.view.appendChild(h('div', { class: 'card' }, form, camHost));
		el.view.appendChild(h('div', { style: 'margin-top:14px' }, out));
		input.focus();
	}

	function qty(n) {
		return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 }).format(n);
	}

	function showScan(out, r, rescan) {
		out.textContent = '';
		var card = h('div', { class: 'card result' },
			h('span', { class: 'tag now' }, TYPES[r.type] || r.type),
			h('h3', { style: 'margin:6px 0 2px;font-size:20px' }, r.label || r.code),
			h('div', { class: 'muted', style: 'font-family:var(--font-mono);font-size:13px' }, r.code)
		);
		out.appendChild(card);

		var st = r.stock;
		var articleId = r.type === 'article' ? r.id : (r.type === 'batch' && r.extra ? r.extra.article_id : 0);

		if (r.type === 'bin' && st) {
			card.appendChild(st.length ? h('table', { class: 'list', style: 'margin-top:12px' },
				h('thead', null, h('tr', null, h('th', null, 'Artikel'), h('th', null, 'Farbe'), h('th', { class: 'num' }, 'Menge'))),
				h('tbody', null, st.map(function (x) { return h('tr', null, h('td', null, h('b', null, x.sku), h('div', { class: 'muted', style: 'font-size:12px' }, x.name)), h('td', null, x.color || '–'), h('td', { class: 'num' }, qty(x.qty))); }))
			) : h('p', { class: 'muted' }, 'Dieser Platz ist leer.'));
		}

		if (articleId && st) {
			card.appendChild(h('p', { style: 'margin:12px 0 4px' }, 'Gesamtbestand: ', h('b', null, qty(st.gesamt)), st.ohne_platz ? h('span', { class: 'muted' }, ' · davon ohne Platz: ' + qty(st.ohne_platz)) : null));
			if (st.bins.length) {
				card.appendChild(h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th', null, 'Platz'), h('th', null, 'Farbe'), h('th', { class: 'num' }, 'Menge'))),
					h('tbody', null, st.bins.map(function (x) { return h('tr', null, h('td', null, x.bin || '–'), h('td', null, x.color || '–'), h('td', { class: 'num' }, qty(x.qty))); }))
				));
			}
			card.appendChild(bookForm(articleId, st, r.code, rescan));
		}

		if (r.type === 'component') { card.appendChild(h('p', { class: 'muted' }, 'Komponenten werden unter „Material“ gepflegt.')); }

		// Wareneingang: Liegt zu diesem Teil eine offene Bestellung, genügt ein Tippen.
		if ((r.type === 'article' || r.type === 'component') && can('lager.einkauf')) {
			var incoming = h('div');
			card.appendChild(incoming);
			api('einkauf/offen?type=' + r.type + '&id=' + r.id).then(function (d) {
				if (!d.open.length) { return; }
				incoming.appendChild(h('h4', { style: 'margin:16px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)' }, 'Wareneingang'));
				d.open.forEach(function (p) {
					incoming.appendChild(h('div', { class: 'incoming', style: 'margin-bottom:8px' },
						h('b', null, 'Bestellt: ' + num(p.qty) + ' ' + p.unit + (p.supplier ? ' bei ' + p.supplier : '')),
						h('small', { class: p.late ? 'tag later' : 'muted' }, p.expected_on ? (p.late ? p.late + ' Tage überfällig' : 'erwartet ' + p.expected_on) : 'bestellt ' + p.ordered_on),
						d.receive ? receiveForm(p, function () { rescan(r.code); }) : h('small', { class: 'muted' }, 'Buchen darfst du nicht.')));
				});
			}).catch(function () { /* ohne Verbindung oder Recht: kein Wareneingang an dieser Stelle */ });
		}
	}

	function bookForm(articleId, st, code, rescan) {
		if (!can('lager.buchen')) {
			return h('p', { class: 'muted', style: 'font-size:13px;margin-top:12px' }, h('span', { class: 'tag' }, 'Nur ansehen'), ' Bestand buchen darfst du nicht.');
		}

		var amount = h('input', { class: 'field', type: 'number', step: 'any', inputmode: 'decimal', required: '', placeholder: 'z. B. 5 oder -2', 'aria-label': 'Menge' });
		var kind = h('select', { class: 'field', 'aria-label': 'Grund' },
			[['korrektur', 'Korrektur'], ['produktion', 'Produktion'], ['ausschuss', 'Ausschuss'], ['muster', 'Muster abgegeben'], ['retoure', 'Retoure']].map(function (o) { return h('option', { value: o[0] }, o[1]); }));
		var where = h('select', { class: 'field', 'aria-label': 'Platz' },
			st.bins.map(function (b) { return h('option', { value: b.bin_id + '|' + b.color }, (b.bin || 'Platz') + (b.color ? ' · ' + b.color : '') + ' (' + qty(b.qty) + ')'); }),
			h('option', { value: '0|' }, 'ohne Platz'));
		var note = h('input', { class: 'field', type: 'text', placeholder: 'Notiz (optional)', 'aria-label': 'Notiz' });

		return h('form', {
			class: 'book', onsubmit: function (e) {
				e.preventDefault();
				var q = parseFloat(String(amount.value).replace(',', '.'));
				if (!q) { toast('Bitte eine Menge ungleich 0 eintragen.', true); return; }
				var w = where.value.split('|');
				var btn = e.target.querySelector('button');
				btn.disabled = true;
				api('lager/bewegung', { root: true, method: 'POST', queue: true, body: { article_id: articleId, qty: q, movement_type: kind.value, bin_id: parseInt(w[0], 10) || 0, color: w[1] || '', note: note.value } }).then(function () {
					toast('Gebucht');
					rescan(code);
				}).catch(function (err) {
					btn.disabled = false;
					toast(err.message, true);
				});
			}
		},
		h('h4', { style: 'margin:16px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)' }, 'Bestand buchen'),
		h('div', { class: 'book-grid' }, amount, kind, where, note, h('button', { class: 'btn primary', type: 'submit' }, 'Buchen')),
		h('p', { class: 'muted', style: 'font-size:12.5px;margin:6px 0 0' }, 'Plus = Zugang, Minus = Abgang. Jede Buchung steht einzeln im Lagerjournal.'));
	}

	/* ---------- Picklisten ---------- */

	function viewPick() {
		el.view.appendChild(h('div', { class: 'page-head' }, h('div', null,
			h('h2', null, 'Picklisten'),
			h('p', { class: 'muted', style: 'margin:4px 0 0' }, 'Offene Listen abarbeiten: Position entnehmen oder den Artikel scannen.'))));

		var box = h('div');
		el.view.appendChild(box);
		var open = S.pickOpen || 0;

		function load() {
			return api('picklisten', { root: true, cache: true }).then(function (lists) { paint(lists); });
		}

		var current = [];

		function pick(item, scanned) {
			return api('picklisten/position', { root: true, method: 'POST', queue: true, body: { item_id: item.id, qty: 0, scanned: scanned || '' } }).then(function (r) {
				if (r && r.queued) {
					// Ohne Verbindung: sofort in der Anzeige und im Zwischenspeicher abhaken.
					item.qty_picked = item.qty_required;
					item.done = true;
					current.forEach(function (l) { l.open = l.items.filter(function (i) { return !i.done; }).length; });
					cacheSet(true, 'picklisten', current);
					toast('Offline vorgemerkt');
					return paint(current);
				}
				toast(r.done ? 'Position vollständig' : 'Entnommen');
				return load();
			}).catch(function (e) { toast(e.message, true); });
		}

		function paint(lists) {
			current = lists;
			box.textContent = '';

			if (!lists.length) {
				box.appendChild(h('div', { class: 'notice' }, 'Keine offenen Picklisten. Neue entstehen aus Bestellungen und Fertigungsaufträgen.'));
				return;
			}

			var list = lists.filter(function (l) { return l.id === open; })[0];

			if (!list) {
				var grid = h('div', { class: 'grid cards' });
				lists.forEach(function (l) {
					grid.appendChild(h('button', { class: 'link-card area-verkauf', type: 'button', style: 'text-align:left;font:inherit;color:inherit;width:100%', onclick: function () { open = l.id; S.pickOpen = l.id; paint(lists); } },
						h('div', { class: 'badge-ico' }, ico('box')),
						h('div', null, h('b', null, l.code), h('span', { class: 'sub' }, l.open + ' von ' + l.items.length + ' Positionen offen' + (l.note ? ' · ' + l.note : '')))
					));
				});
				box.appendChild(grid);
				return;
			}

			var scanIn = h('input', { class: 'field scan-input', type: 'text', placeholder: 'Artikel scannen …', 'aria-label': 'Artikel scannen', autocomplete: 'off' });
			var camHost = h('div');

			function byScan(code) {
				code = String(code || '').trim();
				if (!code) { return; }
				scanIn.value = '';
				var value = code.replace(/^LW16A:/i, '').toUpperCase();
				var todo = list.items.filter(function (i) { return !i.done; });
				var hit = todo.filter(function (i) { return i.sku.toUpperCase() === value; })[0];

				if (hit) { return pick(hit, code); }

				// Fremde Barcodes (EAN) kennt nur der Server: Artikel auflösen.
				api('scan?code=' + encodeURIComponent(code), { root: true, cache: true }).then(function (r) {
					var match = todo.filter(function (i) { return i.item_type !== 'komponente' && r.type === 'article' && i.article_id === r.id; })[0];
					if (match) { return pick(match, code); }
					toast('„' + (r.label || code) + '“ gehört zu keiner offenen Position dieser Liste.', true);
				}).catch(function () { toast('Code nicht erkannt.', true); });
			}

			box.appendChild(h('div', { class: 'card' },
				h('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px' },
					h('button', { class: 'btn', type: 'button', onclick: function () { open = 0; S.pickOpen = 0; paint(lists); } }, '← Alle Listen'),
					h('h3', { style: 'margin:0' }, list.code),
					h('span', { class: 'muted' }, list.open + ' offen')
				),
				h('form', { class: 'scan-form', onsubmit: function (e) { e.preventDefault(); byScan(scanIn.value); } },
					scanIn,
					h('button', { class: 'btn primary', type: 'submit' }, 'Abhaken'),
					h('button', { class: 'btn', type: 'button', onclick: function () { startCamera(camHost, byScan); } }, 'Kamera')
				),
				camHost
			));

			var items = list.items.slice().sort(function (a, b) { return (a.done - b.done) || a.bin.localeCompare(b.bin); });
			var rows = h('div', { class: 'picks' });
			items.forEach(function (i) {
				rows.appendChild(h('div', { class: 'pick' + (i.done ? ' done' : '') },
					h('div', { class: 'pick-bin' }, i.bin || '–'),
					h('div', { class: 'pick-txt' }, h('b', null, i.name || i.sku), h('small', null, i.sku + (i.color ? ' · ' + i.color : '') + (i.wc_order_id ? ' · Bestellung #' + i.wc_order_id : ''))),
					h('div', { class: 'pick-qty' }, qty(i.qty_picked) + ' / ' + qty(i.qty_required)),
					i.done ? h('span', { class: 'tag now' }, 'erledigt') : h('button', { class: 'btn primary', type: 'button', onclick: function (e) { e.target.disabled = true; pick(i, ''); } }, 'Entnommen')
				));
			});
			box.appendChild(rows);
		}

		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		load().catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });

		// Mehrere Personen packen gleichzeitig: Stand alle 30 Sekunden auffrischen.
		timers.push(setInterval(function () { if (!document.hidden) { load().catch(function () {}); } }, 30000));
	}

	/* ---------- Druckfarm, Aufträge, Versand ---------- */

	function classicBtn(item) {
		return item.path ? h('a', { class: 'btn', href: C.adminUrl + item.path, target: '_blank', rel: 'noopener' }, ico('ext'), 'Klassische Ansicht') : null;
	}

	function pageHead(title, sub, right) {
		el.view.appendChild(h('div', { class: 'page-head' },
			h('div', null, h('h2', null, title), h('p', { class: 'muted', style: 'margin:4px 0 0' }, sub)),
			h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, right)
		));
	}

	function ago(ts, now) {
		if (!ts) { return 'nie'; }
		var s = Math.max(0, (now || Math.floor(Date.now() / 1000)) - ts);
		if (s < 90) { return 'vor ' + s + ' s'; }
		if (s < 5400) { return 'vor ' + Math.round(s / 60) + ' min'; }
		return 'vor ' + Math.round(s / 3600) + ' h';
	}

	function farmCard(p, now, withLink) {
		var card = h('article', { class: 'printer st-' + p.status },
			h('header', null, h('h3', null, p.name), h('span', { class: 'pill st-' + p.status }, p.text)),
			p.model ? h('div', { class: 'muted', style: 'font-size:12.5px' }, p.model) : null
		);

		if (p.progress !== null) {
			card.appendChild(h('div', { class: 'bar', role: 'progressbar', 'aria-valuenow': p.progress, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': 'Fortschritt ' + p.name }, h('i', { style: 'width:' + p.progress + '%' })));
			card.appendChild(h('div', { class: 'bar-txt' }, h('b', null, p.progress + ' %'), p.remaining !== null ? h('span', { class: 'muted' }, 'noch ' + p.remaining + ' min') : null));
		}

		if (p.nozzle !== null || p.bed !== null) {
			card.appendChild(h('div', { class: 'temps' }, 'Düse ' + (p.nozzle !== null ? Math.round(p.nozzle) : '–') + ' °C · Bett ' + (p.bed !== null ? Math.round(p.bed) : '–') + ' °C'));
		}

		if (p.trays.length) {
			card.appendChild(h('div', { class: 'trays' }, p.trays.map(function (tr) {
				return h('span', { class: 'tray', title: tr.material + (tr.remain !== null ? ' · ' + tr.remain + ' %' : '') },
					h('i', { style: 'background:' + tr.color }), tr.material, tr.remain !== null ? h('small', null, tr.remain + ' %') : null);
			})));
		}

		if (p.snapshot) { card.appendChild(h('img', { class: 'snap', src: p.snapshot, alt: 'Kamera-Standbild ' + p.name, loading: 'lazy' })); }
		if (p.error) { card.appendChild(h('div', { class: 'err' }, p.error)); }
		if (p.service && p.service.length) { card.appendChild(h('div', { class: 'svc' }, ico('refresh'), p.service.length === 1 ? p.service[0] : p.service.length + ' Wartungen fällig')); }

		card.appendChild(h('footer', null,
			h('span', null, p.queue + ' wartend'),
			h('span', { class: 'muted' }, 'Kontakt ' + ago(p.seen, now)),
			withLink ? h('a', { class: 'more-link', href: '#/drucker/' + encodeURIComponent(p.id) }, 'Akte', ico('chev')) : null
		));

		return card;
	}

	function viewFarm(item) {
		var kiosk = h('button', { class: 'btn', type: 'button', onclick: function () { el.app.classList.add('kiosk'); } }, 'Vollbild-Anzeige');
		pageHead('Drucker', 'Live-Stand aller Bambu-Drucker. Ein Klick auf „Akte“ zeigt Verlauf, Wartung und Verbindung.', [kiosk, classicBtn(item)]);

		var grid = h('div', { class: 'farm' });
		el.view.appendChild(grid);
		el.view.appendChild(h('button', { class: 'kiosk-exit btn', type: 'button', onclick: function () { el.app.classList.remove('kiosk'); } }, 'Anzeige beenden (Esc)'));

		function paint(d) {
			grid.textContent = '';
			if (!d.printers.length) { grid.appendChild(h('div', { class: 'notice' }, 'Noch kein Bambu-Drucker angelegt.')); return; }
			d.printers.forEach(function (p) { grid.appendChild(farmCard(p, d.now, true)); });
		}

		function load() { return api('druckfarm').then(paint); }
		load().catch(function (e) { grid.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		timers.push(setInterval(function () { if (!document.hidden) { load().catch(function () {}); } }, 5000));
	}

	var RUN_TEXT = { freigegeben: 'bereit', ruesten: 'rüsten', druckt: 'im Druck', zu_melden: 'zu melden', erledigt: 'erledigt', abgebrochen: 'abgebrochen' };

	function viewPrinterFile(id) {
		var f = findItem('druckfarm');
		setHead('Drucker', 'Produktion');
		if (f) { renderSub(f); }

		var box = h('div');
		el.view.appendChild(h('a', { class: 'btn back', href: '#/seite/druckfarm' }, ico('chev', 'flip'), 'Alle Drucker'));
		el.view.appendChild(box);

		function load() {
			return api('drucker/' + encodeURIComponent(id)).then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function stat(label, value, sub) {
			return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value), sub ? h('div', { class: 'muted', style: 'font-size:12px' }, sub) : null);
		}

		function paint(d) {
			box.textContent = '';
			setHead(d.name, 'Drucker');

			var stats = h('div', { class: 'grid tiles', style: 'margin:14px 0' },
				stat('Diese Woche', d.week_h + ' h', 'Druckzeit gestempelt'),
				stat('Aktuell', d.current ? 'Platte ' + d.current.id : 'frei', d.current ? d.current.text : 'nichts eingeplant'),
				stat('Betriebsstunden', d.device ? d.device.hours + ' h' : '–', d.device ? 'Gerät ' + d.device.code : 'kein Gerät verknüpft'),
				stat('Wartung', d.service.length ? d.service.length + ' fällig' : 'in Ordnung', d.service.length ? '' : 'nichts überfällig')
			);

			var left = h('div', { class: 'file-col' });
			if (d.live && d.live.id) { left.appendChild(farmCard(d.live, d.now, false)); }
			if (d.current) { left.appendChild(h('a', { class: 'btn primary', href: '#/platte/' + d.current.id }, 'Platte ' + d.current.id + ' öffnen')); }

			var right = h('div', { class: 'file-col' });

			var service = h('div', { class: 'card' }, h('h3', null, 'Wartung'));
			if (d.service.length) {
				service.appendChild(h('ul', { class: 'bullets warn' }, d.service.map(function (s) { return h('li', null, s); })));
			} else {
				service.appendChild(h('p', { class: 'muted' }, 'Nichts überfällig.'));
			}
			if (d.log.length) {
				service.appendChild(h('table', { class: 'list' }, h('tbody', null, d.log.map(function (l) {
					return h('tr', null, h('td', null, l.on), h('td', null, l.type), h('td', null, l.part || l.note || ''));
				}))));
			}
			if (findItem('drucker')) { service.appendChild(h('a', { class: 'btn', href: '#/seite/drucker', style: 'margin-top:10px' }, 'Wartung erfassen')); }
			right.appendChild(service);

			var hist = h('div', { class: 'card' }, h('h3', null, 'Verlauf'));
			if (!d.history.length) { hist.appendChild(h('p', { class: 'muted' }, 'Noch keine Platte auf diesem Drucker.')); }
			else {
				hist.appendChild(h('table', { class: 'list' },
					h('thead', null, h('tr', null, h('th', null, 'Platte'), h('th', null, 'Inhalt'), h('th', { class: 'num' }, 'Soll'), h('th', { class: 'num' }, 'Ist'), h('th', null, ''))),
					h('tbody', null, d.history.map(function (r) {
						return h('tr', null,
							h('td', null, h('a', { href: '#/platte/' + r.id }, '#' + r.id), h('div', { class: 'muted', style: 'font-size:12px' }, r.when ? when(r.when) : '')),
							h('td', null, r.text),
							h('td', { class: 'num' }, r.plan ? minTxt(r.plan) : '–'),
							h('td', { class: 'num' }, r.actual !== null ? minTxt(r.actual) : '–'),
							h('td', null, h('span', { class: 'tag' + (r.status === 'erledigt' ? ' now' : '') }, RUN_TEXT[r.status] || r.status))
						);
					}))
				));
			}
			right.appendChild(hist);

			var conn = h('div', { class: 'card' }, h('h3', null, 'Verbindung'));
			conn.appendChild(h('p', { class: d.linked ? 'muted' : '' }, d.linked ? 'Mit Gerät ' + d.device.code + ' verknüpft: Druckzeiten und Meldungen landen im Druckjournal.' : 'Mit keinem Gerät verknüpft. Ohne Verknüpfung lässt sich nichts ins Druckjournal buchen.'));
			if (findItem('bambu')) { conn.appendChild(h('a', { class: 'btn', href: '#/seite/bambu' }, 'Verbindung bearbeiten')); }
			right.appendChild(conn);

			box.appendChild(stats);
			box.appendChild(h('div', { class: 'file' }, left, right));
		}

		load();
		timers.push(setInterval(function () { if (!document.hidden) { load().catch(function () {}); } }, 10000));
	}

	var ORDER_FILTERS = [['', 'Alle'], ['entwurf', 'Entwürfe'], ['bestaetigt', 'Bestätigt'], ['storniert', 'Storniert']];

	function viewOrders(item) {
		pageHead('Aufträge', 'Bestellungen im Betrieb – aus dem Shop und von Hand.', classicBtn(item));
		var filter = S.orderFilter || '';
		var chips = h('div', { class: 'presets' });
		var box = h('div');
		el.view.appendChild(chips);
		el.view.appendChild(box);

		function drawChips() {
			chips.textContent = '';
			ORDER_FILTERS.forEach(function (f) {
				chips.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': filter === f[0] ? 'true' : 'false', onclick: function () { filter = f[0]; S.orderFilter = f[0]; drawChips(); load(); } }, f[1]));
			});
		}

		function load() {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
			return api('auftraege' + (filter ? '?status=' + filter : '')).then(function (d) { paint(d); }).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function paint(d) {
			box.textContent = '';
			if (!d.orders.length) { box.appendChild(h('div', { class: 'notice' }, 'Keine Aufträge in dieser Ansicht.')); return; }

			var list = h('div', { class: 'rows' });
			d.orders.forEach(function (o) {
				var chip = o.status === 'entwurf' ? 'later' : (o.status === 'storniert' ? '' : 'now');
				list.appendChild(h('div', { class: 'row-card' },
					h('div', { class: 'row-main' },
						h('b', null, o.contact || (o.wc ? 'Shop-Bestellung #' + o.wc : 'Auftrag #' + o.id)),
						h('small', null, '#' + o.id + (o.wc ? ' · Shop #' + o.wc : ' · manuell') + ' · ' + o.date)
					),
					h('div', { class: 'row-num' }, money(o.value, 'EUR')),
					h('div', { class: 'row-tags' },
						h('span', { class: 'tag ' + chip }, o.status),
						h('span', { class: 'tag' + (o.overdue ? ' later' : '') }, o.overdue ? 'Rechnung überfällig' : 'Rechnung ' + o.invoice)
					),
					o.status === 'entwurf' && d.confirm ? h('button', {
						class: 'btn primary', type: 'button',
						onclick: function (e) {
							if (!window.confirm('Entwurf bestätigen? Erst dadurch entstehen Lagerabgang und offener Posten.')) { return; }
							e.target.disabled = true;
							api('auftraege/' + o.id + '/bestaetigen', { method: 'POST' }).then(function (r) { toast(r.message); load(); }).catch(function (er) { e.target.disabled = false; toast(er.message, true); });
						}
					}, 'Bestätigen') : null
				));
			});
			box.appendChild(list);
		}

		drawChips();
		load();
	}

	function shipDone(o, d, reload, btn) {
		if (!window.confirm('Als versendet melden? Dabei wird der Lagerabgang gebucht.')) { return; }
		if (btn) { btn.disabled = true; }
		api('versand/' + o.id + '/versendet', { method: 'POST' })
			.then(function (r) { toast(r.message); reload(); })
			.catch(function (er) { if (btn) { btn.disabled = false; } toast(er.message, true); });
	}

	function shipLabelForm(o, dhl, canLabel, reload) {
		if (!dhl.configured) { return null; }
		if (!canLabel) { return h('small', { class: 'muted' }, 'Label erzeugen darfst du nicht.'); }
		if (!o.suggested) { return null; }

		var product = h('select', { class: 'field', 'aria-label': 'Produkt' }, dhl.products.map(function (p) { return h('option', { value: p.id, selected: p.id === o.suggested.product }, p.label); }));
		var weight = h('input', { class: 'field', type: 'number', min: '1', step: '1', value: String(o.suggested.weight || ''), 'aria-label': 'Gewicht in Gramm', style: 'max-width:110px' });
		var bulky = h('input', { type: 'checkbox', id: 'bulky-' + o.id });

		return h('form', {
			class: 'label-area', onsubmit: function (e) {
				e.preventDefault();
				var btn = e.target.querySelector('button[type=submit]');
				btn.disabled = true;
				api('versand/' + o.id + '/label', { method: 'POST', body: { product: product.value, weight_g: parseFloat(weight.value) || 0, bulky: bulky.checked } })
					.then(function (r) { toast(r.message); reload(); })
					.catch(function (er) { btn.disabled = false; toast(er.message, true); });
			}
		}, product, weight, h('small', null, 'g'), h('label', { class: 'chk', for: 'bulky-' + o.id }, bulky, ' sperrig'), h('button', { class: 'btn primary', type: 'submit' }, 'Label erzeugen'));
	}

	function shipVoid(o, reload) {
		return h('button', {
			class: 'btn', type: 'button', style: 'color:var(--error)',
			onclick: function (e) {
				if (!window.confirm('Label stornieren? Die Frankierung wird dann nicht berechnet.')) { return; }
				e.target.disabled = true;
				api('versand/label/' + o.label.id + '/storno', { method: 'POST' }).then(function (r) { toast(r.message); reload(); }).catch(function (er) { e.target.disabled = false; toast(er.message, true); });
			}
		}, 'Stornieren');
	}

	var SHIP_COLS = [['pack', 'Zu packen'], ['send', 'Label da – zu versenden'], ['done', 'Heute versendet']];

	function viewShipping(item) {
		pageHead('Versand', 'Packen, Label, Versendet – beim Versenden geht der Bestand ab.', classicBtn(item));
		el.view.classList.add('broad');
		var box = h('div');
		el.view.appendChild(box);

		function load() {
			return api('versand').then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function card(o, d) {
			var actions = h('div', { class: 'ship-actions' });

			if (o.stage === 'pack') {
				var form = shipLabelForm(o, d.dhl, d.label, load);
				if (form) { actions.appendChild(form); }
				if (!d.dhl.configured) {
					actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function (e) { shipDone(o, d, load, e.target); } }, 'Versendet ✓'));
				}
			} else if (o.stage === 'send') {
				actions.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function (e) { shipDone(o, d, load, e.target); } }, 'Versendet ✓'));
				actions.appendChild(h('a', { class: 'btn', href: o.label.pdf, target: '_blank', rel: 'noopener' }, 'Label öffnen'));
				if (d.label) { actions.appendChild(shipVoid(o, load)); }
			}

			if (o.stage !== 'done') { actions.appendChild(h('a', { class: 'btn', href: o.slip, target: '_blank', rel: 'noopener' }, 'Lieferschein')); }

			return h('div', { class: 'row-card ship' },
				h('a', { class: 'row-main', href: '#/bestellung/' + o.id, style: 'color:inherit;text-decoration:none' },
					h('b', null, '#' + o.number + ' · ' + o.name),
					h('small', null, o.place + (o.ts ? ' · ' + new Date(o.ts * 1000).toLocaleDateString('de-DE') : '')),
					h('small', null, o.content)
				),
				o.tracking ? h('div', { class: 'ship-track' }, h('span', { style: 'font-family:var(--font-mono);font-size:13px' }, o.tracking), o.tstatus ? h('small', { class: 'muted' }, ' ' + o.tstatus) : null) : null,
				actions
			);
		}

		function paint(d) {
			box.textContent = '';
			if (d.dhl.sandbox) { box.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:12px' }, 'DHL-Sandbox aktiv: Die Labels sehen echt aus, sind aber keine gültige Frankierung.')); }

			var board = h('div', { class: 'board ship-board' });
			SHIP_COLS.forEach(function (c) {
				var list = d.orders.filter(function (o) { return o.stage === c[0]; });
				var col = h('section', { class: 'col' }, h('h3', null, c[1], ' ', h('span', { class: 'count' }, String(list.length))));
				if (!list.length) { col.appendChild(h('div', { class: 'muted', style: 'padding:8px 2px' }, c[0] === 'done' ? 'Noch nichts versendet.' : 'Nichts offen.')); }
				list.forEach(function (o) { col.appendChild(card(o, d)); });
				board.appendChild(col);
			});
			box.appendChild(board);
		}

		load();
	}

	function viewShipOrder(id) {
		setHead('Bestellung', 'Verkauf');
		var sf = findItem('versand');
		if (sf) { renderSub(sf); }
		var box = h('div', { class: 'run' });
		el.view.appendChild(box);

		function load() {
			return api('versand/' + id).then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		// Geschäftskunde: zur Akte springen, zuordnen oder lösen.
		function contactLink(cu) {
			if (!can('verkauf.kontakte')) { return null; }
			var box = h('div', { style: 'margin-top:10px;display:grid;gap:8px' });
			function link(contactId) {
				return api('versand/' + id + '/kontakt', { method: 'POST', body: { contact_id: contactId } })
					.then(function (r) { toast(r.message); load(); })
					.catch(function (er) { toast(er.message, true); });
			}
			if (cu.contact) {
				box.appendChild(h('div', { class: 'label-area' },
					h('a', { class: 'btn primary', href: '#/kontakt/' + cu.contact.id }, 'Zur Akte: ' + cu.contact.company),
					h('button', { class: 'btn', type: 'button', onclick: function () { if (window.confirm('Zuordnung lösen? Die Bestellung zählt dann nicht mehr in der Akte.')) { link(0); } } }, 'Zuordnung lösen')));
				return box;
			}
			(cu.suggest || []).forEach(function (s) {
				box.appendChild(h('button', { class: 'btn', type: 'button', onclick: function (e) { e.target.disabled = true; link(s.id); } },
					'Zu „' + s.company + '“ zuordnen (' + (s.why === 'email' ? 'gleiche E-Mail' : 'gleiche Firma') + ')'));
			});
			if (!(cu.suggest || []).length) { box.appendChild(h('small', { class: 'muted' }, 'Kein Kontakt mit dieser E-Mail oder Firma gefunden. Lege den Kontakt unter Verkauf → Kunden an, danach lässt sich die Bestellung zuordnen.')); }
			return box;
		}

		function paint(o) {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' },
				h('h2', null, '#' + o.number + ' · ' + o.name),
				h('div', null, o.address.map(function (l) { return h('div', null, l); })),
				o.note ? h('div', { class: 'notice', style: 'margin-top:10px' }, 'Kundenhinweis: ' + o.note) : null
			));

			var list = h('div', { class: 'card' }, h('h3', null, 'Packliste'));
			o.items.forEach(function (it, i) {
				var cb = h('input', { type: 'checkbox', id: 'pk-' + i, style: 'width:22px;height:22px' });
				list.appendChild(h('label', { class: 'chk', for: 'pk-' + i, style: 'display:flex;gap:12px;align-items:center;padding:10px 0;font-size:17px' }, cb, h('b', null, it.qty + '×'), ' ' + it.name, it.sku ? h('small', { class: 'muted' }, ' ' + it.sku) : null));
			});
			box.appendChild(list);

			var act = h('div', { class: 'card ship-actions' });
			if (o.stage === 'pack') {
				var form = shipLabelForm(o, o.dhl, o.can_label, load);
				if (form) { act.appendChild(form); }
				if (!o.dhl.configured) { act.appendChild(h('button', { class: 'bigbtn go', type: 'button', onclick: function (e) { shipDone(o, null, function () { load(); }, e.target); } }, 'Versendet ✓')); }
			} else if (o.stage === 'send') {
				act.appendChild(h('a', { class: 'btn', href: o.label.pdf, target: '_blank', rel: 'noopener' }, 'Label öffnen'));
				act.appendChild(h('button', { class: 'bigbtn go', type: 'button', onclick: function (e) { shipDone(o, null, function () { load(); }, e.target); } }, 'Versendet ✓'));
			} else {
				act.appendChild(h('div', { class: 'notice' }, 'Versendet. Der Lagerabgang ist gebucht.'));
			}
			act.appendChild(h('a', { class: 'btn', href: o.slip, target: '_blank', rel: 'noopener' }, 'Lieferschein'));
			box.appendChild(act);

			if (o.customer) {
				var cu = o.customer;
				box.appendChild(h('div', { class: 'card' }, h('h3', null, 'Kunde'),
					h('div', { class: 'kv' },
						h('span', null, 'Name'), h('b', null, cu.name || o.name),
						cu.email ? h('span', null, 'E-Mail') : null, cu.email ? h('b', null, cu.email) : null,
						cu.phone ? h('span', null, 'Telefon') : null, cu.phone ? h('b', null, cu.phone) : null,
						cu.company ? h('span', null, 'Firma') : null, cu.company ? h('b', null, cu.company) : null,
						h('span', null, 'Bestellungen'), h('b', null, cu.orders ? cu.orders + (cu.orders === 1 ? ' (die erste)' : ' insgesamt') : '–')),
					contactLink(cu)));
			}

			if (o.timeline && o.timeline.length) {
				box.appendChild(h('div', { class: 'card' }, h('h3', null, 'Verlauf'),
					h('ol', { class: 'timeline' }, o.timeline.map(function (ev) {
						return h('li', { class: 'ev-' + ev.kind }, h('small', { class: 'muted' }, ev.ts ? when(ev.ts) : ''), h('span', null, ev.text));
					}))));
			}

			box.appendChild(h('a', { class: 'btn', href: '#/seite/versand' }, '← Zurück zum Versand'));
		}

		load();
	}

	function num(v) { return String(Math.round(v * 100) / 100).replace('.', ','); }

	// Bestellformular für eine Position (Einkauf und Material nutzen es gemeinsam).
	function orderForm(o, reload) {
		var qtyIn = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: num(o.qty), 'aria-label': 'Menge in ' + o.unit, style: 'max-width:90px' });
		var sup = h('input', { class: 'field', type: 'text', value: o.supplier, placeholder: 'Lieferant', 'aria-label': 'Lieferant' });
		var price = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: o.price ? num(o.price) : '', placeholder: '€ netto je ' + o.unit, 'aria-label': 'Preis netto', style: 'max-width:120px' });
		var due = h('input', { class: 'field', type: 'date', 'aria-label': 'Erwartet am' });

		return h('form', {
			class: 'label-area', onsubmit: function (e) {
				e.preventDefault();
				var btn = e.target.querySelector('button[type=submit]');
				btn.disabled = true;
				api('einkauf/bestellt', { method: 'POST', body: { type: o.type, item_id: o.item_id, item_ref: o.item_ref, qty: qtyIn.value, price: price.value, supplier: sup.value, expected_on: due.value } })
					.then(function (r) { toast(r.message); reload(); })
					.catch(function (er) { btn.disabled = false; toast(er.message, true); });
			}
		}, qtyIn, h('small', null, o.unit), sup, price, due, h('button', { class: 'btn primary', type: 'submit' }, 'Bestellt ✓'));
	}

	// Wareneingang zu einer offenen Bestellung.
	function receiveForm(p, reload) {
		var qtyIn = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: num(p.qty), 'aria-label': 'Gelieferte Menge in ' + p.unit, style: 'max-width:90px' });
		var lot = p.type === 'filament' ? h('input', { class: 'field', type: 'text', placeholder: 'Chargencode (leer = automatisch)', 'aria-label': 'Chargencode' }) : null;
		var mat = p.type === 'filament' ? h('select', { class: 'field', 'aria-label': 'Material' }, ['PETG', 'PLA', 'ASA', 'ABS', 'TPU'].map(function (m) { return h('option', { value: m }, m); })) : null;

		return h('form', {
			class: 'label-area', onsubmit: function (e) {
				e.preventDefault();
				var btn = e.target.querySelector('button[type=submit]');
				btn.disabled = true;
				api('einkauf/' + p.id + '/eingang', { method: 'POST', body: { qty: qtyIn.value, lot_code: lot ? lot.value : '', material: mat ? mat.value : '' } })
					.then(function (r) { toast(r.message); reload(); })
					.catch(function (er) { btn.disabled = false; toast(er.message, true); });
			}
		}, qtyIn, h('small', null, p.unit), mat, lot, h('button', { class: 'btn primary', type: 'submit' }, 'Eingegangen ✓'));
	}

	function viewProcurement() {
		pageHead('Einkauf', 'Bestellen, unterwegs, eingegangen – der Eingang bucht den Bestand.', null);
		el.view.classList.add('broad');
		var box = h('div');
		el.view.appendChild(box);

		function load() {
			return api('einkauf').then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function orderCard(o) {
			return h('div', { class: 'row-card ship' },
				h('div', { class: 'row-main' },
					h('b', null, o.label),
					h('small', null, o.kind + ' · Bestand ' + num(o.stock) + ' ' + o.unit + (o.coverage === null ? '' : ' · reicht noch ' + Math.floor(o.coverage) + ' Tage (Grenze ' + o.threshold + ')'))
				),
				orderForm(o, load)
			);
		}

		function openCard(p) {
			return h('div', { class: 'row-card ship' },
				h('div', { class: 'row-main' },
					h('b', null, p.label),
					h('small', null, num(p.qty) + ' ' + p.unit + (p.supplier ? ' · ' + p.supplier : '') + ' · bestellt ' + p.ordered_on),
					p.expected_on ? h('small', { class: p.late ? 'tag later' : 'muted' }, p.late ? p.late + ' Tage überfällig (erwartet ' + p.expected_on + ')' : 'erwartet ' + p.expected_on) : null
				),
				receiveForm(p, load)
			);
		}

		function doneCard(p) {
			return h('div', { class: 'row-card ship' },
				h('div', { class: 'row-main' },
					h('b', null, p.label),
					h('small', null, num(p.received === null ? p.qty : p.received) + ' ' + p.unit + ' · eingegangen ' + p.received_on + (p.supplier ? ' · ' + p.supplier : ''))
				)
			);
		}

		function paint(d) {
			box.textContent = '';
			var board = h('div', { class: 'board ship-board' });
			[['Bestellen', d.order, orderCard, 'Nichts zu bestellen.'], ['Unterwegs', d.open, openCard, 'Nichts unterwegs.'], ['Eingegangen (14 Tage)', d.done, doneCard, 'Noch nichts eingegangen.']].forEach(function (c) {
				var col = h('section', { class: 'col' }, h('h3', null, c[0], ' ', h('span', { class: 'count' }, String(c[1].length))));
				if (!c[1].length) { col.appendChild(h('div', { class: 'muted', style: 'padding:8px 2px' }, c[3])); }
				c[1].forEach(function (x) { col.appendChild(c[2](x)); });
				board.appendChild(col);
			});
			box.appendChild(board);
		}

		load();
	}

	var LEVEL_TEXT = { ok: 'reicht', bald: 'bald nachbestellen', knapp: 'jetzt nachbestellen', leer: 'leer' };

	// Material: eine Karte je Filamentfarbe, mit Rollen, Bestellungen und Bestellformular.
	function viewMaterial(item) {
		pageHead('Material', 'Filament nach Farbe: was da ist, wie lange es reicht, was unterwegs ist.', classicBtn(item));
		el.view.classList.add('broad');
		var box = h('div', { class: 'mat-grid' });
		el.view.appendChild(box);

		function load() {
			return api('material').then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function paint(d) {
			box.textContent = '';
			if (!d.colors.length) { box.appendChild(h('div', { class: 'notice' }, 'Noch kein Filament im Lager. Der erste Wareneingang legt die Farbe an.')); return; }

			d.colors.forEach(function (c) {
				var pct = c.coverage === null ? 100 : Math.max(4, Math.min(100, Math.round(c.coverage / (c.threshold * 2) * 100)));
				var card = h('article', { class: 'card mat lv-' + c.level },
					h('header', null, h('h3', null, c.label), h('span', { class: 'lvl' }, LEVEL_TEXT[c.level])),
					h('div', { class: 'mat-kg' }, h('b', null, num(c.stock)), ' kg'),
					h('div', { class: 'bar', 'aria-hidden': 'true' }, h('i', { style: 'width:' + pct + '%' })),
					h('div', { class: 'muted', style: 'font-size:13px' }, c.coverage === null ? 'Kein Verbrauch in den letzten 4 Wochen.' : 'reicht noch ' + Math.floor(c.coverage) + ' Tage · Grenze ' + c.threshold + ' · ' + num(c.daily * 1000) + ' g pro Tag')
				);

				if (c.lots.length) {
					card.appendChild(h('ul', { class: 'lots' }, c.lots.map(function (l) {
						return h('li', null,
							h('b', null, l.code), h('span', null, l.material + ' · ' + num(l.remaining) + ' kg'),
							l.dry === false ? h('span', { class: 'tag later' }, 'trocknen') : null,
							l.location ? h('small', { class: 'muted' }, l.location) : null);
					})));
				}

				c.open.forEach(function (p) {
					card.appendChild(h('div', { class: 'incoming' },
						h('b', null, 'Unterwegs: ' + num(p.qty) + ' kg'),
						h('small', { class: p.late ? 'tag later' : 'muted' }, (p.supplier ? p.supplier + ' · ' : '') + (p.expected_on ? (p.late ? p.late + ' Tage überfällig' : 'erwartet ' + p.expected_on) : 'bestellt ' + p.ordered_on)),
						d.receive ? receiveForm(p, load) : null));
				});

				if (d.order && !c.open.length) {
					var form = h('div', { hidden: true }, orderForm({ type: 'filament', item_id: 0, item_ref: c.color, unit: 'kg', qty: c.qty, supplier: c.supplier, price: c.price }, load));
					var open = h('button', { class: 'btn' + (c.level === 'knapp' || c.level === 'leer' ? ' primary' : ''), type: 'button', onclick: function (e) { form.hidden = !form.hidden; e.target.blur(); } }, 'Nachbestellen');
					card.appendChild(open);
					card.appendChild(form);
				}

				box.appendChild(card);
			});
		}

		load();
	}

	function viewVariance() {
		pageHead('Soll gegen Ist', 'Gestempelte Druckzeit gegen die Planzeit – damit Plan und Kalkulation der Wirklichkeit folgen.', null);
		el.view.classList.add('broad');
		var box = h('div');
		el.view.appendChild(box);

		function load() {
			return api('sollist').then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function pct(f) { var p = Math.round((f - 1) * 100); return (p > 0 ? '+' : '') + p + ' %'; }
		function pc(x) { return Math.round(x * 100) + ' %'; }

		function paint(d) {
			box.textContent = '';

			if (!d.articles.length) {
				box.appendChild(h('div', { class: 'notice' }, 'Noch keine Messwerte: Sobald Platten mit Druckstart und Meldung erledigt sind, erscheinen sie hier (letzte ' + d.days + ' Tage).'));
				return;
			}

			var worse = d.articles.filter(function (a) { return a.adjust && a.factor > 1; }).length;
			var risky = d.articles.filter(function (a) { return a.margin && a.margin.state; }).length;
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' },
				h('b', null, d.runs + ' Platten gemessen'),
				h('small', { class: 'muted' }, ' · Rüsten im Schnitt ' + minTxt(d.setup_avg) + ' · ' + worse + ' Artikel brauchen deutlich länger als geplant' + (d.money ? ' · ' + risky + ' mit kritischer Marge' : ''))
			));

			var rows = h('div', { class: 'rows' });
			d.articles.forEach(function (a) {
				var m = a.margin;
				var tags = h('div', { class: 'row-tags' });
				tags.appendChild(h('span', { class: 'tag' + (a.adjust && a.factor > 1 ? ' later' : '') }, pct(a.factor) + ' · ' + a.runs + ' Platten'));
				if (m && m.state === 'verlust') { tags.appendChild(h('span', { class: 'tag later' }, 'Verlust: DB II ' + m.actual_db2.toFixed(2).replace('.', ',') + ' €')); }
				else if (m && m.state === 'sinkt') { tags.appendChild(h('span', { class: 'tag later' }, 'Marge ' + pc(m.plan_pct) + ' → ' + pc(m.actual_pct))); }

				rows.appendChild(h('div', { class: 'row-card' },
					h('div', { class: 'row-main' },
						h('b', null, a.sku + ' – ' + a.name),
						h('small', null, 'Soll ' + minTxt(a.plan_min) + ' · Ist ' + minTxt(a.actual_min) + ' · Plattenzeit ' + String(a.plate_h).replace('.', ',') + ' h' + (a.adjust ? ' → ' + String(a.new_h).replace('.', ',') + ' h' : ''))
					),
					tags,
					a.adjust && d.edit ? h('button', {
						class: 'btn primary', type: 'button',
						onclick: function (e) {
							if (!window.confirm('Plattenzeit auf ' + a.new_h + ' h setzen? Planung und Kalkulation rechnen danach damit.')) { return; }
							e.target.disabled = true;
							api('sollist/' + a.article_id + '/uebernehmen', { method: 'POST' }).then(function (r) { toast(r.message); load(); }).catch(function (er) { e.target.disabled = false; toast(er.message, true); });
						}
					}, 'Planzeit übernehmen') : null
				));
			});
			box.appendChild(rows);
		}

		load();
	}

	/* ---------- Produkte ---------- */

	var PRODUCT_FILTERS = [['alle', 'Alle'], ['ohne_3mf', 'Ohne 3MF'], ['marge', 'Marge kritisch'], ['nicht_im_shop', 'Nicht im Shop'], ['kein_preis', 'Ohne Preis']];

	function viewProducts(item) {
		pageHead('Produkte', 'Alles, woran ein Produkt hängt, in einer Liste. Probleme stehen direkt am Produkt.', [can('fertigung.stammdaten.edit') ? h('a', { class: 'btn primary', href: '#/produkt-neu' }, 'Neues Produkt') : null, classicBtn(item)]);
		var filter = S.prodFilter || 'alle';
		var q = '';
		var chips = h('div', { class: 'presets' });
		var search = h('input', { class: 'field', type: 'search', placeholder: 'Nummer oder Name suchen …', 'aria-label': 'Produkte suchen', style: 'max-width:340px;margin-bottom:10px', oninput: function (e) { q = e.target.value.trim().toLowerCase(); paintList(); } });
		var box = h('div');
		var data = null;
		el.view.appendChild(chips);
		el.view.appendChild(search);
		el.view.appendChild(box);

		function drawChips() {
			chips.textContent = '';
			PRODUCT_FILTERS.forEach(function (f) {
				var n = data ? data.counts[f[0]] : null;
				chips.appendChild(h('button', { class: 'chip', type: 'button', 'aria-pressed': filter === f[0] ? 'true' : 'false', onclick: function () { filter = f[0]; S.prodFilter = f[0]; drawChips(); paintList(); } }, f[1] + (n !== null && f[0] !== 'alle' ? ' · ' + n : '')));
			});
		}

		function paintList() {
			box.textContent = '';
			if (!data) { return; }
			var rows = data.rows.filter(function (r) {
				return (filter === 'alle' || r.flags.indexOf(filter) > -1) && (!q || (r.sku + ' ' + r.name).toLowerCase().indexOf(q) > -1);
			});
			if (!rows.length) { box.appendChild(h('div', { class: 'notice' }, filter === 'alle' ? 'Keine Produkte gefunden.' : 'Hier ist nichts auffällig.')); return; }

			var list = h('div', { class: 'rows' });
			rows.forEach(function (r) {
				var tags = h('div', { class: 'row-tags' });
				r.flags.forEach(function (fl) { tags.appendChild(h('span', { class: 'tag later' }, data.flags[fl][0])); });
				if (!r.flags.length) { tags.appendChild(h('span', { class: 'tag now' }, 'in Ordnung')); }

				list.appendChild(h('a', { class: 'row-card prod', href: '#/produkt/' + r.id },
					h('div', { class: 'row-main' }, h('b', null, r.sku + ' – ' + r.name), h('small', null, r.sourcing + ' · ' + r.status)),
					h('div', { class: 'row-num' }, r.price === null ? '–' : money(r.price, 'EUR')),
					h('div', { class: 'row-num muted' }, num(r.stock) + ' im Lager'),
					data.money ? h('div', { class: 'row-num muted' }, r.margin === null ? '' : Math.round(r.margin * 100) + ' % Marge') : null,
					tags
				));
			});
			box.appendChild(list);
		}

		drawChips();
		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
		api('produkte').then(function (d) { data = d; drawChips(); paintList(); }).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
	}

	var PRODUCT_TABS = [['', 'Übersicht'], ['stamm', 'Stammdaten'], ['rev', '3MF und Revisionen'], ['bom', 'Stückliste'], ['calc', 'Kalkulation'], ['beschaffung', 'Beschaffung'], ['shop', 'Shop']];

	function viewProduct(id, sub) {
		var f = findItem('artikel');
		if (f) { renderSub(f); }
		setHead('Produkt', 'Katalog');

		var box = h('div');
		el.view.appendChild(h('a', { class: 'btn back', href: '#/seite/artikel' }, ico('chev', 'flip'), 'Alle Produkte'));
		el.view.appendChild(box);

		api('produkte/' + id).then(paint).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });

		function stat(label, value, sub2) {
			return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value), sub2 ? h('div', { class: 'muted', style: 'font-size:12px' }, sub2) : null);
		}

		function paint(d) {
			box.textContent = '';
			setHead(d.sku + ' – ' + d.name, 'Produkt');

			box.appendChild(h('div', { class: 'page-head', style: 'margin-top:10px' }, h('div', null, h('h2', null, d.sku + ' – ' + d.name), h('p', { class: 'muted', style: 'margin:4px 0 0' }, d.sourcing + ' · ' + d.status))));

			var pills = h('div', { class: 'pills', style: 'padding-top:0' });
			PRODUCT_TABS.forEach(function (t) {
				if (t[0] === 'shop' && !d.shop_edit) { return; }
				if (t[0] === 'beschaffung' && ['zukauf', 'hybrid'].indexOf(d.sourcing) < 0) { return; }
				if (t[0] === 'stamm' && !d.edit) { return; }
				pills.appendChild(h('a', { class: 'pill' + ((sub || '') === t[0] ? ' on' : ''), href: '#/produkt/' + d.id + (t[0] ? '/' + t[0] : '') }, t[1]));
			});
			box.appendChild(pills);

			// Reiter, die in assets/erp/pages/ nativ gebaut sind, haben Vorrang vor der eingebetteten Seite.
			if (sub && UI.productTabs && UI.productTabs[sub]) {
				UI.productTabs[sub](d, box, UI);
				return;
			}

			if (sub && d.paths[sub] || sub === 'shop' && d.shop) {
				var path = sub === 'shop' ? d.shop.path : d.paths[sub];
				var src = C.adminUrl + path + (path.indexOf('?') > -1 ? '&' : '?') + 'lw16_embed=1';
				var loading = h('div', { class: 'frame-loading' }, 'Wird geladen …');
				var frame = h('iframe', { src: src, title: sub, loading: 'eager', onload: function () { loading.classList.add('hide'); } });
				box.appendChild(h('div', { class: 'frame-wrap in-file' }, h('div', { class: 'frame-box' }, frame, loading)));
				return;
			}

			d.problems.forEach(function (p) {
				box.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:10px' }, h('b', null, p.label + ': '), p.text));
			});

			var stats = h('div', { class: 'grid tiles', style: 'margin:12px 0' },
				stat('Verkaufspreis', d.price === null ? '–' : money(d.price, 'EUR'), d.sellable ? 'verkäuflich' : 'nicht verkäuflich'),
				stat('Lagerbestand', num(d.stock), 'Stück im Lager')
			);
			if (d.calc) {
				stats.appendChild(stat('Herstellkosten', money(d.calc.cost, 'EUR'), 'je Stück'));
				stats.appendChild(stat('Marge', Math.round(d.calc.margin * 100) + ' %', 'DB II ' + money(d.calc.db2, 'EUR') + ' je Stück'));
			}
			box.appendChild(stats);

			var cards = h('div', { class: 'two', style: 'margin-top:6px' });
			var rev = h('div', { class: 'card' }, h('h3', null, 'Druckdaten'));
			if (d.revision) {
				rev.appendChild(h('div', { class: 'kv' },
					h('span', null, 'Revision'), h('b', null, d.revision.version),
					h('span', null, 'Material'), h('b', null, d.revision.material + (d.revision.color ? ' · ' + d.revision.color : '')),
					h('span', null, 'Projektdatei'), h('b', null, d.revision.kind === 'modell' ? (d.revision.model || 'vorhanden') : (d.revision.model ? d.revision.model + ' (schon geslict)' : 'fehlt')),
					h('span', null, 'Platte'), h('b', null, d.revision.parts + ' Stück · ' + String(d.revision.plate_h).replace('.', ',') + ' h'),
					h('span', null, 'Gewicht'), h('b', null, num(d.revision.weight_g) + ' g')
				));
			} else {
				rev.appendChild(h('p', { class: 'muted' }, 'Noch keine aktive Revision.'));
			}
			cards.appendChild(rev);

			var tz = h('div', { class: 'card' }, h('h3', null, 'Soll gegen Ist'));
			if (d.variance) {
				var p = Math.round((d.variance.factor - 1) * 100);
				tz.appendChild(h('p', null, 'Gemessen über ' + d.variance.runs + ' Platten: ', h('b', null, (p > 0 ? '+' : '') + p + ' % gegenüber der Planzeit.')));
				if (d.variance.adjust) { tz.appendChild(h('p', { class: 'muted' }, 'Vorschlag: Plattenzeit ' + String(d.variance.plate_h).replace('.', ',') + ' h → ' + String(d.variance.new_h).replace('.', ',') + ' h.')); }
				if (findItem('sollist')) { tz.appendChild(h('a', { class: 'btn', href: '#/seite/sollist' }, 'Zu Soll gegen Ist')); }
			} else {
				tz.appendChild(h('p', { class: 'muted' }, 'Noch keine gemessenen Platten.'));
			}
			cards.appendChild(tz);
			box.appendChild(cards);
		}
	}

	/* ---------- Workflow ---------- */

	function dlg(title, body, wide) {
		var d = h('dialog', { class: 'sheet' + (wide ? ' wide' : ''), 'aria-label': title });
		var close = function () { d.close(); };
		d.appendChild(h('header', null, h('h3', null, title), h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Schließen', onclick: close }, ico('x'))));
		d.appendChild(body);
		d.addEventListener('close', function () { d.remove(); });
		d.addEventListener('click', function (e) { if (e.target === d) { d.close(); } });
		document.body.appendChild(d);
		d.showModal();
		return d;
	}

	function minTxt(m) {
		m = Math.max(0, Math.round(m));
		return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + ('0' + (m % 60)).slice(-2);
	}

	function clock(sec) {
		sec = Math.max(0, Math.floor(sec));
		var hh = Math.floor(sec / 3600), mm = Math.floor((sec % 3600) / 60);
		return hh + ':' + ('0' + mm).slice(-2);
	}

	function kgTxt(kg) {
		kg = Number(kg) || 0;
		return kg < 1 ? Math.round(kg * 1000) + ' g' : kg.toFixed(2).replace('.', ',') + ' kg';
	}

	function materialRows(rows) {
		return h('div', { class: 'matrows' }, rows.map(function (m) {
			var lot = m.lots.filter(function (l) { return l.id === m.suggested; })[0];
			return h('div', { class: 'matrow' + (m.ok ? '' : ' bad') },
				h('b', null, m.label),
				h('span', null, kgTxt(m.needed_kg) + ' nötig'),
				h('span', { class: 'muted' }, m.ok
					? (lot ? 'Rolle ' + lot.code + ' · Rest ' + kgTxt(lot.remaining_kg) : 'im Lager ' + kgTxt(m.available_kg))
					: (m.available_kg > 0 ? 'nur ' + kgTxt(m.available_kg) + ' im Lager – es fehlen ' + kgTxt(m.missing_kg) : 'keine Rolle in dieser Farbe im Lager'))
			);
		}));
	}

	function itemsLine(run) {
		return run.items.map(function (i) { return qty(i.qty) + '× ' + i.name; }).join(', ');
	}

	var PRIMARY = {
		freigegeben: { action: 'ruesten', label: 'Rüsten starten' },
		ruesten: { action: 'druckstart', label: 'Druck gestartet' },
		druckt: { action: 'druckende', label: 'Druck fertig' },
		zu_melden: { action: 'melden', label: 'Ist-Menge melden' }
	};

	// Ohne Verbindung geht die Platte lokal weiter, damit die Werkstatt nicht stehen bleibt.
	function localAdvance(run, action) {
		var now = Math.floor(Date.now() / 1000);
		var next = { ruesten: 'ruesten', druckstart: 'druckt', druckende: 'zu_melden', melden: 'erledigt' }[action];
		if (!next) { return; }
		if (action === 'ruesten') { run.setup = now; }
		if (action === 'druckstart') { run.setup = run.setup || now; run.print = now; }
		if (action === 'druckende') { run.print_end = now; }
		run.status = next;
		run.primary = PRIMARY[next] || {};
	}

	function runAct(id, action, body) {
		return api('runs/' + id + '/' + action, { method: 'POST', queue: true, body: body || {} });
	}

	function runCard(run, reload, plan) {
		var live = run.live;
		var progress = null;

		if (run.status === 'druckt') {
			progress = live && live.progress !== null ? live.progress : (run.print && run.est_minutes ? Math.min(99, Math.round((((Date.now() / 1000 + (S.skew || 0)) - run.print) / 60) / run.est_minutes * 100)) : null);
		}

		var meta = [run.printer, run.est_minutes ? 'ca. ' + minTxt(run.est_minutes) : ''].filter(Boolean).join(' · ');
		// Serverzeit: Die Uhr eines Handys geht selten genau.
		var nowSec = Date.now() / 1000 + (S.skew || 0);
		var line = run.status === 'ruesten' ? 'Rüstzeit ' + clock(nowSec - run.setup) : (run.status === 'druckt' ? 'Druckzeit ' + clock(nowSec - run.print) : itemsLine(run));

		var chips = [];
		if (run.batch) { chips.push('Plan ' + run.batch); }
		if (run.file) { chips.push('3MF bereit'); }
		if (live && run.status === 'druckt' && live.remaining !== null) { chips.push('noch ' + minTxt(live.remaining)); }
		if (run.status === 'zu_melden') { chips.push('wartet auf Meldung'); }

		var primary = run.primary && run.primary.action ? h('button', {
			class: 'btn primary wide', type: 'button',
			onclick: function (e) {
				if (run.primary.action === 'melden') { location.hash = '#/platte/' + run.id; return; }
				e.target.disabled = true;
				runAct(run.id, run.primary.action).then(function (r) {
					if (r && r.queued) { toast('Offline vorgemerkt'); }
					reload();
				}).catch(function (err) { e.target.disabled = false; toast(err.message, true); });
			}
		}, run.primary.label) : null;

		return h('article', { class: 'run-card st-' + run.status },
			h('div', { class: 'row-top' },
				h('a', { class: 'run-title', href: '#/platte/' + run.id }, 'Platte ' + run.id),
				h('span', { class: 'muted small' }, meta)
			),
			h('div', { class: 'muted', style: 'font-size:13px;margin-top:3px' }, line),
			run.status === 'ruesten' || run.status === 'druckt' ? h('div', { class: 'muted small', style: 'margin-top:2px' }, itemsLine(run)) : null,
			progress !== null ? h('div', { class: 'bar', role: 'progressbar', 'aria-valuenow': progress, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': 'Fortschritt Platte ' + run.id, style: 'margin-top:9px;height:9px' }, h('i', { style: 'width:' + progress + '%' })) : null,
			chips.length ? h('div', { class: 'chips' }, chips.map(function (c) { return h('span', { class: 'chip' }, c); })) : null,
			h('div', { style: 'display:flex;gap:8px;margin-top:11px;flex-wrap:wrap' },
				primary,
				run.file && run.status === 'freigegeben' ? h('a', { class: 'btn', href: run.file.url }, ico('download'), '3MF') : null
			)
		);
	}

	function viewWorkflow() {
		var data = null;
		var demand = null;
		var selected = {};

		var plan = can('fertigung.workflow.plan');

		var actions = [];
		if (plan) {
			actions.push(h('button', { class: 'btn', type: 'button', onclick: openSettings }, ico('cog'), 'Einstellungen'));
			actions.push(h('button', { class: 'btn primary', type: 'button', onclick: function () { openPlan(demand, selected, load); } }, 'Plan freigeben ▸'));
		}
		pageHead('Workflow', 'Vom Bedarf zum Bestand. Jede Karte hat einen nächsten Schritt.', actions);

		var alertBox = h('div');
		var steps = h('ol', { class: 'steps' });
		var board = h('div', { class: 'board' });
		var done = h('section', { class: 'done-strip' });
		el.view.appendChild(alertBox);
		el.view.appendChild(steps);
		el.view.appendChild(board);
		el.view.appendChild(done);

		function column(title, count, cards, empty) {
			return h('section', { class: 'col' },
				h('header', null, h('h3', null, title), h('span', { class: 'count' }, String(count))),
				h('div', { class: 'col-cards' }, cards.length ? cards : h('div', { class: 'empty muted' }, empty))
			);
		}

		function demandCard(c) {
			var on = !!selected[c.article_id];
			return h('article', { class: 'run-card demand' + (on ? ' on' : '') },
				h('div', { class: 'row-top' }, h('b', { class: 'run-title' }, c.name), h('span', { class: 'muted small' }, qty(c.qty) + ' Stk')),
				h('div', { class: 'muted', style: 'font-size:13px;margin-top:3px' }, c.sku + ' · Lager ' + qty(c.stock)),
				h('div', { class: 'chips' }, c.chips.map(function (x) { return h('span', { class: 'chip' }, x); }).concat(c.has_model ? [] : [h('a', { class: 'chip warn', href: '#/produkt/' + c.article_id + '/rev', title: 'Für diesen Artikel ist keine ungeslicte Projekt-3MF hinterlegt. Klicken, um sie hochzuladen.' }, 'keine 3MF – hochladen')])),
				plan ? h('button', { class: 'btn' + (on ? ' primary' : ''), type: 'button', style: 'margin-top:11px;width:100%', 'aria-pressed': on ? 'true' : 'false', onclick: function () { if (on) { delete selected[c.article_id]; } else { selected[c.article_id] = c.qty; } paint(); } }, on ? 'Im Plan ✓' : 'In Plan aufnehmen') : null
			);
		}

		function paint() {
			if (!data) { return; }
			var cols = data.columns;
			var dc = demand ? demand.cards : [];

			alertBox.textContent = '';
			(data.alerts || []).forEach(function (a) {
				alertBox.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:8px' },
					h('b', null, a.label + ' wird knapp. '),
					(a.coverage === null ? 'Reichweite unbekannt' : 'Reicht noch ' + Math.round(a.coverage) + ' Tage') + ' – Meldegrenze ' + a.threshold + ' Tage (Lieferzeit plus Puffer). Bestellen: ca. ' + Math.round(a.order_kg) + ' kg.'
				));
			});

			steps.textContent = '';
			[['1', 'Bedarf', dc.length], ['2', 'Freigegeben', cols.freigegeben.length], ['3', 'Im Druck', cols.im_druck.length], ['4', 'Ist melden', cols.zu_melden.length], ['5', 'Eingelagert', cols.erledigt.length]].forEach(function (s) {
				steps.appendChild(h('li', null, h('small', null, s[0]), h('b', null, s[1]), h('span', null, plan || s[0] !== '1' ? String(s[2]) : '–')));
			});

			board.textContent = '';
			board.appendChild(column('Bedarf', plan ? dc.length : 0, dc.map(demandCard), plan ? (demand ? 'Alles gedeckt.' : 'Wird berechnet …') : 'Den Bedarf sieht, wer Pläne freigeben darf.'));
			var reload = function () { load(); };
			board.appendChild(column('Freigegeben', cols.freigegeben.length, cols.freigegeben.map(function (r) { return runCard(r, reload, plan); }), 'Keine Platte wartet.'));
			board.appendChild(column('Im Druck', cols.im_druck.length, cols.im_druck.map(function (r) { return runCard(r, reload, plan); }), 'Nichts im Druck.'));
			board.appendChild(column('Ist melden', cols.zu_melden.length, cols.zu_melden.map(function (r) { return runCard(r, reload, plan); }), 'Nichts zu melden.'));

			done.textContent = '';
			done.appendChild(h('b', null, 'Heute eingelagert'));
			if (!cols.erledigt.length) { done.appendChild(h('span', { class: 'muted' }, 'noch nichts')); }
			cols.erledigt.forEach(function (r) {
				done.appendChild(h('a', { class: 'muted', href: '#/platte/' + r.id }, 'Platte ' + r.id + ' · ' + itemsLine(r)));
			});
		}

		function load() {
			return api('workflow', { cache: true }).then(function (d) { data = d; S.wf = d; S.skew = d.now - Date.now() / 1000; paint(); }).catch(function (e) { board.textContent = ''; board.appendChild(h('div', { class: 'notice warn' }, e.message)); });
		}

		function loadDemand() {
			if (!plan) { return; }
			api('workflow/bedarf').then(function (d) { demand = d; paint(); }).catch(function (e) { toast(e.message, true); });
		}

		paintSkeleton(board);
		load();
		loadDemand();
		timers.push(setInterval(function () { if (!document.hidden) { load(); } }, 15000));
	}

	function paintSkeleton(box) {
		box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
	}

	function openPlan(demand, selected, reload) {
		var cards = demand ? demand.cards : [];
		var body = h('div', { class: 'sheet-body' });
		var d;

		if (!cards.length) {
			body.appendChild(h('p', null, 'Es gibt gerade keinen Bedarf, der gedruckt werden müsste.'));
			dlg('Plan freigeben', body);
			return;
		}

		var picks = {};
		var chosen = Object.keys(selected).length ? selected : null;
		cards.forEach(function (c) { picks[c.article_id] = { on: chosen ? !!chosen[c.article_id] : c.has_model, qty: chosen && chosen[c.article_id] ? chosen[c.article_id] : c.qty }; });

		var list = h('div', { class: 'pick-list' });
		cards.forEach(function (c) {
			var cb = h('input', { type: 'checkbox', id: 'pl-' + c.article_id, checked: picks[c.article_id].on, disabled: !c.has_model, onchange: function (e) { picks[c.article_id].on = e.target.checked; } });
			var num = h('input', { class: 'field', type: 'number', min: '1', step: '1', value: String(picks[c.article_id].qty), 'aria-label': 'Menge ' + c.name, style: 'width:90px', oninput: function (e) { picks[c.article_id].qty = parseInt(e.target.value, 10) || 0; } });
			list.appendChild(h('div', { class: 'pick-line' },
				cb,
				h('label', { for: 'pl-' + c.article_id }, h('b', null, c.name), h('small', { class: 'muted' }, ' ' + c.sku + (c.has_model ? '' : ' · keine 3MF hinterlegt'))),
				num
			));
		});

		var printers = (S.wf && S.wf.printers) || [];
		var printerSel = h('select', { class: 'field', id: 'pl-printer', 'aria-label': 'Drucker' }, printers.map(function (p) { return h('option', { value: p.id }, p.name + (p.online ? '' : ' (offline)')); }));
		var out = h('div', { 'aria-live': 'polite', style: 'margin-top:14px' });

		var previewBtn = h('button', { class: 'btn primary', type: 'button' }, 'Platten berechnen');
		previewBtn.addEventListener('click', function () {
			var items = cards.filter(function (c) { return picks[c.article_id].on && picks[c.article_id].qty > 0; }).map(function (c) { return { article_id: c.article_id, qty: picks[c.article_id].qty }; });
			if (!items.length) { toast('Bitte mindestens eine Position wählen.', true); return; }
			if (!printerSel.value) { toast('Kein Bambu-Drucker angelegt.', true); return; }
			previewBtn.disabled = true;
			out.textContent = 'Platten werden angeordnet …';
			api('workflow/vorschau', { method: 'POST', body: { items: items, printer: printerSel.value } }).then(function (r) {
				previewBtn.disabled = false;
				showPreview(out, r, d, reload);
			}).catch(function (e) {
				previewBtn.disabled = false;
				out.textContent = '';
				out.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		});

		body.appendChild(h('p', { class: 'muted', style: 'margin-top:0' }, 'Wähle, was gebaut werden soll. Die Platten werden angeordnet, die Projekt-3MF entsteht ungeslict – geslict wird in der Werkstatt.'));
		body.appendChild(list);
		body.appendChild(h('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px' }, h('label', { for: 'pl-printer', class: 'muted' }, 'Drucker'), printerSel, previewBtn));
		body.appendChild(out);
		d = dlg('Plan freigeben', body, true);
	}

	function showPreview(out, r, d, reload) {
		out.textContent = '';
		(r.warnings || []).forEach(function (w) { out.appendChild(h('div', { class: 'notice warn', style: 'margin-bottom:8px' }, w)); });

		var total = 0;
		var rows = h('tbody');
		r.plates.forEach(function (p, i) {
			total += p.est_minutes;
			rows.appendChild(h('tr', null,
				h('td', null, h('b', null, 'Platte ' + (i + 1))),
				h('td', null, p.printer),
				h('td', null, p.items.map(function (x) { return qty(x.qty) + '× ' + x.name; }).join(', ')),
				h('td', { class: 'num' }, p.est_minutes ? 'ca. ' + minTxt(p.est_minutes) : '–'),
				h('td', null, h('span', { class: 'tag now' }, '3MF erzeugt'))
			));
		});

		out.appendChild(h('div', { class: 'card', style: 'padding:0;overflow:auto' }, h('table', { class: 'list' },
			h('thead', null, h('tr', null, h('th', null, 'Platte'), h('th', null, 'Drucker'), h('th', null, 'Inhalt'), h('th', { class: 'num' }, 'Dauer'), h('th', null, 'Datei'))),
			rows
		)));

		if (r.material && r.material.length) {
			out.appendChild(h('div', { class: 'card', style: 'margin-top:12px' }, h('b', null, 'Filament'), materialRows(r.material)));
		}

		var lbl = h('input', { type: 'checkbox', id: 'pl-label', checked: true });
		var release = h('button', { class: 'btn primary', type: 'button' }, 'Freigeben und Etiketten drucken');
		release.addEventListener('click', function () {
			release.disabled = true;
			api('workflow/freigeben', { method: 'POST', body: { plate_ids: r.plates.map(function (p) { return p.plate_id; }), labels: lbl.checked } }).then(function (res) {
				var msg = res.runs.length + (res.runs.length === 1 ? ' Platte freigegeben' : ' Platten freigegeben');
				if (res.label) { msg += ' · ' + res.label.message; }
				toast(msg, !!(res.label && !res.label.ok));
				d.close();
				reload();
			}).catch(function (e) { release.disabled = false; toast(e.message, true); });
		});

		out.appendChild(h('div', { style: 'display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:12px' },
			h('span', { class: 'muted' }, r.plates.length + ' Platten · Druckzeit gesamt ca. ' + minTxt(total)),
			h('label', { class: 'chk', for: 'pl-label' }, lbl, ' Etiketten drucken'),
			release
		));
	}

	function openSettings() {
		var body = h('div', { class: 'sheet-body' }, 'Wird geladen …');
		var d = dlg('Workflow-Einstellungen', body, true);

		api('workflow/einstellungen').then(function (r) {
			var s = r.settings;
			body.textContent = '';

			var printer = h('select', { class: 'field', id: 'ws-printer' }, [h('option', { value: '' }, '– keiner –')].concat(r.queue_printers.map(function (p) { return h('option', { value: p.id, selected: p.id === s.label_printer }, p.name + (p.online ? '' : ' (offline)')); })));
			var roll = h('select', { class: 'field', id: 'ws-roll' }, r.rolls.map(function (x) { return h('option', { value: x.id, selected: x.id === s.label_roll }, x.label); }));
			var rollHint = h('small', { class: 'muted' }, '');
			function hint() { var cur = r.rolls.filter(function (x) { return x.id === roll.value; })[0]; rollHint.textContent = cur ? cur.hint : ''; }
			roll.addEventListener('change', hint); hint();
			var copies = h('select', { class: 'field', id: 'ws-copies' }, [1, 2, 3].map(function (n) { return h('option', { value: String(n), selected: n === s.label_copies }, String(n)); }));
			var auto = h('input', { type: 'checkbox', id: 'ws-auto', checked: !!s.label_auto });
			var hide = h('input', { type: 'checkbox', id: 'ws-hide', checked: !!s.hide_wpadmin });
			var fLead = h('input', { class: 'field', type: 'number', id: 'ws-flead', min: '0', max: '120', value: String(s.filament_lead_days) });
			var fSafe = h('input', { class: 'field', type: 'number', id: 'ws-fsafe', min: '0', max: '60', value: String(s.filament_safety_days) });
			var weeks = h('input', { class: 'field', type: 'number', id: 'ws-weeks', min: '1', max: '26', value: String(s.weeks) });
			var cover = h('input', { class: 'field', type: 'number', id: 'ws-cover', min: '0', max: '12', step: '0.5', value: String(s.cover_weeks) });

			var fields = h('fieldset', { class: 'fieldset' }, h('legend', null, 'Was auf dem Etikett steht'));
			var boxes = {};
			r.fields.forEach(function (f) {
				boxes[f.id] = h('input', { type: 'checkbox', id: 'ws-f-' + f.id, checked: s.label_fields.indexOf(f.id) > -1 });
				fields.appendChild(h('label', { class: 'chk', for: 'ws-f-' + f.id }, boxes[f.id], ' ' + f.label));
			});

			body.appendChild(h('h4', { class: 'sec' }, 'Etikett'));
			body.appendChild(h('div', { class: 'form-grid' },
				h('div', null, h('label', { for: 'ws-printer' }, 'Etikettendrucker'), printer, r.queue_printers.length ? null : h('small', { class: 'muted' }, 'Noch keiner angelegt: unter Verwaltung → Etikettendrucker.')),
				h('div', null, h('label', { for: 'ws-roll' }, 'Rolle / Größe'), roll, rollHint),
				h('div', null, h('label', { for: 'ws-copies' }, 'Kopien je Platte'), copies)
			));
			body.appendChild(fields);
			body.appendChild(h('label', { class: 'chk', for: 'ws-auto', style: 'margin-top:10px' }, auto, ' Beim Freigeben des Plans automatisch drucken'));
			body.appendChild(h('h4', { class: 'sec' }, 'Hochrechnung'));
			body.appendChild(h('div', { class: 'form-grid' },
				h('div', null, h('label', { for: 'ws-weeks' }, 'Verkaufsverlauf (Wochen)'), weeks),
				h('div', null, h('label', { for: 'ws-cover' }, 'Bestand soll reichen für (Wochen)'), cover)
			));

			body.appendChild(h('h4', { class: 'sec' }, 'Filament nachbestellen'));
			body.appendChild(h('div', { class: 'form-grid' },
				h('div', null, h('label', { for: 'ws-flead' }, 'Lieferzeit (Tage)'), fLead),
				h('div', null, h('label', { for: 'ws-fsafe' }, 'Sicherheitspuffer (Tage)'), fSafe)
			));
			body.appendChild(h('div', { class: 'muted small', style: 'margin-top:4px' }, 'Der Alarm kommt, wenn der Bestand einer Farbe bei dem Verbrauch der letzten 4 Wochen kürzer reicht als Lieferzeit plus Puffer.'));
			body.appendChild(h('h4', { class: 'sec' }, 'WordPress-Admin'));
			body.appendChild(h('label', { class: 'chk', for: 'ws-hide' }, hide, ' wp-admin nur für die Website: ERP-Menüs ausblenden, ERP-Nutzer ohne Websiteverwaltung nach der Anmeldung ins ERP leiten'));
			body.appendChild(h('div', { class: 'muted small', style: 'margin-top:4px' }, 'Das wp-admin zeigt dann nur noch die Website. Die Seiten bleiben erreichbar und werden im ERP weiter eingebettet angezeigt.'));

			var save = h('button', { class: 'btn primary', type: 'button', style: 'margin-top:16px' }, 'Speichern');
			save.addEventListener('click', function () {
				save.disabled = true;
				api('workflow/einstellungen', { method: 'POST', body: {
					label_printer: printer.value, label_roll: roll.value, label_copies: parseInt(copies.value, 10), label_auto: auto.checked ? 1 : 0,
					label_fields: Object.keys(boxes).filter(function (k) { return boxes[k].checked; }),
					weeks: parseInt(weeks.value, 10), cover_weeks: parseFloat(cover.value), hide_wpadmin: hide.checked ? 1 : 0,
					filament_lead_days: parseInt(fLead.value, 10), filament_safety_days: parseInt(fSafe.value, 10)
				} }).then(function () { toast('Gespeichert'); d.close(); }).catch(function (e) { save.disabled = false; toast(e.message, true); });
			});
			body.appendChild(save);
		}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice warn' }, e.message)); });
	}

	/* ---------- Platte (mobile Seite hinter dem QR-Code) ---------- */

	function viewRun(id) {
		var box = h('div', { class: 'runpage' });
		el.view.appendChild(box);
		setHead('Platte ' + id, 'Workflow');

		var run = null;
		var reasons = [];
		var canPlan = false;
		var report = null;

		function reloadFromServer() {
			return api('runs/' + id, { cache: true }).then(function (r) {
				run = r.run; run.fetched = Math.floor(Date.now() / 1000);
				reasons = r.reasons; canPlan = r.plan;
				paint();
			}).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message));
			});
		}

		function act(action) {
			return runAct(id, action).then(function (r) {
				if (r && r.queued) { localAdvance(run, action); toast('Offline vorgemerkt'); paint(); return; }
				run = r.run; run.fetched = Math.floor(Date.now() / 1000); paint();
			}).catch(function (e) { toast(e.message, true); });
		}

		function nowTs() { return run.now + (Date.now() / 1000 - run.fetched); }

		function bigButton(label, onClick, kind) {
			return h('button', { class: 'bigbtn' + (kind ? ' ' + kind : ''), type: 'button', onclick: function (e) { e.target.disabled = true; onClick(); } }, label);
		}

		function paint() {
			box.textContent = '';
			if (!run) { return; }
			setHead('Platte ' + run.id, run.printer || 'Workflow');

			box.appendChild(h('div', { class: 'run-head' },
				h('div', { class: 'eyebrow' }, [run.printer, RUN_STATUS[run.status] || run.status].filter(Boolean).join(' · ')),
				h('h2', null, 'Platte ' + run.id),
				h('div', { class: 'run-items' }, itemsLine(run)),
				h('div', { class: 'muted' }, [run.est_minutes ? 'ca. ' + minTxt(run.est_minutes) : '', run.batch ? 'Plan ' + run.batch : ''].filter(Boolean).join(' · '))
			));

			if (run.status === 'freigegeben') {
				box.appendChild(h('div', { class: 'card stepcard' }, h('div', { class: 'stepno' }, '1'), h('div', null, h('b', null, 'Datei laden'), h('div', { class: 'muted small' }, run.file ? 'Projekt-3MF – im Slicer öffnen und slicen' : 'Keine Datei hinterlegt'))));
				if (run.file) { box.appendChild(h('a', { class: 'btn wide', href: run.file.url, style: 'margin-bottom:12px;min-height:52px' }, ico('download'), '3MF herunterladen')); }
				box.appendChild(h('div', { class: 'card' },
					h('div', { class: 'stepcard' }, h('div', { class: 'stepno alt' }, '2'), h('div', null, h('b', null, 'Material bereit?'), h('div', { class: 'muted small' }, 'Die Rolle wird beim Melden abgezogen'))),
					run.material && run.material.length ? materialRows(run.material) : h('div', { class: 'muted small', style: 'margin-top:8px' }, 'Für diese Artikel ist kein Gewicht hinterlegt.')
				));
				box.appendChild(h('div', { class: 'run-foot' }, bigButton('Rüsten starten ▶', function () { act('ruesten'); }), h('div', { class: 'muted small center' }, 'Die Rüstzeit läuft ab dem Tippen')));
			} else if (run.status === 'ruesten') {
				box.appendChild(timeCard([['Rüstzeit', clock(nowTs() - run.setup), 'läuft']]));
				box.appendChild(h('div', { class: 'run-foot' }, bigButton('Druck gestartet ▶', function () { act('druckstart'); }), h('div', { class: 'muted small center' }, 'Wird automatisch erkannt, wenn der Drucker verbunden ist')));
			} else if (run.status === 'druckt') {
				var live = run.live;
				var est = run.est_minutes ? Math.min(99, Math.round(((nowTs() - run.print) / 60) / run.est_minutes * 100)) : null;
				var pct = live && live.progress !== null ? live.progress : est;

				box.appendChild(h('div', { class: 'card' },
					h('div', { class: 'row-top' }, h('b', null, 'druckt'), h('span', { class: 'muted' }, live && live.remaining !== null ? 'noch ' + minTxt(live.remaining) : '')),
					pct !== null ? h('div', { class: 'bar big', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-label': 'Fortschritt' }, h('i', { style: 'width:' + pct + '%' })) : null,
					h('div', { class: 'row-top muted small', style: 'margin-top:8px' }, h('span', null, pct !== null ? pct + ' %' : ''), live && live.nozzle !== null ? h('span', null, 'Düse ' + Math.round(live.nozzle) + ' °C · Bett ' + Math.round(live.bed) + ' °C') : null),
					h('div', { class: 'muted small' }, live ? 'Live vom Drucker (Bambu)' : 'Schätzung nach Zeit – der Drucker meldet nichts')
				));
				box.appendChild(timeCard([['Rüstzeit', clock(run.print - run.setup), 'beendet'], ['Druckzeit', clock(nowTs() - run.print), 'läuft']]));
				box.appendChild(h('div', { class: 'run-foot' }, bigButton('Druck fertig ▶', function () { act('druckende'); }), h('button', { class: 'btn wide', type: 'button', onclick: function () { report = true; run.status = 'zu_melden'; paint(); } }, 'Direkt Ist-Menge melden')));
			} else if (run.status === 'zu_melden') {
				box.appendChild(timeCard([['Rüstzeit', clock((run.print || run.print_end || nowTs()) - (run.setup || run.print || nowTs())), 'beendet'], ['Druckzeit', clock((run.print_end || nowTs()) - (run.print || nowTs())), 'beendet']]));
				box.appendChild(reportForm());
			} else if (run.status === 'erledigt') {
				box.appendChild(doneCard());
			} else {
				box.appendChild(h('div', { class: 'notice warn' }, 'Diese Platte wurde abgebrochen.'));
			}

			if (canPlan && (run.status === 'freigegeben' || run.status === 'ruesten' || run.status === 'druckt' || run.status === 'zu_melden')) {
				box.appendChild(h('div', { class: 'run-more' },
					h('button', { class: 'btn', type: 'button', onclick: function () { api('runs/' + id + '/etikett', { method: 'POST' }).then(function (r) { toast(r.message); }).catch(function (e) { toast(e.message, true); }); } }, 'Etikett nochmal drucken'),
					h('button', { class: 'btn', type: 'button', style: 'color:var(--error)', onclick: function () { if (window.confirm('Platte abbrechen? Es wird nichts gebucht.')) { act('abbrechen'); } } }, 'Abbrechen')
				));
			}
		}

		function timeCard(rows) {
			return h('div', { class: 'timegrid' }, rows.map(function (r) {
				return h('div', { class: 'card time' }, h('div', { class: 'eyebrow' }, r[0]), h('div', { class: 'tnum' }, r[1]), h('div', { class: 'muted small' }, r[2]));
			}));
		}

		function reportForm() {
			var ok = {};
			var lotPick = {};
			run.items.forEach(function (i) { ok[i.article_id + '|' + i.color] = i.qty; });
			var form = h('form', { class: 'card reportform' });
			var reasonWrap = h('div', { hidden: true }, h('label', { for: 'rf-reason' }, 'Fehlerursache'));
			var reason = h('select', { class: 'field', id: 'rf-reason' }, [h('option', { value: '' }, '– bitte wählen –')].concat(reasons.map(function (r) { return h('option', { value: r.id }, r.label); })));
			reasonWrap.appendChild(reason);
			var counters = [];

			function sync() {
				var any = false;
				counters.forEach(function (c) {
					var key = c.item.article_id + '|' + c.item.color;
					c.good.textContent = qty(ok[key]);
					c.scrap.textContent = qty(c.item.qty - ok[key]);
					if (ok[key] < c.item.qty) { any = true; }
				});
				reasonWrap.hidden = !any;
			}

			run.items.forEach(function (item) {
				var key = item.article_id + '|' + item.color;
				var good = h('div', { class: 'big-n' }, '');
				var scrap = h('div', { class: 'big-n' }, '');
				counters.push({ item: item, good: good, scrap: scrap });
				var step = function (d) { ok[key] = Math.max(0, Math.min(item.qty, ok[key] + d)); sync(); };
				var stepper = function (label, node, minus, plus) {
					return h('div', null, h('div', { class: 'eyebrow' }, label), h('div', { class: 'stepper' },
						h('button', { type: 'button', 'aria-label': 'Eins weniger (' + label + ')', onclick: minus }, '−'), node,
						h('button', { type: 'button', 'aria-label': 'Eins mehr (' + label + ')', onclick: plus }, '+')));
				};
				var mrow = (run.material || []).filter(function (m) { return m.color === item.ckey; })[0];
				var rollBox = null;
				if (item.kg) {
					if (mrow && mrow.lots.length) {
						lotPick[key] = mrow.suggested || mrow.lots[0].id;
						var sel = h('select', { class: 'field', 'aria-label': 'Rolle für ' + item.name, onchange: function (e) { lotPick[key] = parseInt(e.target.value, 10); } },
							mrow.lots.map(function (l) { return h('option', { value: String(l.id), selected: l.id === lotPick[key] }, l.code + ' · Rest ' + kgTxt(l.remaining_kg)); }));
						rollBox = h('div', null, h('div', { class: 'eyebrow' }, 'Rolle · Verbrauch ca. ' + kgTxt(item.kg)), sel);
					} else {
						rollBox = h('div', { class: 'notice warn small' }, 'Keine Rolle in dieser Farbe im Lager – der Verbrauch (ca. ' + kgTxt(item.kg) + ') wird nicht abgezogen.');
					}
				}

				form.appendChild(h('div', { class: 'rf-item' },
					h('div', { class: 'row-top' }, h('b', null, item.name), h('span', { class: 'muted' }, 'Soll ' + qty(item.qty))),
					stepper('Gut', good, function () { step(-1); }, function () { step(1); }),
					stepper('Ausschuss', scrap, function () { step(1); }, function () { step(-1); }),
					rollBox
				));
			});
			sync();

			form.appendChild(reasonWrap);
			var submit = h('button', { class: 'bigbtn go', type: 'submit' }, 'Melden und einlagern ✓');
			form.appendChild(submit);
			form.appendChild(h('div', { class: 'muted small center' }, 'Fehlmengen gehen als Bedarf zurück in den Plan.'));

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var items = run.items.map(function (i) { return { article_id: i.article_id, color: i.color, ok: ok[i.article_id + '|' + i.color] }; });
				var short = run.items.some(function (i) { return ok[i.article_id + '|' + i.color] < i.qty; });
				if (short && !reason.value) { toast('Bitte die Fehlerursache wählen.', true); return; }
				submit.disabled = true;
				runAct(id, 'melden', { items: items, lots: lotPick, failure_reason: short ? reason.value : 'keine' }).then(function (r) {
					if (r && r.queued) { localAdvance(run, 'melden'); run.items = run.items.map(function (i) { return Object.assign({}, i, { ok: ok[i.article_id + '|' + i.color], scrap: i.qty - ok[i.article_id + '|' + i.color] }); }); toast('Offline vorgemerkt – wird gebucht, sobald die Verbindung zurück ist.'); paint(); return; }
					run = r.run; run.fetched = Math.floor(Date.now() / 1000); run.summary = r.summary; paint();
				}).catch(function (er) { submit.disabled = false; toast(er.message, true); });
			});

			return form;
		}

		function doneCard() {
			var wrap = h('div');
			var rows = h('div', { class: 'card summary' }, run.items.map(function (i) {
				var good = i.ok !== undefined ? i.ok : i.qty;
				return h('div', { class: 'row-top' }, h('span', null, i.name), h('b', null, '+' + qty(good) + (i.scrap ? ' · Ausschuss ' + qty(i.scrap) : '')));
			}));
			var times = h('div', { class: 'muted small' }, 'Rüsten ' + clock((run.print || run.setup || 0) - (run.setup || 0)) + ' · Druck ' + clock((run.print_end || 0) - (run.print || 0)));

			wrap.appendChild(h('div', { class: 'okmark' }, ico('check'), h('div', null, h('b', null, 'Platte ' + run.id + ' erledigt'), h('div', { class: 'muted' }, 'Im Lager eingebucht'))));
			wrap.appendChild(rows);
			if (run.summary && run.summary.material && run.summary.material.length) {
				wrap.appendChild(h('div', { class: 'card summary' }, run.summary.material.map(function (m) {
					return h('div', { class: 'row-top' }, h('span', null, m.label), h('b', null, m.lot ? '−' + kgTxt(m.kg) + ' · Rolle ' + m.lot + (m.remaining !== null ? ' (Rest ' + kgTxt(m.remaining) + ')' : '') : 'keine Rolle – nicht abgezogen'));
				})));
			}
			wrap.appendChild(times);

			var next = h('div', { style: 'margin-top:14px' });
			wrap.appendChild(next);
			api('workflow', { cache: true }).then(function (d) {
				var n = d.columns.freigegeben.filter(function (r) { return r.id !== run.id; })[0];
				if (!n) { return; }
				next.appendChild(h('div', { class: 'card dark' }, h('div', { class: 'eyebrow' }, 'Dein Nächstes'), h('div', { class: 'big-title' }, 'Platte ' + n.id), h('div', null, itemsLine(n))));
				next.appendChild(h('a', { class: 'bigbtn', href: '#/platte/' + n.id, style: 'text-decoration:none;display:block;text-align:center;margin-top:12px' }, 'Platte ' + n.id + ' vorbereiten ▶'));
			}).catch(function () { /* ohne Liste kein Vorschlag */ });
			return wrap;
		}

		reloadFromServer();
		timers.push(setInterval(function () {
			if (document.hidden || !run) { return; }
			if (run.status === 'ruesten' || run.status === 'druckt') { reloadFromServer(); } else if (run.status === 'zu_melden' && report) { /* Eingabe nicht überschreiben */ } else { paint(); }
		}, 10000));
	}

	var RUN_STATUS = { freigegeben: 'bereit', ruesten: 'rüstet', druckt: 'druckt', zu_melden: 'Ist melden', erledigt: 'erledigt', abgebrochen: 'abgebrochen' };

	/* ---------- Suche ---------- */

	function openPalette() {
		var all = [{ title: 'Start', area: '', href: '#/start' }];
		S.boot.nav.forEach(function (a) {
			all.push({ title: a.title, area: 'Bereich', href: '#/bereich/' + a.id });
			a.items.forEach(function (i) { all.push({ title: i.title, area: a.title, href: '#/seite/' + i.id }); });
		});

		var dlg = h('dialog', { class: 'palette', 'aria-label': 'Suche' });
		var input = h('input', { type: 'search', placeholder: 'Seite suchen …', 'aria-label': 'Seite suchen', autocomplete: 'off' });
		var ul = h('ul');
		var sel = 0;
		var shown = [];

		function paint() {
			var q = input.value.trim().toLowerCase();
			shown = all.filter(function (x) { return !q || (x.title + ' ' + x.area).toLowerCase().indexOf(q) > -1; }).slice(0, 12);
			sel = Math.min(sel, Math.max(0, shown.length - 1));
			ul.textContent = '';
			shown.forEach(function (x, i) {
				ul.appendChild(h('li', null, h('a', { href: x.href, class: i === sel ? 'sel' : '', onclick: function () { dlg.close(); } }, x.title, h('small', null, x.area))));
			});
			if (!shown.length) { ul.appendChild(h('li', { class: 'muted', style: 'padding:12px' }, 'Nichts gefunden.')); }
		}

		input.addEventListener('input', function () { sel = 0; paint(); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, shown.length - 1); paint(); e.preventDefault(); }
			if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); paint(); e.preventDefault(); }
			if (e.key === 'Enter' && shown[sel]) { location.hash = shown[sel].href; dlg.close(); }
		});
		dlg.addEventListener('close', function () { dlg.remove(); });
		dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });

		dlg.appendChild(input);
		dlg.appendChild(ul);
		document.body.appendChild(dlg);
		paint();
		dlg.showModal();
		input.focus();
	}

	/* ---------- Start ---------- */

	document.addEventListener('keydown', function (e) {
		var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
		if (!S.boot) { return; }
		if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); if (!document.querySelector('dialog[open]')) { openPalette(); } }
		else if (e.key === '/' && !typing && !document.querySelector('dialog[open]')) { e.preventDefault(); openPalette(); }
		else if (e.key === 'Escape' && el.app) { el.app.classList.remove('drawer-open', 'kiosk'); }
	});

	window.addEventListener('beforeunload', function (e) {
		if (S.draft && S.rights && isDirty()) { e.preventDefault(); e.returnValue = ''; }
	});

	// QR-Code am Drucker: /erp/p/<nummer>. Der Server liefert dafür die
	// Oberfläche; hier wird daraus die Platten-Seite.
	(function () {
		var base = C.erpPath || '/';
		var rest = location.pathname.indexOf(base) === 0 ? location.pathname.slice(base.length) : location.pathname.replace(/^\//, '');
		var m = rest.match(/^p\/(\d+)\/?$/);
		if (m) { history.replaceState(null, '', base + '#/platte/' + m[1]); }
	})();

	Object.assign(UI, {
		h: h, api: api, toast: toast, ico: ico, money: money, when: when, ago: ago, qty: qty, num: num, minTxt: minTxt,
		dlg: dlg, pageHead: pageHead, classicBtn: classicBtn, setHead: setHead, can: can, findItem: findItem,
		store: store, config: C,
		view: function () { return el.view; },
		app: function () { return el.app; },
		boot: function () { return S.boot; },
		// Reiter des Bereichs zu einer Seite zeigen (für Seiten mit eigener Adresse).
		section: function (itemId) { var f = findItem(itemId); if (f) { renderSub(f); } },
		// Wiederkehrende Aktualisierung, die beim Seitenwechsel endet.
		every: function (fn, ms) { timers.push(setInterval(function () { if (!document.hidden) { fn(); } }, ms)); },
		// Zum Seitenkopf der Akte: Seitentitel setzen und Reiter halten.
		reload: function () { render(); },
		clockSync: function () { clockSync(); }
	});

	// Laufende Stempeluhr in der Kopfzeile: holt den Stand selten, zählt selbst weiter.
	var uhr = { running: false, paused: false, base: 0, at: 0 };

	function clockText() {
		if (!uhr.running) { return 'Zeit'; }
		var s = Math.max(0, Math.floor(uhr.base + (uhr.paused ? 0 : (Date.now() - uhr.at) / 1000)));
		var t = ('0' + Math.floor(s / 3600)).slice(-2) + ':' + ('0' + Math.floor((s % 3600) / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2);
		return uhr.paused ? 'Pause ' + t : t;
	}

	function clockPaint() {
		if (!el.clockLabel) { return; }
		el.clockLabel.textContent = clockText();
		var pill = document.getElementById('clock-pill');
		if (pill) { pill.classList.toggle('on', uhr.running && !uhr.paused); }
	}

	function clockSync() {
		if (!el.clockLabel) { return; }
		api('zeit/uhr').then(function (d) {
			uhr.running = !!d.clock;
			uhr.paused = !!(d.clock && d.clock.paused);
			uhr.base = d.clock ? d.clock.seconds : 0;
			uhr.at = Date.now();
			clockPaint();
		}).catch(function () {});
	}

	// Ungelesene Mails als Zähler an der Mailbox, auf jeder Seite.
	function mailBadge() {
		if (!el.mailBtn) { return; }
		api('aufgaben').then(function (d) {
			var badge = el.mailBtn.querySelector('.badge');
			badge.textContent = String(d.mail || 0);
			badge.hidden = !d.mail;
		}).catch(function () {});
	}

	api('bootstrap', { cache: true }).then(function (boot) {
		S.boot = boot;
		buildShell();
		mailBadge();
		clockSync();
		setInterval(function () { if (!document.hidden) { mailBadge(); clockSync(); } }, 120000);
		setInterval(clockPaint, 1000);
		window.addEventListener('hashchange', render);
		window.addEventListener('online', function () { stale = false; syncNet(); flush(); });
		window.addEventListener('offline', syncNet);
		setInterval(function () { if (!document.hidden) { flush(); } }, 30000);
		syncNet();
		flush();
		if ('serviceWorker' in navigator && C.sw) { navigator.serviceWorker.register(C.sw, { scope: C.swScope || '/' }).catch(function () {}); }
		render();
	}).catch(function (e) {
		root.textContent = '';
		root.className = '';
		root.appendChild(h('div', { style: 'max-width:30rem;margin:15vh auto;padding:0 16px' },
			h('h1', null, 'ERP nicht erreichbar'),
			h('p', null, e.message),
			h('p', null, h('a', { href: C.erpUrl }, 'Neu laden'), ' · ', h('a', { href: C.shopUrl }, 'Zum Shop'))
		));
	});
})();
