#!/usr/bin/env bash
#
# Installiert/aktualisiert die Bambu-Lab-Bridge auf dem Raspberry Pi.
#
# Läuft neben einer bestehenden Etikettendrucker-Installation
# (haster-print unter /opt/haster-print) her, ohne sie anzufassen - beide
# sind unabhängige systemd-Dienste. Das Skript erkennt eine vorhandene
# haster-print-Installation nur, um höflich Bescheid zu geben und (wenn
# vorhanden) WORDPRESS_URL daraus vorzuschlagen; es ändert dort nichts.
#
# Erneut ausführen ist unbedenklich: Eine bestehende .env.bambu wird nicht
# überschrieben, ohne zu fragen, und der Dienst wird am Ende nur neu
# gestartet, nicht doppelt angelegt.
#
# Aufruf (im raspberry-pi-Ordner des Checkouts):
#   ./install-bambu-bridge.sh
#
# Setzt einen Debian-artigen Raspberry Pi OS voraus (apt, systemd).
set -euo pipefail

# -----------------------------------------------------------------------
# Ausgabe-Helfer
# -----------------------------------------------------------------------

if [ -t 1 ]; then
	BOLD="$(tput bold 2>/dev/null || true)"
	RESET="$(tput sgr0 2>/dev/null || true)"
	GREEN="$(tput setaf 2 2>/dev/null || true)"
	YELLOW="$(tput setaf 3 2>/dev/null || true)"
	RED="$(tput setaf 1 2>/dev/null || true)"
else
	BOLD=""; RESET=""; GREEN=""; YELLOW=""; RED=""
fi

step()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
info()  { printf '    %s\n' "$1"; }
ok()    { printf '    %s✓ %s%s\n' "$GREEN" "$1" "$RESET"; }
warn()  { printf '    %s! %s%s\n' "$YELLOW" "$1" "$RESET"; }
fail()  { printf '%s✗ %s%s\n' "$RED" "$1" "$RESET" >&2; exit 1; }

# -----------------------------------------------------------------------
# Voraussetzungen
# -----------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

for required in bambu_bridge.py requirements-bambu.txt .env.bambu.example bambu-bridge.service; do
	[ -f "$required" ] || fail "Datei '$required' fehlt in $SCRIPT_DIR - bitte im raspberry-pi-Ordner des vollständigen Checkouts ausführen."
done

command -v python3 >/dev/null 2>&1 || fail "python3 wurde nicht gefunden. 'sudo apt install python3 python3-venv' und erneut versuchen."
command -v systemctl >/dev/null 2>&1 || fail "systemd (systemctl) wurde nicht gefunden - dieses Skript ist für Raspberry Pi OS gedacht."

# ffmpeg ist nur fürs Kamera-Standbild nötig (nur X1-Serie) - kein
# fail(), damit die Installation ohne Kamera-Feature trotzdem durchläuft.
if ! command -v ffmpeg >/dev/null 2>&1; then
	warn "ffmpeg wurde nicht gefunden - ohne ffmpeg funktioniert die Bridge, aber ohne Kamera-Standbild (nur X1-Serie)."
	warn "Nachinstallieren mit: sudo apt install -y ffmpeg"
fi

INSTALL_DIR="/opt/bambu-bridge"
SERVICE_NAME="bambu-bridge"
CURRENT_USER="$(whoami)"

step "Lindenweg16 Bambu-Bridge – Installation"
info "Installationsverzeichnis: $INSTALL_DIR"
info "Dienst läuft als Benutzer: $CURRENT_USER"

# -----------------------------------------------------------------------
# Vorhandene Installationen erkennen
# -----------------------------------------------------------------------

step "Bestehende Installationen prüfen"

SUGGESTED_WORDPRESS_URL=""

if [ -d /opt/haster-print ] || systemctl list-unit-files 2>/dev/null | grep -q '^haster-print\.service'; then
	ok "Etikettendrucker-Client (haster-print) ist installiert – bleibt unverändert, läuft als eigener Dienst weiter."
	if [ -f /opt/haster-print/.env ]; then
		SUGGESTED_WORDPRESS_URL="$(grep -E '^WORDPRESS_URL=' /opt/haster-print/.env 2>/dev/null | head -n1 | cut -d= -f2- || true)"
		[ -n "$SUGGESTED_WORDPRESS_URL" ] && info "WORDPRESS_URL daraus übernommen als Vorschlag: $SUGGESTED_WORDPRESS_URL"
	fi
else
	info "Kein Etikettendrucker-Client gefunden – unabhängig davon, dieses Skript installiert nur die Bambu-Bridge."
fi

EXISTING_ENV=""
if [ -f "$INSTALL_DIR/.env.bambu" ]; then
	warn "Es gibt bereits eine Bambu-Bridge-Installation mit Konfiguration unter $INSTALL_DIR/.env.bambu."
	read -r -p "    Vorhandene Konfiguration behalten und nur Dienst/Abhängigkeiten aktualisieren? [J/n] " keep_existing
	case "$keep_existing" in
		[nN]*) EXISTING_ENV="" ;;
		*) EXISTING_ENV="$INSTALL_DIR/.env.bambu" ;;
	esac
fi

if systemctl list-unit-files 2>/dev/null | grep -q "^${SERVICE_NAME}\.service"; then
	info "Dienst '${SERVICE_NAME}' existiert bereits – wird am Ende neu gestartet."
fi

# -----------------------------------------------------------------------
# Konfiguration abfragen (nur, wenn keine vorhandene übernommen wird)
# -----------------------------------------------------------------------

prompt_required() {
	# $1 = Prompt-Text, $2 = Vorgabewert (leer = keine), Ergebnis in $REPLY_VALUE.
	local prompt="$1" default="$2" value=""
	while [ -z "$value" ]; do
		if [ -n "$default" ]; then
			read -r -p "    $prompt [$default]: " value
			value="${value:-$default}"
		else
			read -r -p "    $prompt: " value
		fi
		[ -z "$value" ] && warn "Darf nicht leer sein."
	done
	REPLY_VALUE="$value"
}

if [ -n "$EXISTING_ENV" ]; then
	step "Vorhandene Konfiguration wird übernommen"
	info "$EXISTING_ENV bleibt unverändert."
else
	step "Zugangsdaten eingeben"
	info "WordPress-Backend: Lindenweg16 → Betrieb → Bambu-Lab-Drucker → Drucker anlegen."
	info "Das dabei angezeigte Token nur EINMAL angezeigt – jetzt bereithalten."
	echo

	prompt_required "WordPress-Adresse (ohne abschließenden Schrägstrich)" "$SUGGESTED_WORDPRESS_URL"
	WORDPRESS_URL="${REPLY_VALUE%/}"

	prompt_required "API-Token (aus dem WordPress-Backend, einmalig angezeigt)" ""
	API_TOKEN="$REPLY_VALUE"

	prompt_required "Drucker-ID (derselbe Schlüssel wie im WordPress-Backend, z. B. bambu_p1s_1)" "bambu_p1s_1"
	PRINTER_ID="$REPLY_VALUE"

	info "Am Drucker: Einstellungen → LAN-Modus – dort stehen IP, Zugangscode und Seriennummer."
	info "Dort auch den Entwicklermodus (Developer Mode) einschalten – ohne ihn nimmt aktuelle Firmware keine Druckstarts an."
	info "Der Zugangscode bleibt nur hier auf dem Pi (.env.bambu); WordPress speichert ihn nicht."
	prompt_required "Bambu-Drucker IP-Adresse" ""
	BAMBU_IP="$REPLY_VALUE"

	prompt_required "Bambu LAN-Zugangscode" ""
	BAMBU_ACCESS_CODE="$REPLY_VALUE"

	prompt_required "Bambu-Seriennummer" ""
	BAMBU_SERIAL="$REPLY_VALUE"

	read -r -p "    Long Polling aktivieren? Nur ja, wenn der eigene Webspace lang offen gehaltene Verbindungen zuverlässig durchreicht (siehe README) [j/N] " long_poll
	case "$long_poll" in
		[jJ]*) LONG_POLL_ENABLED="true" ;;
		*) LONG_POLL_ENABLED="false" ;;
	esac
fi

# -----------------------------------------------------------------------
# Verzeichnis, venv, Abhängigkeiten
# -----------------------------------------------------------------------

step "Installationsverzeichnis anlegen"
sudo mkdir -p "$INSTALL_DIR"
sudo chown "$CURRENT_USER:$CURRENT_USER" "$INSTALL_DIR"
ok "$INSTALL_DIR bereit."

step "Dateien kopieren"
cp -v bambu_bridge.py requirements-bambu.txt "$INSTALL_DIR/" | sed 's/^/    /'

step "Python-Umgebung einrichten"
if [ ! -d "$INSTALL_DIR/venv" ]; then
	python3 -m venv "$INSTALL_DIR/venv"
	ok "Virtuelle Umgebung angelegt."
else
	info "Virtuelle Umgebung existiert bereits, wird weiterverwendet."
fi
"$INSTALL_DIR/venv/bin/pip" install --quiet --upgrade pip
"$INSTALL_DIR/venv/bin/pip" install --quiet -r "$INSTALL_DIR/requirements-bambu.txt"
ok "Abhängigkeiten (requests, paho-mqtt) installiert."

# -----------------------------------------------------------------------
# .env.bambu schreiben (nur bei Neukonfiguration)
# -----------------------------------------------------------------------

if [ -z "$EXISTING_ENV" ]; then
	step "Konfiguration schreiben"
	cat > "$INSTALL_DIR/.env.bambu" <<-EOF
	WORDPRESS_URL=${WORDPRESS_URL}
	API_TOKEN=${API_TOKEN}
	PRINTER_ID=${PRINTER_ID}
	BAMBU_IP=${BAMBU_IP}
	BAMBU_ACCESS_CODE=${BAMBU_ACCESS_CODE}
	BAMBU_SERIAL=${BAMBU_SERIAL}
	LONG_POLL_ENABLED=${LONG_POLL_ENABLED}
	DOWNLOAD_DIRECTORY=/var/lib/bambu-bridge
	LOG_LEVEL=INFO
	EOF
	chmod 600 "$INSTALL_DIR/.env.bambu"
	ok "$INSTALL_DIR/.env.bambu geschrieben (Zugriff auf den eigenen Benutzer beschränkt)."
fi

# -----------------------------------------------------------------------
# systemd-Dienst
# -----------------------------------------------------------------------

step "systemd-Dienst einrichten"
sed "s/User=pi/User=$CURRENT_USER/" bambu-bridge.service | sudo tee "/etc/systemd/system/${SERVICE_NAME}.service" >/dev/null
sudo systemctl daemon-reload
sudo systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
sudo systemctl restart "$SERVICE_NAME"
ok "Dienst '${SERVICE_NAME}' läuft und startet nach einem Neustart automatisch wieder."

sleep 2
if sudo systemctl is-active --quiet "$SERVICE_NAME"; then
	ok "Status: aktiv."
else
	warn "Dienst ist nicht aktiv – Log prüfen: journalctl -u ${SERVICE_NAME} -n 50"
fi

# -----------------------------------------------------------------------
# Abschluss
# -----------------------------------------------------------------------

step "Fertig"
info "Log verfolgen:      journalctl -u ${SERVICE_NAME} -f"
info "Konfiguration:      $INSTALL_DIR/.env.bambu"
info "Nächster Schritt:   In WordPress unter Lindenweg16 → Betrieb → Bambu-Lab-Drucker"
info "                    pruefen, ob der Drucker nach kurzer Zeit als \"Online\" erscheint."
