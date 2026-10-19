#!/usr/bin/env python3
"""Legt Beispielmails (Text, HTML mit Fremdbild, Anhang, Umlaute, Verlauf) in
test@example.de bei Dovecot ab – für die Sichtprüfung der Mailbox.

    python3 tests/integration/seed-mailbox.py [imap-port]
"""
import imaplib
import sys
import time
from email.message import EmailMessage
from email.utils import formatdate, make_msgid

port = int(sys.argv[1]) if len(sys.argv) > 1 else 1143
m = imaplib.IMAP4("127.0.0.1", port)
m.authenticate("PLAIN", lambda _: "\0test@example.de\0pässwort".encode("utf-8"))

for box in ("INBOX", "Sent", "Trash", "Spam"):
    m.select(box)
    typ, data = m.search(None, "ALL")
    for num in data[0].split():
        m.store(num, "+FLAGS", "\\Deleted")
    m.expunge()


def put(box, msg, flags=None):
    m.append(box, flags, imaplib.Time2Internaldate(time.time()), msg.as_bytes())


def base(frm, subj, days_ago=0):
    msg = EmailMessage()
    msg["From"] = frm
    msg["To"] = "Test Mitarbeiter <test@example.de>"
    msg["Subject"] = subj
    msg["Date"] = formatdate(time.time() - days_ago * 86400, localtime=True)
    msg["Message-ID"] = make_msgid(domain="kunde.example")
    return msg


# 1) Kundenanfrage, Klartext, Umlaute
a = base("Käthe Müller <kaethe@kunde.example>", "Frage zu Bestellung #1234: Größe passt nicht", 0)
a.set_content("Hallo,\n\nmeine Bestellung ist angekommen, aber die Halterung ist zu groß.\nKann ich sie umtauschen?\n\nSiehe https://kunde.example/foto.jpg\n\nViele Grüße\nKäthe")
put("INBOX", a)

# 2) Newsletter HTML mit Fremdbild, Skript (muss verschwinden) und Tracking-Pixel
b = base("Shop-News <news@lieferant.example>", "Neue Filamente im Sortiment", 1)
b.set_content("Neue Filamente: PLA in 12 Farben.")
b.add_alternative(
    """<html><head><style>h1{color:#8b6914} .btn{background:#8b6914;color:#fff;padding:8px 14px;border-radius:99px}</style></head>
<body><h1>Neue Filamente</h1><p>PLA in <b>12 Farben</b> – jetzt bestellen.</p>
<img src="https://track.example/pixel.gif" width="1" height="1">
<img src="https://cdn.example/banner.png" alt="Banner">
<p><a class="btn" href="https://lieferant.example/shop">Zum Shop</a></p>
<script>alert('xss')</script><iframe src="https://evil.example"></iframe>
<a href="javascript:alert(1)" onclick="alert(2)">böse</a></body></html>""",
    subtype="html",
)
put("INBOX", b)

# 3) Mail mit Anhang und eingebettetem Bild
c = base("Rechnungswesen <buchhaltung@lieferant.example>", "Rechnung 2026-0815", 3)
c.set_content("Anbei die Rechnung.")
c.add_alternative('<p>Anbei die <b>Rechnung</b>.</p><img src="cid:logo123">', subtype="html")
png = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cfc0f01f0005000201a5f645400000000049454e44ae426082")
c.get_payload()[1].add_related(png, "image", "png", cid="<logo123>")
c.add_attachment(b"%PDF-1.4\n%test\n", maintype="application", subtype="pdf", filename="Rechnung 2026-0815.pdf")
put("INBOX", c, "(\\Seen)")

# 4) Verlauf: eigene gesendete Mail und Antwort
d = base("Test Mitarbeiter <test@example.de>", "Angebot Haster Haken", 4)
del d["To"]; d["To"] = "Hans Kunde <hans@kunde.example>"
d.set_content("Hallo Hans,\n\nanbei unser Angebot.\n\nViele Grüße")
put("Sent", d, "(\\Seen)")
e = base("Hans Kunde <hans@kunde.example>", "Re: Angebot Haster Haken", 3)
e["In-Reply-To"] = d["Message-ID"]
e["References"] = d["Message-ID"]
e.set_content("Danke, klingt gut!\n\n> Hallo Hans,\n>\n> anbei unser Angebot.\n")
put("INBOX", e)

# 5) Spam
f = base("Gewinnspiel <win@spam.example>", "Sie haben gewonnen!!!", 2)
f.set_content("Klicken Sie hier")
put("Spam", f)

m.logout()
print("Beispielmails angelegt.")
