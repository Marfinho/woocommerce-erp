/*
 * Service Worker der ERP-Oberfläche.
 *
 * Zweck: Die Oberfläche öffnet sich auch ohne Verbindung (Werkstatt-WLAN
 * bricht ab). Er hält nur die Hülle bereit – Seitengerüst, Skript, Stil und
 * Kamera-Bibliothek. Daten, Anmeldung und Schnittstelle gehen immer ans
 * Netz; was offline erfasst wird, hält die Anwendung selbst in einer
 * Warteschlange (erp.js).
 *
 * __VERSION__ wird beim Ausliefern durch die Plugin-Version ersetzt, damit
 * ein Update den alten Zwischenspeicher verwirft.
 */
var VERSION = '__VERSION__';
var CACHE = 'lw16-erp-' + VERSION;
var SCOPE = new URL(self.registration.scope).pathname;

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (keys) {
			return Promise.all(keys.filter(function (k) { return k.indexOf('lw16-erp-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
		}).then(function () { return self.clients.claim(); })
	);
});

function isShellAsset(url) {
	return /\/assets\/(erp\/(erp\.(js|css)|pages\/[a-z0-9_-]+\.js)|vendor\/jsqr\/jsQR\.js)$/.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
	var req = event.request;
	if (req.method !== 'GET') { return; }

	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }

	// Das Seitengerüst: zuerst das Netz (frische Anmeldung und Prüfzahl),
	// ohne Verbindung die zuletzt gesehene Fassung.
	if (req.mode === 'navigate' && url.pathname.indexOf(SCOPE) === 0 && url.pathname.indexOf('/wp-') < 0) {
		event.respondWith(
			fetch(req).then(function (res) {
				if (res.ok && res.headers.get('content-type') && res.headers.get('content-type').indexOf('text/html') > -1) {
					var copy = res.clone();
					caches.open(CACHE).then(function (c) { c.put(SCOPE, copy); });
				}
				return res;
			}).catch(function () {
				return caches.match(SCOPE).then(function (hit) {
					return hit || new Response('<!doctype html><meta charset="utf-8"><title>Offline</title><p style="font:16px system-ui;margin:20vh auto;max-width:28rem">Keine Verbindung, und das ERP wurde auf diesem Gerät noch nie geöffnet.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
				});
			})
		);
		return;
	}

	// Skript, Stil, Kamera-Bibliothek: sofort aus dem Speicher, im
	// Hintergrund erneuern.
	if (isShellAsset(url)) {
		event.respondWith(
			caches.open(CACHE).then(function (cache) {
				return cache.match(req, { ignoreSearch: false }).then(function (hit) {
					var fresh = fetch(req).then(function (res) {
						if (res.ok) { cache.put(req, res.clone()); }
						return res;
					}).catch(function () { return hit; });
					return hit || fresh;
				});
			})
		);
	}
});
