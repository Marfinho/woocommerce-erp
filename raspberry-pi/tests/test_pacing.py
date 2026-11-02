"""Tests: Die Pi-Programme schonen den Webspace.

Im November 2026 fragten Bridge, Slicing-Worker und Etikettendrucker-Client
zusammen rund 1.800-mal pro Stunde bei WordPress an, bis der Webspace mit
500/503 ausstieg. Hier steht fest: Im Leerlauf wird die Pause länger, bei
Serverfehlern wirklich immer länger, der Status geht nur bei Änderungen
raus und die Kamera nur, wenn jemand zuschaut.

Aufruf im Ordner raspberry-pi:
    python3 -m pytest tests
"""

import pytest

import bambu_bridge as bb
import haster_print_client as hp
import slicing_worker as sw


class Resp:
    """Minimaler Ersatz für requests.Response."""

    def __init__(self, status_code, body=None, headers=None):
        self.status_code = status_code
        self._body = body
        self.headers = headers or {}

    def json(self):
        if self._body is None:
            raise ValueError("kein JSON")
        return self._body


class Stop(Exception):
    pass


# ---------------------------------------------------------------------
# Bausteine
# ---------------------------------------------------------------------


@pytest.mark.parametrize("mod", [bb, sw, hp])
def test_leerlauf_pause_waechst_bis_zum_maximum_und_springt_zurueck(mod):
    pacer = mod.IdlePacer(5, 60)
    assert [pacer.idle() for _ in range(6)] == [5, 10, 20, 40, 60, 60]
    pacer.reset()
    assert pacer.idle() == 5


@pytest.mark.parametrize("mod", [bb, sw, hp])
def test_retry_after_wird_beachtet_aber_begrenzt(mod):
    assert mod.retry_after(Resp(503, headers={"Retry-After": "120"}), 2) == 120
    assert mod.retry_after(Resp(503, headers={"Retry-After": "kaputt"}), 2) == 2
    assert mod.retry_after(Resp(503), 4) == 4
    assert mod.retry_after(Resp(503, headers={"Retry-After": "99999"}), 2) == 600
    assert mod.retry_after(None, 3) == 3


def test_kamera_flag_aus_der_antwort():
    assert bb.camera_flag(Resp(200, {"ok": True, "camera": False}), True) is False
    assert bb.camera_flag(Resp(200, {"ok": True, "camera": True}), False) is True
    # Ältere WordPress-Version ohne Feld: weiter Bilder schicken.
    assert bb.camera_flag(Resp(200, {"ok": True}), False) is True
    # Keine oder fehlerhafte Antwort: bisheriger Wert bleibt.
    assert bb.camera_flag(None, False) is False
    assert bb.camera_flag(Resp(503), True) is True


# ---------------------------------------------------------------------
# Status der Bambu-Bridge
# ---------------------------------------------------------------------


@pytest.fixture
def api(monkeypatch):
    """ApiClient, dessen POSTs mitgeschrieben werden."""
    client = bb.ApiClient()
    client.sent = []
    client.answer = Resp(200, {"ok": True, "camera": False})

    def fake_post(path, **kwargs):
        client.sent.append((path, kwargs.get("data")))
        return client.answer

    monkeypatch.setattr(client, "_post", fake_post)
    monkeypatch.setattr(bb.Config, "STATUS_HEARTBEAT", 60)
    bb.STATE.update(
        connected=True,
        gcode_state="IDLE",
        current_file="",
        progress_percent=0,
        remaining_minutes=0,
        nozzle_temp=25.0,
        bed_temp=24.0,
        last_error="",
    )
    return client


def test_status_nur_bei_aenderung_oder_als_lebenszeichen(api):
    assert api.post_status(now=0) is True
    # Nur die Temperatur schwankt: nichts schicken.
    bb.STATE.update(nozzle_temp=26.0)
    assert api.post_status(now=10) is False
    assert api.post_status(now=50) is False
    # Lebenszeichen fällig.
    assert api.post_status(now=61) is True
    assert len(api.sent) == 2


def test_statuswechsel_geht_sofort_raus(api):
    api.post_status(now=0)
    bb.STATE.update(gcode_state="RUNNING", progress_percent=3)
    assert api.post_status(now=5) is True
    assert api.sent[-1][1]["status"] == "RUNNING"


def test_nicht_angekommener_status_wird_wiederholt(api):
    api.answer = Resp(503)
    api.post_status(now=0)
    assert api.post_status(now=10) is True
    api.answer = Resp(200, {"ok": True})
    api.post_status(now=20)
    assert api.post_status(now=30) is False


def test_status_antwort_steuert_die_kamera(api):
    assert api.camera_wanted is True
    api.post_status(now=0)
    assert api.camera_wanted is False


def test_kamera_ohne_zuschauer_nimmt_nichts_auf(monkeypatch):
    calls = []
    monkeypatch.setattr(bb.Config, "CAMERA_ONLY_WHEN_WATCHED", True)
    monkeypatch.setattr(bb, "capture_snapshot", lambda: calls.append(1) or (b"", "egal"))

    class Api:
        camera_wanted = False

    class Event:
        n = 0

        def is_set(self):
            return self.n >= 3

        def wait(self, _):
            self.n += 1

    bb.camera_loop(Api(), Event())
    assert calls == []


# ---------------------------------------------------------------------
# Abfrageschleifen von Slicing-Worker und Etikettendrucker-Client
# ---------------------------------------------------------------------


def drive(mod, monkeypatch, answers):
    """Die Hauptschleife mit festen Antworten laufen lassen und die Pausen
    mitschreiben."""
    pauses = []
    queue = list(answers)

    class Api:
        def get_jobs(self, wait):
            if not queue:
                raise Stop()
            return queue.pop(0)

    monkeypatch.setattr(mod.Config, "validate", classmethod(lambda cls: None))
    monkeypatch.setattr(mod.Config, "LONG_POLL_ENABLED", False)
    monkeypatch.setattr(mod.Config, "FALLBACK_POLL_INTERVAL", 5)
    monkeypatch.setattr(mod.Config, "IDLE_POLL_MAX", 60)
    monkeypatch.setattr(mod.Config, "MAX_RETRY_DELAY", 30)
    monkeypatch.setattr(mod, "ApiClient", Api)
    monkeypatch.setattr(mod, "process_job", lambda api, job: None)
    monkeypatch.setattr(mod.time, "sleep", pauses.append)
    if hasattr(mod, "prune_old_work_dirs"):
        monkeypatch.setattr(mod, "prune_old_work_dirs", lambda: None)
    monkeypatch.setattr(mod.Config, "DOWNLOAD_DIRECTORY", type("D", (), {"mkdir": lambda *a, **k: None})())

    with pytest.raises(Stop):
        mod.run()
    return pauses


@pytest.mark.parametrize("mod", [sw, hp])
def test_leerlauf_wird_langsamer_und_ein_job_macht_wieder_schnell(mod, monkeypatch):
    leer = [Resp(204)] * 5
    job = [Resp(200, {"jobs": [{"id": 1}]})]
    pauses = drive(mod, monkeypatch, leer + job + [Resp(204)])
    assert pauses == [5, 10, 20, 40, 60, 5]


@pytest.mark.parametrize("mod", [sw, hp])
def test_bei_serverfehlern_wird_wirklich_immer_laenger_gewartet(mod, monkeypatch):
    pauses = drive(mod, monkeypatch, [Resp(503)] * 7 + [Resp(204)])
    assert pauses[:7] == [1, 2, 4, 8, 16, 30, 30]


@pytest.mark.parametrize("mod", [sw, hp])
def test_ratenlimit_beachtet_retry_after(mod, monkeypatch):
    pauses = drive(mod, monkeypatch, [Resp(429, headers={"Retry-After": "300"}), Resp(429)])
    assert pauses == [300, 30]
