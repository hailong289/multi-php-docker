#!/usr/bin/env bash
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
mkdir -p "$TMP/bin" "$TMP/nginx/ssl"
cat > "$TMP/bin/mkcert" << 'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = "-install" ]; then exit 0; fi
while [ $# -gt 0 ]; do
  case "$1" in
    -cert-file) cert="$2"; shift 2 ;;
    -key-file) key="$2"; shift 2 ;;
    *) domain="$1"; shift ;;
  esac
done
printf 'CERT %s\n' "$domain" > "$cert"
printf 'KEY %s\n' "$domain" > "$key"
EOF
chmod +x "$TMP/bin/mkcert"
PATH="$TMP/bin:$PATH" "$ROOT/scripts/ssl/trust_local_ca.sh" "$TMP" my-app my-app.test
test -f "$TMP/nginx/ssl/my-app/cert.pem"
grep -q 'CERT my-app.test' "$TMP/nginx/ssl/my-app/cert.pem"
set +e
PATH="/usr/bin:/bin" "$ROOT/scripts/ssl/trust_local_ca.sh" "$TMP" my-app my-app.test
code=$?
set -e
if [ "$code" -ne 2 ]; then
  echo "expected missing mkcert to fail with 2, got $code" >&2
  exit 1
fi
echo "OK: ssl trust script"
