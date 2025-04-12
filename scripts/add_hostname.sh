#!/bin/bash

# Địa chỉ IP mặc định
IP_ADDRESS="127.0.0.1"

# Kiểm tra xem tệp env.json có tồn tại không
JSON_FILE="env.json"
if [ ! -f "$JSON_FILE" ]; then
  echo "ERROR: $JSON_FILE không tìm thấy hãy kiểm tra lại"
  exit 1
fi

if ! command -v jq &> /dev/null; then
    echo "jq command not found. Cài đặt jq..."

    # Kiểm tra nếu có sudo và apt-get
    if command -v sudo &> /dev/null && command -v apt-get &> /dev/null; then
        sudo apt-get update && sudo apt-get install -y jq

        if command -v jq &> /dev/null; then
            echo "jq đã được cài đặt thành công."
        else
            echo "jq không thể cài đặt. Vui lòng cài đặt thủ công."
        fi
    else
        echo "Không thể cài đặt jq tự động. Vui lòng cài đặt thủ công."
        exit 1
    fi
else
    echo "jq đã được cài đặt."
fi

# Đọc tất cả các SERVER_NAME từ tệp env.json
variables=$(jq -r 'keys[] | select(test("SERVER_NAME"))' "$JSON_FILE")

# Xác định file hosts theo hệ điều hành
OS_TYPE=$(uname -s | tr '[:upper:]' '[:lower:]')
case "$OS_TYPE" in
  linux*)
    if grep -qi "microsoft" /proc/version 2>/dev/null; then
      HOSTS_FILE="/mnt/c/Windows/System32/drivers/etc/hosts"
      PLATFORM="wsl"
    else
      HOSTS_FILE="/etc/hosts"
      PLATFORM="linux"
    fi
    ;;
  darwin*)
    HOSTS_FILE="/etc/hosts"
    PLATFORM="mac"
    ;;
  cygwin* | msys* | mingw*)
    HOSTS_FILE="/mnt/c/Windows/System32/drivers/etc/hosts"
    PLATFORM="windows"
    ;;
  *)
    echo "Unsupported OS: $OS_TYPE"
    exit 1
    ;;
esac

# Lặp qua từng biến SERVER_NAME và thêm vào tệp hosts
for var in $variables; do
    DOCKER_HOSTNAME=$(jq -r --arg var "$var" '.[$var].DOMAIN_NAME' "$JSON_FILE")
    echo "Bắt đầu thêm host ==========================="
    # Kiểm tra xem hostname đã tồn tại trong /etc/hosts chưa
    if grep -q "$DOCKER_HOSTNAME" "$HOSTS_FILE"; then
        echo "Host: $DOCKER_HOSTNAME đã tồn tại trong $HOSTS_FILE"
    else
        # Nếu chưa có, thêm vào tệp hosts
       if [ "$PLATFORM" = "linux" ] || [ "$PLATFORM" = "mac" ]; then
         echo "$IP_ADDRESS $DOCKER_HOSTNAME" | sudo tee -a "$HOSTS_FILE" > /dev/null \
           && echo "Host: $DOCKER_HOSTNAME đã thêm thành công" \
           || echo "Không thể thêm host: $DOCKER_HOSTNAME vào $HOSTS_FILE"
       elif [ "$PLATFORM" = "wsl" ]; then
          powershell.exe -Command "Start-Process powershell -Verb runAs -ArgumentList '-Command \"Add-Content -Path \\\"C:\\Windows\\System32\\drivers\\etc\\hosts\\\" -Value \\\"$IP_ADDRESS $DOCKER_HOSTNAME\\\"\"'" \
           && echo "Host $DOCKER_HOSTNAME được thêm qua PowerShell thành công." \
           || echo "Host $DOCKER_HOSTNAME không thể thêm qua PowerShell. Hãy thao tác thủ công."
       else
         echo "Hệ điều hành không được hỗ trợ: $OS_TYPE, không thể thêm host $DOCKER_HOSTNAME. Hãy thao tác thủ công."
       fi
    fi
done
