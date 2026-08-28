#!/bin/sh

set -u

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
BASE_DIR="/runtime"
REQUEST_DIR="$BASE_DIR/requests"
STATUS_DIR="$BASE_DIR/status"

mkdir -p "$REQUEST_DIR" "$STATUS_DIR"
# php-controller runs with a read-only rootfs; point Docker CLI config at tmpfs.
mkdir -p "${DOCKER_CONFIG:-/tmp/docker-config}"

# PID 1 is this shell loop. Without a TERM trap, docker stop waits the default
# ~10s then SIGKILL (same class of bug as nginx wrapping sh without exec).
shutdown_controller() {
    trap - TERM INT
    # Stop in-flight docker/compose children in this process group, then exit.
    kill -TERM -$$ 2>/dev/null || true
    exit 0
}
trap shutdown_controller TERM INT

container_for_service() {
    case "$1" in
        nginx) printf '%s' 'nginx_container' ;;
        mysql) printf '%s' 'mysql_container' ;;
        redis) printf '%s' 'redis_container' ;;
        rabbitmq) printf '%s' 'rabbitmq_container' ;;
        supervisor) printf '%s' 'supervisor_container' ;;
        supervisor-*)
            # supervisor-8.1 → supervisor81_container
            # supervisor-8.2.33-alpine → supervisor8233alpine_container
            # BusyBox tr treats leading '-' in SET as options; use sed instead.
            printf 'supervisor%s_container' "$(printf '%s' "${1#supervisor-}" | sed 's/[-.]//g')"
            ;;
        php-*)
            # php-8.3 → php8.3_container ; php-8.3-alpine → php8.3alpine_container
            printf 'php%s_container' "$(printf '%s' "${1#php-}" | sed 's/-//g')"
            ;;
        *) return 1 ;;
    esac
}

profile_for_service() {
    case "$1" in
        php-8.5) return 1 ;;
        mysql|redis|rabbitmq|supervisor)
            printf '%s' "$1"
            ;;
        supervisor-*)
            printf '%s' "$1"
            ;;
        php-*)
            printf '%s' "$1"
            ;;
        *) return 1 ;;
    esac
}

is_infra_service() {
    case "$1" in
        mysql|redis|rabbitmq) return 0 ;;
        *) return 1 ;;
    esac
}

is_supervisor_service() {
    case "$1" in
        supervisor|supervisor-*) return 0 ;;
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

list_infra_services() {
    printf '%s\n' mysql redis rabbitmq
}

list_supervisor_services() {
    for f in /project/compose/php-*.yml; do
        [ -f "$f" ] || continue
        sed -n 's/^  \(supervisor[^:]*\):[[:space:]]*$/\1/p' "$f"
    done
}

list_managed_services() {
    list_php_services
    printf '%s\n' nginx
    list_infra_services
    list_supervisor_services
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

# Hub images (MySQL/Redis/RabbitMQ): pull then create — do not build.
run_compose_pull_create() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" pull "$service"
    "$@" || return 1

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" create "$service"
    "$@"
}

# Hub / pre-built images: create without build. Pull if the image is missing.
run_compose_create_or_pull() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" create "$service"
    if "$@"; then
        return 0
    fi

    run_compose_pull_create "$project_name" "$compose_file" "$profile" "$service"
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

# Pull latest image then force-recreate + start the service (apply compose.yml changes).
run_compose_pull_recreate() {
    project_name="$1"
    compose_file="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_file" --profile "$profile" pull "$service"
    "$@" || return 1

    run_compose_recreate_start "$project_name" "$compose_file" "$profile" "$service"
}

run_compose_file_pull() {
    project_name="$1"
    compose_yml="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_yml"
    if [ -n "$profile" ]; then
        set -- "$@" --profile "$profile"
    fi
    set -- "$@" pull "$service"
    "$@"
}

run_compose_file_build() {
    project_name="$1"
    compose_yml="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_yml"
    if [ -n "$profile" ]; then
        set -- "$@" --profile "$profile"
    fi
    set -- "$@" build "$service"
    "$@"
}

run_compose_file_create() {
    project_name="$1"
    compose_yml="$2"
    profile="$3"
    service="$4"

    set -- docker compose -p "$project_name"
    if [ -f /project/.env ]; then
        set -- "$@" --env-file /project/.env
    fi
    set -- "$@" -f "$compose_yml"
    if [ -n "$profile" ]; then
        set -- "$@" --profile "$profile"
    fi
    set -- "$@" create "$service"
    "$@"
}

compose_file_safe_name() {
    name=$(basename "$1")
    case "$name" in
        *.yml|*.yaml) ;;
        *) return 1 ;;
    esac
    printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(yml|yaml)$' || return 1
    printf '%s' "$name"
}

write_compose_file_status() {
    queue_key="$1"
    state="$2"
    message_key="$3"
    request_id="$4"
    compose_file="$5"
    updated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    temp_file="$STATUS_DIR/$queue_key.json.tmp"
    printf '{"compose_file":"%s","queue_key":"%s","state":"%s","message_key":"%s","request_id":"%s","updated_at":"%s"}\n' \
        "$compose_file" "$queue_key" "$state" "$message_key" "$request_id" "$updated_at" > "$temp_file"
    mv "$temp_file" "$STATUS_DIR/$queue_key.json"
}

parse_compose_file_request() {
    request="$1"
    compose_file=$(printf '%s' "$request" | sed -n 's/^.*"compose_file":"\([^"]*\)".*$/\1/p')
    queue_key=$(printf '%s' "$request" | sed -n 's/^.*"queue_key":"\([^"]*\)".*$/\1/p')
    action=$(printf '%s' "$request" | sed -n 's/^.*"action":"\([^"]*\)".*$/\1/p')
    request_id=$(printf '%s' "$request" | sed -n 's/^.*"request_id":"\([0-9a-f]*\)".*$/\1/p')

    compose_file=$(compose_file_safe_name "$compose_file") || return 1
    printf '%s' "$request_id" | grep -Eq '^[0-9a-f]{32}$' || return 1
    case "$action" in
        create|start) ;;
        *) return 1 ;;
    esac
    printf '%s' "$queue_key" | grep -Eq '^compose-file__[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(yml|yaml)$' || return 1
    [ -f "/project/compose/$compose_file" ] || return 1
    return 0
}

handle_compose_file_request() {
    request="$1"
    request_file="$2"

    target_compose_file=$(printf '%s' "$request" | sed -n 's/^.*"compose_file":"\([^"]*\)".*$/\1/p')
    queue_key=$(printf '%s' "$request" | sed -n 's/^.*"queue_key":"\([^"]*\)".*$/\1/p')
    action=$(printf '%s' "$request" | sed -n 's/^.*"action":"\([^"]*\)".*$/\1/p')
    request_id=$(printf '%s' "$request" | sed -n 's/^.*"request_id":"\([0-9a-f]*\)".*$/\1/p')

    write_compose_file_status "$queue_key" "busy" "services.processing" "$request_id" "$target_compose_file"
    ok=0
    host_project=$(resolve_host_project) || true
    if [ -n "$host_project" ]; then
        project_name=$(docker inspect nginx_container --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null) || true
        if [ -z "$project_name" ]; then
            project_name=$(basename "$host_project")
        fi
        tmp_dir="/tmp/compose-file.$$"
        prepare_compose_tmp "$host_project" "$tmp_dir"
        log_file="$STATUS_DIR/$queue_key.last-$action.log"
        : >"$log_file"
        while IFS='|' read -r service profile container has_build; do
            [ -n "$service" ] || continue
            case "$action" in
                create)
                    if [ "$has_build" = "1" ]; then
                        if run_compose_file_build "$project_name" "$tmp_dir/docker-compose.yml" "$profile" "$service" >>"$log_file" 2>&1 \
                            && run_compose_file_create "$project_name" "$tmp_dir/docker-compose.yml" "$profile" "$service" >>"$log_file" 2>&1; then
                            ok=1
                        fi
                    elif run_compose_file_create "$project_name" "$tmp_dir/docker-compose.yml" "$profile" "$service" >>"$log_file" 2>&1; then
                        ok=1
                    else
                        if run_compose_file_pull "$project_name" "$tmp_dir/docker-compose.yml" "$profile" "$service" >>"$log_file" 2>&1 \
                            && run_compose_file_create "$project_name" "$tmp_dir/docker-compose.yml" "$profile" "$service" >>"$log_file" 2>&1; then
                            ok=1
                        fi
                    fi
                    ;;
                start)
                    if [ -n "$container" ] && docker start "$container" >>"$log_file" 2>&1; then
                        sleep 2
                        if container_running "$container"; then
                            ok=1
                        else
                            docker logs --tail 40 "$container" >>"$log_file" 2>&1 || true
                        fi
                    fi
                    ;;
            esac
        done <<EOF
$(awk '
BEGIN { svc=""; profile=""; container=""; has_build="0"; has_image="0" }
/^services:[[:space:]]*$/ { in_services=1; next }
in_services && /^[^ #]/ && !/^  / { exit }
in_services && /^  [a-zA-Z0-9][a-zA-Z0-9._-]*:[[:space:]]*$/ {
    if (svc != "") print svc "|" profile "|" container "|" (has_build == "1" && has_image != "1" ? "1" : "0")
    svc=$1
    sub(/:$/, "", svc)
    profile=""
    container=""
    has_build="0"
    has_image="0"
    next
}
in_services && /^    profiles:/ {
    if (match($0, /"([^"]+)"/)) {
        profile=substr($0, RSTART + 1, RLENGTH - 2)
    }
    next
}
in_services && /^    container_name:/ {
    container=$2
    gsub(/"/, "", container)
    next
}
in_services && /^    image:/ { has_image="1" }
in_services && /^    build:/ { has_build="1" }
END { if (svc != "") print svc "|" profile "|" container "|" (has_build == "1" && has_image != "1" ? "1" : "0") }
' "/project/compose/$target_compose_file")
EOF
        rm -rf "$tmp_dir"
    fi

    final_state="error"
    if [ "$ok" -eq 1 ]; then
        final_state="not_created"
        while IFS='|' read -r _svc _profile container _has_build; do
            [ -n "$container" ] || continue
            s=$(container_state "$container")
            if [ "$s" = "running" ]; then
                final_state="running"
                break
            fi
            if [ "$s" = "stopped" ]; then
                final_state="stopped"
            fi
        done <<EOF2
$(awk '
BEGIN { svc=""; profile=""; container=""; has_build="0"; has_image="0" }
/^services:[[:space:]]*$/ { in_services=1; next }
in_services && /^[^ #]/ && !/^  / { exit }
in_services && /^  [a-zA-Z0-9][a-zA-Z0-9._-]*:[[:space:]]*$/ {
    if (svc != "") print svc "|" profile "|" container "|" (has_build == "1" && has_image != "1" ? "1" : "0")
    svc=$1
    sub(/:$/, "", svc)
    profile=""
    container=""
    has_build="0"
    has_image="0"
    next
}
in_services && /^    profiles:/ {
    if (match($0, /"([^"]+)"/)) {
        profile=substr($0, RSTART + 1, RLENGTH - 2)
    }
    next
}
in_services && /^    container_name:/ {
    container=$2
    gsub(/"/, "", container)
    next
}
in_services && /^    image:/ { has_image="1" }
in_services && /^    build:/ { has_build="1" }
END { if (svc != "") print svc "|" profile "|" container "|" (has_build == "1" && has_image != "1" ? "1" : "0") }
' "/project/compose/$target_compose_file")
EOF2
        write_compose_file_status "$queue_key" "$final_state" "php_controller.action_success" "$request_id" "$target_compose_file"
    else
        write_compose_file_status "$queue_key" "error" "php_controller.action_failed" "$request_id" "$target_compose_file"
    fi
    rm -f "$request_file"
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

container_running() {
    container="$1"
    state=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null) || return 1
    [ "$state" = "running" ]
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
        mysql|redis|rabbitmq) ;;
        supervisor) ;;
        supervisor-*)
            printf '%s' "$service" | grep -Eq '^supervisor-[0-9]+(\.[0-9]+)+(-alpine|-trixie)?$' || return 1
            ;;
        php-*)
            printf '%s' "$service" | grep -Eq '^php-[0-9]+(\.[0-9]+)+(-alpine|-trixie)?$' || return 1
            ;;
        *) return 1 ;;
    esac
    case "$action" in
        start|stop|restart|create|install-version|pull-recreate)
            [ -z "$extension" ] || return 1
            if [ "$action" = "install-version" ] && { is_infra_service "$service" || is_supervisor_service "$service"; }; then
                return 1
            fi
            if [ "$action" = "pull-recreate" ] && ! is_infra_service "$service"; then
                return 1
            fi
            ;;
        modules|available-ext)
            [ "$service" != "nginx" ] || return 1
            is_infra_service "$service" && return 1
            is_supervisor_service "$service" && return 1
            [ -z "$extension" ] || return 1
            ;;
        install-ext|uninstall-ext)
            [ "$service" != "nginx" ] || return 1
            is_infra_service "$service" && return 1
            is_supervisor_service "$service" && return 1
            extension_allowed "$extension" || return 1
            ;;
        *) return 1 ;;
    esac
    return 0
}

for service in $(list_managed_services); do
    refresh_service "$service"
done

while true; do
    for request_file in "$REQUEST_DIR"/*.json; do
        [ -e "$request_file" ] || break
        request=$(tr -d '\r\n' < "$request_file")

        if printf '%s' "$request" | grep -q '"compose_file"'; then
            if parse_compose_file_request "$request"; then
                handle_compose_file_request "$request" "$request_file"
            else
                reject_request "$request_file"
            fi
            continue
        fi

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
        if is_infra_service "$service" && [ "$action" = "install-version" ]; then
            reject_request "$request_file"
            continue
        fi
        if is_supervisor_service "$service" && [ "$action" = "install-version" ]; then
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
        if [ "$action" = "install-version" ] || [ "$action" = "create" ] || [ "$action" = "pull-recreate" ]; then
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
            if [ "$action" = "pull-recreate" ]; then
                if is_infra_service "$service" && run_compose_pull_recreate "$project_name" "$tmp_dir/docker-compose.yml" \
                    "$profile" "$service" >"$STATUS_DIR/$service.last-pull-recreate.log" 2>&1; then
                    ok=1
                else
                    cp "$STATUS_DIR/$service.last-pull-recreate.log" "$STATUS_DIR/last-pull-recreate-error.log" 2>/dev/null || true
                fi
            elif [ "$action" = "install-version" ]; then
                if run_compose_build_up "$project_name" "$tmp_dir/docker-compose.yml" \
                    "$profile" "$service" >"$STATUS_DIR/$service.last-install-version.log" 2>&1; then
                    ok=1
                fi
            elif is_infra_service "$service"; then
                log_file="$STATUS_DIR/$service.last-create.log"
                : >"$log_file"
                if run_compose_pull_create "$project_name" "$tmp_dir/docker-compose.yml" \
                    "$profile" "$service" >>"$log_file" 2>&1; then
                    ok=1
                else
                    cp "$log_file" "$STATUS_DIR/last-create-error.log" 2>/dev/null || true
                fi
            elif is_supervisor_service "$service"; then
                if run_compose_create_or_pull "$project_name" "$tmp_dir/docker-compose.yml" \
                    "$profile" "$service" >"/tmp/${service}-create.log" 2>&1; then
                    ok=1
                else
                    cp "/tmp/${service}-create.log" "$STATUS_DIR/last-create-error.log" 2>/dev/null || true
                fi
            elif run_compose_create "$project_name" "$tmp_dir/docker-compose.yml" \
                "$profile" "$service" >/tmp/php-create.log 2>&1; then
                ok=1
            else
                cp /tmp/php-create.log "$STATUS_DIR/last-create-error.log" 2>/dev/null || true
            fi
            rm -rf "$tmp_dir"
        elif [ "$action" = "start" ]; then
            start_log_file="$STATUS_DIR/$service.last-start.log"
            : >"$start_log_file"
            if docker start "$container" >>"$start_log_file" 2>&1; then
                sleep 2
                if container_running "$container"; then
                    ok=1
                else
                    docker logs --tail 40 "$container" >>"$start_log_file" 2>&1 || true
                fi
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
                        "$profile" "$service" >>"$start_log_file" 2>&1; then
                        ok=1
                    else
                        cp "$start_log_file" "$STATUS_DIR/last-start-error.log" 2>/dev/null || true
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

        for refresh_target in $(list_managed_services); do
            [ "$refresh_target" = "$service" ] || refresh_service "$refresh_target"
        done
    done
    # Keep status files in sync with Docker even when no requests arrive
    # (e.g. containers stopped from OrbStack / docker CLI).
    for refresh_target in $(list_managed_services); do
        refresh_service "$refresh_target"
    done
    # Background sleep so SIGTERM is delivered to the shell promptly (wait is interruptible).
    sleep 1 &
    wait $! || true
done
