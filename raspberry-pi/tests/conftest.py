"""Testumgebung für bambu_bridge.py.

Die Bridge braucht paho-mqtt nur für die echte Verbindung zum Drucker.
Für die Tests der reinen Logik (AMS-Wahl, Überwachung, Wiederaufnahme,
Outbox) wird es durch einen Platzhalter ersetzt, damit die Tests auch
ohne installiertes paho laufen.
"""

import os
import sys
import types
from pathlib import Path

if "paho.mqtt.client" not in sys.modules:
    paho = types.ModuleType("paho")
    paho_mqtt = types.ModuleType("paho.mqtt")
    client = types.ModuleType("paho.mqtt.client")
    client.Client = object
    client.MQTTv311 = 4
    paho.mqtt = paho_mqtt
    paho_mqtt.client = client
    sys.modules.update({"paho": paho, "paho.mqtt": paho_mqtt, "paho.mqtt.client": client})

os.environ.setdefault("WORDPRESS_URL", "https://example.invalid")
os.environ.setdefault("API_TOKEN", "test-token")
os.environ.setdefault("BAMBU_ACCESS_CODE", "12345678")
os.environ.setdefault("BAMBU_SERIAL", "00M00A000000000")

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
