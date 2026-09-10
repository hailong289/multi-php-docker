#!/bin/sh
# MultiPhpHosts.app must return immediately after a protocol open.
# A blocking `do shell script` plus nested `osascript with administrator
# privileges` freezes the applet (macOS "Application Not Responding"),
# especially when several host writes launch the handler at once.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
ENSURE="$ROOT/scripts/hosts/ensure_hosts_env.sh"
PROTOCOL="$ROOT/scripts/hosts/hosts_protocol_macos.sh"
ADD_HOSTNAME="$ROOT/scripts/hosts/add_hostname.sh"

fail() {
  echo "FAIL: $1" >&2
  exit 1
}

[ -f "$ENSURE" ] || fail "missing $ENSURE"
[ -f "$PROTOCOL" ] || fail "missing $PROTOCOL"
[ -f "$ADD_HOSTNAME" ] || fail "missing $ADD_HOSTNAME"

# Extract the compiled AppleScript template from the registrar.
script="$(awk "/<<'APPLESCRIPT'/{flag=1;next} /^APPLESCRIPT\$/{flag=0} flag" "$ENSURE")"
[ -n "$script" ] || fail "could not extract AppleScript template"

echo "$script" | grep -q '/usr/bin/nohup' || fail "AppleScript must detach handler with /usr/bin/nohup"
echo "$script" | grep -q '</dev/null >/dev/null 2>&1 &' || fail "AppleScript must redirect stdio and background with &"

# The old blocking call kept the applet's event loop stuck until add_hostname
# finished (including the nested admin osascript).
if echo "$script" | grep -Eq 'do shell script quoted form of h & " " & quoted form of theURL[[:space:]]*$'; then
  fail "open location must not block on do shell script of the handler"
fi
if echo "$script" | grep -Eq 'do shell script quoted form of h & " write"[[:space:]]*$'; then
  fail "on run must not block on do shell script of the handler"
fi

grep -q "plist_set LSUIElement bool true" "$ENSURE" || fail "protocol app must set LSUIElement (no Dock ANR icon)"

grep -q 'hosts.write.lock' "$ADD_HOSTNAME" || fail "add_hostname must serialize concurrent hosts writes"
grep -q 'request_id' "$ADD_HOSTNAME" || fail "add_hostname status must include the write request_id"
grep -q 'hosts.protocol.log' "$PROTOCOL" || fail "protocol handler must log like the Windows counterpart"

# Concurrent writers must not overlap (two admin prompts / torn /etc/hosts).
lock_tmp="$(mktemp -d)"
lock_script="$(mktemp)"
trap 'rm -rf "$lock_tmp" "$lock_script"' EXIT INT TERM
{
  echo '#!/bin/sh'
  echo 'set -eu'
  echo 'RUNTIME_DIR="$1"'
  echo 'marker="$2"'
  sed -n '/^LOCK_DIR=/,/^read_domains()/p' "$ADD_HOSTNAME" | sed '$d'
  echo 'acquire_write_lock'
  echo 'printf "%s %s\\n" "$$" "$(date +%s)" >> "$marker"'
  echo 'sleep 1'
  echo 'release_write_lock'
} > "$lock_script"
chmod +x "$lock_script"
marker="$lock_tmp/order"
: > "$marker"
"$lock_script" "$lock_tmp" "$marker" &
pid1=$!
"$lock_script" "$lock_tmp" "$marker" &
pid2=$!
wait "$pid1" "$pid2"
lines="$(wc -l < "$marker" | tr -d ' ')"
[ "$lines" = "2" ] || fail "lock test expected 2 completions, got $lines"
if [ -d "$lock_tmp/hosts.write.lock" ]; then
  fail "write lock was not released"
fi

echo "OK: macOS hosts protocol stays responsive"
