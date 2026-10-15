#!/usr/bin/env bash
# Issue a mkcert certificate into nginx/ssl/<app>/.
# Usage: trust_local_ca.sh <app> <domain>
#        trust_local_ca.sh <project-root> <app> <domain>
set -eu

if [ "$#" -eq 3 ]; then
  ROOT="$1"
  APP="$2"
  DOMAIN="$3"
else
  ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
  APP="${1:-}"
  DOMAIN="${2:-}"
fi

if ! printf '%s' "$APP" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'; then
  echo "unsafe app name" >&2
  exit 1
fi
if ! printf '%s' "$DOMAIN" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$'; then
  echo "unsafe domain" >&2
  exit 1
fi

if ! command -v mkcert >/dev/null 2>&1; then
  mkdir -p "$ROOT/runtime"
  printf '%s %s %s missing\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$APP" "$DOMAIN" >> "$ROOT/runtime/ssl.trust.result"
  exit 2
fi

mkcert -install
mkdir -p "$ROOT/nginx/ssl/$APP"
mkcert -cert-file "$ROOT/nginx/ssl/$APP/cert.pem" -key-file "$ROOT/nginx/ssl/$APP/key.pem" "$DOMAIN"
mkdir -p "$ROOT/runtime"
printf '%s %s %s ok\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$APP" "$DOMAIN" >> "$ROOT/runtime/ssl.trust.result"
