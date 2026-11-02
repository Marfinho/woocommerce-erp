"""Tests: Türklingel über Home Assistant / MQTT.

WordPress klingelt bei einem neuen Auftrag, Home Assistant gibt das per MQTT
an den Pi weiter. Die Programme warten zwischen zwei Abfragen auf die Klingel
statt einfach zu schlafen und fragen mit verbundener Klingel nur noch selten
selbst nach.

Der letzte Test läuft gegen einen echten Mosquitto-Broker, wenn einer
installiert ist (sonst übersprungen).

Aufruf im Ordner raspberry-pi:
    python3 -m pytest tests
"""

import importlib
import shutil
import socket
import subprocess
import sys
import time

import pytest

import bambu_bridge as bb
import haster_print_client as hp
import slicing_worker as sw

MODS = [bb, sw, hp]


@pytest.mark.parametrize("mod", MODS)
def test_ohne_host_wird_einfach_geschlafen(mod, monkeypatch):
    monkeypatch.setattr(mod.Config, "DOORBELL_MQTT_HOST", "")
    slept = []
    monkeypatch.setattr(mod.time, "sleep", slept.append)
    bell = mod.Doorbell(("x",))
    bell.start()
    assert bell.enabled is False
    assert bell.wait(7) is False
    assert slept == [7]
    assert bell.idle_max(60) == 60


@pytest.mark.parametrize("mod", MODS)
def test_klingel_weckt_sofort(mod, monkeypatch):
    monkeypatch.setattr(mod.Config, "DOORBELL_MQTT_HOST", "ha.local")
    monkeypatch.setattr(mod, "_MQTT", object())
    bell = mod.Doorbell(("bambu",))
    assert bell.handle("lw16/klingel/bambu", b"") is True
    start = time.monotonic()
    assert bell.wait(30) is True
    assert time.monotonic() - start < 1
    # Einmal geklingelt = einmal geweckt.
    assert bell.wait(0.01) is False


@pytest.mark.parametrize("mod", MODS)
def test_klingel_fuer_einen_anderen_drucker_weckt_nicht(mod, monkeypatch):
    monkeypatch.setattr(mod.Config, "DOORBELL_MQTT_HOST", "ha.local")
    monkeypatch.setattr(mod, "_MQTT", object())
    bell = mod.Doorbell(("bambu",), "x1c_1")
    assert bell.handle("lw16/klingel/bambu", b"x1c_2") is False
    assert bell.handle("lw16/klingel/bambu", b"x1c_1") is True
    assert bell.handle("lw16/klingel/bambu", b"test") is True
    assert bell.handle("lw16/klingel/bambu", b"") is True


def test_kamera_klingel_weckt_nicht_aber_schaltet_die_kamera_ein(monkeypatch):
    monkeypatch.setattr(bb.Config, "DOORBELL_MQTT_HOST", "ha.local")
    rang = []
    bell = bb.Doorbell(("bambu",), "x1c_1", on_ring=rang.append)
    assert bell.handle("lw16/klingel/kamera", b"x1c_1") is False
    assert rang == ["kamera"]
    assert bell.handle("lw16/klingel/kamera", b"x1c_9") is False
    assert rang == ["kamera"]


@pytest.mark.parametrize("mod", MODS)
def test_mit_verbundener_klingel_wird_selten_nachgefragt(mod, monkeypatch):
    monkeypatch.setattr(mod.Config, "DOORBELL_FALLBACK", 300)
    bell = mod.Doorbell(("x",))
    bell.connected = True
    assert bell.idle_max(60) == 300
    bell.connected = False
    assert bell.idle_max(60) == 60


@pytest.mark.parametrize("mod", MODS)
def test_pause_schrumpft_wenn_die_klingel_wegfaellt(mod):
    pacer = mod.IdlePacer(5, 300)
    for _ in range(8):
        pacer.idle()
    assert pacer.idle() == 300
    pacer.maximum = 60
    assert pacer.idle() == 60


# ---------------------------------------------------------------------
# Echter Broker
# ---------------------------------------------------------------------


def _free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def _real_paho():
    """Das echte paho laden (conftest ersetzt es für die Logik-Tests)."""
    saved = {k: v for k, v in sys.modules.items() if k == "paho" or k.startswith("paho.")}
    for k in saved:
        del sys.modules[k]
    try:
        mod = importlib.import_module("paho.mqtt.client")
        if not hasattr(mod, "Client") or mod.Client is object:
            raise ImportError
        return mod
    except ImportError:
        return None
    finally:
        for k in [k for k in sys.modules if k == "paho" or k.startswith("paho.")]:
            del sys.modules[k]
        sys.modules.update(saved)


@pytest.mark.skipif(shutil.which("mosquitto") is None, reason="mosquitto nicht installiert")
def test_ende_zu_ende_mit_mosquitto(monkeypatch):
    paho = _real_paho()
    if paho is None:
        pytest.skip("paho-mqtt nicht installiert")

    port = _free_port()
    broker = subprocess.Popen(["mosquitto", "-p", str(port)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(0.5)
        monkeypatch.setattr(sw, "_MQTT", paho)
        monkeypatch.setattr(sw.Config, "DOORBELL_MQTT_HOST", "127.0.0.1")
        monkeypatch.setattr(sw.Config, "DOORBELL_MQTT_PORT", port)
        monkeypatch.setattr(sw.Config, "DOORBELL_TOPIC", "lw16/klingel")

        bell = sw.Doorbell(("slicing",))
        bell.start()

        deadline = time.monotonic() + 5
        while not bell.connected and time.monotonic() < deadline:
            time.sleep(0.05)
        assert bell.connected, "keine Verbindung zum Broker"
        # Nach dem Verbinden einmal nachsehen (verpasste Klingeln).
        assert bell.wait(1) is True

        # So wie die Automation in Home Assistant es tut.
        pub = paho.Client(client_id="ha-test", protocol=paho.MQTTv311)
        pub.connect("127.0.0.1", port)
        pub.loop_start()
        start = time.monotonic()
        pub.publish("lw16/klingel/slicing", "", qos=1).wait_for_publish(2)
        assert bell.wait(10) is True
        assert time.monotonic() - start < 2

        # Andere Kanäle wecken den Slicing-Worker nicht.
        pub.publish("lw16/klingel/bambu", "", qos=1).wait_for_publish(2)
        assert bell.wait(0.5) is False
        pub.loop_stop()
        pub.disconnect()
        bell._client.loop_stop()
        bell._client.disconnect()
    finally:
        broker.terminate()
        broker.wait(5)
