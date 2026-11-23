#!/bin/sh

# Tạo / cập nhật file config nginx từ template cho từng server trong env.json.
# - Giữ file đã tồn tại nếu DOMAIN_NAME, SERVER_PATH, CONTAINER_PHP_VERSION và SSL khớp.
# - Regenerate khi file chưa có hoặc một trong các giá trị trên đã đổi.
# - Xóa template của app không còn trong env / đã ENABLED=false.
# - Thêm listen 443 khi SSL_ENABLED và đủ cert/key trong $SSL_DIR.
# - TARGET=<tên>: proxy cả site tới REMOTES[<tên>] (dev, staging, ...) thay vì PHP local.

JSON_FILE="${ENV_JSON_FILE:-/var/environment/env.json}"

if [ ! -f "$JSON_FILE" ]; then
    echo "JSON file not found: $JSON_FILE"
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq command not found. Cài đặt jq..."
    if [ -f /etc/alpine-release ]; then
        apk add --no-cache jq
    else
        apt-get update && apt-get install -y jq && rm -rf /var/lib/apt/lists/*
    fi
    echo "jq đã được cài đặt thành công."
else
    echo "jq đã được cài đặt."
fi

keys=$(jq -r 'keys_unsorted | .[] | select(test("SERVER_NAME[0-9]*"))' "$JSON_FILE")

TEMPLATE_FILE="${NGINX_TEMPLATE_FILE:-/etc/nginx/examples/server_example.txt}"
SSL_TEMPLATE_FILE="${NGINX_SSL_TEMPLATE_FILE:-/etc/nginx/examples/server_ssl_example.txt}"
OUTPUT_DIR="${NGINX_OUTPUT_DIR:-/etc/nginx/templates}"
JSON_DIR=$(dirname "$JSON_FILE")
SSL_DIR="${NGINX_SSL_DIR:-$JSON_DIR/nginx/ssl}"

if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "Template file not found: $TEMPLATE_FILE" >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

# Danh sách APP_NAME còn được phục vụ (ENABLED != false)
desired_list=$(mktemp)
trap 'rm -f "$desired_list"' EXIT INT TERM

is_enabled() {
    key="$1"
    ENABLED_RAW=$(jq -r --arg key "$key" '
        .[$key] as $s
        | (if ($s | has("ENABLED")) then $s.ENABLED else true end) as $e
        | if ($e | type) == "boolean" then (if $e then "true" else "false" end)
          elif ($e | type) == "number" then (if $e == 0 then "false" else "true" end)
          else ($e | tostring | ascii_downcase)
          end
    ' "$JSON_FILE")
    case "$ENABLED_RAW" in
        false|0|no|off) return 1 ;;
        *) return 0 ;;
    esac
}

# "    server_name example.test;" → example.test
current_domain_from_template() {
    sed -n 's/^[[:space:]]*server_name[[:space:]]\{1,\}\([^;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1
}

# "    root /var/www/source/app/public;" → path
current_root_from_template() {
    sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1
}

# "        set $php_upstream php8.2_container:9000;" → php8.2_container
# Older files used "fastcgi_pass php8.2_container:9000;".
current_php_from_template() {
    upstream=$(sed -n 's/^[[:space:]]*set[[:space:]]\{1,\}\$php_upstream[[:space:]]\{1,\}\([^:;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1)
    if [ -n "$upstream" ]; then
        printf '%s\n' "$upstream"
        return
    fi
    sed -n 's/^[[:space:]]*fastcgi_pass[[:space:]]\{1,\}\([^:;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1
}

# Literal fastcgi_pass hostnames are resolved at startup and stop Nginx when
# that PHP container does not exist yet.
php_upstream_is_deferred() {
    passes=$(grep -c 'fastcgi_pass' "$1" || true)
    deferred=$(grep -c 'fastcgi_pass[[:space:]]\{1,\}\$php_upstream;' "$1" || true)
    [ "$passes" -gt 0 ] && [ "$passes" = "$deferred" ]
}

is_ssl_enabled() {
    key="$1"
    SSL_RAW=$(jq -r --arg key "$key" '
        .[$key] as $s
        | (if ($s | has("SSL_ENABLED")) then $s.SSL_ENABLED else false end) as $e
        | if ($e | type) == "boolean" then (if $e then "true" else "false" end)
          elif ($e | type) == "number" then (if $e == 0 then "false" else "true" end)
          else ($e | tostring | ascii_downcase)
          end
    ' "$JSON_FILE")
    case "$SSL_RAW" in
        true|1|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

ssl_files_present() {
    app="$1"
    [ -f "$SSL_DIR/$app/cert.pem" ] && [ -f "$SSL_DIR/$app/key.pem" ]
}

current_ssl_listen() {
    grep -q 'listen[[:space:]]\{1,\}443' "$1"
}

current_ssl_app_from_template() {
    sed -n 's#^[[:space:]]*ssl_certificate[[:space:]]\{1,\}.*/\([^/]\{1,\}\)/cert\.pem;.*#\1#p' "$1" | head -n 1
}

# TARGET → REMOTES[TARGET]; empty for local or an unsafe value (env.json may be hand-edited).
remote_url_for() {
    url=$(jq -r --arg key "$1" '
        .[$key] as $s
        | (($s.REMOTES // {}) | if type == "object" then . else {} end) as $r
        | (($s.TARGET // "") | tostring) as $t
        | if $t == "" then "" else (($r[$t] // "") | tostring) end
    ' "$JSON_FILE")
    if [ -n "$url" ] && ! printf '%s' "$url" | grep -Eq '^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$'; then
        echo "Bỏ qua URL remote không hợp lệ cho $1: $url" >&2
        url=""
    fi
    printf '%s' "$url"
}

# "    # remote: https://staging.example.com" → URL (empty for a local PHP template)
current_remote_from_template() {
    sed -n 's/^[[:space:]]*# remote:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$1" | head -n 1
}

# One server block proxying everything to $url. Variables keep nginx starting when the host does not resolve.
proxy_server_block() {
    hostname="$1"
    url="$2"
    listen="$3"
    ssl_lines="$4"
    log_suffix="$5"
    remote_host=$(printf '%s' "$url" | sed -e 's#^[a-z]*://##' -e 's#:.*##')
    # On plain HTTP the browser drops Secure cookies, so strip the flag there.
    cookie_flags=""
    [ -z "$log_suffix" ] && cookie_flags="        proxy_cookie_flags ~ nosecure;"
    cat <<EOF
server {
    listen ${listen};
    server_name ${hostname};
    # remote: ${url}
    client_max_body_size 500M;
${ssl_lines}
    access_log /var/log/nginx/${hostname}${log_suffix}_access.log;
    error_log /var/log/nginx/${hostname}${log_suffix}_error.log;

    location / {
        resolver 127.0.0.11 valid=30s ipv6=off;
        set \$remote_upstream ${url};
        proxy_pass \$remote_upstream;
        proxy_set_header Host ${remote_host};
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_ssl_server_name on;
        proxy_ssl_name ${remote_host};
        # Keep the browser on this local domain: redirects and cookies from the remote host point back here.
        proxy_redirect ~^https?://${remote_host}(:[0-9]+)?(.*)\$ \$scheme://\$host\$2;
        proxy_cookie_domain ~.+ \$host;
${cookie_flags}
    }
}
EOF
}

render_proxy() {
    hostname="$1"
    app_name="$2"
    url="$3"
    output_file="$4"
    with_ssl="$5"

    proxy_server_block "$hostname" "$url" "80" "" "" > "$output_file"
    if [ "$with_ssl" = "1" ]; then
        printf '\n' >> "$output_file"
        proxy_server_block "$hostname" "$url" "443 ssl" "    ssl_certificate ${SSL_DIR}/${app_name}/cert.pem;
    ssl_certificate_key ${SSL_DIR}/${app_name}/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;" "_ssl" >> "$output_file"
    fi
}

render_template() {
    hostname="$1"
    source_path="$2"
    php_version="$3"
    app_name="$4"
    output_file="$5"
    with_ssl="$6"

    sed -e "s|\${SERVER_NAME}|${hostname}|g" \
        -e "s|\${SERVER_PATH}|${source_path}|g" \
        -e "s|\${CONTAINER_PHP_VERSION}|${php_version}|g" \
        -e "s|\${APP_NAME}|${app_name}|g" \
        -e "s|\${SSL_DIR}|${SSL_DIR}|g" \
        "$TEMPLATE_FILE" > "$output_file"

    if [ "$with_ssl" = "1" ]; then
        if [ ! -f "$SSL_TEMPLATE_FILE" ]; then
            echo "SSL template file not found: $SSL_TEMPLATE_FILE" >&2
            return 0
        fi
        printf '\n' >> "$output_file"
        sed -e "s|\${SERVER_NAME}|${hostname}|g" \
            -e "s|\${SERVER_PATH}|${source_path}|g" \
            -e "s|\${CONTAINER_PHP_VERSION}|${php_version}|g" \
            -e "s|\${APP_NAME}|${app_name}|g" \
            -e "s|\${SSL_DIR}|${SSL_DIR}|g" \
            "$SSL_TEMPLATE_FILE" >> "$output_file"
    fi
}

for key in $keys; do
    if ! is_enabled "$key"; then
        echo "Bỏ qua $key (ENABLED=false)"
        continue
    fi

    DOCKER_APP_NAME=$(jq -r --arg key "$key" '.[$key].APP_NAME' "$JSON_FILE")
    DOCKER_HOSTNAME=$(jq -r --arg key "$key" '.[$key].DOMAIN_NAME' "$JSON_FILE")
    DOCKER_SOURCE_PATH=$(jq -r --arg key "$key" '.[$key].SERVER_PATH' "$JSON_FILE")
    DOCKER_PHP_VERSION=$(jq -r --arg key "$key" '.[$key].CONTAINER_PHP_VERSION' "$JSON_FILE")

    if [ -z "$DOCKER_APP_NAME" ] || [ "$DOCKER_APP_NAME" = "null" ] || [ -z "$DOCKER_SOURCE_PATH" ] || [ "$DOCKER_SOURCE_PATH" = "null" ]; then
        echo "Biến môi trường bị thiếu: $key"
        continue
    fi

    printf '%s\n' "$DOCKER_APP_NAME" >> "$desired_list"
    OUTPUT_FILE="$OUTPUT_DIR/${DOCKER_APP_NAME}.template"

    # Domain removed: keep the server and its existing nginx file.
    if [ -z "$DOCKER_HOSTNAME" ] || [ "$DOCKER_HOSTNAME" = "null" ]; then
        echo "Giữ máy chủ (không có domain): $OUTPUT_FILE"
        continue
    fi
    WANT_SSL=0
    if is_ssl_enabled "$key" && ssl_files_present "$DOCKER_APP_NAME"; then
        WANT_SSL=1
    elif is_ssl_enabled "$key"; then
        echo "SSL bật cho $DOCKER_APP_NAME nhưng thiếu cert/key trong $SSL_DIR/$DOCKER_APP_NAME; chỉ ghi HTTP" >&2
    fi

    REMOTE_URL=$(remote_url_for "$key")

    if [ -f "$OUTPUT_FILE" ]; then
        CURRENT_REMOTE=$(current_remote_from_template "$OUTPUT_FILE")
        CURRENT_DOMAIN=$(current_domain_from_template "$OUTPUT_FILE")
        CURRENT_ROOT=$(current_root_from_template "$OUTPUT_FILE")
        CURRENT_PHP=$(current_php_from_template "$OUTPUT_FILE")
        CURRENT_SSL=0
        if current_ssl_listen "$OUTPUT_FILE"; then
            CURRENT_SSL=1
        fi
        CURRENT_SSL_APP=$(current_ssl_app_from_template "$OUTPUT_FILE")
        REASONS=""

        if [ "$CURRENT_DOMAIN" != "$DOCKER_HOSTNAME" ]; then
            REASONS="${REASONS} domain($CURRENT_DOMAIN→$DOCKER_HOSTNAME)"
        fi
        if [ "$CURRENT_REMOTE" != "$REMOTE_URL" ]; then
            REASONS="${REASONS} remote($CURRENT_REMOTE→$REMOTE_URL)"
        fi
        # A proxy template has no root/PHP to compare.
        if [ -z "$REMOTE_URL" ] && [ "$CURRENT_ROOT" != "$DOCKER_SOURCE_PATH" ]; then
            REASONS="${REASONS} root($CURRENT_ROOT→$DOCKER_SOURCE_PATH)"
        fi
        if [ -z "$REMOTE_URL" ] && [ "$CURRENT_PHP" != "$DOCKER_PHP_VERSION" ]; then
            REASONS="${REASONS} php($CURRENT_PHP→$DOCKER_PHP_VERSION)"
        fi
        if [ -z "$REMOTE_URL" ] && ! php_upstream_is_deferred "$OUTPUT_FILE"; then
            REASONS="${REASONS} php_resolve"
        fi
        if [ "$CURRENT_SSL" != "$WANT_SSL" ]; then
            REASONS="${REASONS} ssl($CURRENT_SSL→$WANT_SSL)"
        fi
        if [ "$WANT_SSL" -eq 1 ] && [ "$CURRENT_SSL_APP" != "$DOCKER_APP_NAME" ]; then
            REASONS="${REASONS} ssl_app($CURRENT_SSL_APP→$DOCKER_APP_NAME)"
        fi

        if [ -z "$REASONS" ]; then
            echo "Giữ template (domain/path/php/ssl không đổi): $OUTPUT_FILE"
            continue
        fi
        echo "Ghi đè vì thay đổi:${REASONS} → $OUTPUT_FILE"
    else
        echo "Tạo mới: $OUTPUT_FILE"
    fi

    if [ -n "$REMOTE_URL" ]; then
        render_proxy "$DOCKER_HOSTNAME" "$DOCKER_APP_NAME" "$REMOTE_URL" "$OUTPUT_FILE" "$WANT_SSL"
    else
        render_template "$DOCKER_HOSTNAME" "$DOCKER_SOURCE_PATH" "$DOCKER_PHP_VERSION" "$DOCKER_APP_NAME" "$OUTPUT_FILE" "$WANT_SSL"
    fi
    echo "Tạo config nginx thành công: $OUTPUT_FILE"
done

# Xóa template không còn trong danh sách app enabled
for existing in "$OUTPUT_DIR"/*.template; do
    [ -e "$existing" ] || break
    base=$(basename "$existing" .template)
    if ! grep -Fxq "$base" "$desired_list"; then
        echo "Xóa template orphan/disabled: $existing"
        rm -f "$existing"
    fi
done

# Không có máy chủ: một server mặc định phục vụ trang giới thiệu.
WELCOME_ROOT="${NGINX_WELCOME_ROOT:-/usr/share/nginx/welcome}"
WELCOME_TEMPLATE="$OUTPUT_DIR/_welcome.template"
if [ ! -s "$desired_list" ]; then
    cat > "$WELCOME_TEMPLATE" <<EOF
server {
    listen 80 default_server;
    server_name _;
    root ${WELCOME_ROOT};
    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
EOF
    echo "Không có máy chủ; phục vụ trang giới thiệu: $WELCOME_TEMPLATE"
fi
