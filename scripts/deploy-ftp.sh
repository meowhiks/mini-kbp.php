#!/usr/bin/env bash
# Upload dist-ftp/ → hosting document root via lftp.
#
# Required (env or .ftp-credentials, never commit):
#   FTP_HOST=...
#   FTP_USER=...
#   FTP_PASS=...
# Optional:
#   FTP_DIR=public_html
#   FTP_PARALLEL=16
#   FTP_MAX_RATE=0          # bytes/s total; 0 = unlimited
#   FTP_CLEAR_TT_CACHE=1    # delete remote tt_*.json after upload (default 1)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FTP_HOST="${FTP_HOST:-}"
FTP_USER="${FTP_USER:-}"
FTP_DIR="${FTP_DIR:-public_html}"
FTP_PARALLEL="${FTP_PARALLEL:-16}"
FTP_MAX_RATE="${FTP_MAX_RATE:-0}"
FTP_CLEAR_TT_CACHE="${FTP_CLEAR_TT_CACHE:-1}"

if [[ -f "$ROOT/.ftp-credentials" ]]; then
  # shellcheck disable=SC1091
  source "$ROOT/.ftp-credentials"
fi

if [[ -z "${FTP_HOST:-}" || -z "${FTP_USER:-}" || -z "${FTP_PASS:-}" ]]; then
  echo "FTP_HOST, FTP_USER and FTP_PASS are required. Example:" >&2
  echo "  FTP_HOST=… FTP_USER=… FTP_PASS=… ./scripts/deploy-ftp.sh" >&2
  echo "  # or put them in .ftp-credentials (gitignored)" >&2
  exit 1
fi

./scripts/build-ftp.sh

RATE_CMD=""
if [[ "$FTP_MAX_RATE" != "0" && -n "$FTP_MAX_RATE" ]]; then
  RATE_CMD="set net:limit-total-rate 0:${FTP_MAX_RATE};"
  echo "Soft rate cap: ${FTP_MAX_RATE} B/s (upload)"
else
  echo "Unlimited transfer rate (max speed)"
fi

CLEAR_CMD=""
if [[ "$FTP_CLEAR_TT_CACHE" == "1" ]]; then
  CLEAR_CMD="cd cache; glob -a rm -f tt_*.json; cd ..;"
  echo "Will clear remote tt_*.json after upload"
fi

echo "Uploading $ROOT/dist-ftp → ftp://$FTP_USER@$FTP_HOST/$FTP_DIR (parallel=${FTP_PARALLEL}) …"
# Password comes from env/.ftp-credentials — do not echo it.
lftp -e "
set ssl:verify-certificate no;
set ftp:ssl-force true;
set ftp:ssl-protect-data true;
set ftp:passive-mode true;
set net:max-retries 5;
set net:timeout 60;
set net:connection-limit ${FTP_PARALLEL};
set net:socket-buffer 1048576;
set xfer:clobber true;
${RATE_CMD}
open -u ${FTP_USER},${FTP_PASS} ${FTP_HOST};
cd ${FTP_DIR};
mirror -R --verbose --parallel=${FTP_PARALLEL} \
  --exclude-glob cache/** \
  --exclude-glob .git/** \
  --exclude-glob .ftp-credentials \
  dist-ftp/ .;
${CLEAR_CMD}
bye
"

echo "Done. Check https://mini-kbp.site/api/health.php"
