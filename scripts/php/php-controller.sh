#!/bin/sh

set -u

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
BASE_DIR="/runtime"
REQUEST_DIR="$BASE_DIR/requests"
STATUS_DIR="$BASE_DIR/status"

mkdir -p "$REQUEST_DIR" "$STATUS_DIR"
# php-controller runs with a read-only rootfs; point Docker CLI config at tmpfs.
mkdir -p "${DOCKER_CONFIG:-/tmp/docker-config}"

container_for_service() {
    case "$1" in
        nginx) printf '%s' 'nginx_container' ;;
        php-*)
            # php-8.3 → php8.3_container ; php-8.3-alpine → php8.3alpine_container
            printf 'php%s_container' "$(printf '%s' "${1#php-}" | tr -d '-')"
            ;;
        *) return 1 ;;
    esac
}

profile_for_service() {
    case "$1" in
        php-8.2) return 1 ;;
        php-*)
            printf '%s' "$1"
            ;;
        *) return 1 ;;
    esac
}

list_php_services() {
    for f in /project/compose/php-*.yml; do
        [ -e "$f" ] || continue
        base=$(basename "$f" .yml)
        case "$base" in
            php-*.*) printf '%s\n' "$base" ;;
        esac
    done
}

prepare_compose_tmp() {
    host_project="$1"
    tmp_dir="$2"
    mkdir -p "$tmp_dir/compose"
    # Bind mounts must use host paths (daemon-side). Build context must stay
    # container-visible (/project/...) because the Docker CLI reads it locally.
    sed -e "s|- \\./|- ${host_project}/|g" \
        -e "s|context: \\./|context: /project/|g" \
        /project/docker-compose.yml > "$tmp_dir/docker-compose.yml"
    for f in /project/compose/*.yml; do
        [ -f "$f" ] || continue
        sed -e "s|- \\./|- ${host_project}/|g" \
            -e "s|context: \\./|context: /project/|g" \
            "$f" > "$tmp_dir/compose/$(basename "$f")"
    done
}

run_compose_build_up() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" build "$service"
    "$@" || return 1

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" up -d --no-deps "$service"
    "$@"
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

    # Generated PHP images are not published; build before create.
    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" build "$service"
    "$@" || return 1

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" create "$service"
    "$@"
}

run_compose_recreate_start() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file"
    if [ -n "$profile" ]; then
        set -- "$@" --profile "$profile"
    fi
    set -- "$@" up -d --no-deps --force-recreate "$service"
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

write_modules_sidecar() {
    service="$1"
    container="$2"
    request_id="$3"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    modules_json=$(docker exec "$container" php -m 2>/dev/null | tr -d '\r' | awk '
        BEGIN { printf "[" }
        /^\[/ { next }
        NF==0 { next }
        {
          if (n++) printf ","
          gsub(/\\/,"\\\\"); gsub(/"/,"\\\"")
          printf "\"%s\"", $0
        }
        END { printf "]" }
    ')
    if [ -z "$modules_json" ]; then
        modules_json='[]'
        ok=0
    else
        ok=1
    fi
    temp_file="$STATUS_DIR/$service.modules.json.tmp"
    printf '{"service":"%s","modules":%s,"updated_at":"%s","request_id":"%s","ok":%s}\n' \
        "$service" "$modules_json" "$updated_at" "$request_id" "$ok" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/$service.modules.json"
    [ "$ok" -eq 1 ]
}

extension_allowed() {
    # Any php extension name: starts with a letter, then a-z / digits / underscore.
    printf '%s' "$1" | grep -Eq '^[a-z][a-z0-9_]*$'
}

write_available_sidecar() {
    service="$1"
    container="$2"
    request_id="$3"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    # docker-php-ext-install prints "Possible values for ext-name:" then a space-separated list.
    ext_json=$(docker exec "$container" sh -c 'docker-php-ext-install 2>&1' | tr -d '\r' | awk '
        BEGIN { printf "["; n=0 }
        /^Possible values for ext-name:/ { grab=1; next }
        grab && NF {
            for (i=1; i<=NF; i++) {
                name=tolower($i)
                if (name ~ /^[a-z][a-z0-9_]*$/) {
                    if (n++) printf ","
                    printf "\"%s\"", name
                }
            }
            grab=0
        }
        END { printf "]" }
    ')
    if [ -z "$ext_json" ] || [ "$ext_json" = '[]' ]; then
        ext_json='[]'
        ok=0
    else
        ok=1
    fi
    temp_file="$STATUS_DIR/$service.available-ext.json.tmp"
    printf '{"service":"%s","extensions":%s,"updated_at":"%s","request_id":"%s","ok":%s}\n' \
        "$service" "$ext_json" "$updated_at" "$request_id" "$ok" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/$service.available-ext.json"
    [ "$ok" -eq 1 ]
}

# install-ext / uninstall-ext require a valid extension name ([a-z][a-z0-9_]*).
parse_request_fields() {
    request="$1"
    request_id=$(printf '%s' "$request" | sed -n 's/^.*"request_id":"\([0-9a-f]*\)".*$/\1/p')
    service=$(printf '%s' "$request" | sed -n 's/^.*"service":"\([^"]*\)".*$/\1/p')
    action=$(printf '%s' "$request" | sed -n 's/^.*"action":"\([^"]*\)".*$/\1/p')
    extension=$(printf '%s' "$request" | sed -n 's/^.*"extension":"\([a-z0-9_]*\)".*$/\1/p')

    printf '%s' "$request_id" | grep -Eq '^[0-9a-f]{32}$' || return 1
    case "$service" in
        nginx) ;;
        php-*)
            printf '%s' "$service" | grep -Eq '^php-[0-9]+(\.[0-9]+)+(-alpine|-trixie)?$' || return 1
            ;;
        *) return 1 ;;
    esac
    case "$action" in
        start|stop|restart|create|install-version)
            [ -z "$extension" ] || return 1
            ;;
        modules|available-ext)
            [ "$service" != "nginx" ] || return 1
            [ -z "$extension" ] || return 1
            ;;
        install-ext|uninstall-ext)
            [ "$service" != "nginx" ] || return 1
            extension_allowed "$extension" || return 1
            ;;
        *) return 1 ;;
    esac
    return 0
}

for service in $(list_php_services) nginx; do
    refresh_service "$service"
done

refresh_tick=0
while true; do
    for request_file in "$REQUEST_DIR"/*.json; do
        [ -e "$request_file" ] || break
        request=$(tr -d '\r\n' < "$request_file")

        if ! parse_request_fields "$request"; then
            reject_request "$request_file"
            continue
        fi

        request_id=$(printf '%s' "$request" | sed -n 's/^.*"request_id":"\([0-9a-f]*\)".*$/\1/p')
        service=$(printf '%s' "$request" | sed -n 's/^.*"service":"\([^"]*\)".*$/\1/p')
        action=$(printf '%s' "$request" | sed -n 's/^.*"action":"\([^"]*\)".*$/\1/p')
        extension=$(printf '%s' "$request" | sed -n 's/^.*"extension":"\([a-z0-9_]*\)".*$/\1/p')
        if [ "$service" = "nginx" ] && { [ "$action" = "create" ] || [ "$action" = "install-version" ]; }; then
            reject_request "$request_file"
            continue
        fi
        container=$(container_for_service "$service") || {
            reject_request "$request_file"
            continue
        }

        if [ "$action" = "modules" ]; then
            # Do not flip lifecycle status to busy — probes must not block install/enable UI.
            if [ "$(container_state "$container")" = "running" ] && write_modules_sidecar "$service" "$container" "$request_id"; then
                write_status "$service" "running" "php_controller.action_success" "$request_id"
            else
                write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
            fi
            rm -f "$request_file"
            continue
        fi

        if [ "$action" = "available-ext" ]; then
            if [ "$(container_state "$container")" = "running" ] && write_available_sidecar "$service" "$container" "$request_id"; then
                write_status "$service" "running" "php_controller.action_success" "$request_id"
            else
                write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
            fi
            rm -f "$request_file"
            continue
        fi

        if [ "$action" = "install-ext" ]; then
            write_status "$service" "busy" "php_controller.processing" "$request_id"
            ok=0
            if [ "$(container_state "$container")" = "running" ]; then
                if "$SCRIPT_DIR/php-ext-install.sh" "$container" "$extension" \
                    >"$STATUS_DIR/$service.last-install.log" 2>&1; then
                    ok=1
                    write_modules_sidecar "$service" "$container" "$request_id" || true
                fi
            fi
            state=$(container_state "$container")
            if [ "$ok" -eq 1 ]; then
                write_status "$service" "$state" "php_controller.action_success" "$request_id"
            else
                write_status "$service" "$state" "php_controller.action_failed" "$request_id"
            fi
            rm -f "$request_file"
            continue
        fi

        if [ "$action" = "uninstall-ext" ]; then
            write_status "$service" "busy" "php_controller.processing" "$request_id"
            ok=0
            if [ "$(container_state "$container")" = "running" ]; then
                if "$SCRIPT_DIR/php-ext-uninstall.sh" "$container" "$extension" \
                    >"$STATUS_DIR/$service.last-uninstall.log" 2>&1; then
                    ok=1
                    write_modules_sidecar "$service" "$container" "$request_id" || true
                fi
            fi
            state=$(container_state "$container")
            if [ "$ok" -eq 1 ]; then
                write_status "$service" "$state" "php_controller.action_success" "$request_id"
            else
                write_status "$service" "$state" "php_controller.action_failed" "$request_id"
            fi
            rm -f "$request_file"
            continue
        fi

        write_status "$service" "busy" "php_controller.processing" "$request_id"
        ok=0
        if [ "$action" = "install-version" ] || [ "$action" = "create" ]; then
            profile=$(profile_for_service "$service") || {
                write_status "$service" "$(container_state "$container")" "php_controller.action_failed" "$request_id"
                rm -f "$request_file"
                continue
            }
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
            prepare_compose_tmp "$host_project" "$tmp_dir"
            if [ "$action" = "install-version" ]; then
                if run_compose_build_up "$project_name" "$tmp_dir/docker-compose.yml" \
                    "$profile" "$service" >"$STATUS_DIR/$service.last-install-version.log" 2>&1; then
                    ok=1
                fi
            elif run_compose_create "$project_name" "$tmp_dir/docker-compose.yml" \
                "$profile" "$service" >/tmp/php-create.log 2>&1; then
                ok=1
            else
                cp /tmp/php-create.log "$STATUS_DIR/last-create-error.log" 2>/dev/null || true
            fi
            rm -rf "$tmp_dir"
        elif [ "$action" = "start" ]; then
            if docker start "$container" >/dev/null 2>&1; then
                ok=1
            else
                profile=$(profile_for_service "$service") || true
                host_project=$(resolve_host_project) || true
                if { [ "$service" = "nginx" ] || [ -n "$profile" ]; } && [ -n "$host_project" ]; then
                    project_name=$(docker inspect nginx_container --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null) || true
                    if [ -z "$project_name" ]; then
                        project_name=$(basename "$host_project")
                    fi
                    tmp_dir="/tmp/compose-start.$$"
                    prepare_compose_tmp "$host_project" "$tmp_dir"
                    if run_compose_recreate_start "$project_name" "$tmp_dir/docker-compose.yml" \
                        "$profile" "$service" >/tmp/php-start.log 2>&1; then
                        ok=1
                    else
                        cp /tmp/php-start.log "$STATUS_DIR/last-start-error.log" 2>/dev/null || true
                    fi
                    rm -rf "$tmp_dir"
                fi
            fi
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

        for refresh_target in $(list_php_services) nginx; do
            [ "$refresh_target" = "$service" ] || refresh_service "$refresh_target"
        done
    done
    refresh_tick=$((refresh_tick + 1))
    if [ "$refresh_tick" -ge 5 ]; then
        for refresh_target in $(list_php_services) nginx; do
            refresh_service "$refresh_target"
        done
        refresh_tick=0
    fi
    sleep 1
done
