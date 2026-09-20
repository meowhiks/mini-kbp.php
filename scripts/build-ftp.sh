#!/usr/bin/env bash
# Assemble FTP upload tree: public/ + src/ + config → dist-ftp/
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist-ftp"

rm -rf "$OUT"
mkdir -p "$OUT"

# Document root contents
cp -a "$ROOT/public/." "$OUT/"

# PHP classes live next to docroot on shared hosting
mkdir -p "$OUT/src"
cp -a "$ROOT/src/." "$OUT/src/"

# Runtime config (example always; real config if present locally)
cp -f "$ROOT/config.example.php" "$OUT/config.example.php"
if [[ -f "$ROOT/config.php" ]]; then
  cp -f "$ROOT/config.php" "$OUT/config.php"
else
  cp -f "$ROOT/config.example.php" "$OUT/config.php"
fi

# Empty writable cache dir (do not ship local cache payloads)
mkdir -p "$OUT/cache"
# keep any .gitkeep from public if present
touch "$OUT/cache/.gitkeep"

# Keep the public htaccess (already copied)
# Drop docker-only / local junk / generated calendar dumps
rm -f "$OUT/.env" "$OUT/credentials.json" "$OUT/.ftp-credentials" 2>/dev/null || true
find "$OUT/cal" -type f ! -name '.htaccess' ! -name '.gitkeep' -delete 2>/dev/null || true
rm -f "$OUT/assets/"*.ics 2>/dev/null || true

echo "Built $OUT ($(find "$OUT" -type f | wc -l) files)"
