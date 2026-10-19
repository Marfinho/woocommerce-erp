# Integrationstests der Mailbox

Die normale Suite (`vendor/bin/phpunit`) prüft Parser, IMAP-Client (gegen
vorbereitete Server-Antworten), SMTP-Versand (gegen einen Testserver),
Bereinigung und Zugriffsregeln – ohne WordPress. Was erst im Zusammenspiel
auffällt (Datenbank, Theme-Layout, Rechte, echte Server), prüfen die Skripte
hier. Sie gehören **nicht** in eine echte Installation.

## Aufbau

1. **Dovecot** (echter IMAP-Server): `sudo bash tests/integration/dovecot-test-server.sh`
   (Debian/Ubuntu: `apt install dovecot-imapd`).
2. **Test-SMTP** (nimmt Mails an und legt sie per IMAP in den Posteingang des
   Empfängers): `pip install aiosmtpd` und
   `python3 tests/integration/smtp-delivery-server.py 1587`.
3. **WordPress mit WooCommerce** und diesem Plugin (und möglichst dem Theme
   `block-theme/lindenweg16`), dazu
   `cp tests/integration/mu-mailbox-test-env.php wp-content/mu-plugins/` – es lenkt
   die Mailbox auf die Testserver und lässt das selbstsignierte Zertifikat zu.
4. Ein Administrator `admin` (ID 1).

## Skripte

| Datei | Prüft |
| --- | --- |
| `mailbox-imap-dovecot.php` | `ImapClient` gegen Dovecot: TLS, STARTTLS, Umlaut-Passwort (Literal), 500-KB-Nachricht, Flags, Verschieben, Ordner mit Umlaut |
| `mailbox-e2e.php` (`wp eval-file`) | Postfächer, Verschlüsselung, Senden im CI-Layout, Kopie im Gesendet-Ordner, Empfangen, Antworten und Verlauf, Flags, Löschen, Server-Befehle, Hintergrundjob, Nachladen großer Postfächer, UIDVALIDITY |
| `mailbox-security.php` (`wp eval-file`, nach dem e2e) | Angriffe auf die Handler: fremde Postfächer, falsche/fremde Nonce, Mitnutzer ohne Senderecht, Verwaltungsseiten |
| `seed-mailbox.py` | Beispielmails (Text, HTML mit Fremdbild und Skript, Anhang, Verlauf) für die Sichtprüfung im Browser |

```bash
php tests/integration/mailbox-imap-dovecot.php
wp eval-file tests/integration/mailbox-e2e.php
wp eval-file tests/integration/mailbox-security.php
```
