#!/bin/sh

RELOAD_TRIGGER="/var/runtime/nginx.reload"
SOFT_RELOAD_TRIGGER="/var/runtime/nginx.soft-reload"
TEST_TRIGGER="/var/runtime/nginx.test"

mkdir -p /var/runtime

while true; do
    if [ -f "$RELOAD_TRIGGER" ]; then
        rm -f "$RELOAD_TRIGGER"
        sh /var/scripts/nginx/reload-nginx.sh || true
    fi
    if [ -f "$SOFT_RELOAD_TRIGGER" ]; then
        rm -f "$SOFT_RELOAD_TRIGGER"
        sh /var/scripts/nginx/soft-reload-nginx.sh || true
    fi
    if [ -f "$TEST_TRIGGER" ]; then
        rm -f "$TEST_TRIGGER"
        sh /var/scripts/nginx/test-nginx.sh || true
    fi
    sleep 1
done
