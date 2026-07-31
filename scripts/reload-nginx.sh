#!/bin/sh

set -u

RUNTIME_DIR="/var/runtime"
STATUS_FILE="$RUNTIME_DIR/nginx.status.json"
LOG_FILE="$RUNTIME_DIR/nginx.reload.log"
BACKUP_DIR=$(mktemp -d /tmp/nginx-conf-backup.XXXXXX)

mkdir -p "$RUNTIME_DIR"

write_status() {
    status="$1"
    message="$2"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    temp_file="$STATUS_FILE.tmp"
    printf '{"status":"%s","message":"%s","updated_at":"%s"}\n' \
        "$status" "$message" "$updated_at" > "$temp_file"
    mv "$temp_file" "$STATUS_FILE"
}

restore_config() {
    find /etc/nginx/conf.d -maxdepth 1 -type f -name '*.conf' -delete
    for config in "$BACKUP_DIR"/*.conf; do
        [ -e "$config" ] || break
        cp "$config" /etc/nginx/conf.d/
    done
}

cleanup() {
    rm -rf "$BACKUP_DIR"
}
trap cleanup EXIT INT TERM

for config in /etc/nginx/conf.d/*.conf; do
    [ -e "$config" ] || break
    cp "$config" "$BACKUP_DIR/"
done

: > "$LOG_FILE"

if ! sh /var/scripts/auto-add-template.sh >> "$LOG_FILE" 2>&1; then
    write_status "error" "Could not generate Nginx templates. See runtime/nginx.reload.log."
    exit 1
fi

find /etc/nginx/conf.d -maxdepth 1 -type f -name '*.conf' -delete
for template in /etc/nginx/templates/*.template; do
    [ -e "$template" ] || break
    cp "$template" "/etc/nginx/conf.d/$(basename "${template%.template}.conf")"
done

if ! nginx -t >> "$LOG_FILE" 2>&1; then
    restore_config
    write_status "error" "Nginx configuration is invalid. Previous configuration was restored."
    exit 1
fi

if ! nginx -s reload >> "$LOG_FILE" 2>&1; then
    restore_config
    write_status "error" "Nginx could not reload. Previous configuration was restored."
    exit 1
fi

write_status "success" "Nginx templates were generated and reloaded successfully."
