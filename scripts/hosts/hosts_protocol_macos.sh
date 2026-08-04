#!/usr/bin/env bash
# URL protocol handler for multi-php-hosts: on macOS (registered by ensure_hosts_env.sh).
# Browser opens multi-php-hosts:write → this script → add_hostname.sh --force-admin.

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ADD_HOSTNAME="$SCRIPT_DIR/add_hostname.sh"
URL="${1:-multi-php-hosts:write}"

raw="${URL#multi-php-hosts:}"
raw="${raw#//}"
action="$(printf '%s' "${raw%%[/?#]*}" | tr '[:upper:]' '[:lower:]')"
if [ -z "$action" ]; then
  action='write'
fi

if [ "$action" != 'write' ]; then
  echo "Unsupported multi-php-hosts action: $action" >&2
  exit 1
fi

if [ ! -x "$ADD_HOSTNAME" ] && [ -f "$ADD_HOSTNAME" ]; then
  chmod +x "$ADD_HOSTNAME" || true
fi

if [ ! -f "$ADD_HOSTNAME" ]; then
  echo "add_hostname.sh not found at $ADD_HOSTNAME" >&2
  exit 1
fi

exec "$ADD_HOSTNAME" --force-admin
