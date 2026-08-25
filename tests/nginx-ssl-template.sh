#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT INT TERM

mkdir -p "$TMP/examples" "$TMP/templates" "$TMP/ssl/ssl-app" "$TMP/ssl/http-only"
cp "$ROOT/nginx/examples/server_example.txt" "$TMP/examples/"
cp "$ROOT/nginx/examples/server_ssl_example.txt" "$TMP/examples/"

cat > "$TMP/env.json" <<'EOF'
{
  "SERVER_NAME1": {
    "APP_NAME": "ssl-app",
    "DOMAIN_NAME": "ssl-app.test",
    "SERVER_PATH": "/var/www/source_php8.5/ssl-app/public",
    "CONTAINER_PHP_VERSION": "php8.5_container",
    "ENABLED": true,
    "SSL_ENABLED": true,
    "SSL_MODE": "generated"
  },
  "SERVER_NAME2": {
    "APP_NAME": "http-only",
    "DOMAIN_NAME": "http-only.test",
    "SERVER_PATH": "/var/www/source_php8.5/http-only/public",
    "CONTAINER_PHP_VERSION": "php8.5_container",
    "ENABLED": true,
    "SSL_ENABLED": false
  },
  "SERVER_NAME3": {
    "APP_NAME": "ssl-missing",
    "DOMAIN_NAME": "ssl-missing.test",
    "SERVER_PATH": "/var/www/source_php8.5/ssl-missing/public",
    "CONTAINER_PHP_VERSION": "php8.5_container",
    "ENABLED": true,
    "SSL_ENABLED": true
  }
}
EOF

printf 'dummy-cert\n' > "$TMP/ssl/ssl-app/cert.pem"
printf 'dummy-key\n' > "$TMP/ssl/ssl-app/key.pem"

ENV_JSON_FILE="$TMP/env.json" \
NGINX_TEMPLATE_FILE="$TMP/examples/server_example.txt" \
NGINX_SSL_TEMPLATE_FILE="$TMP/examples/server_ssl_example.txt" \
NGINX_OUTPUT_DIR="$TMP/templates" \
NGINX_SSL_DIR="$TMP/ssl" \
sh "$ROOT/scripts/nginx/auto-add-template.sh"

ssl_out="$TMP/templates/ssl-app.template"
http_out="$TMP/templates/http-only.template"
missing_out="$TMP/templates/ssl-missing.template"

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

grep -q 'listen 80' "$ssl_out" || fail "ssl-app missing listen 80"
grep -q 'listen 443 ssl' "$ssl_out" || fail "ssl-app missing listen 443"
grep -q 'ssl-app.test' "$ssl_out" || fail "ssl-app missing domain"
grep -q "$TMP/ssl/ssl-app/cert.pem" "$ssl_out" || fail "ssl-app missing cert path"

grep -q 'listen 80' "$http_out" || fail "http-only missing listen 80"
if grep -q 'listen 443' "$http_out"; then
    fail "http-only should not listen 443"
fi

grep -q 'listen 80' "$missing_out" || fail "ssl-missing missing listen 80"
if grep -q 'listen 443' "$missing_out"; then
    fail "ssl-missing without files should not listen 443"
fi

echo "OK: nginx ssl template generation"

# Certs live next to env.json (same layout as /var/host-project), without NGINX_SSL_DIR.
PROJ="$(mktemp -d)"
trap 'rm -rf "$TMP" "$PROJ"' EXIT INT TERM
mkdir -p "$PROJ/nginx/examples" "$PROJ/nginx/templates" "$PROJ/nginx/ssl/proj-app"
cp "$ROOT/nginx/examples/server_example.txt" "$PROJ/nginx/examples/"
cp "$ROOT/nginx/examples/server_ssl_example.txt" "$PROJ/nginx/examples/"
printf 'dummy-cert\n' > "$PROJ/nginx/ssl/proj-app/cert.pem"
printf 'dummy-key\n' > "$PROJ/nginx/ssl/proj-app/key.pem"
cat > "$PROJ/env.json" <<'EOF'
{
  "SERVER_NAME1": {
    "APP_NAME": "proj-app",
    "DOMAIN_NAME": "proj-app.test",
    "SERVER_PATH": "/var/www/source_php8.5/proj-app/public",
    "CONTAINER_PHP_VERSION": "php8.5_container",
    "ENABLED": true,
    "SSL_ENABLED": true,
    "SSL_MODE": "generated"
  }
}
EOF

ENV_JSON_FILE="$PROJ/env.json" \
NGINX_TEMPLATE_FILE="$PROJ/nginx/examples/server_example.txt" \
NGINX_SSL_TEMPLATE_FILE="$PROJ/nginx/examples/server_ssl_example.txt" \
NGINX_OUTPUT_DIR="$PROJ/nginx/templates" \
sh "$ROOT/scripts/nginx/auto-add-template.sh"

proj_out="$PROJ/nginx/templates/proj-app.template"
grep -q 'listen 443 ssl' "$proj_out" || fail "project-layout ssl-app missing listen 443"
grep -q "$PROJ/nginx/ssl/proj-app/cert.pem" "$proj_out" || fail "project-layout cert path should match env.json sibling nginx/ssl"

echo "OK: nginx ssl template uses certs beside env.json"
