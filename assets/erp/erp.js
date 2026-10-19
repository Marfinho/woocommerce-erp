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

	function api(path, opts) {
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
						: (json && json.message) || ('Fehler ' + res.status);
					var err = new Error(msg);
					err.status = res.status;
					throw err;
				}
				return json;
			});
		});
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
		return { name: parts[0] || 'start', id: parts[1] || '' };
	}

	/* ---------- Gerüst ---------- */

	function buildShell() {
		var boot = S.boot;
		var theme = store('lw16erp-theme');
		if (theme) { document.documentElement.setAttribute('data-theme', theme); }

		el.nav = h('nav', { class: 'nav', 'aria-label': 'Hauptmenü' });
		el.nav.appendChild(h('a', { class: 'nav-link', href: '#/start', 'data-r': 'start' }, ico('home'), 'Start'));

		boot.nav.forEach(function (area) {
			var btn = h('div', { class: 'nav-row' },
				h('a', { class: 'nav-area area-' + area.id, href: '#/bereich/' + area.id, 'data-area': area.id }, ico(area.icon), area.title),
				h('button', {
					class: 'chev-btn', type: 'button', 'aria-label': area.title + ' auf- oder zuklappen', 'data-chev': area.id, 'aria-expanded': 'false',
					onclick: function () { S.open[area.id] = !S.open[area.id]; syncNav(); }
				}, ico('chev', 'chev'))
			);
			var list = h('ul', { class: 'nav-sub', 'data-sub': area.id });
			area.items.forEach(function (item) {
				list.appendChild(h('li', null,
					h('a', { class: 'nav-link', href: '#/seite/' + item.id, 'data-item': item.id }, item.title, item.locked ? ico('lock', 'nav-lock') : null)
				));
			});
			el.nav.appendChild(btn);
			el.nav.appendChild(list);
		});

		var side = h('aside', { class: 'side', id: 'side' },
			h('div', { class: 'brand' },
				h('div', { class: 'brand-mark', 'aria-hidden': 'true' }, 'L'),
				h('div', { class: 'brand-name' }, boot.shop.name, h('small', null, 'ERP'))
			),
			el.nav,
			h('div', { class: 'side-foot' },
				h('div', { class: 'me' },
					h('img', { src: boot.user.avatar, alt: '', width: 36, height: 36 }),
					h('div', { style: 'min-width:0' }, h('b', null, boot.user.name), h('span', null, boot.user.full ? 'Vollzugriff' : 'ERP-Zugang'))
				),
				h('div', { class: 'side-links' },
					h('a', { href: boot.shop.url }, ico('shop'), 'Zum Shop'),
					boot.user.full ? h('a', { href: C.adminUrl }, ico('ext'), 'wp-admin') : null,
					h('a', { href: C.logout }, ico('logout'), 'Abmelden')
				)
			)
		);

		el.title = h('h1', null, '');
		el.crumb = h('span', { class: 'crumb' }, '');
		el.themeBtn = h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Hell/Dunkel umschalten', onclick: toggleTheme }, ico('moon'));

		var top = h('header', { class: 'top' },
			h('button', { class: 'icon-btn', id: 'menu-btn', type: 'button', 'aria-label': 'Menü', onclick: function () { el.app.classList.toggle('drawer-open'); } }, ico('menu')),
			h('div', { class: 'top-title' }, el.crumb, el.title),
			h('button', { class: 'search-btn', type: 'button', onclick: openPalette }, ico('search'), 'Suchen …', h('kbd', null, navigator.platform.indexOf('Mac') > -1 ? '⌘K' : 'Strg K')),
			h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Suchen', id: 'search-icon', onclick: openPalette }, ico('search')),
			el.themeBtn
		);

		el.view = h('main', { class: 'view', id: 'view', tabindex: '-1' });

		var tabs = h('nav', { class: 'tabbar', 'aria-label': 'Bereiche' },
			h('a', { class: 'tab', href: '#/start', 'data-r': 'start' }, ico('home'), 'Start')
		);
		boot.nav.forEach(function (area) {
			tabs.appendChild(h('a', { class: 'tab', href: '#/bereich/' + area.id, 'data-area': area.id }, ico(area.icon), area.title));
		});

		el.app = h('div', { class: 'erp' },
			side,
			h('div', { class: 'scrim', onclick: function () { el.app.classList.remove('drawer-open'); } }),
			h('div', { class: 'main' }, top, el.view),
			tabs
		);

		root.textContent = '';
		root.className = '';
		root.appendChild(el.app);
		el.tabs = tabs;
		syncTheme();

		// Auf dem Desktop zeigt das große Suchfeld, auf dem Handy nur das Symbol.
		var mq = window.matchMedia('(min-width: 900px)');
		var iconBtn = document.getElementById('search-icon');
		function mqSync() { iconBtn.style.display = mq.matches ? 'none' : 'inline-grid'; }
		mqSync();
		if (mq.addEventListener) { mq.addEventListener('change', mqSync); }
	}

	function isDark() {
		var t = document.documentElement.getAttribute('data-theme');
		if (t) { return t === 'dark'; }
		return window.matchMedia('(prefers-color-scheme: dark)').matches;
	}

	function syncTheme() {
		el.themeBtn.textContent = '';
		el.themeBtn.appendChild(ico(isDark() ? 'sun' : 'moon'));
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
		if (activeArea) { S.open[activeArea] = true; }

		Array.prototype.forEach.call(el.nav.querySelectorAll('[data-r]'), function (a) {
			a.classList.toggle('active', a.getAttribute('data-r') === r.name);
		});
		Array.prototype.forEach.call(el.nav.querySelectorAll('[data-area]'), function (a) {
			var id = a.getAttribute('data-area');
			a.classList.toggle('active', r.name === 'bereich' && r.id === id);
		});
		Array.prototype.forEach.call(el.nav.querySelectorAll('[data-chev]'), function (b) {
			b.setAttribute('aria-expanded', S.open[b.getAttribute('data-chev')] ? 'true' : 'false');
		});
		Array.prototype.forEach.call(el.nav.querySelectorAll('[data-sub]'), function (ul) {
			ul.hidden = !S.open[ul.getAttribute('data-sub')];
		});
		Array.prototype.forEach.call(el.nav.querySelectorAll('[data-item]'), function (a) {
			var on = r.name === 'seite' && r.id === a.getAttribute('data-item');
			a.classList.toggle('active', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		Array.prototype.forEach.call(el.tabs.querySelectorAll('.tab'), function (a) {
			var on = a.hasAttribute('data-r') ? r.name === 'start' : activeArea === a.getAttribute('data-area');
			a.classList.toggle('active', on);
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
		el.app.classList.remove('drawer-open');
		syncNav();

		var r = route();
		el.view.classList.remove('wide');
		el.view.textContent = '';

		if (r.name === 'bereich' && areaOf(r.id)) { return viewArea(areaOf(r.id)); }
		if (r.name === 'seite') { return viewPage(r.id); }
		return viewStart();
	}

	function viewStart() {
		var boot = S.boot;
		var hour = new Date().getHours();
		var hello = hour < 11 ? 'Guten Morgen' : (hour < 18 ? 'Guten Tag' : 'Guten Abend');
		setHead('Start', boot.shop.name);

		var tiles = h('div', { class: 'grid tiles' });
		var ordersCard = h('div', { class: 'card' }, h('h3', null, 'Letzte Bestellungen'), h('p', { class: 'muted' }, 'Wird geladen …'));

		el.view.appendChild(h('div', { class: 'page-head' },
			h('div', null, h('h2', null, hello + ', ' + boot.user.name.split(' ')[0]),
				h('p', { class: 'muted', style: 'margin:4px 0 0' }, new Date().toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' })))
		));

		el.view.appendChild(tiles);

		var areas = h('div', { class: 'grid cards', style: 'margin-top:18px' });
		boot.nav.forEach(function (area) {
			areas.appendChild(h('a', { class: 'link-card area-' + area.id, href: '#/bereich/' + area.id },
				h('div', { class: 'badge-ico' }, ico(area.icon)),
				h('div', null, h('b', null, area.title), h('span', { class: 'sub' }, area.items.length + (area.items.length === 1 ? ' Seite' : ' Seiten') + ' · ' + area.hint))
			));
		});
		el.view.appendChild(areas);

		el.view.appendChild(h('div', { class: 'two', style: 'margin-top:18px' }, ordersCard, phasesCard()));

		var skel = ['a', 'b', 'c', 'd'].map(function () { return h('div', { class: 'tile skeleton' }, h('div', { class: 'lab' }, ' '), h('div', { class: 'val' })); });
		skel.forEach(function (s) { tiles.appendChild(s); });

		function load() {
			return api('dashboard').then(paint);
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

	function phasesCard() {
		var steps = [
			[true, 'Gerüst, Anmeldung mit dem WordPress-Konto, Menü aus den Rechten'],
			[true, 'Rechte pro Person, fein einstellbar, mit Vorlagen'],
			[true, 'Alle bestehenden Seiten eingebettet – voller Funktionsumfang'],
			[true, 'Werkstatt nativ: Scannen mit Kamera oder Handscanner, Bestand buchen, Picklisten'],
			[true, 'Live-Aktualisierung der Startseite; als App auf den Startbildschirm legbar'],
			[false, 'Weitere native Seiten: Druckfarm-Anzeige, Aufträge, Versand – dann entfallen die eingebetteten'],
			[false, 'Module hinter „Nur Vollzugriff“ (Buchhaltung u. a.) auf feine Rechte umstellen'],
			[false, 'Subdomain und Offline-Erfassung in der Werkstatt']
		];
		var list = h('ol', { class: 'phases' });
		steps.forEach(function (s, i) {
			list.appendChild(h('li', { class: s[0] ? 'done' : '' }, h('span', { class: 'dot' }, s[0] ? ico('check') : String(i + 1)), h('span', null, s[1])));
		});
		return h('div', { class: 'card' }, h('h3', null, 'Ausbaustufen'), h('p', { class: 'muted', style: 'margin-top:-4px;font-size:13px' }, 'Prototyp – so weit ist die Oberfläche.'), list);
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

		if (f.item.kind === 'native') {
			if (f.item.id === 'rechte') { return viewRights(); }
			if (f.item.id === 'scannen') { return viewScan(); }
			if (f.item.id === 'picken') { return viewPick(); }
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

		d.groups.forEach(function (g) { ed.appendChild(groupCard(g, p, idx)); });

		ed.appendChild(previewCard(p, idx));

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
			out.appendChild(h('div', { class: 'card' }, 'Suche nach „' + code + '“ …'));

			api('scan?code=' + encodeURIComponent(code), { root: true }).then(function (r) {
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
				api('lager/bewegung', { root: true, method: 'POST', body: { article_id: articleId, qty: q, movement_type: kind.value, bin_id: parseInt(w[0], 10) || 0, color: w[1] || '', note: note.value } }).then(function () {
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
			return api('picklisten', { root: true }).then(function (lists) { paint(lists); });
		}

		function pick(item, scanned) {
			return api('picklisten/position', { root: true, method: 'POST', body: { item_id: item.id, qty: 0, scanned: scanned || '' } }).then(function (r) {
				toast(r.done ? 'Position vollständig' : 'Entnommen');
				return load();
			}).catch(function (e) { toast(e.message, true); });
		}

		function paint(lists) {
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
				api('scan?code=' + encodeURIComponent(code), { root: true }).then(function (r) {
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
		else if (e.key === 'Escape' && el.app) { el.app.classList.remove('drawer-open'); }
	});

	window.addEventListener('beforeunload', function (e) {
		if (S.draft && S.rights && isDirty()) { e.preventDefault(); e.returnValue = ''; }
	});

	api('bootstrap').then(function (boot) {
		S.boot = boot;
		buildShell();
		window.addEventListener('hashchange', render);
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
