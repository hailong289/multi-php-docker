#!/bin/sh
# One vhost per enabled site with WEB_SERVER=apache in env.json, written to $APACHE_SITES_DIR.
# Adds a :443 vhost when SSL_ENABLED and nginx/ssl/<APP_NAME>/{cert,key}.pem exist (same certs Nginx uses).

JSON_FILE="${ENV_JSON_FILE:-/var/host-project/env.json}"
OUT="${APACHE_SITES_DIR:-/etc/apache-sites}"
SSL_DIR="${APACHE_SSL_DIR:-/etc/apache-ssl}"

if [ ! -f "$JSON_FILE" ]; then
    echo "Không thấy env.json: $JSON_FILE" >&2
    exit 1
fi
mkdir -p "$OUT"
find "$OUT" -maxdepth 1 -type f -name '*.conf' -delete

site_block() {
    host="$1"; path="$2"; php="$3"; suffix="$4"
    cat <<EOF
    ServerName ${host}
    DocumentRoot "${path}"
    <Directory "${path}">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:fcgi://${php}:9000"
    </FilesMatch>
    ErrorLog /var/log/apache/${host}${suffix}_error.log
    CustomLog /var/log/apache/${host}${suffix}_access.log combined
EOF
}

jq -r '
    to_entries[]
    | select(.key | test("^SERVER_NAME[0-9]*$"))
    | .value
    | select(((.ENABLED // true) | tostring | ascii_downcase) as $e | ($e != "false" and $e != "0" and $e != "no" and $e != "off"))
    | select(((.WEB_SERVER // "") | tostring | ascii_downcase) == "apache")
    | [(.APP_NAME // ""), (.DOMAIN_NAME // ""), (.SERVER_PATH // ""), (.CONTAINER_PHP_VERSION // ""), ((.SSL_ENABLED // false) | tostring | ascii_downcase)]
    | @tsv
' "$JSON_FILE" | while IFS="$(printf '\t')" read -r app host path php ssl; do
    # Values are written verbatim into Apache config; env.json may be hand-edited.
    if ! printf '%s' "$app" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]*$' \
        || ! printf '%s' "$host" | grep -Eq '^[A-Za-z0-9.-]+$' \
        || ! printf '%s' "$path" | grep -Eq '^/var/www/[A-Za-z0-9._/-]+$' \
        || ! printf '%s' "$php" | grep -Eq '^[A-Za-z0-9._-]+$'; then
        echo "Bỏ qua site không hợp lệ: app=$app host=$host" >&2
        continue
    fi
    file="$OUT/${app}.conf"
    {
        echo "# Sinh từ env.json ($app); sửa tay sẽ bị ghi đè."
        echo "<VirtualHost *:80>"
        site_block "$host" "$path" "$php" ""
        echo "</VirtualHost>"
    } > "$file"
    case "$ssl" in
        true|1|yes|on)
            if [ -f "$SSL_DIR/$app/cert.pem" ] && [ -f "$SSL_DIR/$app/key.pem" ]; then
                {
                    echo ""
                    echo "<VirtualHost *:443>"
                    site_block "$host" "$path" "$php" "_ssl"
                    echo "    SSLEngine on"
                    echo "    SSLCertificateFile $SSL_DIR/$app/cert.pem"
                    echo "    SSLCertificateKeyFile $SSL_DIR/$app/key.pem"
                    echo "</VirtualHost>"
                } >> "$file"
            else
                echo "SSL bật cho $app nhưng thiếu cert/key trong $SSL_DIR/$app; chỉ ghi HTTP" >&2
            fi
            ;;
    esac
    chmod 644 "$file"
    echo "Ghi vhost: $file ($host)"
done
