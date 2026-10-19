#!/usr/bin/env python3
"""Kleiner SMTP-Server für den Integrationstest der Mailbox.

Nimmt Mails mit Anmeldung (AUTH PLAIN/LOGIN, ohne TLS) an und legt sie per
IMAP-APPEND in den Posteingang der lokalen Empfänger bei Dovecot – so läuft
„Senden → Empfangen" im Test einmal komplett durch, ohne echten Mailserver.

    python3 tests/integration/smtp-delivery-server.py [port] [imap-port]

Benutzer und Passwörter stehen unten (müssen zu Dovecot passen).
"""
import imaplib
import sys
import time

from aiosmtpd.controller import Controller
from aiosmtpd.smtp import AuthResult, LoginPassword

USERS = {
    "sven@example.de": "svenpw",
    "katrin@example.de": "katrinpw",
    "test@example.de": "pässwort",
}
IMAP_PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 1143


def authenticator(server, session, envelope, mechanism, auth_data):
    if not isinstance(auth_data, LoginPassword):
        return AuthResult(success=False, handled=False)
    user = auth_data.login.decode()
    pw = auth_data.password.decode()
    ok = USERS.get(user) == pw
    print("AUTH", user, "ok" if ok else "FAILED", flush=True)
    return AuthResult(success=ok)


class Delivery:
    async def handle_DATA(self, server, session, envelope):
        print("MAIL from", envelope.mail_from, "to", envelope.rcpt_tos, flush=True)
        for rcpt in envelope.rcpt_tos:
            rcpt = rcpt.lower()
            if rcpt not in USERS:
                continue
            m = imaplib.IMAP4("127.0.0.1", IMAP_PORT)
            m.authenticate("PLAIN", lambda _: ("\0%s\0%s" % (rcpt, USERS[rcpt])).encode("utf-8"))
            m.append("INBOX", None, imaplib.Time2Internaldate(time.time()), envelope.original_content)
            m.logout()
            print("  zugestellt an", rcpt, flush=True)
        return "250 OK"


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 1587
    c = Controller(
        Delivery(),
        hostname="127.0.0.1",
        port=port,
        authenticator=authenticator,
        auth_required=True,
        auth_require_tls=False,
    )
    c.start()
    print("SMTP lauscht auf", port, flush=True)
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        c.stop()
