#!/usr/bin/env python3
"""
Haster/Lindenweg16 Bambu-Lab-Bridge für den Raspberry Pi.

Verbindet einen Bambu-Lab-3D-Drucker im LAN-Modus (kein Bambu-Cloud-Login
nötig) mit der Bambu-Warteschlange von WordPress/WooCommerce
(lw16-bambu/v1). Eine Bridge-Instanz bedient genau einen Drucker – wie
beim Etikettendrucker-Client bestimmt allein die Warteschlange auf der
WordPress-Seite, was gedruckt wird, nicht die Bridge.

Zwei Aufgaben laufen nebenläufig:

1. Ein MQTT-Client verbindet sich dauerhaft zum Drucker (Port 8883, TLS,
   Benutzer "bblp", Passwort = LAN-Zugangscode vom Display) und liest den
   laufenden Statusreport (Zustand, Fortschritt, Restzeit, Temperaturen).
   Dieser Status wird regelmäßig an POST /status gemeldet – das dient
   zugleich als Heartbeat, unabhängig davon, ob gerade gedruckt wird.

2. Die Hauptschleife holt wartende Druckaufträge per Long Polling ab
   (GET /jobs), lädt die geslicte 3MF-Datei herunter, überträgt sie per
   FTPS auf den Drucker (Port 990, derselbe Benutzer/Zugangscode) und
   löst den Druck per MQTT-Kommando aus. Der weitere Fortschritt wird
   dann aus dem MQTT-Report des laufenden Jobs abgelesen.

Wichtig zum Netzwerk: Anders als beim Etikettendrucker-Client (reiner
Pull-Client zu WordPress) braucht diese Bridge zusätzlich eine
ausgehende Verbindung IM LOKALEN NETZ zum Drucker (MQTT + FTPS). Bridge
und Drucker müssen deshalb im selben Netzwerk stehen – ein Umzug des
Druckers in ein anderes Netz erfordert, die IP in der .env anzupassen
(oder eine feste IP/Reservierung im Router einzurichten).

Das LAN-Protokoll ist von Bambu Lab nicht offiziell dokumentiert; die
hier verwendeten Topics/Kommandos entsprechen dem öffentlich
bekannten und breit genutzten Reverse-Engineering (u. a. Home-Assistant-
und OctoPrint-Integrationen). Ein Firmware-Update kann Details ändern -
dann hilft ein Blick in deren aktuelle Quellen.

Aufruf:
    python3 bambu_bridge.py

Konfiguration ausschließlich über Umgebungsvariablen / .env (siehe
.env.bambu.example).
"""

from __future__ import annotations

import json
import logging
import os
import ssl
import sys
import tempfile
import threading
import time
from ftplib import FTP_TLS, error_perm
from pathlib import Path
from typing import Optional

import requests

try:
    import paho.mqtt.client as mqtt
except ImportError:  # pragma: no cover - Laufzeitfehler, keine Testabdeckung nötig
    sys.exit(
        "Paket 'paho-mqtt' fehlt. Installieren mit: "
        "pip install -r requirements-bambu.txt"
    )

# ---------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------


def _load_dotenv(path: Path) -> None:
    """Minimaler .env-Loader – keine externe Abhängigkeit nötig."""
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        os.environ.setdefault(key, value)


_load_dotenv(Path(__file__).resolve().parent / ".env.bambu")


def _env_bool(name: str, default: bool) -> bool:
    val = os.environ.get(name)
    if val is None:
        return default
    return val.strip().lower() in ("1", "true", "yes", "on")


class Config:
    """Alle Einstellungen an einer Stelle, mit sinnvollen Defaults."""

    WORDPRESS_URL = os.environ.get("WORDPRESS_URL", "").rstrip("/")
    API_TOKEN = os.environ.get("API_TOKEN", "")
    PRINTER_ID = os.environ.get("PRINTER_ID", "bambu_p1s_1")

    BAMBU_IP = os.environ.get("BAMBU_IP", "")
    BAMBU_ACCESS_CODE = os.environ.get("BAMBU_ACCESS_CODE", "")
    BAMBU_SERIAL = os.environ.get("BAMBU_SERIAL", "")

    # Wie oft der zuletzt gelesene MQTT-Status an WordPress gemeldet wird -
    # dient zugleich als Heartbeat/Online-Anzeige, unabhängig von echten
    # Zustandswechseln.
    STATUS_INTERVAL = int(os.environ.get("STATUS_INTERVAL", "10"))

    LONG_POLL_TIMEOUT = int(os.environ.get("LONG_POLL_TIMEOUT", "25"))
    REQUEST_TIMEOUT = int(os.environ.get("REQUEST_TIMEOUT", "35"))
    MAX_RETRY_DELAY = int(os.environ.get("MAX_RETRY_DELAY", "30"))

    DOWNLOAD_DIRECTORY = Path(os.environ.get("DOWNLOAD_DIRECTORY", "/var/lib/bambu-bridge"))
    LOG_LEVEL = os.environ.get("LOG_LEVEL", "INFO").upper()

    API_NAMESPACE = "lw16-bambu/v1"
    MQTT_PORT = 8883
    FTPS_PORT = 990

    @classmethod
    def validate(cls) -> None:
        missing = [
            name
            for name, val in (
                ("WORDPRESS_URL", cls.WORDPRESS_URL),
                ("API_TOKEN", cls.API_TOKEN),
                ("PRINTER_ID", cls.PRINTER_ID),
                ("BAMBU_IP", cls.BAMBU_IP),
                ("BAMBU_ACCESS_CODE", cls.BAMBU_ACCESS_CODE),
                ("BAMBU_SERIAL", cls.BAMBU_SERIAL),
            )
            if not val
        ]
        if missing:
            sys.exit(
                "Fehlende Konfiguration: %s. Bitte .env.bambu prüfen "
                "(siehe .env.bambu.example)." % ", ".join(missing)
            )


logging.basicConfig(
    level=getattr(logging, Config.LOG_LEVEL, logging.INFO),
    format="%(asctime)s %(levelname)-8s %(message)s",
)
log = logging.getLogger("bambu-bridge")


def redact(text: str) -> str:
    """Token und Zugangscode nie im Klartext loggen."""
    for secret in (Config.API_TOKEN, Config.BAMBU_ACCESS_CODE):
        if secret:
            text = text.replace(secret, "***redacted***")
    return text


# ---------------------------------------------------------------------
# Geteilter Live-Status (vom MQTT-Thread geschrieben, von der
# Hauptschleife gelesen) - ein einfaches Lock genügt, da nur kurze
# Zuweisungen geschützt werden, keine Wartezeit.
# ---------------------------------------------------------------------


class PrinterState:
    """Letzter aus dem MQTT-Report gelesener Druckerzustand."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.connected = False
        self.gcode_state = ""  # z. B. IDLE, RUNNING, PAUSE, FINISH, FAILED
        self.progress_percent: Optional[int] = None
        self.remaining_minutes: Optional[int] = None
        self.nozzle_temp: Optional[float] = None
        self.bed_temp: Optional[float] = None
        self.printer_model = ""
        self.last_error = ""

    def update(self, **kwargs) -> None:
        with self._lock:
            for key, value in kwargs.items():
                setattr(self, key, value)

    def snapshot(self) -> dict:
        with self._lock:
            return {
                "connected": self.connected,
                "gcode_state": self.gcode_state,
                "progress_percent": self.progress_percent,
                "remaining_minutes": self.remaining_minutes,
                "nozzle_temp": self.nozzle_temp,
                "bed_temp": self.bed_temp,
                "printer_model": self.printer_model,
                "last_error": self.last_error,
            }


STATE = PrinterState()


# ---------------------------------------------------------------------
# MQTT (Live-Status + Druckbefehl)
# ---------------------------------------------------------------------


def _on_mqtt_connect(client, userdata, flags, rc, properties=None):  # noqa: ANN001 - paho-Signatur
    if rc == 0:
        log.info("MQTT verbunden mit %s.", Config.BAMBU_IP)
        STATE.update(connected=True)
        client.subscribe(f"device/{Config.BAMBU_SERIAL}/report")
        # Ein "pushall" fordert den Drucker auf, den vollständigen Status
        # einmal komplett zu senden, statt auf die nächste Änderung zu
        # warten - sonst zeigt die Bridge nach einem (Neu-)Verbinden bis
        # zur nächsten Änderung "unbekannt".
        client.publish(
            f"device/{Config.BAMBU_SERIAL}/request",
            json.dumps({"pushing": {"sequence_id": "0", "command": "pushall"}}),
        )
    else:
        log.error("MQTT-Verbindung fehlgeschlagen (rc=%s) - Zugangscode/IP prüfen.", rc)
        STATE.update(connected=False)


def _on_mqtt_disconnect(client, userdata, rc, properties=None):  # noqa: ANN001 - paho-Signatur
    log.warning("MQTT-Verbindung getrennt (rc=%s).", rc)
    STATE.update(connected=False)


def _on_mqtt_message(client, userdata, msg):  # noqa: ANN001 - paho-Signatur
    try:
        payload = json.loads(msg.payload.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return

    printer = payload.get("print")
    if not isinstance(printer, dict):
        return

    updates: dict = {}

    if "gcode_state" in printer:
        updates["gcode_state"] = str(printer["gcode_state"]).upper()
    if "mc_percent" in printer:
        try:
            updates["progress_percent"] = int(printer["mc_percent"])
        except (TypeError, ValueError):
            pass
    if "mc_remaining_time" in printer:
        try:
            updates["remaining_minutes"] = int(printer["mc_remaining_time"])
        except (TypeError, ValueError):
            pass
    if "nozzle_temper" in printer:
        try:
            updates["nozzle_temp"] = float(printer["nozzle_temper"])
        except (TypeError, ValueError):
            pass
    if "bed_temper" in printer:
        try:
            updates["bed_temp"] = float(printer["bed_temper"])
        except (TypeError, ValueError):
            pass

    # print_error ist bei Bambu ein numerischer Fehlercode, 0 = kein Fehler.
    fehler_code = printer.get("print_error")
    if fehler_code:
        updates["last_error"] = f"Fehlercode {fehler_code}"
    elif "gcode_state" in printer and str(printer["gcode_state"]).upper() not in ("FAILED",):
        updates["last_error"] = ""

    if updates:
        STATE.update(**updates)


def start_mqtt_client() -> "mqtt.Client":
    """MQTT-Client aufbauen und im Hintergrund verbunden halten.

    Der Drucker verwendet im LAN-Modus ein selbstsigniertes Zertifikat -
    es gibt keine öffentliche CA, gegen die sich prüfen ließe. Die
    Verbindung bleibt trotzdem auf TLS (verschlüsselt), nur die
    Zertifikatsprüfung selbst entfällt (CERT_NONE) - dasselbe Vorgehen wie
    bei allen bekannten Bambu-LAN-Integrationen.
    """
    client = mqtt.Client(client_id=f"lw16-bambu-bridge-{Config.PRINTER_ID}", protocol=mqtt.MQTTv311)
    client.username_pw_set("bblp", Config.BAMBU_ACCESS_CODE)

    ssl_context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    ssl_context.check_hostname = False
    ssl_context.verify_mode = ssl.CERT_NONE
    client.tls_set_context(ssl_context)

    client.on_connect = _on_mqtt_connect
    client.on_disconnect = _on_mqtt_disconnect
    client.on_message = _on_mqtt_message

    client.reconnect_delay_set(min_delay=1, max_delay=Config.MAX_RETRY_DELAY)
    client.connect_async(Config.BAMBU_IP, Config.MQTT_PORT, keepalive=30)
    client.loop_start()

    return client


def send_print_command(client: "mqtt.Client", remote_filename: str) -> None:
    """Druck der zuvor per FTPS hochgeladenen Datei anstoßen.

    "project_file" ist das von Bambu Studio selbst verwendete Kommando,
    um eine bereits auf dem Drucker liegende (S)3MF-Druckdatei zu
    starten. use_ams=false, weil die Filamentzuordnung in dieser
    Anbindung bewusst nicht automatisiert wird - der Slicer/das Profil
    entscheidet beim Erzeugen der Druckdatei bereits, welches Material
    gemeint ist; ein AMS-Wechsel ohne Sichtprüfung vor Ort wäre riskant.
    """
    command = {
        "print": {
            "sequence_id": "0",
            "command": "project_file",
            "param": "Metadata/plate_1.gcode",
            "project_id": "0",
            "profile_id": "0",
            "task_id": "0",
            "subtask_id": "0",
            "subtask_name": remote_filename,
            "url": f"file:///sdcard/{remote_filename}",
            "bed_type": "auto",
            "timelapse": False,
            "bed_leveling": True,
            "flow_cali": False,
            "vibration_cali": False,
            "layer_inspect": False,
            "use_ams": False,
        }
    }
    client.publish(f"device/{Config.BAMBU_SERIAL}/request", json.dumps(command))


# ---------------------------------------------------------------------
# FTPS-Upload der 3MF-Datei an den Drucker
# ---------------------------------------------------------------------


def upload_via_ftps(local_path: Path, remote_filename: str) -> tuple[bool, str]:
    """3MF-Datei per FTPS auf die interne SD-Karte des Druckers legen.

    Der Drucker bietet im LAN-Modus einen FTPS-Server (Port 990, "bblp"/
    Zugangscode) - denselben, den auch Bambu Studio beim "Senden" einer
    Druckdatei verwendet.
    """
    try:
        ftp = FTP_TLS()
        ftp.connect(Config.BAMBU_IP, Config.FTPS_PORT, timeout=30)
        ftp.login("bblp", Config.BAMBU_ACCESS_CODE)
        # Nach dem Login-Handshake auch die Datenverbindung verschlüsseln -
        # ohne prot_p() bliebe die eigentliche Dateiübertragung im Klartext.
        ftp.prot_p()

        with open(local_path, "rb") as fh:
            ftp.storbinary(f"STOR {remote_filename}", fh)

        ftp.quit()
        return True, ""
    except error_perm as exc:
        return False, f"FTPS vom Drucker abgelehnt: {exc}"
    except OSError as exc:
        return False, f"FTPS-Verbindung fehlgeschlagen: {exc}"


# ---------------------------------------------------------------------
# WordPress-REST-API
# ---------------------------------------------------------------------


class ApiClient:
    """Kapselt alle HTTP-Aufrufe gegen die WordPress-REST-API."""

    def __init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {Config.API_TOKEN}",
                "User-Agent": "bambu-bridge/1.0",
            }
        )
        self.base = f"{Config.WORDPRESS_URL}/wp-json/{Config.API_NAMESPACE}"

    def get_jobs(self, wait: int) -> Optional[requests.Response]:
        try:
            return self.session.get(
                f"{self.base}/jobs",
                params={"printer_id": Config.PRINTER_ID, "wait": wait, "_": int(time.time() * 1000)},
                timeout=Config.REQUEST_TIMEOUT,
            )
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei GET /jobs: %s", redact(str(exc)))
            return None

    def _post(self, path: str, **kwargs) -> Optional[requests.Response]:
        try:
            return self.session.post(f"{self.base}/{path}", timeout=15, **kwargs)
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei POST %s: %s", path, redact(str(exc)))
            return None

    def claim(self, job_id: int) -> bool:
        r = self._post(f"jobs/{job_id}/claim")
        return r is not None and r.status_code == 200

    def mark_uploading(self, job_id: int) -> None:
        self._post(f"jobs/{job_id}/uploading")

    def mark_printing(self, job_id: int) -> None:
        self._post(f"jobs/{job_id}/printing")

    def report_progress(self, job_id: int, progress_percent: int, remaining_minutes: int) -> None:
        self._post(
            f"jobs/{job_id}/progress",
            data={"progress_percent": progress_percent, "remaining_minutes": remaining_minutes},
        )

    def complete(self, job_id: int) -> bool:
        r = self._post(f"jobs/{job_id}/complete")
        return r is not None and r.status_code == 200

    def fail(self, job_id: int, message: str) -> bool:
        r = self._post(f"jobs/{job_id}/failed", data={"message": message[:500]})
        return r is not None and r.status_code == 200

    def post_status(self) -> None:
        snap = STATE.snapshot()
        if not snap["connected"]:
            return
        self._post(
            "status",
            data={
                "status": snap["gcode_state"] or "idle",
                "progress_percent": snap["progress_percent"],
                "remaining_minutes": snap["remaining_minutes"],
                "nozzle_temp": snap["nozzle_temp"],
                "bed_temp": snap["bed_temp"],
                "printer_model": snap["printer_model"],
                "error": snap["last_error"],
            },
        )

    def download_file(self, job_id: int, target: Path) -> bool:
        try:
            with self.session.get(f"{self.base}/jobs/{job_id}/file", timeout=60, stream=True) as r:
                if r.status_code != 200:
                    log.error("3MF-Download für Job %s fehlgeschlagen: HTTP %s", job_id, r.status_code)
                    return False
                target.parent.mkdir(parents=True, exist_ok=True)
                tmp = target.with_suffix(target.suffix + ".part")
                with open(tmp, "wb") as fh:
                    for chunk in r.iter_content(chunk_size=262144):
                        fh.write(chunk)
                tmp.replace(target)
                return True
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler beim 3MF-Download (Job %s): %s", job_id, redact(str(exc)))
            return False


# ---------------------------------------------------------------------
# Statusmelder (eigener Thread: regelmäßiger Heartbeat, unabhängig vom
# aktuellen Job)
# ---------------------------------------------------------------------


def status_reporter_loop(api: ApiClient, stop_event: threading.Event) -> None:
    while not stop_event.is_set():
        api.post_status()
        stop_event.wait(Config.STATUS_INTERVAL)


# ---------------------------------------------------------------------
# Druckauftrag abwickeln
# ---------------------------------------------------------------------


def process_job(api: ApiClient, mqtt_client: "mqtt.Client", job: dict) -> None:
    job_id = int(job["id"])
    filename = job.get("filename") or f"job-{job_id}.3mf"

    log.info("Job %s verfügbar – claime …", job_id)

    if not api.claim(job_id):
        log.info("Job %s konnte nicht geclaimt werden (vermutlich schon vergeben).", job_id)
        time.sleep(2)
        return

    local_path = Config.DOWNLOAD_DIRECTORY / filename

    if not api.download_file(job_id, local_path):
        log.error("3MF-Download für Job %s fehlgeschlagen – Job bleibt offen.", job_id)
        return

    api.mark_uploading(job_id)
    log.info("Job %s: übertrage %s per FTPS an den Drucker …", job_id, filename)

    remote_filename = f"lw16-{job_id}-{filename}"
    ok, message = upload_via_ftps(local_path, remote_filename)

    if not ok:
        log.error("Upload für Job %s fehlgeschlagen: %s", job_id, message)
        api.fail(job_id, message)
        return

    STATE.update(gcode_state="", progress_percent=0, remaining_minutes=None, last_error="")
    send_print_command(mqtt_client, remote_filename)
    api.mark_printing(job_id)
    log.info("Job %s: Druck gestartet, verfolge Fortschritt über MQTT.", job_id)

    # Fortschritt aus dem MQTT-Report ablesen, bis der Drucker FINISH oder
    # FAILED meldet. Kein fester Timeout - ein 3D-Druck kann viele Stunden
    # dauern; die Bridge bleibt so lange dran, wie der Drucker verbunden
    # bleibt (fällt die Verbindung dauerhaft aus, gibt die Wartung auf der
    # WordPress-Seite den Job nach LOCK_TIMEOUT_MINUTES wieder frei).
    last_reported = -1
    while True:
        time.sleep(5)
        snap = STATE.snapshot()
        state = snap["gcode_state"]

        if snap["progress_percent"] is not None and snap["progress_percent"] != last_reported:
            api.report_progress(job_id, snap["progress_percent"], snap["remaining_minutes"] or 0)
            last_reported = snap["progress_percent"]

        if state == "FINISH":
            log.info("Job %s: Drucker meldet FINISH.", job_id)
            api.complete(job_id)
            return

        if state == "FAILED":
            fehler = snap["last_error"] or "Drucker meldet FAILED (Fehlercode siehe Druckerdisplay)."
            log.error("Job %s: Drucker meldet FAILED – %s", job_id, fehler)
            api.fail(job_id, fehler)
            return


# ---------------------------------------------------------------------
# Hauptschleife
# ---------------------------------------------------------------------


def run() -> None:
    Config.validate()
    Config.DOWNLOAD_DIRECTORY.mkdir(parents=True, exist_ok=True)

    api = ApiClient()
    mqtt_client = start_mqtt_client()

    stop_event = threading.Event()
    reporter = threading.Thread(target=status_reporter_loop, args=(api, stop_event), daemon=True)
    reporter.start()

    backoff = 1

    log.info(
        "Bambu-Bridge gestartet: %s, Drucker-ID %s, Bambu-IP %s.",
        Config.WORDPRESS_URL,
        Config.PRINTER_ID,
        Config.BAMBU_IP,
    )

    try:
        while True:
            response = api.get_jobs(Config.LONG_POLL_TIMEOUT)

            if response is None:
                log.info("Kein Kontakt zu WordPress – erneuter Versuch in %ss.", backoff)
                time.sleep(backoff)
                backoff = min(backoff * 2, Config.MAX_RETRY_DELAY)
                continue

            backoff = 1

            if response.status_code == 200:
                try:
                    jobs = response.json().get("jobs", [])
                except ValueError:
                    jobs = []

                for job in jobs:
                    process_job(api, mqtt_client, job)

                continue

            if response.status_code == 204:
                continue

            if response.status_code == 401:
                log.error("Token abgelehnt (401). Bitte API_TOKEN in .env.bambu prüfen.")
                time.sleep(min(30, Config.MAX_RETRY_DELAY))
                continue

            if response.status_code == 429:
                log.warning("Serverseitiges Ratenlimit erreicht – warte länger.")
                time.sleep(Config.MAX_RETRY_DELAY)
                continue

            if 500 <= response.status_code < 600:
                log.warning("Serverfehler (HTTP %s) – erneuter Versuch in %ss.", response.status_code, backoff)
                time.sleep(backoff)
                backoff = min(backoff * 2, Config.MAX_RETRY_DELAY)
                continue

            log.warning("Unerwarteter Status %s – erneuter Versuch in 5s.", response.status_code)
            time.sleep(5)
    finally:
        stop_event.set()
        mqtt_client.loop_stop()
        mqtt_client.disconnect()


if __name__ == "__main__":
    try:
        run()
    except KeyboardInterrupt:
        log.info("Beendet.")
