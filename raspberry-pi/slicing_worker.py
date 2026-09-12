#!/usr/bin/env python3
"""
Haster/Lindenweg16 Headless-Slicing-Worker.

Holt angeordnete, aber noch ungeslicte Druckplatten (Projekt-3MF, aus der
Plattenanordnung in WordPress) über die Slicing-Warteschlange
(lw16-slicing/v1) ab, slict sie mit OrcaSlicer im CLI-Modus anhand der
hinterlegten Profile und lädt das Ergebnis zurück hoch. WordPress reiht
die fertige Druckdatei danach automatisch in die Bambu-Warteschlange ein
(siehe Nesting\\SlicingService::forward_to_bambu_queue()) - ab dort läuft
alles wie ein manuell hochgeladener Druckauftrag weiter.

Slicer-Aufruf (siehe run_slicer() und SLICER_ARGS_TEMPLATE in
.env.slicing.example): Das Muster folgt der OrcaSlicer-CLI ab Version 2.x
(https://github.com/OrcaSlicer/OrcaSlicer/wiki/cli_mode) und wurde gegen
das Verhalten von OrcaSlicer 2.4.2 abgeglichen:

  * "--load-settings printer.json;process.json" lädt Drucker- UND
    Prozessprofil (Semikolon-getrennt), "--load-filaments filament.json"
    das Filamentprofil. Ein früherer Aufruf mit "--load" scheiterte live
    mit "Invalid option --load" - dieses Flag kennt OrcaSlicer nicht.
  * "--allow-newer-file": Die zusammengesetzte Platte trägt die
    Versionskennung der Bambu-Studio-Projektdatei, aus der sie stammt.
    Ist die neuer als die OrcaSlicer-Version des Workers, bricht der
    Slicer ohne dieses Flag mit Code -24 (CLI_FILE_VERSION_NOT_SUPPORTED)
    ab - live so passiert.
  * "--outputdir": Dorthin schreibt OrcaSlicer neben der Ausgabedatei
    eine result.json mit Fehlercode und Klartext-Grund. Die wird hier
    ausgewertet, damit in WordPress ein verständlicher Fehler steht statt
    nur "run found error, return -24". Achtung: OrcaSlicer hängt
    --outputdir stumpf vor den --export-3mf-Pfad, deshalb wird dort nur
    der Dateiname übergeben, kein absoluter Pfad.
  * "--slice 1": Die Plattenanordnung legt alles auf Platte 1, und die
    Bambu-Bridge startet später genau Metadata/plate_1.gcode.
  * "--arrange 1": OrcaSlicer selbst endgültig anordnen lassen, statt der
    Platzierung aus der WordPress-Plattenanordnung (PlateComposer/
    BinPacker) zu vertrauen. Grund für den Wechsel von ursprünglich
    "--arrange 0": BinPacker kennt nur das reine Bettrechteck, nicht die
    Sperrflächen des Druckerprofils ("bed_exclude_area", z. B. eine
    gesperrte Bettecke) - OrcaSlicer selbst schon, und lehnte deshalb
    live wiederholt mit Code -64 ("too close to exclusion area") ab,
    obwohl die eigene Anordnung nichts beanstandet hatte. Ausserdem live
    beobachtet: zwei Bauteile ineinander platziert (ein Fehler in der
    Anordnungslogik selbst). OrcaSlicer prüft Sperrflächen UND
    Kollisionen bereits korrekt, wenn es selbst anordnet - PlateComposer
    liefert weiterhin eine sinnvolle Ausgangslage (u. a. fürs
    Gewicht/Kosten-Vorabschätzen in WordPress), die tatsächliche
    Platzierung im Slice-Ergebnis kommt aber von OrcaSlicer.

Vor dem produktiven Einsatz unbedingt mit einer einzelnen, unkritischen
Platte von Hand gegenprüfen (Ergebnis-3MF in Bambu Studio öffnen und die
erzeugten Druckdaten ansehen), bevor ein automatisch geslicter Auftrag
ungeprüft auf einen echten Drucker geht.

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

import json
import logging
import os
import re
import shlex
import shutil
import signal
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


# Standard-Aufrufvorlage, siehe Modulkommentar. Platzhalter:
#   {input}     absoluter Pfad der zu slicenden Projekt-3MF
#   {printer}   absoluter Pfad des Druckerprofils (JSON)
#   {process}   absoluter Pfad des Prozessprofils (JSON)
#   {filament}  absoluter Pfad des Filamentprofils (JSON)
#   {output}    DATEINAME (ohne Pfad) der Ergebnis-3MF - landet in {workdir}
#   {workdir}   absoluter Pfad des Arbeitsverzeichnisses dieses Auftrags
#   {logfile}   absoluter Pfad der Slicer-Logdatei im Arbeitsverzeichnis
# Die Vorlage wird wie eine Kommandozeile in Einzelargumente zerlegt und
# erst danach werden die Platzhalter eingesetzt - Pfade mit Leerzeichen
# bleiben dadurch ein Argument.
DEFAULT_SLICER_ARGS_TEMPLATE = (
    "{input} "
    "--load-settings {printer};{process} "
    "--load-filaments {filament} "
    "--arrange 1 "
    "--slice 1 "
    "--allow-newer-file "
    "--outputdir {workdir} "
    "--export-3mf {output} "
    "--debug 2 "
    "--logfile {logfile}"
)


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

    # Aufrufvorlage, siehe DEFAULT_SLICER_ARGS_TEMPLATE. Nur anpassen,
    # wenn eine andere OrcaSlicer-Version andere Flags braucht.
    SLICER_ARGS_TEMPLATE = os.environ.get("SLICER_ARGS_TEMPLATE", "").strip() or DEFAULT_SLICER_ARGS_TEMPLATE

    # Wie lange ein einzelner Slice-Lauf höchstens dauern darf, bevor der
    # Worker ihn abbricht und als fehlgeschlagen meldet (Sekunden). Eine
    # sehr komplexe Mehrteile-Platte kann mehrere Minuten brauchen.
    SLICER_TIMEOUT = int(os.environ.get("SLICER_TIMEOUT", "600"))

    # Wie oft ein Download (Platte, Profile) bei Netzfehlern wiederholt
    # wird, bevor der Auftrag als fehlgeschlagen gemeldet wird.
    DOWNLOAD_ATTEMPTS = int(os.environ.get("DOWNLOAD_ATTEMPTS", "3"))

    # Arbeitsverzeichnisse erfolgreicher Aufträge werden sofort gelöscht;
    # die fehlgeschlagener Aufträge bleiben so viele Tage zur Diagnose
    # liegen (Slicer-Log, result.json, Eingabedateien). 0 = sofort löschen.
    KEEP_FAILED_JOBS_DAYS = int(os.environ.get("KEEP_FAILED_JOBS_DAYS", "7"))

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
        try:
            build_slicer_command(
                cls.SLICER_ARGS_TEMPLATE,
                cls.SLICER_CLI,
                input_path=Path("/x/in.3mf"),
                output_name="out.3mf",
                printer_json=Path("/x/printer.json"),
                filament_json=Path("/x/filament.json"),
                process_json=Path("/x/process.json"),
                work_dir=Path("/x"),
                logfile=Path("/x/slicer.log"),
            )
        except (KeyError, ValueError, IndexError) as exc:
            sys.exit(
                "SLICER_ARGS_TEMPLATE in .env.slicing ist ungültig (%s). Erlaubte "
                "Platzhalter: {input} {printer} {process} {filament} {output} "
                "{workdir} {logfile}." % exc
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
                "User-Agent": "slicing-worker/1.1",
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
                    timeout=120,
                )
            if r.status_code != 200:
                log.error("complete(%s) abgelehnt: HTTP %s %s", job_id, r.status_code, redact(r.text[:300]))
            return r.status_code == 200
        except (requests.RequestException, OSError) as exc:
            log.warning("Netzwerkfehler bei complete(%s): %s", job_id, redact(str(exc)))
            return False

    def fail(self, job_id: int, message: str) -> bool:
        r = self._post(f"jobs/{job_id}/failed", data={"message": message[:1000]})
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
        except (requests.RequestException, OSError) as exc:
            log.warning("Netzwerkfehler beim Download (%s): %s", url, redact(str(exc)))
            return False

    def download_with_retry(self, url: str, target: Path) -> bool:
        """Download mit einigen Wiederholungen - ein kurzer Netz-Schluckauf
        soll nicht gleich den ganzen Auftrag kosten."""
        attempts = max(1, Config.DOWNLOAD_ATTEMPTS)
        for attempt in range(1, attempts + 1):
            if self.download_file(url, target):
                return True
            if attempt < attempts:
                time.sleep(3 * attempt)
        return False

    def download_job_file(self, job_id: int, target: Path) -> bool:
        return self.download_with_retry(f"{self.base}/jobs/{job_id}/file", target)

    def download_profile(self, job_id: int, kind: str, target: Path) -> bool:
        return self.download_with_retry(f"{self.base}/jobs/{job_id}/profile/{kind}", target)


# ---------------------------------------------------------------------
# Profile auflösen
# ---------------------------------------------------------------------
#
# Ein aus OrcaSlicer/Bambu Studio exportiertes Benutzerprofil ist meist nur
# eine Differenzdatei: "from": "User", "inherits": "<Systemprofil>" und die
# paar geänderten Werte. Der OrcaSlicer-CLI löst so ein "inherits" NUR auf,
# wenn die Datei in seinem eigenen Profilverzeichnis liegt - eine lose
# übergebene Datei lehnt er mit "Preset was not found in the loaded bundle"
# ab (Rückgabecode -5, live so passiert). Ein aufgelöstes Profil OHNE
# "inherits" und mit "from": "User" scheitert danach an der
# Kompatibilitätsprüfung (-17), weil der CLI den Systemnamen des Druckers
# gegen die Liste "compatible_printers" des Prozessprofils vergleicht und
# ein Benutzerprofil ohne "inherits" keinen Systemnamen hat.
#
# Deshalb macht der Worker es so, wie OrcaSlicer seine Herstellerprofile
# liest: Er löst die Vererbungskette gegen die in der AppImage mitgelieferten
# Systemprofile (resources/profiles/<Hersteller>/...) selbst auf, schreibt je
# Auftrag ein kleines eigenes Herstellerpaket (Manifest LW16.json plus
# machine/filament/process-Dateien mit "from": "system", ohne "inherits")
# und trägt den Druckernamen in "compatible_printers" von Filament- und
# Prozessprofil ein. Schlüssel, die die OrcaSlicer-Version im Worker nicht
# kennt, verwirft der CLI beim Laden von selbst.

PROFILE_TYPES = {
    "machine": ("machine_list", "printer"),
    "filament": ("filament_list", "filament"),
    "process": ("process_list", "process"),
}
META_KEYS = ("inherits", "instantiation", "from", "type", "name", "version")


def find_profiles_dir() -> Optional[Path]:
    """Verzeichnis mit den Herstellerpaketen (BBL.json, BBL/...) finden."""
    candidates = []
    if os.environ.get("ORCA_PROFILES_DIR"):
        candidates.append(Path(os.environ["ORCA_PROFILES_DIR"]))
    candidates += [
        Path("/opt/orca-profiles"),
        Path("/opt/orcaslicer/resources/profiles"),
        Path("/opt/orcaslicer/usr/resources/profiles"),
        Path("/opt/orcaslicer/usr/share/OrcaSlicer/profiles"),
    ]
    try:
        cli = Path(Config.SLICER_CLI).resolve()
        candidates.append(cli.parent.parent / "resources" / "profiles")
    except OSError:
        pass
    for path in candidates:
        if path.is_dir() and any(path.glob("*.json")):
            return path
    return None


def load_vendor_index(profiles_dir: Path) -> dict:
    """Profilname -> Datei, je Typ, über alle Herstellermanifeste."""
    index = {"machine": {}, "filament": {}, "process": {}, "machine_model": {}}
    manifests = sorted(profiles_dir.glob("*.json"), key=lambda p: (p.stem == "OrcaFilamentLibrary", p.name))
    for manifest in manifests:
        try:
            data = json.loads(manifest.read_text(encoding="utf-8", errors="replace"))
        except (OSError, ValueError):
            continue
        if not isinstance(data, dict):
            continue
        vendor_dir = profiles_dir / manifest.stem
        lists = {"machine": "machine_list", "filament": "filament_list", "process": "process_list", "machine_model": "machine_model_list"}
        for kind, list_key in lists.items():
            for entry in data.get(list_key, []) or []:
                if isinstance(entry, dict) and entry.get("name") and entry.get("sub_path"):
                    index[kind].setdefault(entry["name"], vendor_dir / entry["sub_path"])
    return index


def _read_json(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8", errors="replace"))
    if not isinstance(data, dict):
        raise ValueError(f"{path.name}: kein JSON-Objekt")
    return data


def flatten_profile(user_json: dict, kind: str, index: dict) -> dict:
    """Vererbungskette eines Profils auflösen: Wurzel zuerst, jede Ebene
    überschreibt die Werte der vorigen (dieselbe Regel wie OrcaSlicers
    PresetBundle::load_vendor_preset)."""
    chain = [user_json]
    seen = {user_json.get("name", "")}
    parent = user_json.get("inherits", "")
    while parent:
        if parent in seen:
            raise ValueError(f"Vererbungsschleife bei '{parent}'")
        seen.add(parent)
        path = index[kind].get(parent)
        if path is None or not path.is_file():
            # Vertauschte Dateien erkennen: Ein exportiertes Benutzerprofil
            # traegt oft keinen "type"-Schluessel, das Elternprofil verraet
            # aber, was es ist.
            for other_kind, other_index in index.items():
                if other_kind != kind and other_kind in PROFILE_TYPES and parent in other_index:
                    raise ValueError(
                        f"als {PROFILE_TYPES[kind][1]}-Profil wurde ein {PROFILE_TYPES[other_kind][1]}-Profil "
                        f"hochgeladen (es erbt von '{parent}') - Drucker-, Filament- und Prozessdatei vertauscht?"
                    )
            raise ValueError(
                f"das {PROFILE_TYPES[kind][1]}-Profil erbt von '{parent}', dieses Systemprofil "
                "kennt die OrcaSlicer-Version im Worker nicht (Profil in OrcaSlicer neu "
                "exportieren oder Worker mit neuerer OrcaSlicer-Version bauen)"
            )
        node = _read_json(path)
        chain.append(node)
        parent = node.get("inherits", "")

    merged: dict = {}
    for level in reversed(chain):
        for key, value in level.items():
            if key in ("inherits", "instantiation"):
                continue
            merged[key] = value

    merged["type"] = kind
    merged["name"] = user_json.get("name") or merged.get("name") or PROFILE_TYPES[kind][1]
    merged["from"] = "system"
    merged["instantiation"] = "true"
    merged.pop("inherits", None)
    return merged


def prepare_profile_bundle(work_dir: Path, printer_json: Path, filament_json: Path, process_json: Path) -> tuple[Path, Path, Path]:
    """Die drei hochgeladenen Profile in ein eigenes Herstellerpaket im
    Arbeitsverzeichnis umsetzen (siehe Abschnittskommentar). Liefert die
    Pfade, die an --load-settings/--load-filaments gehen."""
    sources = {"machine": printer_json, "filament": filament_json, "process": process_json}
    raw = {}
    for kind, path in sources.items():
        try:
            raw[kind] = _read_json(path)
        except (OSError, ValueError) as exc:
            raise ValueError(f"{PROFILE_TYPES[kind][1]}-Profil ist kein lesbares JSON: {exc}") from exc
        declared = str(raw[kind].get("type") or "").lower()
        if declared and declared != kind:
            raise ValueError(
                f"als {PROFILE_TYPES[kind][1]}-Profil wurde eine Datei vom Typ '{declared}' hochgeladen "
                "(Drucker-, Filament- und Prozessprofil vertauscht?)"
            )

    needs_resolve = any(r.get("inherits") for r in raw.values())
    index = None
    if needs_resolve:
        profiles_dir = find_profiles_dir()
        if profiles_dir is None:
            raise ValueError(
                "die Profile erben von OrcaSlicer-Systemprofilen, aber das Profilverzeichnis "
                "der OrcaSlicer-Installation wurde nicht gefunden (ORCA_PROFILES_DIR in .env.slicing setzen)"
            )
        index = load_vendor_index(profiles_dir)
        log.info("Löse Profil-Vererbung gegen %s auf.", profiles_dir)

    flat = {}
    for kind, data in raw.items():
        flat[kind] = flatten_profile(data, kind, index) if index is not None else dict(data)
        if index is None:
            flat[kind].setdefault("type", kind)

    printer_name = str(flat["machine"].get("name") or "printer")
    for kind in ("filament", "process"):
        existing = flat[kind].get("compatible_printers") or []
        if not isinstance(existing, list):
            existing = [str(existing)]
        flat[kind]["compatible_printers"] = list(dict.fromkeys([str(p) for p in existing] + [printer_name]))

    machine = flat["machine"]
    if not machine.get("printer_variant"):
        nozzles = machine.get("nozzle_diameter") or []
        machine["printer_variant"] = str(nozzles[0]) if isinstance(nozzles, list) and nozzles else "0.4"
    model_name = str(machine.get("printer_model") or "")
    if not model_name:
        model_name = printer_name
        machine["printer_model"] = model_name

    bundle_root = work_dir / "profiles"
    vendor = "LW16"
    vendor_dir = bundle_root / vendor
    for sub in ("machine", "filament", "process"):
        (vendor_dir / sub).mkdir(parents=True, exist_ok=True)

    model = None
    if index is not None and index["machine_model"].get(model_name) and index["machine_model"][model_name].is_file():
        try:
            model = _read_json(index["machine_model"][model_name])
        except (OSError, ValueError):
            model = None
    if model is None:
        model = {
            "type": "machine_model",
            "name": model_name,
            "model_id": model_name,
            "nozzle_diameter": str(machine["printer_variant"]),
            "machine_tech": "FFF",
            "family": vendor,
        }
    # Der Lader prüft, dass die Düsenvariante des Druckers zum Modell passt.
    variants = [v for v in str(model.get("nozzle_diameter", "")).split(";") if v]
    if str(machine["printer_variant"]) not in variants:
        variants.append(str(machine["printer_variant"]))
    model["nozzle_diameter"] = ";".join(variants)

    files = {
        "model": vendor_dir / "machine" / "model.json",
        "machine": vendor_dir / "machine" / "printer.json",
        "filament": vendor_dir / "filament" / "filament.json",
        "process": vendor_dir / "process" / "process.json",
    }
    files["model"].write_text(json.dumps(model, ensure_ascii=False, indent=1), encoding="utf-8")
    for kind in ("machine", "filament", "process"):
        files[kind].write_text(json.dumps(flat[kind], ensure_ascii=False, indent=1), encoding="utf-8")

    manifest = {
        "name": "Lindenweg16",
        "version": "01.00.00.00",
        "description": "Vom Slicing-Worker je Auftrag erzeugtes Profilpaket",
        "machine_model_list": [{"name": model_name, "sub_path": "machine/model.json"}],
        "machine_list": [{"name": printer_name, "sub_path": "machine/printer.json"}],
        "filament_list": [{"name": str(flat["filament"].get("name") or "filament"), "sub_path": "filament/filament.json"}],
        "process_list": [{"name": str(flat["process"].get("name") or "process"), "sub_path": "process/process.json"}],
    }
    (bundle_root / f"{vendor}.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=1), encoding="utf-8")

    return files["machine"], files["filament"], files["process"]


# Schluessel aus Metadata/project_settings.config der Platte, die keinem
# Profil zugeordnet sind, aber zum Projekt gehoeren und erhalten bleiben
# muessen (Betttyp, Filamentfarben und -zuordnung, Spuelvolumen,
# Wischturmposition, Presetnamen).
PROJECT_LEVEL_KEYS = frozenset({
    "curr_bed_type", "filament_colour", "filament_map", "filament_map_mode", "filament_self_index",
    "filament_ids", "flush_volumes_matrix", "flush_volumes_vector", "flush_multiplier",
    "wipe_tower_x", "wipe_tower_y", "printer_settings_id", "print_settings_id",
    "filament_settings_id", "inherits_group", "different_settings_to_system",
    "version", "from", "name", "is_custom_defined",
    "first_layer_print_sequence", "other_layers_print_sequence", "other_layers_print_sequence_nums",
})
PROJECT_CONFIG_ENTRY = "Metadata/project_settings.config"

# Diese drei PROJECT_LEVEL_KEYS sind selbst wieder pro-AMS-Filament-Slot-Listen
# (welcher Slot welches Filament/welche Farbe hat) - stehen aber nicht in
# printer.json/filament.json/process.json und werden deshalb von der
# Profil-Ueberschreibung in sanitize_project_config() gar nicht erst
# beruehrt. Bei einer aus einem mehrfarbigen Projekt stammenden Platte, die
# jetzt nur noch ein Filament laedt, muessen sie trotzdem auf genau einen
# Eintrag eingedampft werden - sonst bleibt z. B. filament_map=[1,2,3,4,5]
# stehen und der Slicer/Drucker erwartet eine AMS-Zuordnungstabelle mit
# fuenf Slots statt einem.
PER_FILAMENT_LIST_KEYS = ("filament_map", "filament_ids", "filament_colour")


def sanitize_project_config(input_path: Path, profile_paths: tuple[Path, ...]) -> list[str]:
    """Metadata/project_settings.config der Platte auf die Schluessel der
    drei Profile plus PROJECT_LEVEL_KEYS eindampfen.

    Die zusammengesetzte Platte traegt die Projekteinstellungen der
    Bambu-Studio-Datei, aus der das erste Bauteil stammt - komplett, mit
    allen Werten jener (oft neueren) Studio-Version. Beim Slicen ueberschreibt
    der CLI davon nur die Schluessel, die in den geladenen Profilen
    vorkommen; der Rest bleibt stehen und geht durch validate(). Live so
    gescheitert: Bambu Studio 2.5 schreibt "-1" (= automatisch) fuer
    raft_first_layer_expansion und tree_support_wall_count, OrcaSlicer 2.4.2
    erlaubt dort nur >= 0 (Code -18). Alles, was kein Profil setzt, soll
    deshalb aus dem Standard der installierten OrcaSlicer-Version kommen -
    das ist auch, was die GUI bei "neues Projekt mit diesen Profilen" taete.

    Jeder aus einem der drei Profile stammende Schluessel wird mit dem
    Wert AUS DEM PROFIL ueberschrieben, statt den (moeglicherweise
    veralteten) Wert aus der Projektdatei nur zu uebernehmen. Bambu Studio
    spiegelt praktisch jede Filamentprofil-Einstellung (Betttemperatur,
    Duesentemperatur, Filamenttyp, Luefterkurven, ...) als Liste mit
    einem Eintrag je frueher verwendetem AMS-Filament-Slot in der
    Projektdatei - stammt die Platte aus einem mehrfarbigen Projekt,
    bleiben dort mehrere, teils widerspruechliche Werte stehen (live so
    gefunden: hot_plate_temp=[60,55,55,55,90], gemischt PLA/ABS, bei
    nozzle_temperature sogar zehn statt fuenf Eintraege - pro
    Duesen-Variante verdoppelt). Nur das erste Element dieser Listen zu
    uebernehmen waere selbst dann noch geraten (stimmt zufaellig, wenn
    Slot 0 historisch das gewuenschte Material war) UND riskant fuers
    Erraten anhand der Listenlaenge (z. B. printable_area/
    print_compatible_printers sind ebenfalls Listen mit mehreren
    Eintraegen, haben aber nichts mit der Filamentanzahl zu tun - blind
    kuerzen wuerde dort die Druckbett-Geometrie zerstoeren). Stattdessen:
    jeder Schluessel, den eines der drei geladenen Profile selbst setzt,
    ist per Definition schon korrekt fuer genau dieses eine Filament -
    diesen Wert direkt uebernehmen macht Raten ueberfluessig.
    Reihenfolge printer -> filament -> process, damit bei (unerwarteter)
    Ueberschneidung das speziellste Profil zuletzt gewinnt.

    Liefert die entfernten Schluessel (fuer das Log)."""
    import tempfile
    import zipfile

    profile_data: dict = {}
    for path in profile_paths:
        try:
            profile_data.update(_read_json(path))
        except (OSError, ValueError):
            pass
    allowed = set(PROJECT_LEVEL_KEYS) | set(profile_data.keys())

    try:
        with zipfile.ZipFile(input_path) as zin:
            names = zin.namelist()
            if PROJECT_CONFIG_ENTRY not in names:
                return []
            try:
                config = json.loads(zin.read(PROJECT_CONFIG_ENTRY).decode("utf-8", errors="replace"))
            except ValueError:
                return []
            if not isinstance(config, dict):
                return []
            dropped = sorted(k for k in config if k not in allowed)
            cleaned = {k: v for k, v in config.items() if k in PROJECT_LEVEL_KEYS}

            truncated = []
            for key in PER_FILAMENT_LIST_KEYS:
                value = cleaned.get(key)
                if isinstance(value, list) and len(value) > 1:
                    cleaned[key] = value[:1]
                    truncated.append(key)

            overwritten = sorted(k for k, v in profile_data.items() if config.get(k) != v)
            cleaned.update(profile_data)

            if not dropped and not overwritten and not truncated:
                return []

            if truncated:
                log.info(
                    "Projekteinstellungen der Platte: AMS-Zuordnung %s auf ein Filament eingedampft (Platte stammte aus einem mehrfarbigen Projekt).",
                    ", ".join(truncated),
                )

            if overwritten:
                log.info(
                    "Projekteinstellungen der Platte: %d Einstellung(en) durch die geladenen Profile ersetzt (waren fuer mehrere Filamente/eine andere Version gedacht), u. a. %s%s.",
                    len(overwritten),
                    ", ".join(overwritten[:12]),
                    ", …" if len(overwritten) > 12 else "",
                )

            tmp_fd, tmp_name = tempfile.mkstemp(prefix="plate-", suffix=".3mf", dir=str(input_path.parent))
            os.close(tmp_fd)
            with zipfile.ZipFile(tmp_name, "w", zipfile.ZIP_DEFLATED) as zout:
                for item in zin.infolist():
                    if item.filename == PROJECT_CONFIG_ENTRY:
                        zout.writestr(item, json.dumps(cleaned, ensure_ascii=False, indent=4))
                    else:
                        zout.writestr(item, zin.read(item.filename))
        Path(tmp_name).replace(input_path)
        return dropped
    except (OSError, zipfile.BadZipFile) as exc:
        log.warning("Projekteinstellungen der Platte konnten nicht bereinigt werden: %s", exc)
        return []


MODEL_CONFIG_ENTRY = "Metadata/model_settings.config"


def sanitize_model_settings(input_path: Path) -> int:
    """Metadata/model_settings.config auf ein einziges Filament eindampfen
    - das Gegenstueck zu sanitize_project_config() fuer die Objekt-Ebene.

    Ein <object>/<part> traegt dort ein eigenes "extruder"-Metadata (z. B.
    value="3"), wenn das Bauteil im Original-Bambu-Studio-Projekt einem
    bestimmten AMS-Filament-Slot zugewiesen war. Bleibt das stehen,
    waehrend das Projekt jetzt (siehe sanitize_project_config()) nur noch
    ein Filament kennt, verweist das Bauteil auf einen nicht existenten
    Extruder - live so beobachtet: viele "variant index out of range"-
    Warnungen beim Laden, gefolgt von std::bad_alloc beim eigentlichen
    Slicen (OrcaSlicer 2.4.2 verkraftet dieses Missverhaeltnis offenbar
    nicht). Alle extruder-Werte auf "1" setzen; die Platten-weiten
    "filament_maps"/"filament_volume_maps" (ein Wert je Filament-Slot,
    leerzeichengetrennt) ebenso auf ihren ersten Wert eindampfen.

    Liefert die Anzahl geaenderter Werte (0 = nichts zu tun, Datei bleibt
    unangetastet)."""
    import tempfile
    import xml.etree.ElementTree as ET
    import zipfile

    try:
        with zipfile.ZipFile(input_path) as zin:
            if MODEL_CONFIG_ENTRY not in zin.namelist():
                return 0
            xml_bytes = zin.read(MODEL_CONFIG_ENTRY)
    except (OSError, zipfile.BadZipFile) as exc:
        log.warning("Metadata/model_settings.config konnte nicht gelesen werden: %s", exc)
        return 0

    try:
        root = ET.fromstring(xml_bytes)
    except ET.ParseError as exc:
        log.warning("Metadata/model_settings.config ist kein gueltiges XML: %s", exc)
        return 0

    changed = 0
    for metadata in root.iter("metadata"):
        key = metadata.get("key")
        value = metadata.get("value")
        if value is None:
            continue
        if key == "extruder" and value != "1":
            metadata.set("value", "1")
            changed += 1
        elif key in ("filament_maps", "filament_volume_maps"):
            first = value.split()[:1]
            eingedampft = first[0] if first else value
            if eingedampft != value:
                metadata.set("value", eingedampft)
                changed += 1

    if not changed:
        return 0

    log.info(
        "Metadata/model_settings.config: %d Extruder-/Filament-Zuordnung(en) auf Filament 1 eingedampft.",
        changed,
    )

    tmp_fd, tmp_name = tempfile.mkstemp(prefix="plate-", suffix=".3mf", dir=str(input_path.parent))
    os.close(tmp_fd)
    try:
        with zipfile.ZipFile(input_path) as zin, zipfile.ZipFile(tmp_name, "w", zipfile.ZIP_DEFLATED) as zout:
            for item in zin.infolist():
                if item.filename == MODEL_CONFIG_ENTRY:
                    zout.writestr(item, ET.tostring(root, encoding="unicode", xml_declaration=True))
                else:
                    zout.writestr(item, zin.read(item.filename))
        Path(tmp_name).replace(input_path)
    except (OSError, zipfile.BadZipFile) as exc:
        log.warning("Metadata/model_settings.config konnte nicht geschrieben werden: %s", exc)
        try:
            os.unlink(tmp_name)
        except OSError:
            pass
        return 0

    return changed


# ---------------------------------------------------------------------
# Slicer-Aufruf
# ---------------------------------------------------------------------

# Rückgabecodes der OrcaSlicer-CLI (src/libslic3r/Utils.hpp, CLI_*) mit
# einer Erklärung, die dem Bediener in WordPress weiterhilft. Nicht
# aufgeführte Codes werden nur mit Nummer und dem englischen Klartext
# aus result.json gemeldet.
ORCA_EXIT_HINTS = {
    -1: "Slicer-Umgebung konnte nicht eingerichtet werden (Anzeige/OpenGL im Container prüfen)",
    -2: "ungültige Aufrufparameter - SLICER_ARGS_TEMPLATE passt nicht zur installierten OrcaSlicer-Version",
    -3: "Eingabedatei nicht gefunden",
    -5: "eines der drei Profile (Drucker/Filament/Prozess) ist ungültig oder kann nicht aufgelöst werden - Profil in OrcaSlicer neu exportieren",
    -6: "die Projekt-3MF der Platte lässt sich nicht lesen",
    -7: "Profil ist kein FDM-Profil",
    -13: "Ergebnis-3MF konnte nicht geschrieben werden",
    -14: "Speicher reicht nicht (Geometrie zu fein aufgelöst)",
    -15: "Druckerwechsel wird für diese 3MF nicht unterstützt",
    -16: "Druckerprofil passt nicht zur 3MF",
    -17: "Prozessprofil passt nicht zum Druckerprofil",
    -18: "ungültige Parameterwerte in der 3MF",
    -20: "Druckbett des Druckerprofils ist kleiner als das der Platte",
    -21: "automatisches Anordnen fehlgeschlagen",
    -24: "3MF stammt aus einer neueren Bambu-Studio-/OrcaSlicer-Version - '--allow-newer-file' fehlt im Aufruf",
    -50: "Platte ist leer oder ein Bauteil liegt nicht vollständig im Druckraum (Druckbettmass des Druckers und Sicherheitsabstand prüfen)",
    -51: "ungültige Slicing-Parameter im Profil",
    -52: "Bauteil ragt über den Druckbettrand hinaus",
    -58: "Slicen hat das Zeitlimit des Slicers überschritten",
    -59: "zu viele Dreiecke auf der Platte (Geometrie vereinfachen)",
    -61: "Filamentprofil passt nicht zum Druckbett-Typ",
    -62: "Filamente mit unverträglichen Temperaturen",
    -63: "Kollision bei sequenziellem Druck",
    -64: "Kollision im Layer-Druck",
    -66: "Filamente konnten nicht zugeordnet werden",
    -100: "Fehler beim Slicen",
    -101: "G-Code-Pfadkonflikte erkannt",
    -102: "G-Code-Pfad im nicht bedruckbaren Bereich",
}


def build_slicer_command(
    template: str,
    slicer_cli: str,
    *,
    input_path: Path,
    output_name: str,
    printer_json: Path,
    filament_json: Path,
    process_json: Path,
    work_dir: Path,
    logfile: Path,
) -> list[str]:
    """Aufrufvorlage in eine Argumentliste umsetzen (siehe
    DEFAULT_SLICER_ARGS_TEMPLATE). Erst zerlegen, dann Platzhalter
    einsetzen - so bleibt ein Pfad mit Leerzeichen ein einziges Argument
    und '{printer};{process}' ein einziges Semikolon-Argument."""
    values = {
        "input": str(input_path),
        "output": output_name,
        "printer": str(printer_json),
        "filament": str(filament_json),
        "process": str(process_json),
        "workdir": str(work_dir),
        "logfile": str(logfile),
    }
    args = [token.format_map(values) for token in shlex.split(template)]
    if not args:
        raise ValueError("leere Vorlage")
    return [slicer_cli] + args


def _tail(text: str, limit: int = 600) -> str:
    text = (text or "").strip()
    return text if len(text) <= limit else "…" + text[-limit:]


_HINT_RE = re.compile(r"error|invalid|not found|can not|cannot|failed|unsupported|not compatible|Param values", re.I)


def _console_hints(stderr: str, stdout: str, limit: int = 500) -> str:
    """Die aussagekraeftigen Zeilen der Konsolenausgabe: alles, was nach
    einem Grund aussieht, plus die Zeilen direkt nach "Param values in
    3mf/config error:" (dort stehen Schluessel und Grund). Die reine
    Abschlusszeile "run found error, return N, exit..." traegt nichts bei."""
    lines = [line.strip() for line in (stderr + "\n" + stdout).splitlines() if line.strip()]
    picked: list[str] = []
    grab_following = 0
    for line in lines:
        if "run found error" in line or "found error, exit" in line:
            continue
        if grab_following > 0:
            picked.append(line)
            grab_following -= 1
            continue
        if _HINT_RE.search(line):
            picked.append(line)
            if line.startswith("Param values"):
                grab_following = 6
    if not picked:
        return _tail(stderr, limit) or _tail(stdout, limit)
    text = " | ".join(picked[-8:])
    return text if len(text) <= limit else text[:limit] + "…"


def describe_slicer_failure(work_dir: Path, returncode: Optional[int], stdout: str, stderr: str) -> str:
    """Eine für den Bediener verständliche Fehlermeldung zusammensetzen:
    zuerst der Klartext aus OrcaSlicers result.json (siehe --outputdir im
    Modulkommentar), ergänzt um die letzten Zeilen der Slicer-Logdatei
    bzw. der Konsolenausgabe."""
    code = returncode
    reason = ""

    result_json = work_dir / "result.json"
    if result_json.is_file():
        try:
            data = json.loads(result_json.read_text(encoding="utf-8", errors="replace"))
            if isinstance(data, dict):
                if isinstance(data.get("return_code"), int):
                    code = data["return_code"]
                reason = str(data.get("error_string") or "").strip()
                plate = data.get("plate_index")
                if reason and isinstance(plate, int) and plate > 0:
                    reason = f"Platte {plate}: {reason}"
        except (OSError, ValueError):
            pass

    parts = []
    if code is not None:
        hint = ORCA_EXIT_HINTS.get(code)
        parts.append(f"OrcaSlicer-Fehler {code}" + (f" ({hint})" if hint else ""))
    if reason:
        parts.append(reason)

    # Die Konsolenausgabe immer mitnehmen: Bei Profilfehlern (-5) oder
    # ungueltigen Werten (-18, "Param values in 3mf/config error: <key>:
    # <grund>") steht der eigentliche Grund nur dort, result.json sagt
    # bloss "invalid". Achtung: xvfb-run leitet die Fehlerausgabe des
    # Slicers in die Standardausgabe um ("$@" 2>&1) - deshalb beide
    # Kanaele durchsuchen und nicht nur stderr.
    console = _console_hints(stderr, stdout)
    if console:
        parts.append(console)

    logfile = work_dir / "slicer.log"
    if logfile.is_file():
        try:
            lines = [
                line
                for line in logfile.read_text(encoding="utf-8", errors="replace").splitlines()
                if "[error]" in line or "[fatal]" in line
            ]
            if lines:
                parts.append("Log: " + _tail(" | ".join(lines[-5:]), 400))
        except OSError:
            pass

    return " - ".join(parts) if parts else "unbekannter Slicer-Fehler"


def run_slicer(
    input_path: Path,
    output_path: Path,
    printer_json: Path,
    filament_json: Path,
    process_json: Path,
) -> tuple[bool, str]:
    """OrcaSlicer aufrufen. output_path muss im selben Verzeichnis wie
    input_path liegen (dem Arbeitsverzeichnis des Auftrags) - siehe
    Modulkommentar zu --outputdir."""
    work_dir = input_path.parent
    logfile = work_dir / "slicer.log"

    # Reste eines früheren Versuchs würden die Auswertung verfälschen.
    for stale in (work_dir / "result.json", logfile, output_path):
        try:
            stale.unlink()
        except FileNotFoundError:
            pass
        except OSError as exc:
            log.warning("Konnte %s nicht entfernen: %s", stale, exc)

    try:
        cmd = build_slicer_command(
            Config.SLICER_ARGS_TEMPLATE,
            Config.SLICER_CLI,
            input_path=input_path,
            output_name=output_path.name,
            printer_json=printer_json,
            filament_json=filament_json,
            process_json=process_json,
            work_dir=work_dir,
            logfile=logfile,
        )
    except (KeyError, ValueError, IndexError) as exc:
        return False, f"SLICER_ARGS_TEMPLATE ist ungültig: {exc}"

    log.info("Starte Slicer: %s", " ".join(shlex.quote(c) for c in cmd))

    try:
        # Eigene Prozessgruppe: Bei Zeitüberschreitung muss die ganze
        # Gruppe weg (xvfb-run-Wrapper, Xvfb und der eigentliche Slicer),
        # nicht nur der direkte Kindprozess - sonst blieben verwaiste
        # Slicer-Prozesse mit voller CPU-Last liegen.
        proc = subprocess.Popen(
            cmd,
            cwd=str(work_dir),
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            start_new_session=True,
        )
    except FileNotFoundError:
        return False, f"Slicer-Programm nicht gefunden: {Config.SLICER_CLI}"
    except OSError as exc:
        return False, f"Slicer konnte nicht gestartet werden: {exc}"

    timed_out = False
    try:
        stdout, stderr = proc.communicate(timeout=Config.SLICER_TIMEOUT)
    except subprocess.TimeoutExpired:
        timed_out = True
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError, OSError):
            proc.kill()
        stdout, stderr = proc.communicate()

    try:
        (work_dir / "slicer-output.txt").write_text(
            f"$ {' '.join(shlex.quote(c) for c in cmd)}\n\n--- stdout ---\n{stdout}\n--- stderr ---\n{stderr}\n",
            encoding="utf-8",
        )
    except OSError:
        pass

    log.debug("Slicer stdout:\n%s", stdout)
    log.debug("Slicer stderr:\n%s", stderr)

    if timed_out:
        return False, f"Slicer hat das Zeitlimit ({Config.SLICER_TIMEOUT}s) überschritten und wurde abgebrochen."

    if proc.returncode != 0:
        return False, describe_slicer_failure(work_dir, proc.returncode, stdout, stderr)

    if not output_path.is_file():
        return False, (
            f"Slicer meldete Erfolg, aber die Ergebnisdatei {output_path.name} fehlt - "
            + describe_slicer_failure(work_dir, None, stdout, stderr)
        )

    if output_path.stat().st_size == 0:
        return False, f"Slicer hat eine leere Ergebnisdatei ({output_path.name}) erzeugt."

    return True, ""


# ---------------------------------------------------------------------
# Arbeitsverzeichnisse
# ---------------------------------------------------------------------


def remove_work_dir(work_dir: Path) -> None:
    try:
        shutil.rmtree(work_dir)
    except FileNotFoundError:
        pass
    except OSError as exc:
        log.warning("Arbeitsverzeichnis %s konnte nicht gelöscht werden: %s", work_dir, exc)


def prune_old_work_dirs() -> None:
    """Liegengebliebene Arbeitsverzeichnisse (fehlgeschlagene oder
    abgebrochene Aufträge) nach KEEP_FAILED_JOBS_DAYS entfernen, damit das
    Datenvolumen nicht unbegrenzt wächst."""
    cutoff = time.time() - max(0, Config.KEEP_FAILED_JOBS_DAYS) * 86400
    try:
        candidates = list(Config.DOWNLOAD_DIRECTORY.glob("job-*"))
    except OSError:
        return
    for path in candidates:
        try:
            if path.is_dir() and path.stat().st_mtime < cutoff:
                log.info("Entferne altes Arbeitsverzeichnis %s.", path)
                remove_work_dir(path)
        except OSError:
            continue


# ---------------------------------------------------------------------
# Druckauftrag abwickeln
# ---------------------------------------------------------------------


def process_job(api: ApiClient, job: dict) -> None:
    job_id = int(job["id"])
    filename = Path(str(job.get("filename") or "")).name or f"plate-{job_id}.3mf"

    log.info("Slicing-Auftrag %s verfügbar – claime …", job_id)

    if not api.claim(job_id):
        log.info("Auftrag %s konnte nicht geclaimt werden (vermutlich schon vergeben).", job_id)
        time.sleep(2)
        return

    work_dir = Config.DOWNLOAD_DIRECTORY / f"job-{job_id}"
    # Reste eines früheren Versuchs desselben Auftrags (z. B. nach
    # "Erneut slicen" in WordPress) nicht mit dem neuen Lauf vermischen.
    remove_work_dir(work_dir)
    work_dir.mkdir(parents=True, exist_ok=True)

    input_path = work_dir / filename
    printer_json = work_dir / "printer.json"
    filament_json = work_dir / "filament.json"
    process_json = work_dir / "process.json"
    output_path = work_dir / f"sliced-{filename}"

    downloads = (
        ("Projekt-3MF", lambda: api.download_job_file(job_id, input_path)),
        ("Druckerprofil", lambda: api.download_profile(job_id, "printer", printer_json)),
        ("Filamentprofil", lambda: api.download_profile(job_id, "filament", filament_json)),
        ("Prozessprofil", lambda: api.download_profile(job_id, "process", process_json)),
    )

    for label, download in downloads:
        if download():
            continue
        # Früher blieb der Auftrag hier einfach "claimed" liegen, bis der
        # Server das Lease nach 30 Minuten zurücksetzte - in WordPress war
        # derweil nichts zu sehen. Jetzt wird der Grund gemeldet, und die
        # Platte lässt sich dort sofort erneut einreihen.
        message = f"{label} konnte nicht vom Server heruntergeladen werden (nach {max(1, Config.DOWNLOAD_ATTEMPTS)} Versuchen)."
        log.error("Auftrag %s: %s", job_id, message)
        api.fail(job_id, message)
        return

    api.mark_slicing(job_id)

    try:
        printer_json, filament_json, process_json = prepare_profile_bundle(work_dir, printer_json, filament_json, process_json)
    except ValueError as exc:
        message = f"Slicing-Profile unbrauchbar: {exc}"
        log.error("Auftrag %s: %s", job_id, message)
        api.fail(job_id, message)
        return

    dropped = sanitize_project_config(input_path, (printer_json, filament_json, process_json))
    if dropped:
        log.info(
            "Projekteinstellungen der Platte bereinigt: %d Schlüssel ohne Profilzuordnung entfernt (%s%s).",
            len(dropped),
            ", ".join(dropped[:12]),
            ", …" if len(dropped) > 12 else "",
        )
    sanitize_model_settings(input_path)

    slice_ok, message = run_slicer(input_path, output_path, printer_json, filament_json, process_json)

    if not slice_ok:
        log.error("Slicen für Auftrag %s fehlgeschlagen: %s", job_id, message)
        log.error("Diagnosedateien liegen in %s (slicer.log, result.json, slicer-output.txt).", work_dir)
        api.fail(job_id, message)
        prune_old_work_dirs()
        return

    log.info("Auftrag %s geslict, lade Ergebnis hoch …", job_id)

    if api.complete(job_id, output_path):
        log.info("Auftrag %s abgeschlossen.", job_id)
        remove_work_dir(work_dir)
    else:
        log.error("Ergebnis-Upload für Auftrag %s fehlgeschlagen.", job_id)
        api.fail(job_id, "Ergebnis-Upload nach erfolgreichem Slicen fehlgeschlagen (Upload-Grösse/Timeout des Webservers prüfen).")

    prune_old_work_dirs()


# ---------------------------------------------------------------------
# Hauptschleife
# ---------------------------------------------------------------------


def run() -> None:
    Config.validate()
    Config.DOWNLOAD_DIRECTORY.mkdir(parents=True, exist_ok=True)
    prune_old_work_dirs()

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
