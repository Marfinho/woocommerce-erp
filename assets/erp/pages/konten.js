/*
 * ERP-Seite „Postfächer“ (native Fassung): Postfächer anlegen, ändern, deaktivieren,
 * löschen, Verbindung testen, Freigaben, Mailbox-Einstellungen.
 * Serverseitig: includes/Erp/Pages/Konten.php. Klassisches ES5, kein innerHTML mit Daten.
 *
 * Passwörter: Der Server schickt nie ein Passwort, auch kein verschlüsseltes – nur den
 * Zustand „gespeichert“. Ein leeres Passwortfeld lässt den gespeicherten Wert unverändert.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var STATUS = {
		pw: ['Passwort fehlt oder nicht lesbar – bitte neu eintragen', 'bad'],
		off: ['Deaktiviert', ''],
		error: ['Fehler beim Abruf', 'bad'],
		ok: ['In Ordnung', 'good'],
		'new': ['Noch nicht abgerufen', '']
	};

	UI.pages['mailbox-konten'] = function (item, ui) {
		var h = ui.h;
		var view = ui.view();
		var data = null;
		var box = h('div', { class: 'kt' });

		var addBtn = h('button', { class: 'btn primary', type: 'button', onclick: function () { edit(null); } }, 'Postfach hinzufügen');
		addBtn.hidden = true;
		ui.pageHead('Postfächer', 'Zugangsdaten, Absender und Freigaben der Mailbox. Passwörter werden verschlüsselt gespeichert und nie angezeigt.', [addBtn, ui.classicBtn(item)]);
		view.appendChild(box);

		function notice(text, warn) { return h('div', { class: 'notice' + (warn ? ' warn' : ''), role: warn ? 'alert' : 'status' }, text); }

		function when(ts) { return ts ? ui.when(ts) : ''; }

		function load() {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
			return ui.api('konten').then(function (d) {
				data = d;
				addBtn.hidden = false;
				list();
			}).catch(function (e) { box.textContent = ''; box.appendChild(notice(e.message, true)); });
		}

		/* ------------------------------------------------------------
		 * Liste
		 * ---------------------------------------------------------- */
		function list() {
			addBtn.hidden = false;
			box.textContent = '';

			if (!data.crypto) {
				box.appendChild(notice('Auf diesem Server fehlt libsodium – ohne sie können keine Passwörter gespeichert werden. Bitte den Hoster informieren.', true));
			}

			if (!data.accounts.length) {
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Noch kein Postfach eingerichtet. Lege für jeden Mitarbeiter ein Postfach an – bei IONOS reicht dafür die E-Mail-Adresse und das Passwort des Postfachs.'),
					h('button', { class: 'btn primary', type: 'button', onclick: function () { edit(null); } }, 'Erstes Postfach hinzufügen')));
			} else {
				var rows = h('div', { class: 'rows' });
				data.accounts.forEach(function (a) { rows.appendChild(card(a)); });
				box.appendChild(rows);
			}

			box.appendChild(settingsCard());
			box.appendChild(h('div', { class: 'card kt-tips' },
				h('h3', null, 'Hinweise'),
				h('ul', null,
					h('li', null, 'Die Passwörter werden verschlüsselt gespeichert. Der Schlüssel stammt aus den WordPress-Salts in der wp-config.php – ändern sich die Salts, müssen die Passwörter neu eingetragen werden. Wer das vermeiden will, setzt in der wp-config.php eine feste Konstante LW16_MAIL_KEY.'),
					h('li', null, 'Für die Zustellung sollte die Domain bei IONOS SPF (und möglichst DKIM) eingerichtet haben; versendet wird über den IONOS-Server, nicht über den Webserver des Shops.'),
					h('li', null, 'Entwürfe liegen nur im ERP. Gesendete Mails werden zusätzlich im Gesendet-Ordner des Postfachs abgelegt und erscheinen so auch im IONOS-Webmail und am Handy.'))));
		}

		function card(a) {
			var st = STATUS[a.status] || STATUS['new'];
			var statusText = st[0];
			if (a.status === 'error') { statusText = a.last_error; }
			if (a.status === 'ok' && a.last_sync) { statusText = 'In Ordnung, zuletzt ' + when(a.last_sync); }

			var access = [];
			if (a.owner_name) { access.push(a.owner_name); }
			a.shares.forEach(function (s) { access.push(s.name + (s.can_send ? '' : ' (nur lesen)')); });

			var del = h('button', { class: 'btn', type: 'button' }, 'Löschen');
			del.addEventListener('click', function () { confirmDelete(a, del); });

			return h('article', { class: 'row-card kt-card' },
				h('div', { class: 'row-main' },
					h('b', null, a.label),
					h('div', { class: 'muted kt-mail' }, a.email),
					h('div', { class: 'row-tags' },
						h('span', { class: 'tag' }, a.kind === 'shared' ? 'Geteilt' : 'Persönlich'),
						h('span', { class: 'tag kt-st ' + st[1] }, statusText)),
					h('div', { class: 'muted kt-small' }, 'Zugriff: ' + (access.length ? access.join(', ') : '–'))),
				h('div', { class: 'kt-btns' },
					h('button', { class: 'btn', type: 'button', onclick: function () { edit(a); } }, 'Bearbeiten'),
					del));
		}

		function confirmDelete(a, btn) {
			var d = ui.dlg('Postfach löschen?', h('div', { class: 'kt-dlg' },
				h('p', null, '„' + a.label + '“ (' + a.email + ') samt aller lokal gespeicherten Mails löschen? Auf dem Mailserver bleibt alles erhalten.'),
				h('div', { class: 'kt-btns' },
					h('button', { class: 'btn', type: 'button', onclick: function () { d.close(); } }, 'Abbrechen'),
					h('button', { class: 'btn primary', type: 'button', onclick: function () {
						d.close();
						btn.disabled = true;
						ui.api('konten/' + a.id + '/loeschen', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); load(); }).catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
					} }, 'Ja, löschen'))));
		}

		/* ------------------------------------------------------------
		 * Einstellungen
		 * ---------------------------------------------------------- */
		function settingsCard() {
			var s = data.settings;
			var sync = h('input', { type: 'checkbox', id: 'kt-sync', checked: !!s.sync });
			var look = h('input', { class: 'field', type: 'number', id: 'kt-look', min: '1', max: '3650', inputmode: 'numeric', value: String(s.lookback_days) });
			var att = h('input', { class: 'field', type: 'number', id: 'kt-att', min: '1', max: '100', inputmode: 'numeric', value: String(s.max_attachment_mb) });
			var save = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');

			var form = h('form', { class: 'card kt-form' },
				h('h3', null, 'Einstellungen'),
				h('label', { class: 'kt-check', for: 'kt-sync' }, sync, h('span', null, 'Automatisch abrufen – alle fünf Minuten im Hintergrund')),
				h('label', { class: 'kt-f', for: 'kt-look' }, h('span', null, 'Wie weit zurück laden (Tage)'), look,
					h('small', { class: 'muted' }, 'Gilt beim ersten Abruf eines Ordners; ältere Mails bleiben auf dem Server.')),
				h('label', { class: 'kt-f', for: 'kt-att' }, h('span', null, 'Anhänge je Mail (MB höchstens)'), att,
					h('small', { class: 'muted' }, 'IONOS erlaubt insgesamt 50 MB je Mail.')),
				h('div', { class: 'kt-btns' }, save));

			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				save.disabled = true;
				ui.api('konten/einstellungen', { method: 'POST', body: { sync: sync.checked, lookback_days: Number(look.value), max_attachment_mb: Number(att.value) } }).then(function (r) {
					save.disabled = false;
					s.sync = sync.checked; s.lookback_days = Number(look.value); s.max_attachment_mb = Number(att.value);
					ui.toast(r.message);
				}).catch(function (e) { save.disabled = false; ui.toast(e.message, true); });
			});
			return form;
		}

		/* ------------------------------------------------------------
		 * Formular
		 * ---------------------------------------------------------- */
		function edit(a) {
			var isNew = !a;
			var d = a || {
				id: 0, label: '', kind: 'personal', owner_user_id: 0, email: '', from_name: '', username: '',
				imap_host: data.defaults.imap_host, imap_port: data.defaults.imap_port, imap_secure: data.defaults.imap_secure,
				smtp_host: data.defaults.smtp_host, smtp_port: data.defaults.smtp_port, smtp_secure: data.defaults.smtp_secure,
				signature: data.signature, active: true, password: 'missing', shares: []
			};
			addBtn.hidden = true;
			box.textContent = '';

			var f = {};
			function text(key, attrs) {
				var a2 = { class: 'field', type: 'text', id: 'kt-' + key, value: d[key] === undefined || d[key] === null ? '' : String(d[key]) };
				Object.keys(attrs || {}).forEach(function (k) { a2[k] = attrs[k]; });
				f[key] = h('input', a2);
				return f[key];
			}
			function num(key) { f[key] = h('input', { class: 'field kt-port', type: 'number', id: 'kt-' + key, min: '1', max: '65535', inputmode: 'numeric', value: String(d[key]) }); return f[key]; }
			function select(key, opts, current) {
				f[key] = h('select', { class: 'field', id: 'kt-' + key });
				opts.forEach(function (o) {
					var opt = h('option', { value: o[0] }, o[1]);
					if (o[0] === current) { opt.selected = true; }
					f[key].appendChild(opt);
				});
				return f[key];
			}
			function row(key, label, input, hint) {
				return h('label', { class: 'kt-f', for: 'kt-' + key }, h('span', null, label), input, hint ? h('small', { class: 'muted' }, hint) : null);
			}

			var owner = h('select', { class: 'field', id: 'kt-owner' }, h('option', { value: '0' }, '– niemand –'));
			data.users.forEach(function (u) {
				var o = h('option', { value: String(u.id) }, u.name);
				if (u.id === d.owner_user_id) { o.selected = true; }
				owner.appendChild(o);
			});
			f.owner = owner;

			var kind = select('kind', [['personal', 'Persönlich (gehört einem Mitarbeiter)'], ['shared', 'Geteilt (z. B. info@, support@)']], d.kind);
			var ownerRow = row('owner', 'Besitzer', owner, 'Nur bei persönlichen Postfächern: Dieser Mitarbeiter darf lesen und senden.');
			function kindChanged() { ownerRow.hidden = kind.value === 'shared'; }
			kind.addEventListener('change', kindChanged);
			kindChanged();

			// Das Passwort bleibt leer: nie vorbefüllt, nie angezeigt.
			f.password = h('input', { class: 'field', type: 'password', id: 'kt-password', autocomplete: 'new-password', autocapitalize: 'off', spellcheck: 'false', placeholder: isNew ? '' : (d.password === 'saved' ? '(gespeichert – leer lassen, um es zu behalten)' : '') });
			var pwHint = d.password === 'saved' ? 'Ein Passwort ist gespeichert. Nur ausfüllen, wenn es sich geändert hat.'
				: (d.password === 'unreadable' ? 'Das gespeicherte Passwort ist nicht mehr lesbar (z. B. nach einer Änderung der WordPress-Salts). Bitte neu eintragen.'
				: 'Das Passwort des Postfachs aus dem IONOS-Kundenbereich. Es wird verschlüsselt gespeichert.');

			var secureOpts = [['ssl', 'SSL/TLS'], ['tls', 'STARTTLS']];
			var imapSec = select('imap_secure', secureOpts, d.imap_secure);
			var smtpSec = select('smtp_secure', [['tls', 'STARTTLS'], ['ssl', 'SSL/TLS']], d.smtp_secure);

			f.signature = h('textarea', { class: 'field kt-area', id: 'kt-signature', rows: '4' });
			f.signature.value = d.signature || '';

			var active = h('input', { type: 'checkbox', id: 'kt-active', checked: !!d.active });

			// Freigaben
			var shareBox = h('div', { class: 'kt-shares' });
			var shareState = {};
			d.shares.forEach(function (s) { shareState[s.user_id] = s.can_send; });
			data.users.forEach(function (u) {
				var on = h('input', { type: 'checkbox', id: 'kt-sh-' + u.id, checked: shareState[u.id] !== undefined });
				var send = h('input', { type: 'checkbox', id: 'kt-se-' + u.id, checked: shareState[u.id] === undefined ? true : !!shareState[u.id] });
				on.addEventListener('change', function () { send.disabled = !on.checked; });
				send.disabled = !on.checked;
				shareBox.appendChild(h('div', { class: 'kt-share' },
					h('label', { class: 'kt-check', for: 'kt-sh-' + u.id }, on, h('span', null, u.name)),
					h('label', { class: 'kt-check kt-send', for: 'kt-se-' + u.id }, send, h('span', null, 'darf senden'))));
				u.on = on; u.send = send;
			});

			var msg = h('div', { class: 'kt-msg', 'aria-live': 'polite' });
			var testOut = h('div', { class: 'kt-test', 'aria-live': 'polite' });
			var testBtn = h('button', { class: 'btn', type: 'button' }, 'Verbindung testen');
			var saveBtn = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');
			var cancel = h('button', { class: 'btn', type: 'button', onclick: function () { load(); } }, 'Abbrechen');

			function values() {
				var shares = [];
				data.users.forEach(function (u) {
					if (u.on.checked && !(kind.value === 'personal' && Number(owner.value) === u.id)) { shares.push({ user_id: u.id, can_send: u.send.checked }); }
				});
				return {
					id: d.id,
					label: f.label.value, kind: kind.value, owner_user_id: kind.value === 'personal' ? Number(owner.value) : 0,
					email: f.email.value, from_name: f.from_name.value, username: f.username.value,
					password: f.password.value,
					imap_host: f.imap_host.value, imap_port: Number(f.imap_port.value), imap_secure: imapSec.value,
					smtp_host: f.smtp_host.value, smtp_port: Number(f.smtp_port.value), smtp_secure: smtpSec.value,
					signature: f.signature.value, active: active.checked, shares: shares
				};
			}

			function line(ok, text) { return h('div', { class: 'kt-testline ' + (ok ? 'good' : 'bad') }, h('b', null, ok ? 'OK: ' : 'Fehler: '), text); }

			testBtn.addEventListener('click', function () {
				testOut.textContent = '';
				var v = values();
				v.shares = [];
				testBtn.disabled = true;
				testBtn.textContent = 'Wird getestet …';
				ui.api('konten/test', { method: 'POST', body: v }).then(function (r) {
					testBtn.disabled = false; testBtn.textContent = 'Verbindung testen';
					testOut.appendChild(line(r.imap.ok, r.imap.message || 'Eingang nicht erreichbar.'));
					testOut.appendChild(line(r.smtp.ok, r.smtp.message || 'Ausgang nicht erreichbar.'));
					f.password.value = '';
				}).catch(function (e) {
					testBtn.disabled = false; testBtn.textContent = 'Verbindung testen';
					testOut.appendChild(line(false, e.message));
				});
			});

			var form = h('form', { class: 'card kt-form', novalidate: true },
				h('h3', null, isNew ? 'Neues Postfach' : d.label),
				row('label', 'Bezeichnung', text('label', { required: true, placeholder: 'z. B. Katrin oder Info' })),
				row('kind', 'Typ', kind),
				ownerRow,
				row('email', 'E-Mail-Adresse', text('email', { type: 'email', required: true, inputmode: 'email', autocapitalize: 'off' }), 'Absenderadresse. Bei IONOS muss sie zum Postfach gehören.'),
				row('from_name', 'Absendername', text('from_name', { placeholder: 'z. B. Katrin – Lindenweg16' })),
				row('username', 'Benutzername', text('username', { autocomplete: 'off', autocapitalize: 'off' }), 'Bei IONOS die vollständige E-Mail-Adresse. Leer lassen, um die E-Mail-Adresse zu nehmen.'),
				row('password', 'Passwort', f.password, pwHint),
				h('fieldset', { class: 'kt-set' }, h('legend', null, 'Eingang (IMAP)'),
					row('imap_host', 'Server', text('imap_host', { autocapitalize: 'off' })),
					h('div', { class: 'kt-pair' }, row('imap_port', 'Port', num('imap_port')), row('imap_secure', 'Verschlüsselung', imapSec))),
				h('fieldset', { class: 'kt-set' }, h('legend', null, 'Ausgang (SMTP)'),
					row('smtp_host', 'Server', text('smtp_host', { autocapitalize: 'off' })),
					h('div', { class: 'kt-pair' }, row('smtp_port', 'Port', num('smtp_port')), row('smtp_secure', 'Verschlüsselung', smtpSec)),
					h('small', { class: 'muted' }, 'IONOS: smtp.ionos.de, Port 587 mit STARTTLS (oder 465 mit SSL/TLS).')),
				row('signature', 'Signatur', f.signature, '{name} wird durch den Namen des Mitarbeiters ersetzt, der die Mail schreibt.'),
				h('div', { class: 'kt-f' }, h('span', null, 'Weitere Mitarbeiter mit Zugriff'), shareBox,
					h('small', { class: 'muted' }, 'Ohne „darf senden“ können sie die Mails nur lesen, markieren und notieren. Verwalter sehen immer alle Postfächer.')),
				h('label', { class: 'kt-check', for: 'kt-active' }, active, h('span', null, 'Aktiv – Postfach abrufen und nutzen (ohne Haken ist es deaktiviert)')),
				h('div', { class: 'kt-btns' }, testBtn), testOut,
				msg,
				h('div', { class: 'kt-btns' }, saveBtn, cancel));

			box.appendChild(form);

			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				msg.textContent = '';
				saveBtn.disabled = true;
				ui.api('konten/speichern', { method: 'POST', body: values() }).then(function (r) {
					f.password.value = '';
					ui.toast(r.message);
					load();
				}).catch(function (e) {
					saveBtn.disabled = false;
					msg.appendChild(notice(e.message, true));
				});
			});
		}

		load();
	};
})();
