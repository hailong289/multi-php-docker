#!/bin/sh

# Tạo / cập nhật file config nginx từ template cho từng server trong env.json.
# - Giữ file đã tồn tại nếu DOMAIN_NAME, SERVER_PATH và CONTAINER_PHP_VERSION khớp.
# - Regenerate khi file chưa có hoặc một trong các giá trị trên đã đổi.
# - Xóa template của app không còn trong env / đã ENABLED=false.

JSON_FILE="${ENV_JSON_FILE:-/var/environment/env.json}"

if [ ! -f "$JSON_FILE" ]; then
    echo "JSON file not found: $JSON_FILE"
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq command not found. Cài đặt jq..."
    apt-get update && apt-get install -y jq
    echo "jq đã được cài đặt thành công."
else
    echo "jq đã được cài đặt."
fi

keys=$(jq -r 'keys_unsorted | .[] | select(test("SERVER_NAME[0-9]*"))' "$JSON_FILE")

TEMPLATE_FILE="/etc/nginx/examples/server_example.txt"
OUTPUT_DIR="/etc/nginx/templates"

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

# "    root /var/www/source_php8.2/app/public;" → path
current_root_from_template() {
    sed -n 's/^[[:space:]]*root[[:space:]]\{1,\}\([^;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1
}

# "        fastcgi_pass php8.2_container:9000;" → php8.2_container
current_php_from_template() {
    sed -n 's/^[[:space:]]*fastcgi_pass[[:space:]]\{1,\}\([^:;[:space:]]\{1,\}\).*/\1/p' "$1" | head -n 1
}

render_template() {
    hostname="$1"
    source_path="$2"
    php_version="$3"
    output_file="$4"

    sed -e "s|\${SERVER_NAME}|${hostname}|g" \
        -e "s|\${SERVER_PATH}|${source_path}|g" \
        -e "s|\${CONTAINER_PHP_VERSION}|${php_version}|g" \
        "$TEMPLATE_FILE" > "$output_file"
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

    if [ -z "$DOCKER_APP_NAME" ] || [ -z "$DOCKER_HOSTNAME" ] || [ -z "$DOCKER_SOURCE_PATH" ]; then
        echo "Biến môi trường bị thiếu: $key"
        continue
    fi

    printf '%s\n' "$DOCKER_APP_NAME" >> "$desired_list"
    OUTPUT_FILE="$OUTPUT_DIR/${DOCKER_APP_NAME}.template"

    if [ -f "$OUTPUT_FILE" ]; then
        CURRENT_DOMAIN=$(current_domain_from_template "$OUTPUT_FILE")
        CURRENT_ROOT=$(current_root_from_template "$OUTPUT_FILE")
        CURRENT_PHP=$(current_php_from_template "$OUTPUT_FILE")
        REASONS=""

        if [ "$CURRENT_DOMAIN" != "$DOCKER_HOSTNAME" ]; then
            REASONS="${REASONS} domain($CURRENT_DOMAIN→$DOCKER_HOSTNAME)"
        fi
        if [ "$CURRENT_ROOT" != "$DOCKER_SOURCE_PATH" ]; then
            REASONS="${REASONS} root($CURRENT_ROOT→$DOCKER_SOURCE_PATH)"
        fi
        if [ "$CURRENT_PHP" != "$DOCKER_PHP_VERSION" ]; then
            REASONS="${REASONS} php($CURRENT_PHP→$DOCKER_PHP_VERSION)"
        fi

        if [ -z "$REASONS" ]; then
            echo "Giữ template (domain/path/php không đổi): $OUTPUT_FILE"
            continue
        fi
        echo "Ghi đè vì thay đổi:${REASONS} → $OUTPUT_FILE"
    else
        echo "Tạo mới: $OUTPUT_FILE"
    fi

    render_template "$DOCKER_HOSTNAME" "$DOCKER_SOURCE_PATH" "$DOCKER_PHP_VERSION" "$OUTPUT_FILE"
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
