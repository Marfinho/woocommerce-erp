#!/usr/bin/env python3
"""
Haster/Lindenweg16 DHL-Druck-Client für den Raspberry Pi.

Holt Druckjobs über Long Polling von der WordPress-/WooCommerce-Instanz
ab, lädt das DHL-Thermolabel-PDF herunter und übergibt es unverändert
(kein Skalieren, kein "fit to page") an CUPS.

Netzwerk: Der Pi baut ausschließlich ausgehende HTTPS-Verbindungen zum
WordPress-Server auf (Pull-Client). Es wird kein eingehender Port
benötigt, keine Portfreigabe am Router, unabhängig davon, in welchem
Netzwerk der Pi gerade steht.

Aufruf:
    python3 haster_print_client.py

Konfiguration ausschließlich über Umgebungsvariablen / .env (siehe
.env.example). Kein Konfigurationswert enthält eine lokale Adresse des
Pi selbst – ein Umzug in ein anderes Netzwerk erfordert keine Änderung
auf der WordPress-Seite.
"""

from __future__ import annotations

import logging
import os
import subprocess
import sys
import time
from pathlib import Path
from typing import Optional

import requests

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


_load_dotenv(Path(__file__).resolve().parent / ".env")


def _env_bool(name: str, default: bool) -> bool:
    val = os.environ.get(name)
    if val is None:
        return default
    return val.strip().lower() in ("1", "true", "yes", "on")


class Config:
    """Alle Einstellungen an einer Stelle, mit sinnvollen Defaults."""

    WORDPRESS_URL = os.environ.get("WORDPRESS_URL", "").rstrip("/")
    API_TOKEN = os.environ.get("API_TOKEN", "")
    PRINTER_ID = os.environ.get("PRINTER_ID", "eg_dhl")
    PRINTER_NAME = os.environ.get("PRINTER_NAME", "DHL_PRINTER")

    LONG_POLL_ENABLED = _env_bool("LONG_POLL_ENABLED", True)
    LONG_POLL_TIMEOUT = int(os.environ.get("LONG_POLL_TIMEOUT", "25"))
    REQUEST_TIMEOUT = int(os.environ.get("REQUEST_TIMEOUT", "35"))
    MAX_RETRY_DELAY = int(os.environ.get("MAX_RETRY_DELAY", "30"))
    FALLBACK_POLL_INTERVAL = int(os.environ.get("FALLBACK_POLL_INTERVAL", "10"))

    DOWNLOAD_DIRECTORY = Path(os.environ.get("DOWNLOAD_DIRECTORY", "/var/lib/haster-print"))
    LOG_LEVEL = os.environ.get("LOG_LEVEL", "INFO").upper()

    API_NAMESPACE = "lw16-dhl-print/v1"

    @classmethod
    def validate(cls) -> None:
        missing = [
            name
            for name, val in (
                ("WORDPRESS_URL", cls.WORDPRESS_URL),
                ("API_TOKEN", cls.API_TOKEN),
                ("PRINTER_ID", cls.PRINTER_ID),
            )
            if not val
        ]
        if missing:
            sys.exit(
                "Fehlende Konfiguration: %s. Bitte .env prüfen (siehe .env.example)."
                % ", ".join(missing)
            )


logging.basicConfig(
    level=getattr(logging, Config.LOG_LEVEL, logging.INFO),
    format="%(asctime)s %(levelname)-8s %(message)s",
)
log = logging.getLogger("haster-print")


def redact(text: str) -> str:
    """Token nie im Klartext loggen – auch nicht in einer Fehlermeldung,
    die zufällig die Autorisierungs-Kopfzeile enthält."""
    if not Config.API_TOKEN:
        return text
    return text.replace(Config.API_TOKEN, "***redacted***")


class ApiClient:
    """Kapselt alle HTTP-Aufrufe gegen die WordPress-REST-API."""

    def __init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Authorization": f"Bearer {Config.API_TOKEN}",
                "User-Agent": "haster-print-client/1.0",
            }
        )
        self.base = f"{Config.WORDPRESS_URL}/wp-json/{Config.API_NAMESPACE}"

    def get_jobs(self, wait: int) -> Optional[requests.Response]:
        """Ein einzelner Long-Poll-Request. Gibt None bei Netzwerkfehler
        zurück, sonst die rohe Antwort (200 mit Jobs oder 204 leer)."""
        try:
            return self.session.get(
                f"{self.base}/jobs",
                params={"printer_id": Config.PRINTER_ID, "wait": wait},
                # Etwas länger als der Server maximal wartet, damit ein
                # normaler 204-Timeout nicht schon als Client-Timeout gilt.
                timeout=Config.REQUEST_TIMEOUT,
            )
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei GET /jobs: %s", redact(str(exc)))
            return None

    def claim(self, job_id: int) -> bool:
        try:
            r = self.session.post(f"{self.base}/jobs/{job_id}/claim", timeout=15)
            return r.status_code == 200
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei claim(%s): %s", job_id, redact(str(exc)))
            return False

    def mark_printing(self, job_id: int) -> bool:
        try:
            r = self.session.post(f"{self.base}/jobs/{job_id}/printing", timeout=15)
            return r.status_code == 200
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei printing(%s): %s", job_id, redact(str(exc)))
            return False

    def complete(self, job_id: int) -> bool:
        """Darf beliebig oft wiederholt aufgerufen werden (Idempotenz)."""
        try:
            r = self.session.post(f"{self.base}/jobs/{job_id}/complete", timeout=15)
            return r.status_code == 200
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei complete(%s): %s", job_id, redact(str(exc)))
            return False

    def fail(self, job_id: int, message: str) -> bool:
        """Darf beliebig oft wiederholt aufgerufen werden (Idempotenz)."""
        try:
            r = self.session.post(
                f"{self.base}/jobs/{job_id}/failed",
                data={"message": message[:500]},
                timeout=15,
            )
            return r.status_code == 200
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei failed(%s): %s", job_id, redact(str(exc)))
            return False

    def download_file(self, job_id: int, target: Path) -> bool:
        try:
            with self.session.get(
                f"{self.base}/jobs/{job_id}/file", timeout=30, stream=True
            ) as r:
                if r.status_code != 200:
                    log.error("PDF-Download für Job %s fehlgeschlagen: HTTP %s", job_id, r.status_code)
                    return False
                target.parent.mkdir(parents=True, exist_ok=True)
                tmp = target.with_suffix(target.suffix + ".part")
                with open(tmp, "wb") as fh:
                    for chunk in r.iter_content(chunk_size=65536):
                        fh.write(chunk)
                tmp.replace(target)
                return True
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler beim PDF-Download (Job %s): %s", job_id, redact(str(exc)))
            return False


# ---------------------------------------------------------------------
# CUPS
# ---------------------------------------------------------------------


def print_via_cups(pdf_path: Path, width_mm: int, height_mm: int) -> tuple[bool, str]:
    """PDF unskaliert an CUPS übergeben.

    Es wird bewusst kein bestimmtes Druckermodell vorausgesetzt – nur,
    dass PRINTER_NAME als CUPS-Drucker eingerichtet ist. "fit-to-page"
    wird explizit ausgeschaltet, die Seitengröße wird als Custom-Media in
    Punkten (1 mm = 72/25.4 pt) übergeben, damit ein DHL-Rollenetikett
    1:1 herauskommt statt auf A4 verkleinert zu werden.
    """
    factor = 72 / 25.4
    media = f"Custom.{round(width_mm * factor)}x{round(height_mm * factor)}"

    cmd = [
        "lp",
        "-d",
        Config.PRINTER_NAME,
        "-o",
        f"media={media}",
        "-o",
        "fit-to-page=false",
        "-o",
        "scaling=100",
        "-o",
        "orientation-requested=3",
        str(pdf_path),
    ]

    try:
        result = subprocess.run(
            cmd, capture_output=True, text=True, timeout=60, check=False
        )
    except FileNotFoundError:
        return False, "CUPS-Kommando 'lp' nicht gefunden – ist CUPS installiert?"
    except subprocess.TimeoutExpired:
        return False, "CUPS-Aufruf hat zu lange gedauert (Timeout)."

    if result.returncode != 0:
        message = (result.stderr or result.stdout or "unbekannter CUPS-Fehler").strip()
        return False, message[:500]

    return True, result.stdout.strip()


def cups_printer_model() -> str:
    """Freitext-Beschreibung des CUPS-Druckers, nur zur Anzeige im Admin."""
    try:
        result = subprocess.run(
            ["lpstat", "-l", "-p", Config.PRINTER_NAME],
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        for line in result.stdout.splitlines():
            line = line.strip()
            if line.lower().startswith("description:"):
                return line.split(":", 1)[1].strip()
    except Exception:  # noqa: BLE001 - rein informativ, darf nie den Client stoppen
        pass
    return ""


# ---------------------------------------------------------------------
# Hauptschleife
# ---------------------------------------------------------------------


def process_job(api: ApiClient, job: dict) -> None:
    job_id = int(job["id"])
    filename = job.get("filename") or f"job-{job_id}.pdf"

    log.info("Job %s (Bestellung %s) verfügbar – claime …", job_id, job.get("order_id"))

    if not api.claim(job_id):
        # Ein anderer Pi war schneller, oder der Job ist nicht mehr
        # verfügbar. Kein Fehlerzustand – einfach weiter zum nächsten Poll.
        log.info("Job %s konnte nicht geclaimt werden (vermutlich schon vergeben).", job_id)
        return

    target = Config.DOWNLOAD_DIRECTORY / filename

    if not api.download_file(job_id, target):
        # PDF-Download fehlgeschlagen: Der Job bleibt bewusst NICHT als
        # "printed" markiert. Ein erneuter Versuch (nächster Poll, oder
        # nach Ablauf des Locks) kann ihn erneut claimen.
        log.error("PDF-Download für Job %s fehlgeschlagen – Job bleibt offen.", job_id)
        return

    api.mark_printing(job_id)

    ok, message = print_via_cups(target, int(job.get("width_mm", 103)), int(job.get("height_mm", 199)))

    if ok:
        log.info("Job %s gedruckt.", job_id)
        api.complete(job_id)
    else:
        log.error("Druck von Job %s fehlgeschlagen: %s", job_id, message)
        api.fail(job_id, message)


def run() -> None:
    Config.validate()
    Config.DOWNLOAD_DIRECTORY.mkdir(parents=True, exist_ok=True)

    api = ApiClient()
    backoff = 1

    log.info(
        "Haster-Print-Client gestartet: %s, Drucker-ID %s, Long-Poll=%s",
        Config.WORDPRESS_URL,
        Config.PRINTER_ID,
        Config.LONG_POLL_ENABLED,
    )

    while True:
        wait = Config.LONG_POLL_TIMEOUT if Config.LONG_POLL_ENABLED else 0
        response = api.get_jobs(wait)

        if response is None:
            # Netzwerkfehler: exponentieller Backoff, kein Datenverlust –
            # der Job bleibt in der Warteschlange, bis der Pi wieder da ist.
            log.info("Kein Kontakt zum Server – erneuter Versuch in %ss.", backoff)
            time.sleep(backoff)
            backoff = min(backoff * 2, Config.MAX_RETRY_DELAY)
            continue

        # Erfolgreiche Verbindung: Backoff zurücksetzen.
        backoff = 1

        if response.status_code == 200:
            try:
                jobs = response.json().get("jobs", [])
            except ValueError:
                log.error("Antwort war kein gültiges JSON.")
                jobs = []

            for job in jobs:
                process_job(api, job)

            # Sofort die nächste Long-Poll-Verbindung öffnen.
            continue

        if response.status_code == 204:
            # Timeout ohne Job: sofort erneut fragen (kein sleep – das
            # WAIT des Servers hat die Pause schon übernommen).
            if not Config.LONG_POLL_ENABLED:
                time.sleep(Config.FALLBACK_POLL_INTERVAL)
            continue

        if response.status_code == 401:
            log.error("Token abgelehnt (401). Bitte API_TOKEN in .env prüfen.")
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


if __name__ == "__main__":
    try:
        run()
    except KeyboardInterrupt:
        log.info("Beendet.")
