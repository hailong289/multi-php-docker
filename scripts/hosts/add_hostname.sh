#!/usr/bin/env bash
# Apply domains from env.json (+ runtime/hosts.extra.json) into the OS hosts file.
# Usage:
#   ./scripts/hosts/add_hostname.sh              # one-shot apply
#   ./scripts/hosts/add_hostname.sh --watch       # watch runtime/hosts.sync for Manager
#   ./scripts/hosts/add_hostname.sh --force-admin # prefer elevated write

set -eu

IP_ADDRESS="127.0.0.1"
BEGIN='# multi-php-docker-serve:managed:begin'
END='# multi-php-docker-serve:managed:end'

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

JSON_FILE="$REPO_ROOT/env.json"
RUNTIME_DIR="$REPO_ROOT/runtime"
EXTRA_FILE="$RUNTIME_DIR/hosts.extra.json"
SYNC_FILE="$RUNTIME_DIR/hosts.sync"
STATUS_FILE="$RUNTIME_DIR/hosts.status.json"

WATCH=0
FORCE_ADMIN=0
REQUEST_ID=""
for arg in "$@"; do
  case "$arg" in
    --watch) WATCH=1 ;;
    --force-admin) FORCE_ADMIN=1 ;;
    -h|--help)
      sed -n '2,7p' "$0"
      exit 0
      ;;
  esac
done

if ! command -v jq >/dev/null 2>&1; then
  echo "jq not found. Trying to install..."
  if command -v sudo >/dev/null 2>&1 && command -v apt-get >/dev/null 2>&1; then
    sudo apt-get update && sudo apt-get install -y jq
  fi
  if ! command -v jq >/dev/null 2>&1; then
    echo "Please install jq manually."
    exit 1
  fi
fi

OS_TYPE="$(uname -s | tr '[:upper:]' '[:lower:]')"
PLATFORM='linux'
HOSTS_FILE='/etc/hosts'
case "$OS_TYPE" in
  linux*)
    if grep -qi microsoft /proc/version 2>/dev/null; then
      PLATFORM='wsl'
      HOSTS_FILE='/mnt/c/Windows/System32/drivers/etc/hosts'
    fi
    ;;
  darwin*)
    PLATFORM='mac'
    HOSTS_FILE='/etc/hosts'
    ;;
  msys*|mingw*|cygwin*)
    echo "On native Windows use: powershell -ExecutionPolicy Bypass -File .\\scripts\\hosts\\add_hostname.ps1"
    exit 1
    ;;
  *)
    echo "Unsupported OS: $OS_TYPE"
    exit 1
    ;;
esac

mkdir -p "$RUNTIME_DIR"

LOCK_DIR="$RUNTIME_DIR/hosts.write.lock"

release_write_lock() {
  if [ ! -d "$LOCK_DIR" ]; then
    return 0
  fi
  owner="$(cat "$LOCK_DIR/pid" 2>/dev/null || true)"
  if [ "$owner" = "$$" ]; then
    rm -rf "$LOCK_DIR"
  fi
}

acquire_write_lock() {
  i=0
  while ! mkdir "$LOCK_DIR" 2>/dev/null; do
    oldpid="$(cat "$LOCK_DIR/pid" 2>/dev/null || true)"
    if [ -n "$oldpid" ] && ! kill -0 "$oldpid" 2>/dev/null; then
      rm -rf "$LOCK_DIR"
      continue
    fi
    sleep 0.2
    i=$((i + 1))
    # ~90s: another write may be waiting on the admin password dialog.
    if [ "$i" -ge 450 ]; then
      echo "Timed out waiting for hosts write lock" >&2
      return 1
    fi
  done
  printf '%s\n' "$$" > "$LOCK_DIR/pid"
  trap release_write_lock EXIT INT TERM
}

read_domains() {
  {
    if [ -f "$JSON_FILE" ]; then
      jq -r 'to_entries[] | select(.key | test("^SERVER_NAME")) | .value.DOMAIN_NAME // empty' "$JSON_FILE"
    fi
    if [ -f "$EXTRA_FILE" ]; then
      jq -r '.[]?' "$EXTRA_FILE"
    fi
  } | tr '[:upper:]' '[:lower:]' | sed '/^$/d' | sort -u
}

# Bash 3.2 (macOS /bin/bash) has no mapfile/readarray.
load_domains() {
  domains=()
  while IFS= read -r domain || [ -n "$domain" ]; do
    [ -n "$domain" ] || continue
    domains+=("$domain")
  done <<EOF
$(read_domains || true)
EOF
}

domains_map_json() {
  state="$1"
  shift
  first=1
  printf '{'
  for domain in "$@"; do
    [ -n "$domain" ] || continue
    if [ "$first" -eq 1 ]; then first=0; else printf ','; fi
    printf '"%s":"%s"' "$domain" "$state"
  done
  printf '}'
}

manual_json() {
  lines_json=$(
    first=1
    printf '['
    for domain in "$@"; do
      [ -n "$domain" ] || continue
      if [ "$first" -eq 1 ]; then first=0; else printf ','; fi
      printf '"%s %s"' "$IP_ADDRESS" "$domain"
    done
    printf ']'
  )
  printf '{"hosts_path":"%s","lines":%s}' "$HOSTS_FILE" "$lines_json"
}

sync_request_id() {
  if [ ! -f "$SYNC_FILE" ]; then
    printf ''
    return 0
  fi
  jq -r '.request_id // empty' "$SYNC_FILE" 2>/dev/null || true
}

write_status() {
  status="$1"
  message_key="$2"
  domains_json="$3"
  manual_json_payload="${4:-}"
  updated_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  tmp="$STATUS_FILE.tmp"
  req_json=""
  if printf '%s' "$REQUEST_ID" | grep -Eq '^[a-f0-9]{8,64}$'; then
    req_json="$(printf ',"request_id":"%s"' "$REQUEST_ID")"
  fi
  if [ -n "$manual_json_payload" ]; then
    printf '{"status":"%s","message_key":"%s","updated_at":"%s"%s,"domains":%s,"manual":%s}\n' \
      "$status" "$message_key" "$updated_at" "$req_json" "$domains_json" "$manual_json_payload" > "$tmp"
  else
    printf '{"status":"%s","message_key":"%s","updated_at":"%s"%s,"domains":%s}\n' \
      "$status" "$message_key" "$updated_at" "$req_json" "$domains_json" > "$tmp"
  fi
  mv "$tmp" "$STATUS_FILE"
}

build_hosts_tmp() {
  domains=("$@")
  tmp="$(mktemp)"
  if [ -f "$HOSTS_FILE" ]; then
    awk -v begin="$BEGIN" -v end="$END" '
      $0 == begin { skip=1; next }
      $0 == end { skip=0; next }
      !skip { print }
    ' "$HOSTS_FILE" > "$tmp" || true
  else
    : > "$tmp"
  fi
  {
    printf '%s\n' "$BEGIN"
    for domain in "${domains[@]}"; do
      [ -n "$domain" ] || continue
      printf '%s %s\n' "$IP_ADDRESS" "$domain"
    done
    printf '%s\n' "$END"
  } >> "$tmp"
  printf '%s' "$tmp"
}

# Copy tmp hosts file into place (sudo, or macOS GUI elevation when no TTY).
install_hosts_file() {
  local src="$1"
  if [ "$PLATFORM" = 'mac' ] && [ ! -t 0 ] && command -v osascript >/dev/null 2>&1; then
    osascript -e "do shell script \"cp $(printf %q "$src") $(printf %q "$HOSTS_FILE")\" with administrator privileges"
    return $?
  fi
  sudo cp "$src" "$HOSTS_FILE"
}

apply_unix() {
  domains=("$@")
  tmp="$(build_hosts_tmp "${domains[@]}")"
  local rc=0

  if [ "$FORCE_ADMIN" -eq 1 ]; then
    install_hosts_file "$tmp" || rc=$?
  elif cp "$tmp" "$HOSTS_FILE" 2>/dev/null; then
    rc=0
  else
    install_hosts_file "$tmp" || rc=$?
  fi
  rm -f "$tmp"
  return "$rc"
}

apply_wsl() {
  domains=("$@")
  # Prefer elevating via PowerShell helper in the same scripts folder.
  ps1_win="$(wslpath -w "$SCRIPT_DIR/add_hostname.ps1")"
  if [ "$FORCE_ADMIN" -eq 1 ]; then
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ps1_win" -Once -ForceAdmin
    return $?
  fi
  if powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ps1_win" -Once; then
    return 0
  fi
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ps1_win" -Once -ForceAdmin
}

apply_hosts() {
  load_domains
  echo "Applying domains: ${domains[*]:-}"
  if [ "$PLATFORM" = 'wsl' ]; then
    apply_wsl "${domains[@]:-}"
  else
    apply_unix "${domains[@]:-}"
  fi
}

apply_and_status() {
  local force_from_sync="${1:-0}"
  local rc=1
  if ! acquire_write_lock; then
    return 1
  fi
  REQUEST_ID="$(sync_request_id)"
  if [ "$force_from_sync" = "1" ]; then
    FORCE_ADMIN=1
  fi
  load_domains
  domains_json="$(domains_map_json unknown "${domains[@]:-}")"
  if [ "$FORCE_ADMIN" -eq 1 ]; then
    write_status busy hosts.elevation_required "$domains_json"
  else
    write_status busy hosts.processing "$domains_json"
  fi
  if apply_hosts; then
    write_status success hosts.sync_success "$(domains_map_json synced "${domains[@]:-}")"
    rm -f "$SYNC_FILE"
    echo "Hosts updated successfully."
    rc=0
  else
    write_status error hosts.manual_required "$(domains_map_json unknown "${domains[@]:-}")" "$(manual_json "${domains[@]:-}")"
    rm -f "$SYNC_FILE"
    echo "Hosts update failed. Add entries manually if needed."
    rc=1
  fi
  release_write_lock
  return "$rc"
}

if [ "$WATCH" -eq 1 ]; then
  echo "add_hostname watching $SYNC_FILE ($PLATFORM)"
  echo "Press Ctrl+C to stop."
  while true; do
    if [ -f "$SYNC_FILE" ]; then
      force_sync=0
      if [ "$(jq -r '.force_admin // false' "$SYNC_FILE" 2>/dev/null || echo false)" = "true" ]; then
        force_sync=1
      fi
      apply_and_status "$force_sync" || true
      rm -f "$SYNC_FILE"
    fi
    sleep 1
  done
fi

apply_and_status 0
