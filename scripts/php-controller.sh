#!/bin/sh

set -u

BASE_DIR="/runtime"
REQUEST_DIR="$BASE_DIR/requests"
STATUS_DIR="$BASE_DIR/status"

mkdir -p "$REQUEST_DIR" "$STATUS_DIR"

container_for_service() {
    case "$1" in
        php-8.2) printf '%s' 'php8.2_container' ;;
        php-8.1) printf '%s' 'php8.1_container' ;;
        php-8.0) printf '%s' 'php8.0_container' ;;
        php-7.4) printf '%s' 'php7.4_container' ;;
        *) return 1 ;;
    esac
}

container_state() {
    container="$1"
    state=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null) || {
        printf '%s' 'not_created'
        return
    }
    if [ "$state" = "running" ]; then
        printf '%s' 'running'
    else
        printf '%s' 'stopped'
    fi
}

write_status() {
    service="$1"
    state="$2"
    message_key="$3"
    request_id="$4"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    temp_file="$STATUS_DIR/$service.json.tmp"
    printf '{"service":"%s","state":"%s","message_key":"%s","request_id":"%s","updated_at":"%s"}\n' \
        "$service" "$state" "$message_key" "$request_id" "$updated_at" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/$service.json"
}

refresh_service() {
    service="$1"
    container=$(container_for_service "$service") || return
    state=$(container_state "$container")
    write_status "$service" "$state" "php_controller.status_refreshed" ""
}

reject_request() {
    request_file="$1"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    temp_file="$STATUS_DIR/last-error.json.tmp"
    printf '{"state":"error","message_key":"php_controller.invalid_request","updated_at":"%s"}\n' \
        "$updated_at" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/last-error.json"
    rm -f "$request_file"
}

for service in php-8.2 php-8.1 php-8.0 php-7.4; do
    refresh_service "$service"
done

refresh_tick=0
while true; do
    for request_file in "$REQUEST_DIR"/*.json; do
        [ -e "$request_file" ] || break
        request=$(tr -d '\r\n' < "$request_file")

        if ! printf '%s' "$request" | grep -Eq '^\{"request_id":"[0-9a-f]{32}","service":"php-(8\.2|8\.1|8\.0|7\.4)","action":"(start|stop|restart)","requested_at":"[0-9T:+-]+"\}$'; then
            reject_request "$request_file"
            continue
        fi

        request_id=$(printf '%s' "$request" | sed -n 's/^.*"request_id":"\([0-9a-f]*\)".*$/\1/p')
        service=$(printf '%s' "$request" | sed -n 's/^.*"service":"\([^"]*\)".*$/\1/p')
        action=$(printf '%s' "$request" | sed -n 's/^.*"action":"\([^"]*\)".*$/\1/p')
        container=$(container_for_service "$service") || {
            reject_request "$request_file"
            continue
        }

        write_status "$service" "busy" "php_controller.processing" "$request_id"
        if docker "$action" "$container" >/dev/null 2>&1; then
            state=$(container_state "$container")
            write_status "$service" "$state" "php_controller.action_success" "$request_id"
        else
            state=$(container_state "$container")
            write_status "$service" "$state" "php_controller.action_failed" "$request_id"
        fi
        rm -f "$request_file"

        for refresh_target in php-8.2 php-8.1 php-8.0 php-7.4; do
            [ "$refresh_target" = "$service" ] || refresh_service "$refresh_target"
        done
    done
    refresh_tick=$((refresh_tick + 1))
    if [ "$refresh_tick" -ge 5 ]; then
        for refresh_target in php-8.2 php-8.1 php-8.0 php-7.4; do
            refresh_service "$refresh_target"
        done
        refresh_tick=0
    fi
    sleep 1
done
