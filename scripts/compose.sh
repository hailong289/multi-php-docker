#!/usr/bin/env bash
# Run ensure_hosts_env, then forward all args to docker compose.
# Usage:
#   ./scripts/compose.sh up -d
#   ./scripts/compose.sh --profile php-8.1 up -d

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

OS_TYPE="$(uname -s | tr '[:upper:]' '[:lower:]')"
case "$OS_TYPE" in
  msys*|mingw*|cygwin*)
    powershell.exe -ExecutionPolicy Bypass -File "$SCRIPT_DIR/ensure_hosts_env.ps1"
    ;;
  *)
    "$SCRIPT_DIR/ensure_hosts_env.sh"
    ;;
esac

exec docker compose "$@"
