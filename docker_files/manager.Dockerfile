# Static Vite output. Build on the runner CPU so npm never runs under QEMU:
# arm64 emulation hits "Illegal instruction" inside npm ci and the step hangs.
FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend

WORKDIR /frontend
COPY server/manager/frontend/package.json server/manager/frontend/package-lock.json ./
RUN npm ci
COPY server/manager/frontend/ ./
# vite.config.js writes to ../public → /public
RUN npm run build

ARG PHP_BASE_IMAGE=multi-php-local:php-8.5
FROM ${PHP_BASE_IMAGE}

WORKDIR /app
COPY server/manager/backend ./backend
COPY server/manager/router.php ./router.php
COPY --from=frontend /public ./public

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/router.php"]
