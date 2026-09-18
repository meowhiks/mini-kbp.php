#!/bin/bash
set -euo pipefail

DOMAIN="${DOMAIN:-localhost}"
EMAIL="${CERTBOT_EMAIL:-admin@localhost}"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
SELF_DIR="/etc/ssl/mini-kbp"

mkdir -p /var/www/app/cache /var/www/certbot "$SELF_DIR"
chown -R www-data:www-data /var/www/app/cache || true

# Prefer Let's Encrypt cert if present; else self-signed for local/dev.
if [[ ! -f "${CERT_DIR}/fullchain.pem" ]]; then
  if [[ ! -f "${SELF_DIR}/fullchain.pem" ]]; then
    echo "[ssl] Generating self-signed certificate for ${DOMAIN}"
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout "${SELF_DIR}/privkey.pem" \
      -out "${SELF_DIR}/fullchain.pem" \
      -subj "/CN=${DOMAIN}" >/dev/null 2>&1
  fi
  export SSL_CERT_FILE="${SELF_DIR}/fullchain.pem"
  export SSL_KEY_FILE="${SELF_DIR}/privkey.pem"
else
  export SSL_CERT_FILE="${CERT_DIR}/fullchain.pem"
  export SSL_KEY_FILE="${CERT_DIR}/privkey.pem"
fi

# Inject cert paths into SSL vhost
sed -i "s|SSLCertificateFile .*|SSLCertificateFile ${SSL_CERT_FILE}|" /etc/apache2/sites-available/default-ssl.conf
sed -i "s|SSLCertificateKeyFile .*|SSLCertificateKeyFile ${SSL_KEY_FILE}|" /etc/apache2/sites-available/default-ssl.conf

# Optional: obtain/renew via certbot when ENABLE_CERTBOT=1 and DOMAIN is public
if [[ "${ENABLE_CERTBOT:-0}" == "1" && "${DOMAIN}" != "localhost" ]]; then
  echo "[certbot] Attempting certificate for ${DOMAIN}"
  certbot certonly --webroot -w /var/www/certbot \
    -d "${DOMAIN}" --email "${EMAIL}" --agree-tos --non-interactive \
    || echo "[certbot] Issuance skipped/failed — using existing/self-signed cert"
  if [[ -f "${CERT_DIR}/fullchain.pem" ]]; then
    sed -i "s|SSLCertificateFile .*|SSLCertificateFile ${CERT_DIR}/fullchain.pem|" /etc/apache2/sites-available/default-ssl.conf
    sed -i "s|SSLCertificateKeyFile .*|SSLCertificateKeyFile ${CERT_DIR}/privkey.pem|" /etc/apache2/sites-available/default-ssl.conf
  fi
fi

exec "$@"
