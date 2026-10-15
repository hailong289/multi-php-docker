#!/usr/bin/env bash
# Register multi-php-ssl: on macOS. One-time, same idea as scripts/hosts/ensure_hosts_env.sh.
set -eu
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MACOS_APP="$REPO_ROOT/scripts/macos/MultiPhpSsl.app"
PROTOCOL_HANDLER="$SCRIPT_DIR/ssl_protocol_macos.sh"

if [ "$(uname -s)" != "Darwin" ]; then
  echo "On Windows, from an elevated shell after installing mkcert:"
  echo "  bash scripts/ssl/trust_local_ca.sh APP DOMAIN"
  exit 0
fi
if ! command -v osacompile >/dev/null 2>&1; then
  echo "osacompile not found"
  exit 1
fi
chmod +x "$PROTOCOL_HANDLER" "$SCRIPT_DIR/trust_local_ca.sh"
mkdir -p "$(dirname "$MACOS_APP")"
rm -rf "$MACOS_APP"
tmp_script="$(mktemp /tmp/multi-php-ssl-XXXXXX.applescript)"
cat > "$tmp_script" <<'APPLESCRIPT'
on handlerPath()
  set appPOSIX to POSIX path of (path to me as alias)
  if appPOSIX ends with "/" then set appPOSIX to text 1 thru -2 of appPOSIX
  set scriptsDir to do shell script "dirname \"$(dirname " & quoted form of appPOSIX & ")\""
  return scriptsDir & "/ssl/ssl_protocol_macos.sh"
end handlerPath

on launchDetached(theArg)
  set h to handlerPath()
  do shell script "/usr/bin/nohup " & quoted form of h & " " & quoted form of theArg & " </dev/null >/dev/null 2>&1 &"
end launchDetached

on open location theURL
  launchDetached(theURL)
end open location

on run
  launchDetached("issue")
end run
APPLESCRIPT
osacompile -o "$MACOS_APP" "$tmp_script" >/dev/null
rm -f "$tmp_script"
plist="$MACOS_APP/Contents/Info.plist"
/usr/libexec/PlistBuddy -c 'Delete :CFBundleURLTypes' "$plist" 2>/dev/null || true
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes array' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0 dict' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLName string Multi PHP SSL Protocol' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes array' "$plist"
/usr/libexec/PlistBuddy -c 'Add :CFBundleURLTypes:0:CFBundleURLSchemes:0 string multi-php-ssl' "$plist"
echo "Registered multi-php-ssl: → $MACOS_APP"
