#!/usr/bin/env bash
# Builds a clean release tree in dist/<slug> (and a zip) without development files.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=$(basename "$PWD")
rm -rf dist && mkdir -p "dist/$SLUG"
rsync -a --exclude-from=.distignore ./ "dist/$SLUG/"
find "dist/$SLUG" -name '.DS_Store' -delete
(cd dist && zip -qr "$SLUG.zip" "$SLUG")
echo "dist/$SLUG.zip"
