#!/usr/bin/env bash
# Baut das installierbare Theme-ZIP: block-theme/dist/lindenweg16.zip
# Aufruf: bash block-theme/build-zip.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dist="$here/dist"
mkdir -p "$dist"
rm -f "$dist/lindenweg16.zip"

cd "$here"
zip -r -X -q "$dist/lindenweg16.zip" lindenweg16 \
	-x 'lindenweg16/.*' 'lindenweg16/**/.DS_Store' 'lindenweg16/node_modules/*'

echo "Fertig: $dist/lindenweg16.zip ($(du -h "$dist/lindenweg16.zip" | cut -f1))"
