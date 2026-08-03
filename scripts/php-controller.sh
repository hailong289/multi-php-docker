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

profile_for_service() {
    case "$1" in
        php-8.1|php-8.0|php-7.4) printf '%s' "$1" ;;
        *) return 1 ;;
    esac
}

resolve_host_project() {
    host_project="${HOST_PROJECT_PATH:-}"
    if [ -n "$host_project" ] && [ "$host_project" != "/project" ]; then
        printf '%s' "$host_project"
        return 0
    fi

    docker inspect php_controller_container \
        --format '{{range .Mounts}}{{if eq .Destination "/project"}}{{.Source}}{{end}}{{end}}' \
        2>/dev/null
}

run_compose_create() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" create "$service"
    "$@"
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

        if ! printf '%s' "$request" | grep -Eq '^\{"request_id":"[0-9a-f]{32}","service":"php-(8\.2|8\.1|8\.0|7\.4)","action":"(start|stop|restart|create)","requested_at":"[0-9T:+-]+"\}$'; then
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
        ok=0
        if [ "$action" = "create" ]; then
            profile=$(profile_for_service "$service") || {
                write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
                rm -f "$request_file"
                continue
            }
            # Compose bind sources must be host paths. Short Windows mounts like
            # D:/repo:D:/repo:ro break ("too many colons"), so the project is at
            # /project. Prefer HOST_PROJECT_PATH, or infer the host source of that
            # mount when the stack was started without a generated .env file.
            host_project=$(resolve_host_project) || true
            if [ -z "$host_project" ]; then
                write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
                rm -f "$request_file"
                continue
            fi
            project_name=$(docker inspect nginx_container --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null) || true
            if [ -z "$project_name" ]; then
                project_name=$(basename "$host_project")
            fi
            tmp_dir="/tmp/compose-create.$$"
            mkdir -p "$tmp_dir/compose"
            # Rewrite ./ bind sources to absolute *host* paths (Docker Engine bind-mounts
            # the host FS via the mounted docker.sock). Include paths must stay readable
            # inside this container — do NOT rewrite project_directory to a Windows path
            # (Linux treats "D:/..." as relative → /project/D:/... which does not exist).
            # shellcheck disable=SC2016
            sed "s|- \\./|- ${host_project}/|g" /project/docker-compose.yml > "$tmp_dir/docker-compose.yml"
            for f in /project/compose/*.yml; do
                [ -f "$f" ] || continue
                sed "s|- \\./|- ${host_project}/|g" "$f" > "$tmp_dir/compose/$(basename "$f")"
            done
            if run_compose_create "$project_name" "$tmp_dir/docker-compose.yml" \
                "$profile" "$service" >/tmp/php-create.log 2>&1; then
                ok=1
            else
                cp /tmp/php-create.log "$STATUS_DIR/last-create-error.log" 2>/dev/null || true
            fi
            rm -rf "$tmp_dir"
        elif docker "$action" "$container" >/dev/null 2>&1; then
            ok=1
        fi

        state=$(container_state "$container")
        if [ "$ok" -eq 1 ]; then
            write_status "$service" "$state" "php_controller.action_success" "$request_id"
        else
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
