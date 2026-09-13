# Haster/Lindenweg16 Druck-Client (Raspberry Pi)

Holt Druckjobs aus der Druck-Warteschlange von WordPress/WooCommerce ab und
druckt sie über CUPS auf einem lokal per USB angeschlossenen
Etikettendrucker – DHL-Versandlabels ebenso wie Artikel- und
Lagerplatz-Etiketten oder andere Etiketten aus dem Plugin. Ein Client
bedient genau einen Drucker; mehrere Drucker (z. B. einen für DHL-Labels
am Packtisch, einen fürs Lager) bedeuten mehrere registrierte Drucker im
Backend und entsprechend mehrere laufende Client-Instanzen – auf einem
oder auf mehreren Raspberry Pis, siehe unten.

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

**Hinweis zum Benutzernamen:** Die Beispielbefehle in dieser Anleitung
verwenden `pi` als Benutzername. Auf aktuellen Raspberry Pi OS-Images
(Bookworm und neuer) gibt es diesen Standardbenutzer nicht mehr – es
zählt der Benutzer, den man beim Flashen selbst festgelegt hat. Vor dem
Copy-Paste einmal mit `whoami` den eigenen Benutzernamen prüfen und
überall `pi` dadurch ersetzen (auch in `haster-print.service`, Schritt 9).

### 2b. Bei WLAN: Energiesparmodus abschalten (dringend empfohlen)

Läuft der Pi über WLAN statt Kabel, schaltet der WLAN-Chip standardmäßig
zwischendurch kurz in den Stromsparmodus – dabei können laufende
Verbindungen (egal ob zum Etikettendrucker-Server, zur Bambu-Bridge oder
zu WordPress) mit einem harten `Connection reset by peer` abreißen, auch
mitten in einer Übertragung. Das äußert sich als sporadische,
scheinbar zusammenhanglose Netzwerkfehler in den Logs beider Bridges.

Prüfen:

```bash
iwconfig wlan0 | grep -i power
```

Steht dort `Power Management:on`, dauerhaft abschalten:

```bash
sudo tee /etc/systemd/system/wifi-powersave-off.service > /dev/null <<'UNIT'
[Unit]
Description=WLAN-Energiesparmodus dauerhaft abschalten (verhindert sporadische Verbindungsabbrueche)
After=network.target

[Service]
Type=oneshot
ExecStart=/sbin/iw dev wlan0 set power_save off
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now wifi-powersave-off.service
```

Ein einfaches `iw wlan0 set power_save off` von Hand reicht nur bis zum
nächsten Neustart – der Dienst oben setzt es bei jedem Boot neu.

### 3. CUPS installieren

Nur nötig für den CUPS-Druckweg (Standard, `PRINT_BACKEND=cups`). Wird
stattdessen `brother_ql` verwendet (siehe Abschnitt weiter unten), kann
dieser Schritt komplett übersprungen werden.

```bash
sudo apt install -y cups python3-venv python3-pip
sudo usermod -aG lpadmin $(whoami)
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

Im `raspberry-pi`-Ordner des Checkouts ausführen (dort liegen
`haster_print_client.py` & Co.):

```bash
SRC="$(pwd)"
sudo mkdir -p /opt/haster-print
sudo chown $(whoami):$(whoami) /opt/haster-print
cp "$SRC"/haster_print_client.py "$SRC"/requirements.txt "$SRC"/.env.example /opt/haster-print/
cd /opt/haster-print
python3 -m venv venv
venv/bin/pip install -r requirements.txt
```

Bei `PRINT_BACKEND=brother_ql` (siehe unten) zusätzlich `requirements-brother-ql.txt`
mitkopieren und installieren (weiterhin in `/opt/haster-print`, `$SRC` ist
noch aus dem Befehl oben gesetzt):

```bash
cp "$SRC"/requirements-brother-ql.txt /opt/haster-print/
venv/bin/pip install -r requirements-brother-ql.txt
```

### 7./8. `.env` konfigurieren und Token eintragen

Im WordPress-Backend unter **Lindenweg16 → Betrieb → Druck-Warteschlange: Drucker** einen
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

`haster-print.service` enthält `User=pi` – vor dem Kopieren durch den
eigenen Benutzernamen ersetzen (siehe Hinweis in Schritt 3):

```bash
sed "s/User=pi/User=$(whoami)/" haster-print.service | sudo tee /etc/systemd/system/haster-print.service
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

Im WordPress-Backend unter **Lindenweg16 → Betrieb → Druck-Warteschlange: Drucker** bei dem
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

### Mehrere Drucker

Jeder Drucker bekommt eine eigene Drucker-ID und ein eigenes Token im
Backend, und jeder Client-Prozess bedient genau eine Drucker-ID – er
druckt, was in der Warteschlange für diese ID liegt, egal ob DHL-Label,
Artikel- oder Lagerplatz-Etikett. Zwei gängige Aufbauten:

**Ein Pi, zwei Drucker** – empfohlen, wenn beide Drucker nah beieinander
stehen (z. B. Packtisch und Lager im selben Raum oder Gebäude): Beide
Drucker per USB an denselben Pi, in CUPS als zwei separate Drucker
einrichten (Schritt 4, zweimal mit unterschiedlichem `-p NAME`), dann
zwei Kopien von `/opt/haster-print` mit je eigener `.env`
(`PRINTER_ID`/`PRINTER_NAME` unterschiedlich) und zwei systemd-Units
(z. B. `haster-print-dhl.service` und `haster-print-lager.service`,
kopiert aus `haster-print.service` mit angepasstem `WorkingDirectory`
und `ExecStart`). Günstiger und weniger zu warten als ein zweiter Pi.

**Zwei separate Pis** – sinnvoll, wenn die Drucker weit auseinander
stehen (unterschiedliche Räume, Stockwerke oder Standorte): ein
eigenständiger Pi direkt am jeweiligen Drucker, jeder mit eigener
Installation nach dieser Anleitung und eigener `.env`. Kein
USB-Kabel quer durchs Gebäude nötig, und fällt ein Pi aus, druckt der
andere trotzdem weiter.

In beiden Fällen ändert sich am WordPress-Backend nichts weiter: Jeder
Drucker wird dort einmal angelegt, unabhängig davon, wie viele Pis dahinterstehen.

### Brother QL über brother_ql (ohne CUPS)

Manche Brother-QL-Modelle (u. a. der QL-710W) lassen sich unter Linux
über CUPS **nicht** sauber einrichten: `-m everywhere` funktioniert nur
über IPP/Netzwerk (nicht USB), und weder Gutenprint noch Brothers
eigenes `.deb`-Treiberpaket (nur i386, läuft nicht auf einem ARM-Pi)
liefern ein passendes PPD. Für diese Fälle druckt der Client stattdessen
direkt per USB mit der Python-Bibliothek [`brother_ql`](https://github.com/pklaus/brother_ql) –
CUPS wird dabei komplett umgangen, Schritt 3–5 dieser Anleitung entfallen.

**1. Abhängigkeiten installieren** (siehe Schritt 6 oben):

```bash
cd /opt/haster-print
venv/bin/pip install -r requirements-brother-ql.txt
```

**2. USB-Zugriff ohne root erlauben.** Der Dienst läuft nicht als root –
ohne udev-Regel bekommt `brother_ql` beim Senden „Permission denied".
Vendor-/Produkt-ID mit `lsusb` ermitteln (Zeile z. B.
`Bus 001 Device 004: ID 04f9:2043 Brother Industries, Ltd`) und eine
Regel für genau diese IDs anlegen:

```bash
lsusb | grep -i brother
sudo tee /etc/udev/rules.d/99-brother-ql.rules <<'EOF'
SUBSYSTEM=="usb", ATTR{idVendor}=="04f9", ATTR{idProduct}=="2043", MODE="0666"
EOF
sudo udevadm control --reload-rules
sudo udevadm trigger
```

(idVendor/idProduct ggf. an die `lsusb`-Ausgabe anpassen, falls
abweichend von 04f9:2043.)

**3. `.env` konfigurieren:**

```env
PRINT_BACKEND=brother_ql
BROTHER_QL_MODEL=QL-710W
# Muss zur eingelegten Rolle passen (siehe LabelSheet::rolls() im Plugin):
# DK-11201 (29x90mm, gestanzt)  -> 29x90
# DK-11209 (62x29mm, gestanzt)  -> 62x29
# DK-22205 (endlos, 62mm breit) -> 62
BROTHER_QL_LABEL=29x90
BROTHER_QL_USB_IDENTIFIER=usb://0x04f9:0x2043
```

`PRINTER_NAME` bleibt in der `.env` stehen, wird bei diesem Backend aber
nicht verwendet – es wird kein CUPS-Drucker benötigt.

**4. Testen.** Schritt 9/10 (systemd-Service) wie gewohnt einrichten,
dann Schritt 11 (Testlabel im Backend anklicken). Bei Problemen zeigt
`journalctl -u haster-print -f` die genaue Fehlermeldung – häufigste
Ursachen: udev-Regel fehlt/IDs falsch (`Permission denied` oder
`Errno 13`), oder `BROTHER_QL_LABEL` passt nicht zur eingelegten Rolle
(der Client meldet dann selbst eine Grössenabweichung, statt schief zu
drucken).

**Wichtig:** Das konfigurierte `BROTHER_QL_LABEL` muss zur *physisch
eingelegten* Rolle passen – die Software kann das nicht automatisch
erkennen. Ein Drucker, der auf diesem Weg betrieben wird, sollte im
Backend nur für die eine dazu passende Etikettenart (z. B.
Artikel-/Lagerplatz-Etiketten im Standardformat) ausgewählt werden,
nicht für DHL-Labels anderer Grösse.

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
Lindenweg16 → Betrieb → Druck-Warteschlange: Jobs: neuer Eintrag "Wartend"
        ↓
Pi erkennt den Job (journalctl -u haster-print -f zeigt "Job … verfügbar")
        ↓
Pi lädt das PDF herunter und übergibt es an CUPS
        ↓
Etikett kommt am Drucker heraus
        ↓
Lindenweg16 → Betrieb → Druck-Warteschlange: Jobs: Eintrag wechselt auf "Gedruckt"
```

## Bambu-Lab-Bridge (3D-Drucker)

Zweiter, unabhängiger Dienst in diesem Ordner: `bambu_bridge.py`
verbindet einen Bambu-Lab-3D-Drucker im **LAN-Modus** (kein
Bambu-Cloud-Login) mit der Bambu-Warteschlange von WordPress
(`Lindenweg16 → Betrieb → Bambu-Lab-Drucker`). Sie holt geslicte
Druckdateien (`.gcode.3mf`) per Polling ab (Long Polling optional, siehe
unten), lädt sie per **implizitem FTPS** (Port 990) auf den Drucker,
startet den Druck per MQTT und meldet Fortschritt sowie Live-Status
(Temperaturen, Restzeit) laufend zurück.

**Anders als beim Etikettendrucker-Client** genügt hier kein reiner
Internetzugang: Die Bridge braucht zusätzlich eine Verbindung im
**lokalen Netz** zum Drucker (MQTT Port 8883, FTPS Port 990) – Bridge und
Drucker müssen also im selben Netzwerk stehen. Für den Weg zu WordPress
gilt weiterhin: nur ausgehende HTTPS-Verbindungen, kein eingehender Port.

### Schnellinstallation

Am Drucker zuerst LAN-Modus aktivieren und in WordPress einen Drucker
anlegen (Schritte 1–2 unten), danach führt
[`install-bambu-bridge.sh`](install-bambu-bridge.sh) durch den Rest:
Python-Umgebung, Abhängigkeiten, Konfiguration (fragt IP/Zugangscode/
Token interaktiv ab) und den systemd-Dienst. Es erkennt eine bestehende
Etikettendrucker-Installation (`haster-print`) automatisch, lässt sie
unangetastet und übernimmt daraus sogar `WORDPRESS_URL` als Vorschlag.
Erneutes Ausführen ist unbedenklich (fragt, ob eine vorhandene
Konfiguration erhalten bleiben soll).

```bash
cd raspberry-pi
./install-bambu-bridge.sh
```

Wer lieber jeden Schritt einzeln nachvollziehen (oder das Skript nicht
nutzen) möchte, findet ihn im Folgenden unverändert von Hand beschrieben.

### 1. Am Drucker: LAN-Modus aktivieren

Am Display: Einstellungen → LAN-Modus (bzw. „LAN Only Mode") aktivieren.
Notiert werden IP-Adresse, Zugangscode und Seriennummer – alle drei
stehen dort auf einem Bildschirm.

Eine feste IP-Reservierung im Router (DHCP-Reservierung anhand der
MAC-Adresse des Druckers) verhindert, dass sich die Adresse nach einem
Neustart des Druckers ändert und die `.env.bambu` dadurch veraltet.

### 2. Im WordPress-Backend: Drucker anlegen

Unter **Lindenweg16 → Betrieb → Bambu-Lab-Drucker** einen Drucker
anlegen (Drucker-ID, Name, IP, Zugangscode, Seriennummer wie in Schritt 1
notiert). Das dabei angezeigte Token wird **nur einmalig** angezeigt –
sofort übernehmen.

### 3. Python-Abhängigkeiten installieren

```bash
SRC="$(pwd)"
sudo mkdir -p /opt/bambu-bridge
sudo chown $(whoami):$(whoami) /opt/bambu-bridge
cp "$SRC"/bambu_bridge.py "$SRC"/requirements-bambu.txt "$SRC"/.env.bambu.example /opt/bambu-bridge/
cd /opt/bambu-bridge
python3 -m venv venv
venv/bin/pip install -r requirements-bambu.txt
```

Für das Kamera-Standbild (nur X1-Serie, siehe Abschnitt weiter unten)
zusätzlich:

```bash
sudo apt install -y ffmpeg
```

### 4. `.env.bambu` konfigurieren

```bash
cp .env.bambu.example .env.bambu
nano .env.bambu
```

Mindestens ausfüllen: `WORDPRESS_URL`, `API_TOKEN` (aus Schritt 2),
`PRINTER_ID` (dieselbe Drucker-ID wie im Backend), `BAMBU_IP`,
`BAMBU_ACCESS_CODE`, `BAMBU_SERIAL`.

### 5. systemd-Service installieren

`bambu-bridge.service` enthält `User=pi` – vor dem Kopieren durch den
eigenen Benutzernamen ersetzen:

```bash
sed "s/User=pi/User=$(whoami)/" bambu-bridge.service | sudo tee /etc/systemd/system/bambu-bridge.service
sudo systemctl daemon-reload
sudo systemctl enable bambu-bridge
sudo systemctl start bambu-bridge
sudo systemctl status bambu-bridge
journalctl -u bambu-bridge -f
```

### 6. Test

Im WordPress-Backend unter **Lindenweg16 → Betrieb → Bambu-Lab-Drucker**
sollte der Drucker nach kurzer Zeit als „Online" mit Live-Status
erscheinen. Über den Reiter **„Aufträge"** lässt sich eine geslicte
`.gcode.3mf`-Datei hochladen und einreihen – oder eine Druckdatei wählen,
die ohnehin schon an einer aktiven Artikelrevision hängt („Aus der
Modellablage einreihen"). Die Bridge holt sie beim nächsten Kontakt ab,
überträgt sie und startet den Druck. Fortschritt und Restzeit erscheinen
live in der Druckerkarte, bis der Auftrag als „Fertig" markiert wird.

Meldet die Karte **„Drucker nicht erreichbar"**, läuft die Bridge, findet
aber den Drucker im LAN nicht: Gerät aus, IP geändert (DHCP-Reservierung
im Router setzen) oder Zugangscode falsch. Startet ein Auftrag nicht
innerhalb von `PRINT_START_TIMEOUT` Sekunden (Standard 300), gilt er als
fehlgeschlagen und blockiert die Warteschlange nicht weiter.

Der LAN-Zugangscode ist das Passwort des Druckers – wer ihn kennt, kann
im selben Netz Dateien aufspielen und Drucke auslösen. Er wird deshalb
nur Benutzern mit der Berechtigung zur Druckerverwaltung angezeigt und
nach dem Speichern nicht mehr in die Seite zurückgeschrieben.

### Update einspielen

`bambu_bridge.py` läuft NICHT aus dem geklonten Repository, sondern aus
einer eigenen Kopie unter `/opt/bambu-bridge/bambu_bridge.py` (siehe
Schnellinstallation oben) – ein `git pull` im Repository-Verzeichnis
aktualisiert diese Kopie NICHT von selbst. Nach einem `git pull` also
zusätzlich:

```bash
cp ~/woocommerce-erp/raspberry-pi/bambu_bridge.py /opt/bambu-bridge/bambu_bridge.py
sudo systemctl restart bambu-bridge
```

Zur Kontrolle, dass der neue Stand wirklich läuft (Beispiel mit einer
Funktion, die es erst seit einer bestimmten Änderung gibt):

```bash
grep -c find_ams_tray_for_filament /opt/bambu-bridge/bambu_bridge.py
```

Ein Neustart mitten in einem laufenden Druck lässt die Bridge die
Fortschritts-Verfolgung für den gerade laufenden Auftrag verlieren (der
Druck selbst läuft am Drucker unabhängig weiter) – der Auftrag bleibt
dann in der „Aufträge"-Liste auf seinem letzten Stand hängen, bis
entweder ein späterer MQTT-Report ihn doch noch abschließt oder die
automatische Wartung ihn nach `BambuJobRepository::LOCK_TIMEOUT_MINUTES`
(180 Minuten) wieder freigibt. Updates deshalb nach Möglichkeit
zwischen zwei Aufträgen einspielen.

### Mehrere Bambu-Drucker

Jeder Drucker bekommt eine eigene Drucker-ID und ein eigenes Token,
genau wie bei den Etikettendruckern. Für mehrere Bambu-Drucker am selben
Raspberry Pi mehrere Kopien von `/opt/bambu-bridge` mit je eigener
`.env.bambu` und eigener systemd-Unit anlegen (kopiert aus
`bambu-bridge.service` mit angepasstem `WorkingDirectory` und
`ExecStart`) – dieselbe Bauform wie bei mehreren Etikettendruckern an
einem Pi (siehe oben, Abschnitt „Mehrere Drucker").

### Polling: klassisch (Standard) oder Long Polling

Anders als der Etikettendrucker-Client (dort ist Long Polling der
Standard) pollt die Bambu-Bridge standardmäßig **klassisch**: Jede
Anfrage bekommt sofort eine Antwort (`SHORT_POLL_WAIT=0` – der Server
hält dafür keinen PHP-Worker offen), danach eine Pause von
`FALLBACK_POLL_INTERVAL` Sekunden bis zur nächsten Anfrage. Grund: Auf
manchen Webhosting-Paketen (v. a. günstigen Shared-Hosting-Tarifen)
kappt der Provider oder ein davorstehender Reverse-Proxy eine offen
gehaltene Verbindung nach wenigen Sekunden von sich aus – die Bridge
sähe das als Netzwerkfehler statt als sauberes `204` und würde ständig
in den Backoff laufen, ohne dass ein Job je zuverlässig ankommt.
Genauso wichtig auf Shared-Hosting mit wenigen PHP-FPM-Workern: Ein
`SHORT_POLL_WAIT` größer als 0 hält je Anfrage einen Worker samt
Datenbankverbindung für die gewählte Zeit offen (serverseitig auf
5–30s begrenzt) – schon ein einzelner Drucker kann damit dauerhaft
die Hälfte eines Workers belegen und die ganze Website spürbar
verlangsamen. `SHORT_POLL_WAIT=0` sollte deshalb im klassischen Modus
so bleiben; für kürzere Verzögerung eher `FALLBACK_POLL_INTERVAL`
senken.

Wer weiß, dass der eigene Webspace lang offen gehaltene Verbindungen
zuverlässig durchreicht (eigener Server, VPS, die meisten Managed-
WordPress-Hoster), kann in `.env.bambu` auf Long Polling umstellen für
etwas geringere Verzögerung bis zum Abholen eines Jobs:

```env
LONG_POLL_ENABLED=true
```

Ob Long Polling beim eigenen Hoster überhaupt funktioniert, zeigt sich
im Log: Bricht `GET /jobs` regelmäßig mit einem Verbindungsfehler statt
mit einem sauberen `204` ab, bleibt es bei `LONG_POLL_ENABLED=false`.

### Ist-Druckzeit im Druckjournal

Ist ein Bambu-Job aus einem Fertigungsauftrag entstanden (z. B. künftig
direkt aus „Artikel & Revisionen" heraus eingereiht), trägt ein
erfolgreich abgeschlossener Druck seine tatsächliche Druckzeit
automatisch in die Ist-vs-Plan-Auswertung unter „Produktionsplanung" ein
– ohne die manuelle Eingabe, die dort für rein manuell geführte
Druckaufträge weiterhin nötig ist. Die Ist-Arbeitszeit (Verpacken,
Stützstrukturen entfernen …) bleibt in jedem Fall eine manuelle Eingabe,
da sie sich aus dem Druckerstatus nicht ableiten lässt.

### AMS-Filamente, Kamera-Standbild, "Druckfarm"

Die Bridge liest aus demselben MQTT-Report, der Status/Temperaturen
liefert, auch die AMS-Belegung (Material, Farbe, Restmenge je Fach) mit
und meldet sie beim normalen Status-Heartbeat – kein eigener Kanal, kein
zusätzlicher Verbindungsaufbau. Im Admin erscheint sie direkt auf der
Druckerkarte.

Zusätzlich holt die Bridge alle `CAMERA_SNAPSHOT_INTERVAL` Sekunden
(Standard 10s) ein einzelnes JPEG-Standbild und lädt es hoch – kein
Dauerstream, nur ein regelmäßiges Bild. Abschalten mit
`CAMERA_ENABLED=false` in `.env.bambu`.

**Nur die X1-Serie (X1C/X1E) wird unterstützt.** Sie liefert ihre
Kamera per RTSPS (RTSP über TLS, Port 322) – dafür braucht die Bridge
zusätzlich **`ffmpeg`** auf dem Pi:

```bash
sudo apt install -y ffmpeg
```

P1/A1-Drucker nutzen ein anderes, proprietäres Protokoll (JPEG-Framing
auf Port 6000) – das ist hier (noch) nicht implementiert, dort bleibt
das Kamera-Feld auf der Druckerkarte leer.

Beides erscheint auf der Druckerkarte unter „Bambu-Lab-Drucker" sowie
auf der eigenständigen Übersichtsseite **Fertigung → Druckfarm** (reine
Live-Ansicht ohne Verwaltungsformulare, für die Werkstatt-Anzeige) –
beide aktualisieren sich automatisch alle paar Sekunden per AJAX, ohne
manuelles Neuladen.

### Filament-Auswahl beim Einreihen

Überall, wo ein Druckauftrag eingereiht wird (Bambu-Lab-Drucker →
Aufträge, Bedarf → „Direkt drucken"), steht – sofern der Drucker
gerade eine AMS-Belegung meldet – eine zusätzliche Auswahl „welches
Filament" zur Verfügung. Sie gilt für den **gesamten** Druckauftrag
(ein Material für die ganze Platte, kein Mehrfarb-/Mehrmaterial-Mapping
pro Bauteil – dafür weiterhin manuell in Bambu Studio slicen). Ohne
Auswahl verhält sich die Bridge wie bisher: der Drucker nutzt das
gerade eingelegte bzw. externe Filament, ohne AMS-Beteiligung.

### Unsicherheiten des LAN-Protokolls

Bambu Lab dokumentiert das LAN-MQTT-/FTPS-Protokoll nicht offiziell. Die
hier verwendeten Topics und Kommandos entsprechen dem öffentlich
bekannten, breit genutzten Reverse-Engineering (u. a. aus
Home-Assistant- und OctoPrint-Integrationen) und funktionieren zum
Zeitpunkt dieser Anleitung mit aktueller Firmware. Ein künftiges
Firmware-Update **kann** Details ändern – meldet die Bridge dauerhaft
Verbindungsfehler nach einem Firmware-Update, lohnt ein Blick in die
aktuellen Quellen dieser Projekte.

## Headless-Slicing-Worker (Plattenanordnung → automatischer Druck)

Dritter, unabhängiger Dienst in diesem Ordner: `slicing_worker.py`
schliesst die Kette **Bedarf → Plattenanordnung → automatisch slicen →
Bambu-Warteschlange** ab. Unter „Lindenweg16 → Betrieb →
Bambu-Lab-Drucker" lassen sich im Reiter **„Platte anordnen"** mehrere
Artikel und Mengen automatisch auf dem virtuellen Druckbett anordnen
(konturbasiertes Nesting anhand der tatsächlichen Bauteilform, nicht nur
des Begrenzungsrechtecks) – das Ergebnis ist eine zusammengesetzte, noch
ungeslicte Projekt-3MF. Dieser Worker holt sie ab, slict sie mit einem
Kommandozeilen-Slicer (OrcaSlicer) anhand hinterlegter Profile und lädt
das Ergebnis zurück hoch; WordPress reiht die fertige Druckdatei danach
automatisch in die ganz normale Bambu-Warteschlange ein.

**Wichtiger Vorbehalt:** Der Slicer-Aufruf ist gegen OrcaSlicer 2.4.2
abgeglichen (`--load-settings` für Drucker- und Prozessprofil,
`--load-filaments` für das Filamentprofil, `--arrange 0`, `--allow-newer-file`, siehe
Kommentar in `slicing_worker.py`) und lässt sich über
`SLICER_ARGS_TEMPLATE` in `.env.slicing` an eine andere Version
anpassen. Vor dem produktiven Einsatz trotzdem mit einer unkritischen
Testplatte von Hand kontrollieren (Ergebnis-3MF in Bambu Studio öffnen,
Druckdaten ansehen), bevor ein automatisch geslicter Auftrag ungeprüft
auf einen echten Drucker geht.

**Läuft nicht auf dem Bambu-Pi selbst** – OrcaSlicer braucht x86_64,
der Pi ist ARM. Ein eigener Server im selben Netz (oder irgendwo mit
Zugriff auf die WordPress-Seite) übernimmt diesen Dienst, per Docker
(empfohlen) oder manuell installiert.

### 1. OrcaSlicer installieren

**Per Docker (empfohlen):** übernimmt zugleich Python-Abhängigkeiten
und Dienst-Start (Schritte 3 und 5 unten entfallen damit) –

```bash
cd raspberry-pi
cp .env.slicing.example .env.slicing
nano .env.slicing   # mindestens WORDPRESS_URL, API_TOKEN (siehe Schritt 2) eintragen
docker compose -f docker-compose.slicing-worker.yml up -d --build
docker compose -f docker-compose.slicing-worker.yml logs -f
```

`slicing-worker.Dockerfile` lädt beim Bauen automatisch die aktuellste
OrcaSlicer-Linux-AppImage von GitHub, entpackt sie (kein FUSE im
Container nötig) und startet sie beim Slicen unter einer virtuellen
Anzeige (`xvfb-run`) – OrcaSlicer ist im "CLI-Modus" technisch dieselbe
Anwendung wie mit Fenster, nur ohne sichtbare Oberfläche, und braucht
trotzdem denselben grafischen Unterbau. Für eine reproduzierbare, feste
Version statt "jeweils aktuellste beim Bauen": `--build-arg
ORCASLICER_VERSION=v2.2.0` (Tag-Namen siehe
[Releases](https://github.com/OrcaSlicer/OrcaSlicer/releases)).

Mit Docker weiter bei Schritt 2 (Profile hochladen), Schritt 4
(`.env.slicing`) ist mit obigem `nano .env.slicing` schon erledigt.

**Ohne Docker:** auf dem Rechner, der slicen soll (ein eigener x86_64-
Rechner, siehe oben – nicht der Bambu-Pi), die
[OrcaSlicer](https://github.com/SoftFever/OrcaSlicer)-AppImage bzw. das
passende Paket für die eigene Plattform installieren. Den Pfad zum
Programm für Schritt 4 notieren.

### 2. Slicing-Profile exportieren und hochladen

In OrcaSlicer bzw. Bambu Studio für jedes verwendete Material × Drucker
das Drucker-, Filament- und Prozessprofil exportieren (Rechtsklick auf
das Profil → „Export"). Unter **Lindenweg16 → Betrieb →
Bambu-Lab-Drucker → Reiter „Slicing-Profile"** die drei Dateien je
Material/Druckermodell hochladen und dort auch das Worker-Token
erzeugen (**„Token neu erzeugen"**, wird nur einmalig angezeigt).

### 3. Python-Abhängigkeiten installieren (nur ohne Docker)

```bash
SRC="$(pwd)"
sudo mkdir -p /opt/slicing-worker
sudo chown $(whoami):$(whoami) /opt/slicing-worker
cp "$SRC"/slicing_worker.py "$SRC"/requirements-slicing.txt "$SRC"/.env.slicing.example /opt/slicing-worker/
cd /opt/slicing-worker
python3 -m venv venv
venv/bin/pip install -r requirements-slicing.txt
```

### 4. `.env.slicing` konfigurieren (nur ohne Docker)

Mit Docker schon in Schritt 1 erledigt.

```bash
cp .env.slicing.example .env.slicing
nano .env.slicing
```

Mindestens ausfüllen: `WORDPRESS_URL`, `API_TOKEN` (aus Schritt 2),
`SLICER_CLI` (Pfad aus Schritt 1, dort einkommentieren).

### 5. systemd-Service installieren (nur ohne Docker)

```bash
sed "s/User=pi/User=$(whoami)/" slicing-worker.service | sudo tee /etc/systemd/system/slicing-worker.service
sudo systemctl daemon-reload
sudo systemctl enable slicing-worker
sudo systemctl start slicing-worker
sudo systemctl status slicing-worker
journalctl -u slicing-worker -f
```

### 6. Test

Im Reiter „Platte anordnen" eine kleine Testplatte anlegen und auf
„Automatisch slicen" klicken. Der Worker holt sie beim nächsten Kontakt
ab; im Log (Docker: `docker compose -f docker-compose.slicing-worker.yml
logs -f`, ohne Docker: `journalctl -u slicing-worker -f`) ist der
Slicer-Aufruf und sein Ergebnis zu sehen. Nach Erfolg erscheint die
Platte im Reiter „Aufträge" als neuer Bambu-Druckauftrag.

### Fehlersuche

Scheitert ein Slice-Lauf, steht die Platte im Reiter „Platte anordnen"
auf **Fehlgeschlagen**, und direkt unter dem Status steht der Grund, den
der Worker gemeldet hat (OrcaSlicer-Fehlercode mit Erklärung, dazu der
Klartext des Slicers). Nach Behebung der Ursache genügt **„Erneut
slicen"** – die Platte muss nicht neu angeordnet werden. Steht eine
Platte lange auf „Wird geslict" mit dem Hinweis „wartet seit … auf den
Slicing-Worker", läuft der Worker nicht oder sein Token stimmt nicht
(Worker-Log prüfen).

Je fehlgeschlagenem Auftrag bleibt im Arbeitsverzeichnis des Workers
(Docker: Volume `/data`, sonst `DOWNLOAD_DIRECTORY`) ein Ordner
`job-<ID>` mit den Eingabedateien, `slicer.log` (Warnungen/Fehler von
OrcaSlicer), `result.json` (Fehlercode und Klartext-Grund) und
`slicer-output.txt` (vollständige Konsolenausgabe samt Aufruf) liegen –
standardmässig 7 Tage (`KEEP_FAILED_JOBS_DAYS`). Mit Docker:

```bash
docker compose -f docker-compose.slicing-worker.yml exec slicing-worker sh -c 'ls /data; cat /data/job-*/result.json'
```

Typische Ursachen:

| Meldung / Code | Ursache | Abhilfe |
| --- | --- | --- |
| `Invalid option --load` | alter Worker-Aufruf, OrcaSlicer kennt `--load` nicht | Worker aktualisieren (dieser Stand nutzt `--load-settings`/`--load-filaments`) |
| `-24` Unsupported 3MF version | Projektdatei stammt aus neuerer Bambu-Studio-Version als der OrcaSlicer im Worker | ist mit `--allow-newer-file` im Standardaufruf behoben; alternativ `--build-arg ORCASLICER_VERSION=…` auf eine neuere Version |
| `-5` Profil ungültig | eines der drei Profil-JSON lässt sich nicht laden. Der Klartext dahinter sagt, welches: „Preset was not found in the loaded bundle" hiess früher, dass ein Benutzerprofil mit `inherits` übergeben wurde (der CLI löst das für lose Dateien nicht auf, der Worker tut es seit diesem Stand selbst, siehe unten) | Profil in OrcaSlicer neu exportieren und unter „Slicing-Profile" ersetzen; bei „kennt die OrcaSlicer-Version im Worker nicht" den Worker mit `--build-arg ORCASLICER_VERSION=…` neuer bauen |
| „Slicing-Profile unbrauchbar: … erbt von …" | das Elternprofil (`inherits`) gibt es in der OrcaSlicer-Version des Workers nicht, oder Drucker-/Filament-/Prozessdatei sind vertauscht | siehe Meldung; Profile in der Reihenfolge Drucker, Filament, Prozess hochladen |
| `-50` Platte leer / Objekt nicht im Druckraum | Druckbettmass des Druckers in WordPress passt nicht zum Druckerprofil, oder Teile ragen über den Rand | Bettmass beim Drucker prüfen, Sicherheitsabstand erhöhen |
| `-18` ungültige Parameterwerte in der 3MF (z. B. `tree_support_wall_count: -1 not in range`) | die Projektdatei der Platte trägt Werte einer neueren Bambu-Studio-Version, die diese OrcaSlicer-Version nicht kennt; der Worker entfernt seit diesem Stand alle Projektwerte ohne Profilzuordnung vor dem Slicen | die Meldung nennt den Schlüssel; kommt er aus einem der drei Profile, dort korrigieren |
| `-2` ungültige Parameter | andere OrcaSlicer-Version mit anderen Flags | `orca-slicer --help` im Container ansehen, `SLICER_ARGS_TEMPLATE` anpassen |

### Wie der Worker die Profile aufbereitet

Ein aus OrcaSlicer exportiertes Benutzerprofil ist meist nur eine
Differenzdatei: `"from": "User"`, `"inherits": "Bambu PLA Matte @BBL X1C"`
und die paar geänderten Werte. Der OrcaSlicer-CLI löst so ein `inherits`
nur für Dateien in seinem eigenen Profilverzeichnis auf; eine lose
übergebene Datei lehnt er ab (`-5`, „Preset was not found in the loaded
bundle"). Der Worker löst die Vererbungskette deshalb selbst gegen die
Herstellerprofile der installierten OrcaSlicer-Version auf
(`ORCA_PROFILES_DIR`, im Docker-Image `/opt/orca-profiles`), schreibt je
Auftrag ein kleines eigenes Profilpaket (`job-<ID>/profiles/LW16.json`
samt `LW16/machine|filament|process/`) und übergibt diese Dateien an den
Slicer. Die Dateien bleiben bei einem Fehlschlag zur Diagnose liegen.

Vor dem Slicen bereinigt der Worker ausserdem `Metadata/project_settings.config`
der Platte: Alle Schlüssel, die weder in den drei Profilen noch zu den
projektbezogenen Angaben (Betttyp, Filamentfarben, Spülvolumen,
Wischturmposition) gehören, werden entfernt, damit OrcaSlicer dafür
seine eigenen Standardwerte nimmt. Die Projektdatei stammt aus der
Bambu-Studio-Datei des ersten Bauteils und enthält sonst Werte einer
womöglich neueren Version, die der Slicer als ungültig ablehnt (`-18`).
Die entfernten Schlüssel stehen im Worker-Log.

### Ohne Worker: Plattenanordnung + manuelles Slicen

Auch ohne diesen Dienst ist die Plattenanordnung nutzbar: Im Reiter
„Platte anordnen" lässt sich die zusammengesetzte, ungeslicte
Projekt-3MF jeder angeordneten Platte herunterladen, von Hand in Bambu
Studio slicen und die fertige Druckdatei über den Reiter „Aufträge"
hochladen – derselbe Weg wie beim manuellen Einzeldruck.
