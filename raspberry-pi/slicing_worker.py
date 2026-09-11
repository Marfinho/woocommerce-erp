#!/usr/bin/env python3
"""
Haster/Lindenweg16 Headless-Slicing-Worker.

Holt angeordnete, aber noch ungeslicte Druckplatten (Projekt-3MF, aus der
Plattenanordnung in WordPress) über die Slicing-Warteschlange
(lw16-slicing/v1) ab, slict sie mit einem Kommandozeilen-Slicer (OrcaSlicer
oder Bambu Studio im CLI-Modus) anhand der hinterlegten Profile und lädt
das Ergebnis zurück hoch. WordPress reiht die fertige Druckdatei danach
automatisch in die Bambu-Warteschlange ein (siehe
Nesting\\SlicingService::forward_to_bambu_queue()) - ab dort läuft alles
wie ein manuell hochgeladener Druckauftrag weiter.

WICHTIGER VORBEHALT: Das Kommandozeilen-Aufruf-Muster in run_slicer()
(--load-settings für Drucker-/Prozessprofil, --load-filaments fürs
Filamentprofil, --slice, --export-3mf) folgt der öffentlich
dokumentierten OrcaSlicer-CLI
(https://github.com/OrcaSlicer/OrcaSlicer/wiki/cli_mode), bestätigt
gegen einen echten Slice-Lauf mit OrcaSlicer 2.4.2 (ein früherer Versuch
mit "--load" scheiterte live mit "Invalid option --load" - OrcaSlicer
kennt dieses Flag nicht). Kann sich trotzdem von Version zu Version
wieder ändern. Vor dem produktiven Einsatz unbedingt mit einer
einzelnen, unkritischen Platte von Hand gegenprüfen (Ergebnis-3MF in
Bambu Studio öffnen und die erzeugten Druckdaten ansehen), bevor ein
automatisch geslicter Auftrag ungeprüft auf einen echten Drucker geht.

Läuft wie die Bambu-Bridge standardmässig mit klassischem Intervall-
Polling (LONG_POLL_ENABLED=false) statt Long Polling - siehe dort für die
Begründung (manche Webhosting-Pakete kappen lang offen gehaltene
Verbindungen).

Aufruf:
    python3 slicing_worker.py

Konfiguration ausschliesslich über Umgebungsvariablen / .env.slicing
(siehe .env.slicing.example).
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


_load_dotenv(Path(__file__).resolve().parent / ".env.slicing")


def _env_bool(name: str, default: bool) -> bool:
    val = os.environ.get(name)
    if val is None:
        return default
    return val.strip().lower() in ("1", "true", "yes", "on")


class Config:
    """Alle Einstellungen an einer Stelle, mit sinnvollen Defaults."""

    WORDPRESS_URL = os.environ.get("WORDPRESS_URL", "").rstrip("/")
    API_TOKEN = os.environ.get("API_TOKEN", "")

    # Siehe Modulkommentar: Standard ist klassisches Intervall-Polling,
    # nicht Long Polling.
    LONG_POLL_ENABLED = _env_bool("LONG_POLL_ENABLED", False)
    LONG_POLL_TIMEOUT = int(os.environ.get("LONG_POLL_TIMEOUT", "25"))
    # SHORT_POLL_WAIT=0 (Standard) heißt "sofort antworten" - der Server
    # hält dafür keinen PHP-Worker samt Datenbankverbindung offen (siehe
    # SlicingJobRepository::clamp_wait() auf der PHP-Seite; live als
    # Performance-Problem bei der Bambu-Warteschlange aufgefallen, siehe
    # dortiger Fix). Die Pause zwischen den Anfragen übernimmt stattdessen
    # FALLBACK_POLL_INTERVAL unten.
    SHORT_POLL_WAIT = int(os.environ.get("SHORT_POLL_WAIT", "0"))
    FALLBACK_POLL_INTERVAL = int(os.environ.get("FALLBACK_POLL_INTERVAL", "5"))

    REQUEST_TIMEOUT = int(os.environ.get("REQUEST_TIMEOUT", "35"))
    MAX_RETRY_DELAY = int(os.environ.get("MAX_RETRY_DELAY", "30"))

    DOWNLOAD_DIRECTORY = Path(os.environ.get("DOWNLOAD_DIRECTORY", "/var/lib/slicing-worker"))
    LOG_LEVEL = os.environ.get("LOG_LEVEL", "INFO").upper()

    # Pfad zum Slicer-Kommandozeilenprogramm.
    SLICER_CLI = os.environ.get("SLICER_CLI", "/usr/bin/orca-slicer")

    # Wie lange ein einzelner Slice-Lauf höchstens dauern darf, bevor der
    # Worker ihn abbricht und als fehlgeschlagen meldet (Sekunden). Eine
    # sehr komplexe Mehrteile-Platte kann mehrere Minuten brauchen.
    SLICER_TIMEOUT = int(os.environ.get("SLICER_TIMEOUT", "600"))

    API_NAMESPACE = "lw16-slicing/v1"

    @classmethod
    def validate(cls) -> None:
        missing = [
            name
            for name, val in (
                ("WORDPRESS_URL", cls.WORDPRESS_URL),
                ("API_TOKEN", cls.API_TOKEN),
            )
            if not val
        ]
        if missing:
            sys.exit(
                "Fehlende Konfiguration: %s. Bitte .env.slicing prüfen "
                "(siehe .env.slicing.example)." % ", ".join(missing)
            )
        if not Path(cls.SLICER_CLI).is_file():
            log.warning(
                "SLICER_CLI (%s) wurde beim Start nicht gefunden - falls das kein "
                "Tippfehler ist, bitte den Pfad in .env.slicing prüfen.",
                cls.SLICER_CLI,
            )


logging.basicConfig(
    level=getattr(logging, "INFO", logging.INFO),
    format="%(asctime)s %(levelname)-8s %(message)s",
)
log = logging.getLogger("slicing-worker")
log.setLevel(getattr(logging, Config.LOG_LEVEL, logging.INFO))


def redact(text: str) -> str:
    """Token nie im Klartext loggen."""
    if not Config.API_TOKEN:
        return text
    return text.replace(Config.API_TOKEN, "***redacted***")


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
                "User-Agent": "slicing-worker/1.0",
            }
        )
        self.base = f"{Config.WORDPRESS_URL}/wp-json/{Config.API_NAMESPACE}"

    def get_jobs(self, wait: int) -> Optional[requests.Response]:
        try:
            return self.session.get(
                f"{self.base}/jobs",
                params={"wait": wait, "_": int(time.time() * 1000)},
                timeout=Config.REQUEST_TIMEOUT,
            )
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei GET /jobs: %s", redact(str(exc)))
            return None

    def _post(self, path: str, **kwargs) -> Optional[requests.Response]:
        try:
            return self.session.post(f"{self.base}/{path}", timeout=kwargs.pop("timeout", 15), **kwargs)
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler bei POST %s: %s", path, redact(str(exc)))
            return None

    def claim(self, job_id: int) -> bool:
        r = self._post(f"jobs/{job_id}/claim")
        return r is not None and r.status_code == 200

    def mark_slicing(self, job_id: int) -> None:
        self._post(f"jobs/{job_id}/slicing")

    def complete(self, job_id: int, result_path: Path) -> bool:
        try:
            with open(result_path, "rb") as fh:
                r = self.session.post(
                    f"{self.base}/jobs/{job_id}/complete",
                    files={"datei": (result_path.name, fh, "model/3mf")},
                    timeout=60,
                )
            return r.status_code == 200
        except (requests.RequestException, OSError) as exc:
            log.warning("Netzwerkfehler bei complete(%s): %s", job_id, redact(str(exc)))
            return False

    def fail(self, job_id: int, message: str) -> bool:
        r = self._post(f"jobs/{job_id}/failed", data={"message": message[:500]})
        return r is not None and r.status_code == 200

    def download_file(self, url: str, target: Path) -> bool:
        try:
            with self.session.get(url, timeout=60, stream=True) as r:
                if r.status_code != 200:
                    log.error("Download von %s fehlgeschlagen: HTTP %s", url, r.status_code)
                    return False
                target.parent.mkdir(parents=True, exist_ok=True)
                tmp = target.with_suffix(target.suffix + ".part")
                with open(tmp, "wb") as fh:
                    for chunk in r.iter_content(chunk_size=262144):
                        fh.write(chunk)
                tmp.replace(target)
                return True
        except requests.RequestException as exc:
            log.warning("Netzwerkfehler beim Download (%s): %s", url, redact(str(exc)))
            return False

    def download_job_file(self, job_id: int, target: Path) -> bool:
        return self.download_file(f"{self.base}/jobs/{job_id}/file", target)

    def download_profile(self, job_id: int, kind: str, target: Path) -> bool:
        return self.download_file(f"{self.base}/jobs/{job_id}/profile/{kind}", target)


# ---------------------------------------------------------------------
# Slicer-Aufruf
# ---------------------------------------------------------------------


def run_slicer(input_path: Path, output_path: Path, printer_json: Path, filament_json: Path, process_json: Path) -> tuple[bool, str]:
    """Slicer-CLI aufrufen. Siehe Modulkommentar zum Vorbehalt bezüglich
    der genauen Kommandozeile - je nach installierter Version kann ein
    anderer Aufruf nötig sein.

    "--load" existiert in OrcaSlicer nicht (live gemeldet: "Invalid
    option --load") - Drucker- und Prozessprofil werden zusammen über
    "--load-settings" (Semikolon-getrennt) geladen, das Filamentprofil
    separat über "--load-filaments" (siehe
    https://github.com/OrcaSlicer/OrcaSlicer/wiki/cli_mode).

    "--arrange 0" ist Pflicht, kein Optimum: ohne dieses Flag steht
    Arrange laut CLI-Doku auf "auto" (nicht "unverändert lassen") und
    würde die von PlateComposer/BinPacker bereits berechnete,
    kollisionsfreie Platzierung (siehe dortige Klassendoku) beim Slicen
    stillschweigend durch eine eigene Anordnung ersetzen können.
    """
    cmd = [
        Config.SLICER_CLI,
        str(input_path),
        "--load-settings", f"{printer_json};{process_json}",
        "--load-filaments", str(filament_json),
        "--arrange", "0",
        "--slice", "1",
        "--export-3mf", str(output_path),
    ]

    log.info("Starte Slicer: %s", " ".join(cmd))

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=Config.SLICER_TIMEOUT,
            check=False,
        )
    except FileNotFoundError:
        return False, f"Slicer-Programm nicht gefunden: {Config.SLICER_CLI}"
    except subprocess.TimeoutExpired:
        return False, f"Slicer hat das Zeitlimit ({Config.SLICER_TIMEOUT}s) überschritten."

    if result.returncode != 0 or not output_path.is_file():
        message = (result.stderr or result.stdout or "unbekannter Slicer-Fehler").strip()
        return False, message[:1000]

    return True, ""


# ---------------------------------------------------------------------
# Druckauftrag abwickeln
# ---------------------------------------------------------------------


def process_job(api: ApiClient, job: dict) -> None:
    job_id = int(job["id"])
    filename = job.get("filename") or f"plate-{job_id}.3mf"

    log.info("Slicing-Auftrag %s verfügbar – claime …", job_id)

    if not api.claim(job_id):
        log.info("Auftrag %s konnte nicht geclaimt werden (vermutlich schon vergeben).", job_id)
        time.sleep(2)
        return

    work_dir = Config.DOWNLOAD_DIRECTORY / f"job-{job_id}"
    work_dir.mkdir(parents=True, exist_ok=True)

    input_path = work_dir / filename
    printer_json = work_dir / "printer.json"
    filament_json = work_dir / "filament.json"
    process_json = work_dir / "process.json"
    output_path = work_dir / f"sliced-{filename}"

    ok = (
        api.download_job_file(job_id, input_path)
        and api.download_profile(job_id, "printer", printer_json)
        and api.download_profile(job_id, "filament", filament_json)
        and api.download_profile(job_id, "process", process_json)
    )

    if not ok:
        log.error("Download für Auftrag %s fehlgeschlagen – Auftrag bleibt offen.", job_id)
        return

    api.mark_slicing(job_id)

    slice_ok, message = run_slicer(input_path, output_path, printer_json, filament_json, process_json)

    if not slice_ok:
        log.error("Slicen für Auftrag %s fehlgeschlagen: %s", job_id, message)
        api.fail(job_id, message)
        return

    log.info("Auftrag %s geslict, lade Ergebnis hoch …", job_id)

    if api.complete(job_id, output_path):
        log.info("Auftrag %s abgeschlossen.", job_id)
    else:
        log.error("Ergebnis-Upload für Auftrag %s fehlgeschlagen.", job_id)
        api.fail(job_id, "Ergebnis-Upload nach erfolgreichem Slicen fehlgeschlagen.")


# ---------------------------------------------------------------------
# Hauptschleife
# ---------------------------------------------------------------------


def run() -> None:
    Config.validate()
    Config.DOWNLOAD_DIRECTORY.mkdir(parents=True, exist_ok=True)

    api = ApiClient()
    backoff = 1

    log.info(
        "Slicing-Worker gestartet: %s, Long-Poll=%s, Slicer=%s.",
        Config.WORDPRESS_URL,
        Config.LONG_POLL_ENABLED,
        Config.SLICER_CLI,
    )

    while True:
        wait = Config.LONG_POLL_TIMEOUT if Config.LONG_POLL_ENABLED else Config.SHORT_POLL_WAIT
        response = api.get_jobs(wait)

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
                process_job(api, job)

            continue

        if response.status_code == 204:
            if not Config.LONG_POLL_ENABLED:
                time.sleep(Config.FALLBACK_POLL_INTERVAL)
            continue

        if response.status_code == 401:
            log.error("Token abgelehnt (401). Bitte API_TOKEN in .env.slicing prüfen.")
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
