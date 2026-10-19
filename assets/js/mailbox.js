/* Lindenweg16 – Mailbox: Abruf, Vorschau, Verbindungstest. Ohne Abhängigkeiten. */
( function () {
	'use strict';

	var cfg = window.LW16Mailbox || {};
	var i18n = cfg.i18n || {};

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	/* Bestätigung bei Links mit data-confirm (leer = ohne Rückfrage). */
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-confirm]' );
		if ( el && el.getAttribute( 'data-confirm' ) && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	/* ---------- Postfach abrufen ---------- */
	var root = document.querySelector( '.lw16-mb' );
	if ( root ) {
		var account = root.getAttribute( 'data-account' );
		var btn = root.querySelector( '.lw16-mb-sync-btn' );
		var status = root.querySelector( '.lw16-mb-sync-status' );
		var running = false;
		var composing = !! root.querySelector( '.lw16-mb-compose-form' );

		var sync = function ( manual ) {
			if ( running || ! btn ) {
				return;
			}
			running = true;
			btn.disabled = true;
			if ( status ) {
				status.textContent = i18n.syncing;
			}

			var total = 0;
			var step = function () {
				post( 'lw16_mail_sync', { account: account } ).then( function ( res ) {
					if ( ! res.success ) {
						throw new Error( res.data && res.data.message ? res.data.message : '?' );
					}
					total += res.data.new + res.data.removed;
					// Noch mehr offen (erster Abruf): weiter, solange die Seite offen ist.
					if ( res.data.more && ! res.data.skipped ) {
						if ( status ) {
							status.textContent = i18n.syncing + ' (' + total + ')';
						}
						return step();
					}
					running = false;
					btn.disabled = false;
					if ( total > 0 && ! composing ) {
						if ( status ) {
							status.innerHTML = '';
							var a = document.createElement( 'a' );
							a.href = window.location.href;
							a.textContent = total + ' ' + i18n.newMails;
							status.appendChild( a );
						}
					} else if ( status ) {
						status.textContent = i18n.synced;
					}
				} ).catch( function ( err ) {
					running = false;
					btn.disabled = false;
					if ( status ) {
						status.innerHTML = '';
						var span = document.createElement( 'span' );
						span.className = 'lw16-mb-error';
						span.textContent = i18n.error + ' ' + err.message;
						status.appendChild( span );
					}
				} );
			};
			step();
			return manual;
		};

		if ( btn ) {
			btn.addEventListener( 'click', function () {
				sync( true );
			} );

			// Beim Öffnen und danach alle paar Minuten, solange die Seite offen ist.
			sync( false );
			window.setInterval( function () {
				if ( ! document.hidden ) {
					sync( false );
				}
			}, cfg.syncMs || 120000 );
		}

		/* ---------- Schreiben ---------- */
		var form = root.querySelector( '.lw16-mb-compose-form' );
		if ( form ) {
			// Antwort: Schreibmarke an den Anfang, vor das Zitat.
			var bodyField = form.querySelector( '#lw16-mb-body' );
			if ( bodyField && /^\s*\n/.test( bodyField.value ) && form.querySelector( '[name="reply_to_id"]' ).value !== '0' ) {
				bodyField.focus();
				bodyField.setSelectionRange( 0, 0 );
			}

			var showcc = form.querySelector( '.lw16-mb-showcc' );
			if ( showcc ) {
				showcc.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					form.querySelectorAll( '.lw16-mb-cc' ).forEach( function ( tr ) {
						tr.hidden = ! tr.hidden;
					} );
				} );
			}

			form.addEventListener( 'submit', function ( e ) {
				var send = form.querySelector( '.lw16-mb-send' );
				// Doppelklick verhindern: sonst geht die Mail zweimal raus.
				if ( send && ( ! e.submitter || e.submitter === send ) ) {
					send.disabled = true;
					send.textContent = i18n.sending;
				}
			} );

			var pbtn = form.querySelector( '.lw16-mb-preview-btn' );
			var box = root.querySelector( '.lw16-mb-preview' );
			if ( pbtn && box ) {
				var frame = box.querySelector( '.lw16-mb-preview-frame' );
				pbtn.addEventListener( 'click', function () {
					var from = form.querySelector( '[name="account"]' );
					box.hidden = false;
					frame.srcdoc = '<p style="font:14px sans-serif;padding:20px">' + ( i18n.preview || '' ) + '</p>';
					post( 'lw16_mail_preview', {
						account: from ? from.value : account,
						subject: form.querySelector( '[name="subject"]' ).value,
						body: form.querySelector( '[name="body"]' ).value
					} ).then( function ( res ) {
						frame.srcdoc = res.success ? res.data.html : '<p style="padding:20px">' + ( res.data && res.data.message ? res.data.message : 'Fehler' ) + '</p>';
					} );
				} );
				box.querySelector( '.lw16-mb-preview-close' ).addEventListener( 'click', function () {
					box.hidden = true;
				} );
			}
		}
	}

	/* ---------- Verwaltung: Verbindungstest ---------- */
	var accForm = document.querySelector( '.lw16-mb-account-form' );
	if ( accForm ) {
		var kind = accForm.querySelector( '#lw16-mb-kind' );
		var ownerRow = accForm.querySelector( '[name="owner_user_id"]' );
		var toggleOwner = function () {
			if ( ownerRow ) {
				ownerRow.closest( 'tr' ).hidden = kind.value !== 'personal';
			}
		};
		if ( kind ) {
			kind.addEventListener( 'change', toggleOwner );
			toggleOwner();
		}

		var tbtn = accForm.querySelector( '.lw16-mb-test-btn' );
		var out = accForm.querySelector( '.lw16-mb-test-result' );
		if ( tbtn ) {
			tbtn.addEventListener( 'click', function () {
				var f = function ( n ) {
					var el = accForm.querySelector( '[name="' + n + '"]' );
					return el ? el.value : '';
				};
				tbtn.disabled = true;
				out.textContent = '…';
				post( 'lw16_mail_account_test', {
					id: accForm.getAttribute( 'data-account' ),
					email: f( 'email' ), username: f( 'username' ), password: f( 'password' ),
					imap_host: f( 'imap_host' ), imap_port: f( 'imap_port' ), imap_secure: f( 'imap_secure' ),
					smtp_host: f( 'smtp_host' ), smtp_port: f( 'smtp_port' ), smtp_secure: f( 'smtp_secure' )
				} ).then( function ( res ) {
					tbtn.disabled = false;
					out.innerHTML = '';
					if ( ! res.success ) {
						out.textContent = res.data && res.data.message ? res.data.message : 'Fehler';
						return;
					}
					[ 'imap', 'smtp' ].forEach( function ( k ) {
						var d = document.createElement( 'div' );
						d.className = res.data[ k ].ok ? 'ok' : 'fail';
						d.textContent = ( res.data[ k ].ok ? '✔ ' : '✖ ' ) + res.data[ k ].message;
						out.appendChild( d );
					} );
				} ).catch( function ( err ) {
					tbtn.disabled = false;
					out.textContent = err.message;
				} );
			} );
		}
	}
}() );
