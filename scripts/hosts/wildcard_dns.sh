#!/usr/bin/env bash
# Point whole local TLDs at the dns container so new domains need no hosts-file write (macOS / Linux).
# Usage:
#   ./scripts/hosts/wildcard_dns.sh install [tld...]   # default: test
#   ./scripts/hosts/wildcard_dns.sh remove
#   ./scripts/hosts/wildcard_dns.sh status
# Supported TLDs: test, internal, lan (the Corefile's host block). Windows keeps using the hosts file.

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MARKER="$REPO_ROOT/runtime/hosts.wildcard.json"
SUPPORTED="test internal lan"
RESOLVED_CONF="/etc/systemd/resolved.conf.d/multi-php-local.conf"

PORT="$(sed -n 's/^DNS_HOST_PORT=//p' "$REPO_ROOT/.env" 2>/dev/null | tail -1)"
PORT="${PORT:-10053}"

case "$(uname -s)" in
  Darwin) OS=macos ;;
  Linux) OS=linux ;;
  *) echo "Only macOS and Linux are supported; keep using the hosts file." >&2; exit 1 ;;
esac

if [ "$OS" = linux ] && ! systemctl is-active --quiet systemd-resolved 2>/dev/null; then
  echo "systemd-resolved is not running; keep using the hosts file." >&2
  exit 1
fi

# Manager reads this to skip hosts writes for covered domains.
write_marker() {
  mkdir -p "$(dirname "$MARKER")"
  local list=""
  for tld in "$@"; do list="$list${list:+,}\"$tld\""; done
  printf '{"tlds":[%s],"port":%s,"os":"%s"}\n' "$list" "$PORT" "$OS" > "$MARKER"
}

installed_tlds() {
  if [ "$OS" = macos ]; then
    for tld in $SUPPORTED; do
      if grep -qs "port $PORT" "/etc/resolver/$tld"; then printf '%s ' "$tld"; fi
    done
  else
    sed -n 's/^Domains=//p' "$RESOLVED_CONF" 2>/dev/null | tr -d '~'
  fi
  return 0
}

cmd="${1:-status}"
[ $# -gt 0 ] && shift

case "$cmd" in
  install)
    tlds=("$@")
    [ ${#tlds[@]} -eq 0 ] && tlds=(test)
    for tld in "${tlds[@]}"; do
      case " $SUPPORTED " in
        *" $tld "*) ;;
        *) echo "Unsupported TLD: $tld (supported: $SUPPORTED)" >&2; exit 1 ;;
      esac
    done
    if [ "$OS" = macos ]; then
      sudo mkdir -p /etc/resolver
      for tld in "${tlds[@]}"; do
        printf 'nameserver 127.0.0.1\nport %s\n' "$PORT" | sudo tee "/etc/resolver/$tld" >/dev/null
      done
    else
      sudo mkdir -p "$(dirname "$RESOLVED_CONF")"
      domains=""
      for tld in "${tlds[@]}"; do domains="$domains ~$tld"; done
      printf '[Resolve]\nDNS=127.0.0.1:%s\nDomains=%s\n' "$PORT" "${domains# }" | sudo tee "$RESOLVED_CONF" >/dev/null
      sudo systemctl restart systemd-resolved
    fi
    write_marker "${tlds[@]}"
    echo "Installed for: ${tlds[*]} (dns container on 127.0.0.1:$PORT)."
    ;;
  remove)
    if [ "$OS" = macos ]; then
      for tld in $SUPPORTED; do
        if grep -qs "port $PORT" "/etc/resolver/$tld"; then sudo rm -f "/etc/resolver/$tld"; fi
      done
    elif [ -f "$RESOLVED_CONF" ]; then
      sudo rm -f "$RESOLVED_CONF"
      sudo systemctl restart systemd-resolved
    fi
    rm -f "$MARKER"
    echo "Removed. New domains need hosts-file writes again."
    ;;
  status)
    current="$(installed_tlds)"
    # Re-sync the marker with what is really configured (e.g. a resolver file removed by hand).
    if [ -n "${current// /}" ]; then
      # shellcheck disable=SC2086
      write_marker $current
      echo "Installed for: $current(port $PORT)"
    else
      rm -f "$MARKER"
      echo "Not installed."
    fi
    ;;
  *)
    sed -n '2,7p' "$0"
    exit 1
    ;;
esac
