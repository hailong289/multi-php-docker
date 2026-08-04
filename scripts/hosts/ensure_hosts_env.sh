#!/usr/bin/env bash
# Detect OS hosts path and write HOSTS_FILE into project .env for docker compose.
# On macOS also registers the multi-php-hosts: URL protocol (Manager → write hosts).
# Usage:
#   ./scripts/hosts/ensure_hosts_env.sh
#   ./scripts/hosts/ensure_hosts_env.sh --unregister-protocol

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$REPO_ROOT/.env"
MACOS_APP="$SCRIPT_DIR/../macos/MultiPhpHosts.app"
PROTOCOL_HANDLER="$SCRIPT_DIR/hosts_protocol_macos.sh"
UNREGISTER=0

for arg in "$@"; do
  case "$arg" in
    --unregister-protocol) UNREGISTER=1 ;;
    -h|--help)
      sed -n '2,7p' "$0"
      exit 0
      ;;
  esac
done

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
    echo "On native Windows use: powershell -ExecutionPolicy Bypass -File .\\scripts\\hosts\\ensure_hosts_env.ps1"
    exit 1
    ;;
  *)
    echo "Unsupported OS: $OS_TYPE"
    exit 1
    ;;
esac

unregister_macos_protocol() {
  if [ -d "$MACOS_APP" ]; then
    if [ -x /System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister ]; then
      /System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -u "$MACOS_APP" 2>/dev/null || true
    fi
    rm -rf "$MACOS_APP"
    echo "Removed macOS protocol app: $MACOS_APP"
  else
    echo "macOS protocol app was not registered"
  fi
}

register_macos_protocol() {
  if ! command -v osacompile >/dev/null 2>&1; then
    echo "WARNING: osacompile not found; skip multi-php-hosts: protocol registration"
    return 0
  fi
  if [ ! -f "$PROTOCOL_HANDLER" ]; then
    echo "WARNING: missing $PROTOCOL_HANDLER; skip protocol registration"
    return 0
  fi
  chmod +x "$PROTOCOL_HANDLER" "$SCRIPT_DIR/add_hostname.sh" 2>/dev/null || true

  mkdir -p "$(dirname "$MACOS_APP")"
  rm -rf "$MACOS_APP"

  # Relocatable: resolve scripts/hosts/hosts_protocol_macos.sh from scripts/macos/*.app
  local tmp_script
  tmp_script="$(mktemp /tmp/multi-php-hosts-XXXXXX.applescript)"
  cat > "$tmp_script" <<'APPLESCRIPT'
on handlerPath()
  set appPOSIX to POSIX path of (path to me as alias)
  if appPOSIX ends with "/" then set appPOSIX to text 1 thru -2 of appPOSIX
  set scriptsDir to do shell script "dirname \"$(dirname " & quoted form of appPOSIX & ")\""
  return scriptsDir & "/hosts/hosts_protocol_macos.sh"
end handlerPath

on open location theURL
  set h to handlerPath()
  do shell script quoted form of h & " " & quoted form of theURL
end open location

on run
  set h to handlerPath()
  do shell script quoted form of h & " write"
end run
APPLESCRIPT

  osacompile -o "$MACOS_APP" "$tmp_script" >/dev/null
  rm -f "$tmp_script"

  local plist="$MACOS_APP/Contents/Info.plist"
  /usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$plist" 2>/dev/null || true
  /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$plist"
  /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$plist"
  /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLName string Multi PHP Hosts Protocol' "$plist"
  /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$plist"
  /usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string multi-php-hosts' "$plist"

  plist_set() {
    local key="$1"
    local type="$2"
    local value="$3"
    if /usr/libexec/PlistBuddy -c "Print :$key" "$plist" >/dev/null 2>&1; then
      /usr/libexec/PlistBuddy -c "Set :$key $value" "$plist"
    else
      /usr/libexec/PlistBuddy -c "Add :$key $type $value" "$plist"
    fi
  }
  plist_set CFBundleIdentifier string local.multi-php-docker-serve.hosts
  plist_set CFBundleName string MultiPhpHosts
  plist_set CFBundleDisplayName string MultiPhpHosts

  # Drop quarantine so browser/Launch Services can open the local app.
  xattr -cr "$MACOS_APP" 2>/dev/null || true

  local lsregister='/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister'
  if [ -x "$lsregister" ]; then
    "$lsregister" -f "$MACOS_APP" 2>/dev/null || true
  fi

  echo "Registered multi-php-hosts: → $MACOS_APP"
  echo "If the browser asks, allow opening MultiPhpHosts."
}

if [ "$UNREGISTER" -eq 1 ]; then
  if [ "$OS_TYPE" = 'darwin' ] || [[ "$OS_TYPE" == darwin* ]]; then
    unregister_macos_protocol
    exit 0
  fi
  echo "Protocol unregister is only implemented for macOS here."
  echo "On Windows use: ensure_hosts_env.ps1 -UnregisterProtocol"
  exit 1
fi

if [ ! -f "$HOSTS_FILE" ]; then
  echo "ERROR: hosts file not found at $HOSTS_FILE"
  exit 1
fi

upsert_env_line() {
  local key="$1"
  local value="$2"
  local line="$key=$value"
  if [ -f "$ENV_FILE" ]; then
    if grep -q "^${key}=" "$ENV_FILE"; then
      tmp="$(mktemp)"
      sed "s|^${key}=.*|$line|" "$ENV_FILE" > "$tmp"
      mv "$tmp" "$ENV_FILE"
    else
      printf '\n%s\n' "$line" >> "$ENV_FILE"
    fi
  else
    printf '%s\n' "$line" > "$ENV_FILE"
  fi
  echo "Wrote $line to .env"
}

upsert_env_line 'HOSTS_FILE' "$HOSTS_FILE"
upsert_env_line 'HOST_PROJECT_PATH' "$REPO_ROOT"

case "$OS_TYPE" in
  darwin*)
    register_macos_protocol
    ;;
esac
