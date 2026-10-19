/*
 * ERP-Seite „Mailbox“ (native Fassung).
 *
 * Postfachwahl, Ordner, Liste mit Suche und Filtern, Mail lesen, Anhänge,
 * Markieren, Notizen, Zuweisen, Antworten/Weiterleiten/Schreiben (ohne Anhänge),
 * Entwürfe. Die Rechte je Postfach prüft der Server bei jeder Anfrage.
 *
 * Fremde Mail-Inhalte sind nicht vertrauenswürdig:
 *  - Klartext und alle Felder gelangen nur über textContent/DOM-Knoten in die Seite,
 *    nie über innerHTML; Links entstehen nur aus http(s)-Adressen.
 *  - HTML-Mails erscheinen ausschließlich im abgeschotteten iframe (sandbox ohne
 *    Skripte, vom Server bereinigt, mit eigener Content-Security-Policy).
 *  - Fremde Bilder werden erst nach ausdrücklichem Klick geladen.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var SYNC_MS = 120000;

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	// Kurz: Uhrzeit heute, sonst Tag und Monat (mit Jahr, wenn nicht dieses Jahr).
	function shortDate(ts) {
		if (!ts) { return ''; }
		var d = new Date(ts * 1000);
		var now = new Date();
		if (d.toDateString() === now.toDateString()) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
		var s = pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.';
		return d.getFullYear() === now.getFullYear() ? s : s + d.getFullYear();
	}

	function longDate(ts) {
		if (!ts) { return ''; }
		var d = new Date(ts * 1000);
		return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	// Klartext in DOM-Knoten: Zeilenumbrüche, anklickbare http(s)-Links, Zitate (> …) abgesetzt.
	function plainText(h, text) {
		var box = h('div', { class: 'pf-plain' });
		var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
		var quote = null;

		function linkLine(parent, line) {
			var parts = line.split(/(https?:\/\/[^\s<>"']+)/i);
			parts.forEach(function (part, i) {
				if (i % 2 === 1) {
					parent.appendChild(h('a', { href: part, target: '_blank', rel: 'noopener noreferrer nofollow' }, part));
				} else if (part) {
					parent.appendChild(document.createTextNode(part));
				}
			});
		}

		lines.forEach(function (line) {
			var m = line.match(/^\s*>+ ?(.*)$/);
			if (m) {
				if (!quote) { quote = h('blockquote', { class: 'pf-quote' }); box.appendChild(quote); }
				linkLine(quote, m[1]);
				quote.appendChild(document.createTextNode('\n'));
				return;
			}
			quote = null;
			linkLine(box, line);
			box.appendChild(document.createTextNode('\n'));
		});

		return box;
	}

	UI.pages['mailbox'] = function (item, ui) {
		var h = ui.h;
		var view = ui.view();
		var right = [];

		if (ui.can('verwaltung.mailbox_konten')) {
			right.push(h('a', { class: 'btn', href: '#/seite/mailbox-konten' }, 'Postfächer verwalten'));
		}
		right.push(ui.classicBtn(item));
		ui.pageHead('Mailbox', 'Nachrichten lesen und beantworten.', right);
		view.classList.add('broad');

		var st = {
			accounts: [],
			account: 0,
			folders: [],
			folder: '',
			filter: 'all',
			search: '',
			paged: 1,
			list: null,
			mail: null,
			openId: 0,
			compose: null,
			syncing: false,
			syncText: '',
			canSend: false
		};

		var root = h('div', { class: 'pf' });
		view.appendChild(root);

		function alive() { return document.body.contains(root); }

		function fail(e) { ui.toast(e && e.message ? e.message : 'Das hat nicht geklappt.', true); }

		function currentAccount() {
			return st.accounts.filter(function (a) { return a.id === st.account; })[0] || null;
		}

		function folderRow(name) {
			return st.folders.filter(function (f) { return f.name === name; })[0] || null;
		}

		/* ---------- Laden ---------- */

		function loadOverview(account) {
			return ui.api('postfach' + (account ? '?account=' + account : '')).then(function (d) {
				if (!alive()) { return; }
				st.accounts = d.accounts;
				if (!d.accounts.length) { st.account = 0; paint(d); return; }
				var changed = st.account !== d.account;
				st.account = d.account;
				st.folders = d.folders;
				if (changed || !folderRow(st.folder)) { st.folder = d.inbox || (d.folders[0] && d.folders[0].name) || ''; }
				st.noAccounts = null;
				paint(d);
			});
		}

		function loadList() {
			if (!st.account || !st.folder) { return Promise.resolve(); }
			var q = 'postfach/liste?account=' + st.account + '&folder=' + encodeURIComponent(st.folder) +
				'&filter=' + st.filter + '&paged=' + st.paged + (st.search ? '&search=' + encodeURIComponent(st.search) : '');
			return ui.api(q).then(function (d) {
				if (!alive()) { return; }
				st.list = d;
				st.canSend = d.can_send;
				st.folders = d.counts;
				paint();
			});
		}

		function refreshAll() {
			return loadOverview(st.account).then(loadList).catch(fail);
		}

		/* ---------- Abruf ---------- */

		function sync() {
			var acc = currentAccount();
			if (!acc || !acc.active || st.syncing) { return; }
			st.syncing = true;
			st.syncText = 'Rufe Mails ab …';
			paintBar();
			var total = 0;
			var rounds = 0;

			function step() {
				return ui.api('postfach/abrufen', { method: 'POST', body: { account: acc.id } }).then(function (r) {
					total += r.new + r.removed;
					rounds++;
					// Noch mehr offen (erster Abruf): weiter, solange die Seite offen ist.
					if (r.more && !r.skipped && rounds < 25 && alive()) {
						st.syncText = 'Rufe Mails ab … (' + total + ')';
						paintBar();
						return step();
					}
				});
			}

			step().then(function () {
				st.syncing = false;
				st.syncText = total > 0 ? total + ' Änderung' + (total === 1 ? '' : 'en') + ' abgerufen.' : 'Aktuell.';
				if (!alive()) { return; }
				if (total > 0 && !st.compose) { return refreshAll(); }
				paintBar();
			}).catch(function (e) {
				st.syncing = false;
				st.syncText = '';
				paintBar();
				fail(e);
			});
		}

		/* ---------- Aktionen an Mails ---------- */

		function act(id, body, done) {
			body = body || {};
			return ui.api('postfach/mail/' + id + '/aktion', { method: 'POST', body: body }).then(function (r) {
				ui.toast(r.message);
				if (done) { done(r); }
				return r;
			}).catch(fail);
		}

		function openMail(id, images) {
			st.openId = id;
			st.compose = null;
			if (!images) { st.mail = null; }
			paint();
			ui.api('postfach/mail/' + id + (images ? '?images=1' : '')).then(function (m) {
				if (!alive() || st.openId !== id) { return; }
				if (m.draft) { openCompose('draft', m.id); return; }
				st.mail = m;
				// Lokal als gelesen zeigen; die Zähler holen wir still nach.
				if (st.list) { st.list.rows.forEach(function (r) { if (r.id === id) { r.unread = false; } }); }
				paint();
				if (!images) { loadOverview(st.account).catch(function () {}); }
			}).catch(function (e) {
				st.openId = 0;
				st.mail = null;
				paint();
				fail(e);
			});
		}

		function closeReader() {
			st.openId = 0;
			st.mail = null;
			st.compose = null;
			paint();
		}

		function openCompose(mode, ref) {
			var acc = currentAccount();
			if (!acc) { return; }
			st.openId = 0;
			st.mail = null;
			st.compose = { loading: true };
			paint();
			ui.api('postfach/schreiben?account=' + acc.id + '&mode=' + mode + (ref ? '&ref=' + ref : '')).then(function (d) {
				if (!alive()) { return; }
				st.compose = { values: d.values, senders: d.senders, account: d.account, signature: d.signature, mode: mode, busy: false };
				paint();
			}).catch(function (e) {
				st.compose = null;
				paint();
				fail(e);
			});
		}

		/* ---------- Darstellung ---------- */

		function paint(d) {
			root.textContent = '';

			if (d && !d.accounts.length) {
				root.appendChild(h('div', { class: 'card' },
					h('p', { style: 'margin:0 0 8px' }, 'Dir ist noch kein Postfach zugewiesen.'),
					h('p', { class: 'muted', style: 'margin:0' }, d.manager ? 'Lege unter „Postfächer verwalten“ ein Postfach an.' : 'Bitte wende dich an die Administration.')
				));
				return;
			}
			if (!st.account) { root.appendChild(h('div', { class: 'card' }, 'Wird geladen …')); return; }

			var reading = !!(st.openId || st.compose);
			root.className = 'pf' + (reading ? ' reading' : '');

			root.appendChild(h('div', { class: 'pf-bar', id: 'pf-bar' }));
			root.appendChild(folderChips());

			var main = h('div', { class: 'pf-main' });
			main.appendChild(listPane());
			main.appendChild(readerPane());
			root.appendChild(main);
			paintBar();
		}

		function paintBar() {
			var bar = root.querySelector('#pf-bar');
			if (!bar) { return; }
			bar.textContent = '';
			var acc = currentAccount();
			if (!acc) { return; }

			if (st.accounts.length > 1) {
				var sel = h('select', { class: 'field pf-account', 'aria-label': 'Postfach' });
				st.accounts.forEach(function (a) {
					sel.appendChild(h('option', { value: String(a.id), selected: a.id === st.account }, a.label + (a.unread ? ' (' + a.unread + ')' : '')));
				});
				sel.addEventListener('change', function () {
					st.account = parseInt(sel.value, 10);
					st.folder = '';
					st.paged = 1;
					st.search = '';
					st.filter = 'all';
					st.list = null;
					closeReader();
					loadOverview(st.account).then(loadList).then(sync).catch(fail);
				});
				bar.appendChild(sel);
			} else {
				bar.appendChild(h('div', { class: 'pf-acc' }, h('b', null, acc.label), h('small', { class: 'muted' }, acc.email)));
			}

			var tools = h('div', { class: 'pf-tools' });
			if (acc.can_send) {
				tools.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { openCompose('new', 0); } }, 'Neue Mail'));
			}
			if (acc.active) {
				tools.appendChild(h('button', { class: 'btn', type: 'button', disabled: st.syncing, onclick: sync }, ui.ico('refresh'), st.syncing ? 'Abruf läuft …' : 'Jetzt abrufen'));
			}
			bar.appendChild(tools);

			var status;
			if (st.syncText) { status = st.syncText; }
			else if (!acc.active) { status = 'Postfach deaktiviert.'; }
			else if (acc.last_error) { status = acc.last_error; }
			else if (acc.last_sync) { status = 'Zuletzt abgerufen: ' + longDate(acc.last_sync); }
			else { status = 'Noch nicht abgerufen.'; }
			bar.appendChild(h('small', { class: 'pf-status' + (acc.last_error && !st.syncText ? ' err' : ''), 'aria-live': 'polite' }, status));
		}

		function folderChips() {
			var wrap = h('div', { class: 'pf-folders', role: 'tablist', 'aria-label': 'Ordner' });
			st.folders.forEach(function (f) {
				var n = f.unread ? f.unread : (f.role === 'drafts' ? f.total : 0);
				wrap.appendChild(h('button', {
					class: 'chip pf-folder', type: 'button', role: 'tab', 'aria-pressed': f.name === st.folder ? 'true' : 'false',
					onclick: function () { st.folder = f.name; st.paged = 1; st.list = null; closeReader(); loadList().catch(fail); }
				}, f.label, n ? h('span', { class: 'pf-count' + (f.unread ? '' : ' quiet') }, String(n)) : null));
			});
			return wrap;
		}

		function listPane() {
			var pane = h('section', { class: 'pf-list card', 'aria-label': 'Nachrichten' });

			var input = h('input', { class: 'field', type: 'search', placeholder: 'Suchen …', value: st.search, 'aria-label': 'Mails durchsuchen' });
			pane.appendChild(h('form', {
				class: 'pf-search', onsubmit: function (e) { e.preventDefault(); st.search = input.value.trim(); st.paged = 1; loadList().catch(fail); }
			}, input));

			var filters = h('div', { class: 'pf-filters' });
			[['all', 'Alle'], ['unread', 'Ungelesen'], ['starred', 'Markiert'], ['mine', 'Mir zugewiesen']].forEach(function (f) {
				filters.appendChild(h('button', {
					class: 'chip', type: 'button', 'aria-pressed': st.filter === f[0] ? 'true' : 'false',
					onclick: function () { st.filter = f[0]; st.paged = 1; loadList().catch(fail); }
				}, f[1]));
			});
			pane.appendChild(filters);

			if (!st.list) { pane.appendChild(h('p', { class: 'muted' }, 'Wird geladen …')); return pane; }
			if (!st.list.rows.length) {
				pane.appendChild(h('p', { class: 'muted pf-none' }, st.search ? 'Nichts gefunden.' : 'Keine Mails.'));
				return pane;
			}

			var ul = h('ul', { class: 'pf-rows' });
			st.list.rows.forEach(function (r) {
				var li = h('li', { class: 'pf-row' + (r.unread ? ' unread' : '') + (r.id === st.openId ? ' current' : '') });
				if (!r.draft) {
					li.appendChild(h('button', {
						class: 'pf-star' + (r.starred ? ' on' : ''), type: 'button', 'aria-label': r.starred ? 'Markierung entfernen' : 'Markieren', 'aria-pressed': r.starred ? 'true' : 'false',
						onclick: function () {
							act(r.id, { do: r.starred ? 'unstar' : 'star' }, function () { r.starred = !r.starred; paint(); });
						}
					}, r.starred ? '★' : '☆'));
				} else {
					li.appendChild(h('span', { class: 'pf-star' }, ''));
				}
				li.appendChild(h('button', {
					class: 'pf-open', type: 'button',
					onclick: function () { if (r.draft) { openCompose('draft', r.id); } else { openMail(r.id, false); } }
				},
					h('span', { class: 'pf-top' }, h('span', { class: 'pf-who' }, r.who), h('span', { class: 'pf-date' }, shortDate(r.ts))),
					h('span', { class: 'pf-subject' }, r.subject, r.attach ? h('span', { class: 'pf-flag', title: 'Anhang' }, ' 📎') : null, r.answered ? h('span', { class: 'pf-flag', title: 'Beantwortet' }, ' ↩') : null),
					h('span', { class: 'pf-snippet' }, r.snippet)
				));
				ul.appendChild(li);
			});
			pane.appendChild(ul);

			if (st.list.pages > 1) {
				pane.appendChild(h('div', { class: 'pf-pager' },
					h('button', { class: 'btn', type: 'button', disabled: st.paged <= 1, onclick: function () { st.paged--; loadList().catch(fail); } }, '‹ Neuere'),
					h('small', { class: 'muted' }, 'Seite ' + st.list.paged + ' von ' + st.list.pages),
					h('button', { class: 'btn', type: 'button', disabled: st.paged >= st.list.pages, onclick: function () { st.paged++; loadList().catch(fail); } }, 'Ältere ›')
				));
			}

			return pane;
		}

		function readerPane() {
			var pane = h('section', { class: 'pf-pane card', 'aria-label': 'Lesefenster' });

			if (st.compose) { pane.appendChild(composeForm()); return pane; }
			if (st.openId && !st.mail) { pane.appendChild(backBtn()); pane.appendChild(h('p', { class: 'muted' }, 'Wird geladen …')); return pane; }
			if (st.mail) { pane.appendChild(messageView(st.mail)); return pane; }

			pane.classList.add('empty');
			pane.appendChild(h('div', { class: 'pf-empty' }, ui.ico('mail'), h('p', null, 'Wähle links eine Mail aus.')));
			return pane;
		}

		function backBtn() {
			return h('button', { class: 'btn pf-back', type: 'button', onclick: closeReader }, '‹ Zurück zur Liste');
		}

		function messageView(m) {
			var box = h('article', { class: 'pf-message' });
			box.appendChild(backBtn());
			box.appendChild(h('h3', { class: 'pf-title' }, m.subject));

			var tb = h('div', { class: 'pf-toolbar' });
			if (m.can_send) {
				tb.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () { openCompose('reply', m.id); } }, 'Antworten'));
				tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { openCompose('replyall', m.id); } }, 'Allen antworten'));
				tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { openCompose('forward', m.id); } }, 'Weiterleiten'));
			}
			tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
				act(m.id, { do: 'unread' }, function () {
					if (st.list) { st.list.rows.forEach(function (r) { if (r.id === m.id) { r.unread = true; } }); }
					loadOverview(st.account).catch(function () {});
					closeReader();
				});
			} }, 'Ungelesen'));
			tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
				act(m.id, { do: m.starred ? 'unstar' : 'star' }, function () {
					m.starred = !m.starred;
					if (st.list) { st.list.rows.forEach(function (r) { if (r.id === m.id) { r.starred = m.starred; } }); }
					paint();
				});
			} }, m.starred ? 'Markierung entfernen' : 'Markieren'));

			if (m.can_send) {
				var gone = function () { closeReader(); refreshAll(); };
				tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { act(m.id, { do: m.role === 'spam' ? 'notspam' : 'spam' }, gone); } }, m.role === 'spam' ? 'Kein Spam' : 'Spam'));
				tb.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					if (m.role === 'trash' && !window.confirm('Diese Mail endgültig löschen? Das lässt sich nicht rückgängig machen.')) { return; }
					act(m.id, { do: 'trash' }, gone);
				} }, m.role === 'trash' ? 'Endgültig löschen' : 'Löschen'));

				if (m.targets.length) {
					var dest = h('select', { class: 'field', 'aria-label': 'Verschieben nach' }, h('option', { value: '' }, 'Verschieben nach …'));
					m.targets.forEach(function (t) { dest.appendChild(h('option', { value: t.name }, t.label)); });
					dest.addEventListener('change', function () {
						if (dest.value) { act(m.id, { do: 'move', dest: dest.value }, gone); }
					});
					tb.appendChild(dest);
				}
			}
			box.appendChild(tb);

			var kv = h('dl', { class: 'pf-head' },
				h('dt', null, 'Von'), h('dd', null, m.from),
				h('dt', null, 'An'), h('dd', null, m.to));
			if (m.cc) { kv.appendChild(h('dt', null, 'Cc')); kv.appendChild(h('dd', null, m.cc)); }
			kv.appendChild(h('dt', null, 'Datum'));
			kv.appendChild(h('dd', null, longDate(m.ts)));
			box.appendChild(kv);

			box.appendChild(messageBody(m));

			if (m.attachments.length) {
				var ul = h('ul', { class: 'pf-files' });
				m.attachments.forEach(function (a) {
					ul.appendChild(h('li', null, h('a', { class: 'btn', href: a.url, rel: 'noopener' }, ui.ico('download'), a.name, ' ', h('small', { class: 'muted' }, '(' + a.size + ')'))));
				});
				box.appendChild(h('div', { class: 'pf-block' }, h('b', null, 'Anhänge'), ul));
			}

			box.appendChild(metaBlocks(m));
			return box;
		}

		function messageBody(m) {
			var b = m.body;
			if (b.kind === 'text') { return plainText(h, b.text); }

			var wrap = h('div', { class: 'pf-html' });
			if (b.remote && !b.images) {
				wrap.appendChild(h('div', { class: 'notice warn pf-imgnote' },
					'Externe Bilder wurden blockiert, damit der Absender nicht sieht, dass du die Mail gelesen hast. ',
					h('button', { class: 'btn', type: 'button', onclick: function () { openMail(m.id, true); } }, 'Bilder laden')
				));
			}
			// Abgeschotteter Rahmen: sandbox ohne Skripte und ohne same-origin, Inhalt über srcdoc.
			var frame = h('iframe', {
				class: 'pf-frame', title: 'Inhalt der Mail', sandbox: 'allow-popups allow-popups-to-escape-sandbox', referrerpolicy: 'no-referrer'
			});
			frame.srcdoc = b.frame;
			wrap.appendChild(frame);
			return wrap;
		}

		function metaBlocks(m) {
			var meta = h('div', { class: 'pf-meta' });

			if (m.users.length) {
				var sel = h('select', { class: 'field', 'aria-label': 'Zugewiesen an' }, h('option', { value: '0' }, '– niemand –'));
				m.users.forEach(function (u) { sel.appendChild(h('option', { value: String(u.id), selected: u.id === m.assigned }, u.name)); });
				meta.appendChild(h('div', { class: 'pf-block pf-assign' }, h('b', null, 'Zugewiesen an'), sel,
					h('button', { class: 'btn', type: 'button', onclick: function () {
						act(m.id, { do: 'assign', assign_to: parseInt(sel.value, 10) }, function () { m.assigned = parseInt(sel.value, 10); });
					} }, 'Speichern')));
			}

			if (m.customer && m.customer.length) {
				var cu = h('ul', { class: 'pf-lines' });
				m.customer.forEach(function (c) {
					cu.appendChild(h('li', null, c.url ? h('a', { href: c.url, target: '_blank', rel: 'noopener' }, c.text) : c.text));
				});
				meta.appendChild(h('div', { class: 'pf-block' }, h('b', null, 'Im Shop'), cu));
			}

			if (m.thread.length) {
				var tl = h('ul', { class: 'pf-lines' });
				m.thread.forEach(function (t) {
					tl.appendChild(h('li', null,
						h('button', { class: 'pf-link', type: 'button', onclick: function () { openMail(t.id, false); } }, longDate(t.ts) + ' · ' + t.who),
						h('small', { class: 'muted' }, ' – ' + t.snippet)));
				});
				meta.appendChild(h('div', { class: 'pf-block' }, h('b', null, 'Verlauf'), tl));
			}

			var notes = h('div', { class: 'pf-notes' });
			function paintNotes(list) {
				notes.textContent = '';
				list.forEach(function (n) {
					notes.appendChild(h('div', { class: 'pf-note' }, h('small', { class: 'muted' }, n.who + ' · ' + longDate(n.ts)), h('div', { style: 'white-space:pre-wrap' }, n.text)));
				});
			}
			paintNotes(m.notes);
			var ta = h('textarea', { class: 'field', rows: '2', placeholder: 'Notiz für das Team …', 'aria-label': 'Neue Notiz' });
			var save = h('button', { class: 'btn', type: 'button', onclick: function () {
				if (!ta.value.trim()) { ui.toast('Bitte zuerst die Notiz schreiben.', true); return; }
				save.disabled = true;
				ui.api('postfach/mail/' + m.id + '/notiz', { method: 'POST', body: { note: ta.value } }).then(function (r) {
					m.notes = r.notes;
					paintNotes(r.notes);
					ta.value = '';
					ui.toast('Notiz gespeichert.');
				}).catch(fail).then(function () { save.disabled = false; });
			} }, 'Notiz speichern');
			meta.appendChild(h('div', { class: 'pf-block' }, h('b', null, 'Interne Notizen'), ' ', h('small', { class: 'muted' }, '(nur im ERP sichtbar)'), notes, ta, save));

			return meta;
		}

		/* ---------- Schreiben ---------- */

		function composeForm() {
			var c = st.compose;
			if (c.loading) { return h('div', null, backBtn(), h('p', { class: 'muted' }, 'Wird geladen …')); }

			var v = c.values;
			var title = c.mode === 'forward' ? 'Weiterleiten' : (c.mode === 'reply' || c.mode === 'replyall' ? 'Antworten' : (c.mode === 'draft' ? 'Entwurf' : 'Neue Mail'));
			var form = h('form', { class: 'pf-compose', novalidate: true });
			form.appendChild(backBtn());
			form.appendChild(h('h3', { class: 'pf-title' }, title));

			var from;
			if (c.senders.length > 1) {
				from = h('select', { class: 'field', id: 'pf-from' });
				c.senders.forEach(function (s) { from.appendChild(h('option', { value: String(s.id), selected: s.id === c.account }, s.label + ' <' + s.email + '>')); });
			} else {
				from = h('input', { class: 'field', id: 'pf-from', value: c.senders[0] ? c.senders[0].label + ' <' + c.senders[0].email + '>' : '', disabled: true });
			}

			function row(label, id, control) { return h('div', { class: 'pf-field' }, h('label', { for: id }, label), control); }

			var to = h('input', { class: 'field', id: 'pf-to', type: 'text', value: v.to, placeholder: 'name@example.de, …', autocomplete: 'off' });
			var cc = h('input', { class: 'field', id: 'pf-cc', type: 'text', value: v.cc });
			var bcc = h('input', { class: 'field', id: 'pf-bcc', type: 'text', value: v.bcc });
			var subject = h('input', { class: 'field', id: 'pf-subject', type: 'text', value: v.subject });
			var body = h('textarea', { class: 'field pf-body', id: 'pf-body', rows: '14', placeholder: 'Deine Nachricht …', 'aria-label': 'Nachricht' });
			body.value = v.body;

			var ccRows = h('div', { class: 'pf-ccrows' }, row('Cc', 'pf-cc', cc), row('Bcc', 'pf-bcc', bcc));
			ccRows.hidden = !(v.cc || v.bcc);
			var ccToggle = h('button', { class: 'pf-link', type: 'button', onclick: function () { ccRows.hidden = !ccRows.hidden; } }, 'Cc/Bcc');

			form.appendChild(row('Von', 'pf-from', from));
			form.appendChild(row('An', 'pf-to', h('div', { class: 'pf-to' }, to, ccToggle)));
			form.appendChild(ccRows);
			form.appendChild(row('Betreff', 'pf-subject', subject));
			form.appendChild(body);
			form.appendChild(h('p', { class: 'muted pf-hint' }, 'Die Mail geht im Layout der Shop-Mails raus (Logo, Farben, Fußzeile). Ein Link allein in einer Zeile wird zum Button. Anhänge verschickst du in der klassischen Mailbox.'));
			if (c.signature) {
				form.appendChild(h('div', { class: 'pf-sig' }, h('small', { class: 'muted' }, 'Signatur (wird automatisch unter deinen Text gesetzt):'), h('pre', null, c.signature)));
			}

			function collect(save) {
				return {
					account: c.senders.length > 1 ? parseInt(from.value, 10) : c.account,
					to: to.value, cc: cc.value, bcc: bcc.value, subject: subject.value, body: body.value,
					reply_to_id: v.reply_to_id, draft_id: v.draft_id, save_draft: save ? 1 : 0
				};
			}

			var send = h('button', { class: 'btn primary', type: 'submit' }, 'Senden');
			var draft = h('button', { class: 'btn', type: 'button' }, 'Als Entwurf speichern');
			var buttons = [send, draft];

			function busy(on) { buttons.forEach(function (b) { b.disabled = on; }); }

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				busy(true);
				ui.api('postfach/senden', { method: 'POST', body: collect(false) }).then(function (r) {
					ui.toast(r.message, r.warnings);
					st.compose = null;
					if (r.sent) { st.folder = r.sent; st.paged = 1; }
					st.list = null;
					refreshAll();
				}).catch(function (err) {
					// Der Server hat den Text als Entwurf gesichert (steht in der Meldung).
					busy(false);
					fail(err);
				});
			});

			draft.addEventListener('click', function () {
				busy(true);
				ui.api('postfach/senden', { method: 'POST', body: collect(true) }).then(function (r) {
					v.draft_id = r.draft_id;
					ui.toast(r.message);
					busy(false);
				}).catch(function (err) { busy(false); fail(err); });
			});

			var bar = h('div', { class: 'pf-actions' }, send, draft);
			if (v.draft_id) {
				bar.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () {
					if (!window.confirm('Entwurf verwerfen?')) { return; }
					act(v.draft_id, { do: 'trash' }, function () { closeReader(); refreshAll(); });
				} }, 'Entwurf verwerfen'));
			}
			form.appendChild(bar);
			return form;
		}

		/* ---------- Start ---------- */

		loadOverview(0).then(loadList).then(sync).catch(function (e) {
			root.textContent = '';
			root.appendChild(h('div', { class: 'notice warn' }, e.message));
		});

		// Regelmäßig abrufen, solange die Seite offen ist (endet beim Seitenwechsel).
		ui.every(function () { if (alive() && !st.compose) { sync(); } }, SYNC_MS);
	};
})();
