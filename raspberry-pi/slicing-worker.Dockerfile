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

FROM debian:bookworm-slim

# OrcaSlicer ist im "CLI-Modus" technisch dieselbe GUI-Anwendung wie im
# normalen Betrieb, nur ohne sichtbares Fenster - sie braucht deshalb
# trotzdem denselben grafischen Unterbau (Qt, OpenGL, XCB) wie ein echter
# Desktop. xvfb liefert dafür eine virtuelle Anzeige, ohne dass ein
# echter Bildschirm oder X-Server am Container hängen muss.
RUN apt-get update && apt-get install -y --no-install-recommends \
		ca-certificates curl \
		xvfb libgl1 libglu1-mesa libegl1 libopengl0 \
		libxkbcommon0 libxkbcommon-x11-0 libdbus-1-3 \
		libxcb-cursor0 libxcb-icccm4 libxcb-image0 libxcb-keysyms1 \
		libxcb-randr0 libxcb-render-util0 libxcb-shape0 \
		libxcb-xinerama0 libxcb-xkb1 libxrender1 libxi6 libsm6 \
		libgstreamer1.0-0 libgstreamer-plugins-base1.0-0 \
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
# GitHub listet je Release mehrere Linux-AppImages (x86_64 UND aarch64) -
# ohne Architektur-Filter griff das vorherige Muster blind die erste
# gelistete, was auf einem x86_64-Server zum aarch64-Build und "Exec
# format error" beim Ausführen führte. Deshalb an die tatsächliche
# Build-Architektur anpassen.
ARG ORCASLICER_VERSION=latest
RUN set -eux; \
	arch="$(uname -m)"; \
	case "$arch" in \
		x86_64) pattern="x86_64" ;; \
		aarch64|arm64) pattern="aarch64" ;; \
		*) echo "Nicht unterstuetzte Architektur fuer OrcaSlicer: $arch"; exit 1 ;; \
	esac; \
	if [ "$ORCASLICER_VERSION" = "latest" ]; then \
		API_URL=https://api.github.com/repos/OrcaSlicer/OrcaSlicer/releases/latest; \
	else \
		API_URL="https://api.github.com/repos/OrcaSlicer/OrcaSlicer/releases/tags/${ORCASLICER_VERSION}"; \
	fi; \
	URL=$(curl -sL "$API_URL" \
		| grep -o "\"browser_download_url\": *\"[^\"]*${pattern}[^\"]*\\.AppImage\"" \
		| head -n1 | cut -d '"' -f4); \
	[ -n "$URL" ] || { echo "Konnte keine Linux-AppImage-URL (Architektur $arch) fuer Version '$ORCASLICER_VERSION' finden."; exit 1; }; \
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
