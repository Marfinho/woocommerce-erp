# Headless-Slicing-Worker (siehe slicing_worker.py) samt OrcaSlicer, für
# einen eigenen Server im selben Netz wie der Bambu-Pi - läuft NICHT auf
# dem Raspberry Pi selbst (ARM), OrcaSlicer braucht x86_64.
#
# Bauen:
#   docker build -f slicing-worker.Dockerfile -t lw16-slicing-worker .
# Start siehe docker-compose.slicing-worker.yml.
#
# WICHTIGER VORBEHALT (siehe auch slicing_worker.py): Dieses Image wurde
# nicht in dieser Umgebung gebaut/getestet (kein Docker, kein Zugriff auf
# eine echte OrcaSlicer-Installation hier) - vor dem produktiven Einsatz
# unbedingt mit einer unkritischen Testplatte von Hand kontrollieren.

# Ubuntu 24.04, nicht Debian - die OrcaSlicer-Linux-AppImage heisst nicht
# zufällig "..._Ubuntu2404_...": sie ist gegen dessen glibc/libstdc++
# gelinkt (glibc >= 2.38, GLIBCXX >= 3.4.32). debian:bookworm-slim
# (Debian 12) bringt nur glibc 2.36 mit - der Slicer startete damit gar
# nicht erst ("version `GLIBCXX_3.4.32' not found").
FROM ubuntu:24.04

# Ohne das würde apt-get bei manchen Paketen (z. B. tzdata als
# Abhängigkeit) interaktiv nach der Zeitzone fragen und den Build ohne
# Terminal endlos hängen lassen.
ENV DEBIAN_FRONTEND=noninteractive

# OrcaSlicer ist im "CLI-Modus" technisch dieselbe GUI-Anwendung wie im
# normalen Betrieb, nur ohne sichtbares Fenster - sie braucht deshalb
# trotzdem denselben grafischen Unterbau (Qt, OpenGL, XCB) wie ein echter
# Desktop. xvfb liefert dafür eine virtuelle Anzeige, ohne dass ein
# echter Bildschirm oder X-Server am Container hängen muss. Die in
# OrcaSlicer eingebettete Hilfe-/Onboarding-Oberfläche ist eine
# WebKitGTK-Ansicht (libwebkit2gtk-4.1) - fehlt sie, bricht schon der
# Start ab, auch im reinen CLI-Slice-Betrieb ohne sichtbares Fenster.
RUN apt-get update && apt-get install -y --no-install-recommends \
		ca-certificates curl \
		xvfb xauth libgl1 libglu1-mesa libegl1 libopengl0 \
		libxkbcommon0 libxkbcommon-x11-0 libdbus-1-3 \
		libxcb-cursor0 libxcb-icccm4 libxcb-image0 libxcb-keysyms1 \
		libxcb-randr0 libxcb-render-util0 libxcb-shape0 \
		libxcb-xinerama0 libxcb-xkb1 libxrender1 libxi6 libsm6 \
		libgstreamer1.0-0 libgstreamer-plugins-base1.0-0 \
		libwebkit2gtk-4.1-0 \
		python3 python3-venv python3-pip \
	&& rm -rf /var/lib/apt/lists/*

# OrcaSlicer-AppImage laden und entpacken statt es per FUSE auszuführen -
# FUSE ist in Docker-Containern ohne --privileged/--device meist nicht
# verfügbar, das entpackte squashfs-root braucht das nicht.
#
# ORCASLICER_VERSION per --build-arg auf ein festes Tag pinnen (z. B.
# "v2.2.0", siehe https://github.com/OrcaSlicer/OrcaSlicer/releases) für
# reproduzierbare Builds; "latest" (Standard) holt bei jedem Build ohne
# Cache-Treffer die zu diesem Zeitpunkt aktuellste Version.
# GitHub listet je Release mehrere Linux-AppImages, aber NICHT
# symmetrisch benannt: die x86_64-Datei trägt keinerlei Architektur-
# Kennung im Namen (z. B. "..._Ubuntu2404_V2.4.2.AppImage" - x86_64 ist
# der unmarkierte Standardfall), nur die aarch64-Variante nennt sich
# ausdrücklich so ("..._aarch64_V2.4.2.AppImage"). Ein Filter, der nach
# "x86_64" im Dateinamen sucht, findet deshalb auf x86_64 nichts -
# stattdessen für x86_64 die Linux-AppImage OHNE "aarch64" im Namen
# nehmen, für aarch64 die MIT "aarch64" im Namen.
#
# Die API-Antwort landet dabei in einer Datei statt direkt in grep zu
# pipen - schlägt die Anfrage fehl (Rate-Limit, Netzwerk, GitHub down),
# zeigt das sonst nur ein leeres, nicht diagnostizierbares grep-Ergebnis
# statt der eigentlichen Fehlerantwort.
ARG ORCASLICER_VERSION=latest
RUN set -eux; \
	arch="$(uname -m)"; \
	case "$arch" in \
		x86_64|aarch64|arm64) : ;; \
		*) echo "Nicht unterstuetzte Architektur fuer OrcaSlicer: $arch"; exit 1 ;; \
	esac; \
	if [ "$ORCASLICER_VERSION" = "latest" ]; then \
		API_URL=https://api.github.com/repos/OrcaSlicer/OrcaSlicer/releases/latest; \
	else \
		API_URL="https://api.github.com/repos/OrcaSlicer/OrcaSlicer/releases/tags/${ORCASLICER_VERSION}"; \
	fi; \
	HTTP_CODE=$(curl -sL -o /tmp/release.json -w "%{http_code}" "$API_URL"); \
	if [ "$HTTP_CODE" != "200" ]; then \
		echo "GitHub-API-Anfrage fehlgeschlagen (HTTP $HTTP_CODE): $API_URL"; \
		echo "Antwort:"; cat /tmp/release.json; \
		exit 1; \
	fi; \
	LINUX_URLS=$(grep -o '"browser_download_url": *"[^"]*[Ll]inux[^"]*\.AppImage"' /tmp/release.json | cut -d '"' -f4); \
	case "$arch" in \
		x86_64) URL=$(echo "$LINUX_URLS" | grep -vi aarch64 | head -n1) ;; \
		aarch64|arm64) URL=$(echo "$LINUX_URLS" | grep -i aarch64 | head -n1) ;; \
	esac; \
	[ -n "$URL" ] || { echo "Konnte keine Linux-AppImage-URL (Architektur $arch) fuer Version '$ORCASLICER_VERSION' finden. Verfuegbare Assets:"; grep -o '"browser_download_url": *"[^"]*"' /tmp/release.json; exit 1; }; \
	rm /tmp/release.json; \
	echo "Lade $URL"; \
	curl -L -o /tmp/OrcaSlicer.AppImage "$URL"; \
	chmod +x /tmp/OrcaSlicer.AppImage; \
	cd /opt; \
	/tmp/OrcaSlicer.AppImage --appimage-extract >/dev/null; \
	mv squashfs-root /opt/orcaslicer; \
	rm /tmp/OrcaSlicer.AppImage

# Wrapper: OrcaSlicer immer unter einer frischen virtuellen Anzeige
# starten - dasselbe Kommando, das slicing_worker.py über SLICER_CLI
# aufruft (siehe run_slicer() dort).
RUN printf '#!/bin/sh\nexec xvfb-run -a /opt/orcaslicer/AppRun "$@"\n' > /usr/local/bin/orca-slicer \
	&& chmod +x /usr/local/bin/orca-slicer

WORKDIR /opt/slicing-worker
COPY requirements-slicing.txt .
RUN python3 -m venv venv && venv/bin/pip install --no-cache-dir -r requirements-slicing.txt
COPY slicing_worker.py .

ENV SLICER_CLI=/usr/local/bin/orca-slicer
ENV DOWNLOAD_DIRECTORY=/data
VOLUME ["/data"]

ENTRYPOINT ["/opt/slicing-worker/venv/bin/python3", "/opt/slicing-worker/slicing_worker.py"]
