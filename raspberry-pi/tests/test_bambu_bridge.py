"""Tests der reinen Logik der Bambu-Bridge (ohne Drucker, ohne WordPress).

Aufruf im Ordner raspberry-pi:
    python3 -m pytest tests
"""

import json
import zipfile

import pytest

import bambu_bridge as bb


def ams(*trays):
    """AMS-Schnappschuss im Format von _on_mqtt_message(): eine Einheit,
    Fächer als (id, filament_id, farbe, rest)."""
    return [
        {
            "id": 0,
            "trays": [
                {"id": t[0], "type": "PLA", "tray_info_idx": t[1], "color": t[2], "remain": t[3]}
                for t in trays
            ],
        }
    ]


def snap(**kwargs):
    base = {
        "connected": True,
        "report_age": 1.0,
        "gcode_state": "RUNNING",
        "current_file": "lw16-7-platte.3mf",
        "progress_percent": 10,
        "remaining_minutes": 50,
        "last_error": "",
        "print_error_code": None,
        "ams": None,
    }
    base.update(kwargs)
    return base


# ---------------------------------------------------------------------
# AMS-Fach-Wahl
# ---------------------------------------------------------------------


def test_farbe_wird_normalisiert():
    assert bb.normalize_rgb("000000FF") == "000000"
    assert bb.normalize_rgb("#ffaa00") == "FFAA00"
    assert bb.normalize_rgb("#FFAA00FF") == "FFAA00"
    assert bb.normalize_rgb("") == ""
    assert bb.normalize_rgb(None) == ""


def test_gleiche_sorte_in_zwei_farben_waehlt_die_richtige_farbe():
    # Bisher gewann einfach das erste Fach mit derselben Filament-ID -
    # hier also Schwarz, obwohl Weiß gebraucht wird.
    snapshot = ams((0, "GFA01", "000000FF", 80), (1, "GFA01", "FFFFFFFF", 60))
    assert bb.choose_ams_tray(snapshot, "GFA01", "FFFFFF") == (1, "")


def test_ohne_farbe_in_der_datei_ist_eine_mehrfarbige_wahl_mehrdeutig():
    snapshot = ams((0, "GFA01", "000000FF", 80), (1, "GFA01", "FFFFFFFF", 60))
    fach, fehler = bb.choose_ams_tray(snapshot, "GFA01", "")
    assert fach is None
    assert "mehreren Farben" in fehler


def test_ohne_farbe_aber_einheitlicher_farbe_ist_die_wahl_eindeutig():
    snapshot = ams((0, "GFA01", "000000FF", 20), (2, "GFA01", "000000FF", 90))
    assert bb.choose_ams_tray(snapshot, "GFA01", "") == (2, "")


def test_leere_faecher_scheiden_aus_und_das_vollste_gewinnt():
    snapshot = ams((0, "GFA01", "000000FF", 0), (1, "GFA01", "000000FF", 30), (3, "GFA01", "000000FF", 70))
    assert bb.choose_ams_tray(snapshot, "GFA01", "000000") == (3, "")


def test_unbekannte_restmenge_bleibt_waehlbar():
    snapshot = ams((1, "GFA01", "000000FF", -1))
    assert bb.choose_ams_tray(snapshot, "GFA01", "000000") == (1, "")


def test_kein_passendes_fach_nennt_filament_und_farbe():
    snapshot = ams((0, "GFA01", "000000FF", 80))
    fach, fehler = bb.choose_ams_tray(snapshot, "GFA01", "FF0000")
    assert fach is None
    assert "GFA01" in fehler and "#FF0000" in fehler


def test_globale_fachnummer_ueber_mehrere_einheiten():
    snapshot = [
        {"id": 0, "trays": [{"id": 0, "tray_info_idx": "GFL99", "color": "000000FF", "remain": 50}]},
        {"id": 1, "trays": [{"id": 2, "tray_info_idx": "GFA01", "color": "FFFFFFFF", "remain": 50}]},
    ]
    assert bb.choose_ams_tray(snapshot, "GFA01", "FFFFFF") == (6, "")


def druckdatei(tmp_path, config):
    pfad = tmp_path / "plate.3mf"
    with zipfile.ZipFile(pfad, "w") as zf:
        zf.writestr("Metadata/project_settings.config", json.dumps(config))
    return pfad


def test_filament_und_farbe_werden_aus_der_druckdatei_gelesen(tmp_path):
    pfad = druckdatei(tmp_path, {"filament_ids": ["GFA01"], "filament_colour": ["#FFFFFF"]})
    assert bb.read_target_filament(pfad) == ("GFA01", "FFFFFF")


def test_fremde_datei_ohne_profilangaben(tmp_path):
    pfad = tmp_path / "x.3mf"
    pfad.write_bytes(b"kein zip")
    assert bb.read_target_filament(pfad) == (None, "")


def test_manuell_gewaehltes_fach_gilt_immer(tmp_path):
    pfad = druckdatei(tmp_path, {"filament_ids": ["GFA01"], "filament_colour": ["#FFFFFF"]})
    assert bb.decide_ams_tray({"ams_tray_id": 2}, ams(), pfad) == (2, "")


def test_ohne_ams_druckt_der_drucker_mit_dem_eingelegten_filament(tmp_path):
    pfad = druckdatei(tmp_path, {"filament_ids": ["GFA01"], "filament_colour": ["#FFFFFF"]})
    assert bb.decide_ams_tray({"ams_tray_id": None}, None, pfad) == (None, "")


def test_ohne_filament_id_bleibt_es_beim_alten_verhalten(tmp_path):
    pfad = druckdatei(tmp_path, {})
    assert bb.decide_ams_tray({"ams_tray_id": None}, ams((0, "GFA01", "000000FF", 50)), pfad) == (None, "")


def test_fehlendes_filament_verhindert_den_start(tmp_path):
    pfad = druckdatei(tmp_path, {"filament_ids": ["GFA01"], "filament_colour": ["#FF0000"]})
    fach, fehler = bb.decide_ams_tray({"ams_tray_id": None}, ams((0, "GFA01", "000000FF", 50)), pfad)
    assert fach is None and fehler


# ---------------------------------------------------------------------
# Dateizuordnung
# ---------------------------------------------------------------------


def test_datei_gehoert_nur_zum_job_mit_genauer_id():
    assert bb.file_belongs_to_job("lw16-7-platte.3mf", 7)
    assert bb.file_belongs_to_job("/sdcard/LW16-7-platte", 7)
    assert not bb.file_belongs_to_job("lw16-77-platte.3mf", 7)
    assert not bb.file_belongs_to_job("platte.3mf", 7)
    assert not bb.file_belongs_to_job("", 7)


def test_frische_braucht_verbindung_und_jungen_bericht():
    assert bb.is_fresh(snap())
    assert not bb.is_fresh(snap(connected=False))
    assert not bb.is_fresh(snap(report_age=None))
    assert not bb.is_fresh(snap(report_age=bb.Config.STALE_REPORT_SECONDS + 1))


# ---------------------------------------------------------------------
# Überwachung eines laufenden Drucks
# ---------------------------------------------------------------------


def test_laufender_eigener_druck_wartet():
    assert bb.MonitorTracker(7).step(snap(), 0) == ("wait", "")


def test_veralteter_zustand_entscheidet_nichts():
    # Früher hätte ein altes FINISH hier den Job abgeschlossen.
    tracker = bb.MonitorTracker(7)
    assert tracker.step(snap(gcode_state="FINISH", connected=False), 0) == ("wait", "")
    assert tracker.step(snap(gcode_state="FINISH", report_age=9999), 0) == ("wait", "")


def test_finish_der_eigenen_datei_schliesst_ab():
    assert bb.MonitorTracker(7).step(snap(gcode_state="FINISH"), 0) == ("complete", "")


def test_finish_einer_fremden_datei_ist_unklar():
    action, _ = bb.MonitorTracker(7).step(snap(gcode_state="FINISH", current_file="privat.3mf"), 0)
    assert action == "unclear"


def test_fremder_druck_am_geraet_beendet_den_auftrag():
    action, message = bb.MonitorTracker(7).step(snap(current_file="privat.3mf"), 0)
    assert action == "failed"
    assert "privat.3mf" in message


def test_abbruch_am_display_wird_benannt():
    action, message = bb.MonitorTracker(7).step(snap(gcode_state="FAILED", print_error_code=50348044), 0)
    assert (action, message) == ("failed", "Druck am Drucker abgebrochen.")


def test_anderer_fehler_nennt_den_fehlercode():
    action, message = bb.MonitorTracker(7).step(snap(gcode_state="FAILED", last_error="Fehlercode 123"), 0)
    assert (action, message) == ("failed", "Fehlercode 123")


def test_leerlauf_nach_druckbeginn_wird_nach_der_schonfrist_unklar():
    tracker = bb.MonitorTracker(7)
    grace = bb.Config.IDLE_GRACE_SECONDS
    assert tracker.step(snap(gcode_state="IDLE"), 100) == ("wait", "")
    assert tracker.step(snap(gcode_state="IDLE"), 100 + grace - 1) == ("wait", "")
    action, _ = tracker.step(snap(gcode_state="IDLE"), 100 + grace)
    assert action == "unclear"


def test_kurzer_leerlauf_setzt_die_schonfrist_zurueck():
    tracker = bb.MonitorTracker(7)
    grace = bb.Config.IDLE_GRACE_SECONDS
    tracker.step(snap(gcode_state="IDLE"), 0)
    tracker.step(snap(gcode_state="RUNNING"), grace - 1)
    assert tracker.step(snap(gcode_state="IDLE"), grace + 1) == ("wait", "")


def test_fortschritt_bei_aenderung_und_spaetestens_als_herzschlag():
    tracker = bb.MonitorTracker(7)
    beat = bb.Config.PROGRESS_HEARTBEAT_SECONDS
    assert tracker.progress_due(snap(progress_percent=10), 0)
    tracker.mark_progress(snap(progress_percent=10), 0)
    assert not tracker.progress_due(snap(progress_percent=10), beat - 1)
    assert tracker.progress_due(snap(progress_percent=10), beat), "Herzschlag hält das Lease bei langer Pause am Leben."
    assert tracker.progress_due(snap(progress_percent=11), 1)
    assert not tracker.progress_due(snap(progress_percent=11, connected=False), 1)


def test_pushall_bei_stillem_drucker_hoechstens_alle_fuenf_minuten():
    tracker = bb.MonitorTracker(7)
    still = snap(report_age=9999)
    assert tracker.pushall_due(still, 0)
    assert not tracker.pushall_due(still, 299)
    assert tracker.pushall_due(still, 300)
    assert not tracker.pushall_due(snap(), 1000), "Frischer Bericht braucht keinen pushall."


# ---------------------------------------------------------------------
# Wiederaufnahme nach einem Neustart der Bridge
# ---------------------------------------------------------------------


def job(status, job_id=7):
    return {"id": job_id, "status": status}


def test_laufender_eigener_druck_wird_weiter_verfolgt():
    assert bb.recovery_decision(job("printing"), snap(), "printing") == ("monitor", "")
    assert bb.recovery_decision(job("unklar"), snap(), None) == ("monitor", "")


def test_inzwischen_fertiger_druck_wird_abgeschlossen():
    assert bb.recovery_decision(job("printing"), snap(gcode_state="FINISH"), None) == ("complete", "")


def test_druck_im_leerlauf_wird_unklar_statt_neu_gedruckt():
    action, message = bb.recovery_decision(job("printing"), snap(gcode_state="IDLE", current_file=""), "printing")
    assert action == "unclear"
    assert "IDLE" in message


def test_nie_gestarteter_auftrag_geht_zurueck_in_die_warteschlange():
    assert bb.recovery_decision(job("claimed"), snap(gcode_state="IDLE", current_file=""), "claimed") == ("release", "")
    assert bb.recovery_decision(job("uploading"), snap(gcode_state="FINISH", current_file="lw16-6-alt.3mf"), None) == ("release", "")


def test_nach_dem_startkommando_wird_nicht_mehr_zurueckgegeben():
    action, _ = bb.recovery_decision(job("uploading"), snap(gcode_state="IDLE", current_file=""), "starting")
    assert action == "unclear"


def test_schon_unklarer_auftrag_ohne_neue_erkenntnis_bleibt_unklar():
    assert bb.recovery_decision(job("unklar"), snap(gcode_state="IDLE", current_file=""), None) == ("leave", "")


# ---------------------------------------------------------------------
# Outbox: Endmeldungen gehen nicht verloren
# ---------------------------------------------------------------------


class FakeApi:
    def __init__(self, antworten):
        self.antworten = list(antworten)
        self.gesendet = []

    def send_final(self, job_id, action, message=""):
        self.gesendet.append((job_id, action, message))
        return self.antworten.pop(0) if self.antworten else 200


def test_endmeldung_bleibt_bis_der_server_sie_annimmt(tmp_path):
    outbox = bb.Outbox(tmp_path / "outbox.json")
    outbox.add(7, "complete")

    assert not outbox.flush(FakeApi([None]))
    assert not outbox.flush(FakeApi([503]))
    # Auch nach einem Neustart (neues Objekt, dieselbe Datei) noch da.
    neu = bb.Outbox(tmp_path / "outbox.json")
    assert neu.pending() == [{"job_id": 7, "action": "complete", "message": ""}]
    assert neu.flush(FakeApi([200]))
    assert neu.pending() == []


def test_endgueltige_ablehnung_wird_verworfen(tmp_path):
    outbox = bb.Outbox(tmp_path / "outbox.json")
    outbox.add(7, "failed", "x")
    outbox.add(8, "unclear", "y")
    assert outbox.flush(FakeApi([409, 404]))
    assert outbox.pending() == []


def test_neue_endmeldung_ersetzt_die_alte_desselben_jobs(tmp_path):
    outbox = bb.Outbox(tmp_path / "outbox.json")
    outbox.add(7, "unclear", "erst")
    outbox.add(7, "complete")
    assert outbox.pending() == [{"job_id": 7, "action": "complete", "message": ""}]


def test_aktiver_auftrag_ueberlebt_den_neustart(tmp_path):
    store = bb.ActiveJobStore(tmp_path / "active.json")
    assert store.load() is None
    store.save(7, "starting")
    assert bb.ActiveJobStore(tmp_path / "active.json").load() == {"job_id": 7, "phase": "starting"}
    store.clear()
    assert store.load() is None


# ---------------------------------------------------------------------
# Startbestätigung
# ---------------------------------------------------------------------


@pytest.fixture
def schnell(monkeypatch):
    monkeypatch.setattr(bb.time, "sleep", lambda s: None)
    bb.STATE.update(gcode_state="", current_file="", connected=True, last_report_at=bb.time.monotonic())
    yield
    bb.STATE.update(gcode_state="", current_file="", command_results={})


def test_abgelehnter_start_scheitert_sofort_mit_grund(schnell):
    bb.STATE.record_command_result("41", "FAIL", "Datei ungültig")
    ergebnis, grund = bb.warte_auf_druckstart(7, "41")
    assert ergebnis == "failed"
    assert "Datei ungültig" in grund


def test_laufender_zustand_gilt_als_gestartet(schnell):
    bb.STATE.update(gcode_state="PREPARE", current_file="lw16-7-platte.3mf")
    assert bb.warte_auf_druckstart(7, "42") == ("started", "")


def test_fremder_start_waehrend_des_wartens_scheitert(schnell):
    bb.STATE.update(gcode_state="RUNNING", current_file="privat.3mf")
    ergebnis, _ = bb.warte_auf_druckstart(7, "43")
    assert ergebnis == "failed"


def test_ohne_verbindung_beim_timeout_ist_der_start_unklar(schnell, monkeypatch):
    monkeypatch.setattr(bb.Config, "PRINT_START_TIMEOUT", 0)
    bb.STATE.update(connected=False)
    ergebnis, _ = bb.warte_auf_druckstart(7, "44")
    assert ergebnis == "unclear"


def test_schonfrist_laeuft_auch_bei_stillem_drucker_ab():
    # Ein Drucker im Leerlauf meldet sich oft nur auf pushall - zwischen
    # zwei Antworten ist der Bericht veraltet. Die Schonfrist darf dadurch
    # nicht immer wieder von vorn beginnen.
    tracker = bb.MonitorTracker(7)
    grace = bb.Config.IDLE_GRACE_SECONDS
    tracker.step(snap(gcode_state="IDLE"), 0)
    assert tracker.step(snap(gcode_state="IDLE", report_age=9999), grace / 2) == ("wait", "")
    action, _ = tracker.step(snap(gcode_state="IDLE"), grace + 1)
    assert action == "unclear"


# ---------------------------------------------------------------------
# Festgeschriebenes Druckerzertifikat
# ---------------------------------------------------------------------


def test_erstes_zertifikat_wird_gemerkt_und_danach_verlangt(tmp_path):
    pins = bb.CertPinStore(tmp_path / "cert.json")
    pins.verify("mqtt", b"zertifikat-a")
    pins.verify("mqtt", b"zertifikat-a")

    with pytest.raises(bb.ssl.SSLError):
        pins.verify("mqtt", b"zertifikat-b")
    assert "anderes Zertifikat" in pins.last_mismatch

    # Auch nach einem Neustart (neues Objekt) gilt das gemerkte.
    with pytest.raises(bb.ssl.SSLError):
        bb.CertPinStore(tmp_path / "cert.json").verify("mqtt", b"zertifikat-b")


def test_jeder_dienst_hat_sein_eigenes_zertifikat(tmp_path):
    pins = bb.CertPinStore(tmp_path / "cert.json")
    pins.verify("mqtt", b"a")
    pins.verify("ftps", b"b")
    pins.verify("ftps", b"b")
    with pytest.raises(bb.ssl.SSLError):
        pins.verify("ftps", b"a")


def test_eingetragener_fingerabdruck_hat_vorrang(tmp_path):
    fp = bb.CertPinStore.fingerprint(b"richtig")
    pins = bb.CertPinStore(tmp_path / "cert.json", ":".join(fp[i:i + 2] for i in range(0, len(fp), 2)).upper())
    pins.verify("mqtt", b"richtig")
    with pytest.raises(bb.ssl.SSLError):
        pins.verify("ftps", b"falsch")
    assert not (tmp_path / "cert.json").exists(), "Mit eingetragenem Wert wird nichts gemerkt."


def test_ohne_zertifikat_keine_verbindung(tmp_path):
    with pytest.raises(bb.ssl.SSLError):
        bb.CertPinStore(tmp_path / "cert.json").verify("mqtt", None)


@pytest.fixture
def tls_server(tmp_path):
    """Kleiner TLS-Server mit selbstsigniertem Zertifikat - wie der Drucker."""
    import shutil
    import socket
    import subprocess
    import threading

    if not shutil.which("openssl"):
        pytest.skip("openssl fehlt")

    key, cert = tmp_path / "key.pem", tmp_path / "cert.pem"
    subprocess.run(
        ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(key), "-out", str(cert),
         "-days", "1", "-subj", "/CN=00M00A000000000"],
        check=True, capture_output=True,
    )
    ctx = bb.ssl.SSLContext(bb.ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(str(cert), str(key))
    srv = socket.socket()
    srv.bind(("127.0.0.1", 0))
    srv.listen(5)
    stop = threading.Event()

    def serve():
        srv.settimeout(0.2)
        while not stop.is_set():
            try:
                conn, _ = srv.accept()
            except OSError:
                continue
            try:
                with ctx.wrap_socket(conn, server_side=True) as tls:
                    tls.recv(1)
            except OSError:
                pass

    t = threading.Thread(target=serve, daemon=True)
    t.start()
    der = bb.ssl.PEM_cert_to_DER_cert(cert.read_text())
    yield srv.getsockname()[1], der
    stop.set()
    srv.close()


def test_festschreibung_greift_im_echten_handshake(tls_server, tmp_path, monkeypatch):
    port, der = tls_server
    monkeypatch.setattr(bb.Config, "BAMBU_IP", "127.0.0.1")

    monkeypatch.setattr(bb, "CERT_PINS", bb.CertPinStore(tmp_path / "cert.json", bb.CertPinStore.fingerprint(der)))
    assert bb.verify_printer_certificate("rtsps", port) == (True, "")

    monkeypatch.setattr(bb, "CERT_PINS", bb.CertPinStore(tmp_path / "cert.json", "00" * 32))
    ok, grund = bb.verify_printer_certificate("rtsps", port)
    assert not ok and "anderes Zertifikat" in grund


def test_festschreibung_auch_bei_spaetem_handshake_wie_paho(tls_server, tmp_path, monkeypatch):
    # paho wickelt mit do_handshake_on_connect=False und ruft do_handshake()
    # selbst auf - auch dann muss die Prüfung vor dem ersten Byte greifen.
    import socket

    port, _ = tls_server
    monkeypatch.setattr(bb, "CERT_PINS", bb.CertPinStore(tmp_path / "cert.json", "00" * 32))
    raw = socket.create_connection(("127.0.0.1", port), timeout=5)
    tls = bb._printer_ssl_context("mqtt").wrap_socket(raw, server_hostname=None, do_handshake_on_connect=False)
    with pytest.raises(bb.ssl.SSLError):
        tls.do_handshake()
    tls.close()


def test_modell_aus_der_seriennummer():
    assert bb.model_from_serial("00M09A123456789") == "X1C"
    assert bb.model_from_serial("01p00a000000000") == "P1S"
    assert bb.model_from_serial("03000A000000000") == "A1 mini"
    assert bb.model_from_serial("XYZ") == ""
    assert bb.model_from_serial("") == ""
