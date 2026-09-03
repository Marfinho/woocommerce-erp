# Haster/Lindenweg16 DHL-Druck-Client (Raspberry Pi)

Holt DHL-Versandlabels aus der Druck-Warteschlange von WordPress/WooCommerce
ab und druckt sie über CUPS auf einem lokal per USB angeschlossenen
Thermo-Etikettendrucker.

**Wichtig zum Netzwerk:** Der Pi baut ausschließlich eine ausgehende
HTTPS-Verbindung zum WordPress-Server auf ("Pull"-Client). Es wird **keine
Portfreigabe am Router** benötigt und **kein eingehender Port** geöffnet –
weder auf dem Pi noch auf dem Server. Solange der Pi Internetzugang hat,
funktioniert er unverändert weiter, egal in welchem Netzwerk er steht.

## Installation

### 1. Raspberry Pi OS installieren

Raspberry Pi Imager verwenden, "Raspberry Pi OS Lite" (64-Bit) empfohlen.
SSH beim Flashen aktivieren, dann den Pi per SSH erreichen.

### 2. System aktualisieren

```bash
sudo apt update && sudo apt full-upgrade -y
```

### 3. CUPS installieren

```bash
sudo apt install -y cups python3-venv python3-pip
sudo usermod -aG lpadmin pi
```

Web-Oberfläche von außerhalb des Pi erreichbar machen (optional, für die
Einrichtung praktisch):

```bash
sudo cupsctl --remote-admin --remote-any --share-printers
sudo systemctl restart cups
```

### 4. Drucker einrichten

Drucker per USB anschließen, dann entweder über die CUPS-Weboberfläche
(`http://<pi-adresse>:631/admin`) oder per Kommandozeile einrichten. Der
Name, den der Drucker dabei bekommt, ist `PRINTER_NAME` in der `.env`.

```bash
lpinfo -v            # verfügbare Geräte auflisten
sudo lpadmin -p DHL_PRINTER -E -v usb://... -m everywhere
```

Es wird **kein bestimmtes Druckermodell vorausgesetzt** – jeder Drucker, der
sich als CUPS-Drucker einrichten lässt (auch generische Thermodrucker über
`everywhere` bzw. ein passendes PPD), funktioniert.

Für Thermolabel-Drucker: In den CUPS-Druckereinstellungen die Medien-/
Papiergröße möglichst nicht auf A4 vorgeben – der Client übergibt die
Seitengröße ohnehin explizit je Druckjob (103×199 mm für das DHL-Format
910-300-610), damit das Label 1:1 ohne Skalierung herauskommt.

### 5. Drucker testen

```bash
echo "Testseite" | lp -d DHL_PRINTER
```

### 6. Python-Abhängigkeiten installieren

```bash
sudo mkdir -p /opt/haster-print
sudo chown pi:pi /opt/haster-print
cp haster_print_client.py requirements.txt .env.example /opt/haster-print/
cd /opt/haster-print
python3 -m venv venv
venv/bin/pip install -r requirements.txt
```

### 7./8. `.env` konfigurieren und Token eintragen

Im WordPress-Backend unter **Lindenweg16 → Betrieb → DHL Drucker** einen
Drucker anlegen (z. B. Drucker-ID `eg_dhl`, Name „EG DHL"). Das dabei
angezeigte Token wird **nur einmalig** angezeigt – sofort übernehmen.

```bash
cp .env.example .env
nano .env
```

Mindestens ausfüllen:

```env
WORDPRESS_URL=https://IHRE-DOMAIN.de
API_TOKEN=<das einmalig angezeigte Token>
PRINTER_ID=eg_dhl
PRINTER_NAME=DHL_PRINTER
```

### 9. systemd-Service installieren

```bash
sudo cp haster-print.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable haster-print
```

### 10. Dienst starten

```bash
sudo systemctl start haster-print
sudo systemctl status haster-print
journalctl -u haster-print -f
```

Der Dienst startet nach jedem Neustart des Pi automatisch wieder
(`systemctl enable`), auch nach einem Stromausfall.

### 11. Testlabel aus WordPress drucken

Im WordPress-Backend unter **Lindenweg16 → Betrieb → DHL Drucker** bei dem
angelegten Drucker auf **„Testlabel drucken"** klicken. Der Pi holt den
Job beim nächsten Kontakt ab (in der Regel deutlich unter 5 Sekunden) und
druckt ein Label mit dem Text „HASTER HAKEN / TESTLABEL / KEINE
VERSENDUNG" – es entsteht dabei keine echte DHL-Sendung.

## Betrieb

### Long Polling statt Intervall-Polling

Der Client hält (Standard: `LONG_POLL_ENABLED=true`) eine Anfrage
`GET /jobs?wait=25` offen, bis entweder ein Job verfügbar wird oder der
Server nach der Wartezeit mit `204 No Content` antwortet – danach öffnet
der Client sofort die nächste Anfrage. Es gibt kein festes
5-Sekunden-Intervall und keine Busy-Loop.

Server-Hinweis: `wait` wird serverseitig auf 5–30 Sekunden begrenzt. Läuft
WordPress hinter einem Reverse-Proxy (nginx, Cloudflare Tunnel o. Ä.),
muss dessen Proxy-Timeout größer sein als der maximale `wait`-Wert (30 s)
zzgl. Puffer – üblicherweise reichen 60 s Proxy-Timeout. Ebenso sollte
`max_execution_time` in PHP mindestens `wait + 15` Sekunden betragen; der
Client setzt dies serverseitig bereits selbst (`set_time_limit`).

Sollte Long Polling wegen einer Hosting-Beschränkung nicht funktionieren
(die Anfrage bricht immer nach wenigen Sekunden ab, ohne 204 zu liefern),
auf klassisches Intervall-Polling zurückfallen:

```env
LONG_POLL_ENABLED=false
FALLBACK_POLL_INTERVAL=10
```

Das ist ausdrücklich ein Fallback, kein normaler Betriebsmodus.

### Netzwerkfehler und Backoff

Ist der WordPress-Server nicht erreichbar, wartet der Client mit
exponentiellem Backoff (1 s, 2 s, 4 s, 8 s, 16 s, danach `MAX_RETRY_DELAY`)
und versucht es erneut – ohne den Server mit Anfragen zu fluten. Sobald
eine Verbindung wieder klappt, wird der Backoff zurückgesetzt. Kein
Druckjob geht dabei verloren: Er bleibt in der Warteschlange, bis der Pi
wieder Kontakt hat.

### Umzug in ein anderes Netzwerk

Der Pi benötigt ausschließlich `WORDPRESS_URL`, `API_TOKEN`, `PRINTER_ID`
und `PRINTER_NAME`. Er kennt und braucht seine eigene lokale IP-Adresse
nicht als Konfigurationswert – ein Umzug in ein anderes Netzwerk (Haus A
→ Haus B → Büro → Lager) erfordert keine Änderung auf der
WordPress-Seite, solange der Pi Internetzugang hat.

### Token neu erzeugen

Falls ein Token in falsche Hände geraten ist: im Admin bei „Token neu
erzeugen" klicken (das alte Token wird sofort ungültig) und die `.env`
auf dem Pi mit dem neuen Wert aktualisieren, dann:

```bash
sudo systemctl restart haster-print
```

## Manueller End-to-End-Test

```
WooCommerce-Testbestellung anlegen
        ↓
DHL-Label erzeugen (Einstellung "Automatisch drucken" muss aktiv sein)
        ↓
Lindenweg16 → Betrieb → Druckjobs: neuer Eintrag "Wartend"
        ↓
Pi erkennt den Job (journalctl -u haster-print -f zeigt "Job … verfügbar")
        ↓
Pi lädt das PDF herunter und übergibt es an CUPS
        ↓
Etikett kommt am Drucker heraus
        ↓
Lindenweg16 → Betrieb → Druckjobs: Eintrag wechselt auf "Gedruckt"
```
