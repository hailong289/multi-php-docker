#!/bin/bash

# Kiểm tra xem tệp env.json có tồn tại không
JSON_FILE="env.json"
if [ ! -f "$JSON_FILE" ]; then
  echo "ERROR: $JSON_FILE not found"
  exit 1
fi

# Đọc tất cả các SERVER_NAME từ tệp env.json
variables=$(jq -r 'keys[] | select(test("SERVER_NAME"))' "$JSON_FILE")

# Xác định tệp hosts tùy theo hệ điều hành
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    HOSTS_FILE="/etc/hosts"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    HOSTS_FILE="/etc/hosts"
elif [[ "$OSTYPE" == "cygwin" || "$OSTYPE" == "msys" ]]; then
    HOSTS_FILE="/c/Windows/System32/drivers/etc/hosts"
else
    echo "Unsupported OS: $OSTYPE"
    exit 1
fi

# Lặp qua từng biến SERVER_NAME và thêm vào tệp hosts
for var in $variables; do
    # Trích xuất DOMAIN_NAME từ tệp env.json sử dụng jq
    HOSTNAME=$(jq -r --arg var "$var" '.[$var].DOMAIN_NAME' "$JSON_FILE")

    # Địa chỉ IP mặc định
    IP_ADDRESS="127.0.0.1"

    echo "Processing host: $HOSTNAME"

    # Kiểm tra xem hostname đã tồn tại trong /etc/hosts chưa
    if grep -q "$HOSTNAME" "$HOSTS_FILE"; then
        echo "Host entry for $HOSTNAME already exists."
    else
        # Nếu chưa có, thêm vào tệp hosts
        echo "$IP_ADDRESS $HOSTNAME" | sudo tee -a "$HOSTS_FILE" > /dev/null
        echo "Host entry for $HOSTNAME added successfully."
    fi
done
