<?php
/**
 * Testumgebung: lenkt die Mailbox auf lokale Testserver (Dovecot, Test-SMTP).
 *
 * Als mu-plugin in eine Test-WordPress-Installation kopieren – nie in einen
 * echten Shop. Siehe tests/integration/README.md.
 */

// Selbstsigniertes Zertifikat des Test-Dovecot zulassen.
add_filter(
	'lw16_mail_imap_options',
	function ( $options ) {
		$options['verify_peer'] = false;
		return $options;
	}
);

// SMTP: lokaler Testserver ohne Verschlüsselung.
add_filter(
	'lw16_mail_smtp_account',
	function ( $account ) {
		$account['smtp_host']   = '127.0.0.1';
		$account['smtp_port']   = 1587;
		$account['smtp_secure'] = 'none';
		$account['verify_peer'] = false;
		return $account;
	}
);
