#!/usr/bin/env bash
# multi-php-ssl://issue?id=<token> → trust_local_ca.sh when the token matches runtime/ssl.trust
set -eu
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
URL="${1:-}"
raw="${URL#multi-php-ssl:}"
raw="${raw#//}"
token=""
if printf '%s' "$raw" | grep -Eq '[?&]id=[a-fA-F0-9]{8,64}'; then
  token="$(printf '%s' "$raw" | sed -n 's/.*[?&]id=\([a-fA-F0-9]\{8,64\}\).*/\1/p' | tr '[:upper:]' '[:lower:]')"
fi
file="$REPO_ROOT/runtime/ssl.trust"
if [ ! -f "$file" ] || [ -z "$token" ]; then
  exit 1
fi
got="$(sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([a-f0-9]*\)".*/\1/p' "$file" | head -n 1)"
if [ "$got" != "$token" ]; then
  exit 1
fi
app="$(sed -n 's/.*"app_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$file" | head -n 1)"
domain="$(sed -n 's/.*"domain_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$file" | head -n 1)"
exec "$SCRIPT_DIR/trust_local_ca.sh" "$app" "$domain"
