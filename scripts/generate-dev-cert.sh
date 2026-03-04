#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CERT_DIR="${1:-$ROOT_DIR/certs}"
COMMON_NAME="${2:-127.0.0.1}"
ALT_NAME="${3:-localhost}"

mkdir -p "$CERT_DIR"

KEY_FILE="$CERT_DIR/dev-key.pem"
CERT_FILE="$CERT_DIR/dev-cert.pem"

build_san_entry() {
  local name="$1"
  if [[ "$name" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf 'IP:%s' "$name"
  else
    printf 'DNS:%s' "$name"
  fi
}

SAN="$(build_san_entry "$COMMON_NAME"),$(build_san_entry "$ALT_NAME")"

openssl req \
  -x509 \
  -newkey rsa:2048 \
  -keyout "$KEY_FILE" \
  -out "$CERT_FILE" \
  -sha256 \
  -days 7 \
  -nodes \
  -subj "/CN=$COMMON_NAME" \
  -addext "subjectAltName=$SAN"

chmod 600 "$KEY_FILE"

cat <<EOF
generated:
  cert: $CERT_FILE
  key:  $KEY_FILE
  san:  $SAN

run:
  php server.php 127.0.0.1 18443 $CERT_FILE $KEY_FILE
  curl --http2 -k https://127.0.0.1:18443/
EOF
