#!/bin/sh

TRIGGER_FILE="/var/runtime/nginx.reload"

mkdir -p /var/runtime

while true; do
    if [ -f "$TRIGGER_FILE" ]; then
        rm -f "$TRIGGER_FILE"
        sh /var/scripts/reload-nginx.sh || true
    fi
    sleep 1
done
