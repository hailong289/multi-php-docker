#!/usr/bin/env bash
# Detect OS hosts path and write HOSTS_FILE into project .env for docker compose.
# Usage: ./scripts/ensure_hosts_env.sh

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$REPO_ROOT/.env"

OS_TYPE="$(uname -s | tr '[:upper:]' '[:lower:]')"
HOSTS_FILE='/etc/hosts'

case "$OS_TYPE" in
  linux*)
    if grep -qi microsoft /proc/version 2>/dev/null; then
      # Docker Desktop / browsers on Windows use the Windows hosts file.
      HOSTS_FILE='/mnt/c/Windows/System32/drivers/etc/hosts'
    fi
    ;;
  darwin*)
    HOSTS_FILE='/etc/hosts'
    ;;
  msys*|mingw*|cygwin*)
    echo "On native Windows use: powershell -ExecutionPolicy Bypass -File .\\scripts\\ensure_hosts_env.ps1"
    exit 1
    ;;
  *)
    echo "Unsupported OS: $OS_TYPE"
    exit 1
    ;;
esac

if [ ! -f "$HOSTS_FILE" ]; then
  echo "ERROR: hosts file not found at $HOSTS_FILE"
  exit 1
fi

LINE="HOSTS_FILE=$HOSTS_FILE"
if [ -f "$ENV_FILE" ]; then
  if grep -q '^HOSTS_FILE=' "$ENV_FILE"; then
    tmp="$(mktemp)"
    sed "s|^HOSTS_FILE=.*|$LINE|" "$ENV_FILE" > "$tmp"
    mv "$tmp" "$ENV_FILE"
  else
    printf '\n%s\n' "$LINE" >> "$ENV_FILE"
  fi
else
  printf '%s\n' "$LINE" > "$ENV_FILE"
fi

echo "Wrote $LINE to .env"
