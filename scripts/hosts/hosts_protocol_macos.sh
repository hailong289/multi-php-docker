#!/usr/bin/env bash
# URL protocol handler for multi-php-hosts: on macOS (registered by ensure_hosts_env.sh).
# Browser opens multi-php-hosts://write?id=<token> → wait for matching runtime/hosts.sync
# → add_hostname.sh --force-admin.
# MultiPhpHosts.app launches this via nohup so the applet stays responsive.

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ADD_HOSTNAME="$SCRIPT_DIR/add_hostname.sh"
SYNC_FILE="$REPO_ROOT/runtime/hosts.sync"
LOG_FILE="$REPO_ROOT/runtime/hosts.protocol.log"
URL="${1:-multi-php-hosts://write}"

log() {
  mkdir -p "$REPO_ROOT/runtime"
  printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1" >> "$LOG_FILE" 2>/dev/null || true
}

raw="${URL#multi-php-hosts:}"
raw="${raw#//}"
action="$(printf '%s' "${raw%%[/?#]*}" | tr '[:upper:]' '[:lower:]')"
token=""
case "$raw" in
  write/*)
    rest="${raw#write/}"
    token="$(printf '%s' "${rest%%[/?#]*}" | tr '[:upper:]' '[:lower:]')"
    ;;
esac
if printf '%s' "$raw" | grep -Eq '[?&]id=[a-fA-F0-9]{8,64}'; then
  token="$(printf '%s' "$raw" | sed -n 's/.*[?&]id=\([a-fA-F0-9]\{8,64\}\).*/\1/p' | tr '[:upper:]' '[:lower:]')"
fi
if ! printf '%s' "$token" | grep -Eq '^[a-f0-9]{8,64}$'; then
  token=""
fi

if [ -z "$action" ]; then
  action='write'
fi

log "url=$URL action=$action token=$token"

if [ "$action" != 'write' ]; then
  log "unsupported action"
  echo "Unsupported multi-php-hosts action: $action" >&2
  exit 1
fi

if [ ! -x "$ADD_HOSTNAME" ] && [ -f "$ADD_HOSTNAME" ]; then
  chmod +x "$ADD_HOSTNAME" || true
fi

if [ ! -f "$ADD_HOSTNAME" ]; then
  log "missing add_hostname at $ADD_HOSTNAME"
  echo "add_hostname.sh not found at $ADD_HOSTNAME" >&2
  exit 1
fi

wait_for_sync_token() {
  request_id="$1"
  [ -n "$request_id" ] || return 0
  i=0
  while [ "$i" -lt 200 ]; do
    if [ -f "$SYNC_FILE" ] && grep -Fq "\"request_id\":\"$request_id\"" "$SYNC_FILE"; then
      return 0
    fi
    sleep 0.1
    i=$((i + 1))
  done
  echo "Timed out waiting for hosts.sync request_id=$request_id" >&2
  return 1
}

# Protocol is launched on the user click, before Manager finishes writing hosts.sync.
if [ -n "$token" ]; then
  if ! wait_for_sync_token "$token"; then
    log "timeout waiting for request_id=$token"
    exit 0
  fi
fi

log 'starting add_hostname --force-admin'
set +e
"$ADD_HOSTNAME" --force-admin
rc=$?
set -e
log "add_hostname exit=$rc"
exit "$rc"
