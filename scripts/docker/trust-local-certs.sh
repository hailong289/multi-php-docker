#!/bin/sh
# Trust Manager's self-signed site certs (nginx/ssl/<app>/cert.pem) so PHP can call https://<site>.test.
dir=/usr/local/share/ca-certificates
[ -d "$dir" ] || exit 0
rm -f "$dir"/multi-php-*.crt
for cert in /var/nginx-ssl/*/cert.pem; do
    [ -f "$cert" ] || continue
    cp "$cert" "$dir/multi-php-$(basename "$(dirname "$cert")").crt"
done
update-ca-certificates >/dev/null 2>&1 || true
