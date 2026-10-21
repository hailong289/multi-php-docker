# Static Vite output. Build on the runner CPU so npm never runs under QEMU:
# arm64 emulation hits "Illegal instruction" inside npm ci and the step hangs.
FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend

WORKDIR /frontend
COPY server/manager/frontend/package.json server/manager/frontend/package-lock.json ./
RUN npm ci
COPY server/manager/frontend/ ./
# vite.config.js writes to ../public → /public
RUN npm run build

FROM docker:cli AS dockercli

FROM php:8.5-cli-alpine

# Compose actions run in this image. Shipping the CLI avoids a separate docker:cli pull.
COPY --from=dockercli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=dockercli /usr/local/bin/docker-compose /usr/local/bin/docker-compose
COPY --from=dockercli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

WORKDIR /app
COPY server/manager/backend ./backend
COPY server/manager/router.php ./router.php
COPY nginx/welcome/index.html ./welcome/index.html
COPY --from=frontend /public ./public

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/router.php"]
