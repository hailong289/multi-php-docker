FROM node:22-alpine AS frontend

WORKDIR /frontend
COPY server/manager/frontend/package.json server/manager/frontend/package-lock.json ./
RUN npm ci
COPY server/manager/frontend/ ./
# vite.config.js writes to ../public → /public
RUN npm run build

FROM long301001/multi-php-docker:php-8.5

WORKDIR /app
COPY server/manager/backend ./backend
COPY server/manager/router.php ./router.php
COPY --from=frontend /public ./public

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/router.php"]
