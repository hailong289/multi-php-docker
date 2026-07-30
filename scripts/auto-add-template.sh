#!/bin/sh

# Tạo file config nginx từ template cho từng server_name trong env.json
JSON_FILE="/var/environment/env.json"

if [ ! -f "$JSON_FILE" ]; then
    echo "JSON file not found: $JSON_FILE"
    exit 1
fi

if ! command -v jq &> /dev/null; then
    echo "jq command not found. Cài đặt jq..."
    # Tải jq từ trang chính (cần curl)
    apt-get update && apt-get install -y jq

    echo "jq đã được cài đặt thành công."
else
    echo "jq đã được cài đặt."
fi

keys=$(jq -r 'keys_unsorted | .[] | select(test("SERVER_NAME[0-9]*"))' "$JSON_FILE")

# Định nghĩa đường dẫn
TEMPLATE_FILE="/etc/nginx/examples/server_example.txt"
OUTPUT_DIR="/etc/nginx/templates"

# Kiểm tra file template tồn tại
if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "Template file not found: $TEMPLATE_FILE" >&2
    exit 1
fi

# Tạo thư mục đầu ra nếu chưa tồn tại
mkdir -p "$OUTPUT_DIR"

# Xóa template được sinh từ lần chạy trước để tránh nạp lại project đã xóa
# hoặc tên PHP container cũ. Thư mục này chỉ chứa file runtime được tạo từ env.json.
find "$OUTPUT_DIR" -maxdepth 1 -type f -name '*.template' -delete

# Lặp qua từng server
for key in $keys; do
    # Trích xuất NAME và PATH từ JSON bằng jq
    DOCKER_APP_NAME=$(jq -r --arg key "$key" '.[$key].APP_NAME' "$JSON_FILE")
    DOCKER_HOSTNAME=$(jq -r --arg key "$key" '.[$key].DOMAIN_NAME' "$JSON_FILE")
    DOCKER_SOURCE_PATH=$(jq -r --arg key "$key" '.[$key].SERVER_PATH' "$JSON_FILE")
    DOCKER_PHP_VERSION=$(jq -r --arg key "$key" '.[$key].CONTAINER_PHP_VERSION' "$JSON_FILE")

    if [ -n "$DOCKER_APP_NAME" ] && [ -n "$DOCKER_HOSTNAME" ] && [ -n "$DOCKER_SOURCE_PATH" ]; then
        OUTPUT_FILE="$OUTPUT_DIR/${DOCKER_APP_NAME}.template"

        # Thay thế cả SERVER_NAME và SERVER_PATH trong template
        sed -e "s|\${SERVER_NAME}|${DOCKER_HOSTNAME}|g" \
            -e "s|\${SERVER_PATH}|${DOCKER_SOURCE_PATH}|g" \
            -e "s|\${CONTAINER_PHP_VERSION}|${DOCKER_PHP_VERSION}|g" \
            "$TEMPLATE_FILE" > "$OUTPUT_FILE"

        echo "Tạo config nginx thành công: $OUTPUT_FILE"
    else
        echo "Biến môi trường bị thiếu: $key"
    fi
done
