#!/usr/bin/env bash
# Copies the built React app (frontend/dist) into backend/public so Laravel serves it (same origin, cookie auth).
# Brand assets under backend/public/assets/brand are never overwritten.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
rm -rf "$ROOT/backend/public/spa"
cp -R "$ROOT/frontend/dist/spa" "$ROOT/backend/public/spa"
cp "$ROOT/frontend/dist/index.html" "$ROOT/backend/public/index.html"
echo "frontend synced into backend/public"
