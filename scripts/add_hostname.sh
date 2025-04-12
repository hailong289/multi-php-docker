#!/bin/bash

# Kiểm tra xem tệp env.json có tồn tại không
JSON_FILE="env.json"
if [ ! -f "$JSON_FILE" ]; then
  echo "ERROR: $JSON_FILE not found"
  exit 1
fi

# Đọc tất cả các SERVER_NAME từ tệp env.json
variables=$(jq -r 'keys[] | select(test("SERVER_NAME"))' "$JSON_FILE")

# Xác định file hosts theo hệ điều hành
case "$OSTYPE" in
  linux-gnu*)
    if grep -qi "microsoft" /proc/version 2>/dev/null; then
      # WSL
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
  cygwin* | msys* | win32)
    HOSTS_FILE="/mnt/c/Windows/System32/drivers/etc/hosts"
    PLATFORM="windows"
    ;;
  *)
    echo "Unsupported OS: $OSTYPE"
    exit 1
    ;;
esac

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
       if [ "$PLATFORM" = "linux" ] || [ "$PLATFORM" = "mac" ]; then
         echo "$IP $DOMAIN" | sudo tee -a "$HOSTS_FILE" > /dev/null \
           && echo "✔️ Host entry for $DOMAIN added successfully." \
           || echo "❌ Failed to add host entry."
       elif [ "$PLATFORM" = "wsl" ]; then
         powershell.exe -Command "Start-Process powershell -Verb runAs -ArgumentList 'Add-Content -Path \"C:\Windows\System32\drivers\etc\hosts\" -Value \"${IP} ${DOMAIN}\"'" \
           && echo "✔️ Host entry for $DOMAIN added via PowerShell." \
           || echo "❌ Failed to add host entry via PowerShell."
       else
         echo "❌ Unsupported method to add host entry."
       fi
    fi
done
