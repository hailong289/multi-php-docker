[Tiếng Việt](README.md) | [English](README.en.md)

# PHP Development Environment with Docker

This repository provides a local development environment with Nginx, PHP 7.4, PHP 8.2, MySQL, Redis, and RabbitMQ. Ready-to-use multi-architecture images are provided on Docker Hub, so users can pull and run the stack without building it. Dockerfiles remain available for users who need customized images. Nginx generates virtual hosts from [`env.json`](env.json), allowing multiple projects to use different domains and PHP versions.

## Default services and ports

| Service | Container | Host ports | Default configuration |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Serves domains defined in `env.json` |
| PHP 8.2 | `php8_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| PHP 7.4 | `php7_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | No password |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor_container` | Not published | Runs PHP 8.2 background workers |

The following images are provided:

| Service | Image |
| --- | --- |
| `nginx` | `long301001/multi-php-docker:nginx` |
| `php-8.2`, `supervisor` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `long301001/multi-php-docker:php-7.4` |
| `mysql` | `long301001/multi-php-docker:mysql` |
| `redis` | `long301001/multi-php-docker:redis-alpine` |
| `rabbitmq` | `long301001/multi-php-docker:rabbitmq-3-management` |

## Requirements

- Docker Desktop or Docker Engine.
- Docker Compose v2 (the `docker compose` command).
- `jq` when using the script that automatically updates the `hosts` file.
- Administrator privileges to edit the `hosts` file.

Verify the environment:

```bash
docker --version
docker compose version
```

## Installation and usage

### 1. Clone the repository

```bash
git clone <repository-url>
cd <repository-folder>
```

### 2. Place the source code in the correct directory

- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

These directories are mounted inside the containers at `/var/www/source_php8.2` and `/var/www/source_php7.4`, respectively.

```text
server/
├── source_php8.2/
│   └── my-php8-app/
└── source_php7.4/
    └── my-php7-app/
```

### 3. Define projects in `env.json`

Each project uses one `SERVER_NAME<N>` entry:

```json
{
  "SERVER_NAME1": {
    "APP_NAME": "my-php8-app",
    "DOMAIN_NAME": "my-php8-app.test",
    "SERVER_PATH": "/var/www/source_php8.2/my-php8-app/public",
    "CONTAINER_PHP_VERSION": "php8_container"
  },
  "SERVER_NAME2": {
    "APP_NAME": "my-php7-app",
    "DOMAIN_NAME": "my-php7-app.test",
    "SERVER_PATH": "/var/www/source_php7.4/my-php7-app/public",
    "CONTAINER_PHP_VERSION": "php7_container"
  }
}
```

| Field | Description |
| --- | --- |
| `APP_NAME` | Project name and generated Nginx configuration filename |
| `DOMAIN_NAME` | Domain used on the local machine |
| `SERVER_PATH` | Absolute document-root path **inside the container** |
| `CONTAINER_PHP_VERSION` | `php8_container` or `php7_container` |

For Laravel or frameworks with a separate public directory, `SERVER_PATH` must point to `public`, `webroot`, or the directory containing `index.php`.

### 4. Add domains to the `hosts` file

Automatic setup on macOS, Linux, or WSL:

```bash
chmod +x scripts/add_hostname.sh
./scripts/add_hostname.sh
```

The script reads every `DOMAIN_NAME` in `env.json` and maps it to `127.0.0.1`. To configure domains manually, edit:

- macOS/Linux: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

```text
127.0.0.1 my-php8-app.test
127.0.0.1 my-php7-app.test
```

### 5. Pull images and start the environment

On the first run, pull the provided images before creating the containers:

```bash
docker compose pull
docker compose up -d
```

For subsequent starts, run:

```bash
docker compose up -d
```

Check the container status:

```bash
docker compose ps
```

When Nginx starts, `scripts/auto-add-template.sh` reads `env.json`, generates virtual hosts from `nginx/examples/server_example.txt`, and loads the resulting configuration. Open a configured domain such as `http://my-php8-app.test`.

## Common commands

### View logs

```bash
docker compose logs -f
docker compose logs -f nginx
docker compose logs -f php-8.2
docker compose logs -f mysql
```

### Stop, restart, and remove containers

```bash
# Stop without removing containers
docker compose stop

# Start stopped containers
docker compose start

# Remove containers and the network; named volumes are preserved
docker compose down

# Restart one service
docker compose restart nginx
```

### Update images from the registry

```bash
# Pull the newest content for the configured tags
docker compose pull
docker compose up -d
```

### Build custom images

To change extensions, packages, or configuration inside an image, change `image` to your own name and add `build` to the corresponding service. Do not keep a `long301001/multi-php-docker:*` name for a custom image.

Example for a custom PHP 8.2 image:

```yaml
services:
  php-8.2:
    image: my-project/php:8.2-local
    build:
      context: .
      dockerfile: ./docker_files/php8.Dockerfile
    # Keep the existing volumes, working_dir, and networks

  supervisor:
    image: my-project/php:8.2-local
    # Do not add build; Supervisor reuses the php-8.2 image
```

Build the custom image before starting the containers:

```bash
docker compose build php-8.2
docker compose up -d php-8.2 supervisor

# Build and run another service
docker compose build <service-name>
docker compose up -d <service-name>
```

Valid service names: `nginx`, `php-8.2`, `php-7.4`, `supervisor`, `mysql`, `redis`, and `rabbitmq`.

## Running background workers with Supervisor

The `php-8.2` and `supervisor` services both use the provided `long301001/multi-php-docker:php-8.2` image. They also mount the same source directory at `server/source_php8.2` and the same `php.ini`. Supervisor runs workers in its own container; it does not control processes inside the PHP-FPM container.

### Create a worker configuration

Copy the example file:

```bash
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/worker.conf
```

Update `directory` and `command` in `worker.conf` for the project. Laravel example:

```ini
[program:app_worker]
directory=/var/www/source_php8.2/my-project
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/app-worker.log
```

Create multiple `.conf` files in `configs/supervisor.d/` to run workers for multiple projects. Files ending in `.example` are not loaded automatically.

### Start and manage workers

```bash
# Start PHP-FPM and Supervisor from the provided image
docker compose up -d php-8.2 supervisor

# View worker status
docker compose exec supervisor supervisorctl status

# Reload configuration after adding or editing .conf files
docker compose exec supervisor supervisorctl reread
docker compose exec supervisor supervisorctl update

# Restart all workers
docker compose exec supervisor supervisorctl restart all

# View container and worker logs
docker compose logs -f supervisor
ls logs/supervisor
```

Supervisor uses `mysql`, `redis`, and `rabbitmq` as hostnames inside `app-network`. `depends_on` only controls container startup order; it does not guarantee that a dependency is ready to accept connections, so workers should retry failed connections.

### Using Supervisor with other PHP versions

Each Supervisor container contains one PHP runtime. When projects use multiple PHP versions, create one Supervisor service per version and share the image with the matching PHP-FPM service:

| PHP-FPM service | Supervisor service | Shared image |
| --- | --- | --- |
| `php-8.2` | `supervisor` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `supervisor-7` | Your custom PHP 7.4 image |
| `php-8-3` | `supervisor-8-3` | `server-php:8.3-local` |

Do not add `build` to a Supervisor service. For a custom image, only the matching PHP-FPM service declares `build`; the Supervisor service reuses the same image name to prevent duplicate builds.

#### PHP 7.4 Supervisor example

The provided PHP 7.4 image does not currently include Supervisor. To run `supervisor-7`, create a custom image: add `supervisor` to the package list in `docker_files/php7.Dockerfile`, change `php-7.4` to your own image name, and add `build` as described under **Build custom images**.

```dockerfile
RUN apt-get update && apt-get install -y \
    supervisor \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    curl
```

Create a version-specific worker configuration directory:

```bash
mkdir -p configs/supervisor.d/php7.4
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php7.4/worker.conf
```

Add the service to `docker-compose.yml`:

```yaml
  supervisor-7:
    image: my-project/php:7.4-local
    container_name: supervisor7_container
    volumes:
      - ./server/source_php7.4:/var/www/source_php7.4
      - ./scripts:/var/scripts
      - ./configs/php7.4/php.ini:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf:ro
      - ./configs/supervisor.d/php7.4:/etc/supervisor/conf.d:ro
      - ./logs/supervisor-7:/var/log/supervisor
    working_dir: /var/www/source_php7.4
    command: ["/var/scripts/supervisord.sh"]
    depends_on:
      - mysql
      - redis
      - rabbitmq
    networks:
      - app-network
```

Build the PHP 7.4 image once, then start PHP-FPM and Supervisor:

```bash
docker compose build php-7.4
docker compose up -d php-7.4 supervisor-7
docker compose exec supervisor-7 supervisorctl status
```

#### PHP 8.3 or newer Supervisor example

If the new version's Dockerfile was copied from `php8.Dockerfile`, the image already includes Supervisor. Assign a fixed image tag to the PHP-FPM service:

```yaml
  php-8-3:
    image: server-php:8.3-local
    build:
      context: .
      dockerfile: ./docker_files/php8.3.Dockerfile
    # volumes, working_dir, and networks...
```

Then add a Supervisor service that reuses the image:

```yaml
  supervisor-8-3:
    image: server-php:8.3-local
    container_name: supervisor83_container
    volumes:
      - ./server/source_php8.3:/var/www/source_php8.3
      - ./scripts:/var/scripts
      - ./configs/php8.3/php.ini:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf:ro
      - ./configs/supervisor.d/php8.3:/etc/supervisor/conf.d:ro
      - ./logs/supervisor-8.3:/var/log/supervisor
    working_dir: /var/www/source_php8.3
    command: ["/var/scripts/supervisord.sh"]
    depends_on:
      - mysql
      - redis
      - rabbitmq
    networks:
      - app-network
```

Create the worker configuration and start the services:

```bash
mkdir -p configs/supervisor.d/php8.3
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf

docker compose build php-8-3
docker compose up -d php-8-3 supervisor-8-3
docker compose exec supervisor-8-3 supervisorctl status
```

Update `directory` in each `worker.conf` to match the PHP version's source path, such as `/var/www/source_php7.4/my-project` or `/var/www/source_php8.3/my-project`. Separate configuration and log directories per version prevent workers from being loaded by the wrong runtime.

### Run commands inside containers

```bash
docker compose exec php-8.2 sh
docker compose exec php-8.2 php -v
docker compose exec php-7.4 php -v
docker compose exec mysql mysql -uroot -p1
```

## Connecting applications to services

Applications running inside a container must use Docker service names as hostnames instead of `localhost`:

```dotenv
DB_HOST=mysql
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=1

REDIS_HOST=redis
REDIS_PORT=6379

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=admin
RABBITMQ_PASSWORD=admin
```

RabbitMQ Management UI: [http://localhost:15672](http://localhost:15672).

Applications running directly on the host should use `127.0.0.1` and the host ports listed in the services table.

## Adding or changing a project

1. Place the source code in the appropriate PHP directory.
2. Add or update the project in `env.json`.
3. Run `./scripts/add_hostname.sh` again when adding a domain.
4. Recreate Nginx to regenerate the virtual hosts:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

## Adding another PHP version

The following example adds PHP 8.3. The same process applies to other versions, but system libraries and extensions must be compatible with the selected PHP version.

### 1. Create the PHP 8.3 Dockerfile

Copy the closest existing Dockerfile:

```bash
cp docker_files/php8.Dockerfile docker_files/php8.3.Dockerfile
```

Change the base image in `docker_files/php8.3.Dockerfile`:

```dockerfile
FROM php:8.3-fpm
```

Keep or adjust the packages and extensions according to the project requirements. The PHP 8.2 image currently includes `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl`, and Redis.

### 2. Create the PHP configuration

```bash
mkdir -p configs/php8.3
cp configs/php8/php.ini configs/php8.3/php.ini
```

Edit `configs/php8.3/php.ini` to change upload, memory, or execution-time limits if required.

### 3. Create the source directory

```bash
mkdir -p server/source_php8.3
```

### 4. Add the service to `docker-compose.yml`

Add a service at the same level as `php-8.2` and `php-7.4`:

```yaml
  php-8-3:
    build:
      context: .
      dockerfile: ./docker_files/php8.3.Dockerfile
    container_name: php83_container
    volumes:
      - ./server/source_php8.3:/var/www/source_php8.3
      - ./scripts:/var/scripts
      - ./configs/php8.3/php.ini:/usr/local/etc/php/php.ini
    working_dir: /var/www/source_php8.3
    networks:
      - app-network
```

Port `9000` does not need to be published to the host because Nginx connects to PHP-FPM through `app-network`.

Add the new service to the Nginx `depends_on` list:

```yaml
  nginx:
    # ...
    depends_on:
      - php-8.2
      - php-7.4
      - php-8-3
```

### 5. Define a project that uses PHP 8.3

Add the project to `env.json`. `CONTAINER_PHP_VERSION` must match the new `container_name`:

```json
{
  "SERVER_NAME3": {
    "APP_NAME": "my-php83-app",
    "DOMAIN_NAME": "my-php83-app.test",
    "SERVER_PATH": "/var/www/source_php8.3/my-php83-app/public",
    "CONTAINER_PHP_VERSION": "php83_container"
  }
}
```

`SERVER_NAME3` is only an example. Use an unused number and preserve the existing project entries in `env.json`.

### 6. Build and verify

```bash
./scripts/add_hostname.sh
docker compose up -d --build php-8-3
docker compose up -d --force-recreate nginx

docker compose exec php-8-3 php -v
docker compose exec nginx nginx -t
```

Open `http://my-php83-app.test`. If an extension fails to build, confirm that it supports the selected PHP version and update the required system packages in the Dockerfile.

## Backing up and restoring MySQL

The MySQL named volume is `mysql-data`.

### Backup

Stop MySQL to keep the direct volume backup consistent:

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .

docker compose start mysql
```

The backup is created as `./mysql-data.tar.gz`.

### Restore

> Restoring writes backup data into the current volume. Back up the current volume before proceeding.

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/mysql-data.tar.gz -C /data

docker compose start mysql
```

## Troubleshooting

### A domain cannot be reached

- Verify that the domain exists in the `hosts` file and points to `127.0.0.1`.
- Run `docker compose ps` to check Nginx and PHP.
- Check the fields in `env.json`, then recreate the Nginx container.

### 404 or `File not found`

- `SERVER_PATH` must be a path inside the container.
- Verify that the document root contains `index.php`.
- Confirm that the source is in the correct PHP 7.4 or PHP 8.2 directory.

### A port is already in use

Stop the application using the port or change the host side of the mapping in `docker-compose.yml`. For example, change `"3306:3306"` to `"3307:3306"` to expose MySQL on host port `3307`.

### PHP cannot connect to MySQL, Redis, or RabbitMQ

Inside a container, use `mysql`, `redis`, and `rabbitmq` as hostnames instead of `localhost`. Check container status with `docker compose ps`.

### An image fails to build

- View the full build output with `docker compose build --no-cache <service-name>`.
- Check the network connection and Docker daemon.
- Verify Dockerfile names, especially `docker_files/rabbitMQ.Dockerfile` for RabbitMQ.

## Building multi-architecture images with Docker Buildx

Use this workflow when publishing images that must support both:

- `linux/amd64`: Intel/AMD machines and most Windows/Linux systems.
- `linux/arm64`: Apple Silicon and ARM64 machines.

Multi-architecture configuration is unnecessary for local-only use. Run `docker compose build` and Docker will build for the host's native architecture.

### 1. Define platforms in `docker-compose.yml`

Add `platforms` under `build` for each service being published. The `image` value must also be a fully qualified Docker Hub or registry name that you are authorized to push:

```yaml
services:
  nginx:
    image: <dockerhub-username>/server-nginx:v1.0.0
    build:
      context: .
      dockerfile: ./docker_files/nginx.Dockerfile
      platforms:
        - linux/amd64
        - linux/arm64

  php-8.2:
    image: <dockerhub-username>/server-php:8.2-v1.0.0
    build:
      context: .
      dockerfile: ./docker_files/php8.Dockerfile
      platforms:
        - linux/amd64
        - linux/arm64
```

Apply the same structure to `php-7.4`, `mysql`, `redis`, and `rabbitmq`. The `supervisor` service has no `build` section and continues to share the `php-8.2` image.

> Do not push local tags such as `server-php:8.2-local`. Use `<registry>/<namespace>/<image>:<tag>`, for example `docker.io/my-user/server-php:8.2-v1.0.0`.

### 2. Create a Buildx builder

This step is required only once per machine:

```bash
docker buildx create \
  --name multiarch-builder \
  --driver docker-container \
  --use

docker buildx inspect --bootstrap
```

Check the selected builder and its supported platforms:

```bash
docker buildx ls
```

### 3. Sign in to the registry

For Docker Hub:

```bash
docker login
```

For another registry:

```bash
docker login <registry-domain>
```

### 4. Build and push all images

Docker Compose v2 uses BuildKit/Buildx for builds. After selecting the builder above, run:

```bash
docker compose build --push
```

Build and push one service only:

```bash
docker compose build --push php-8.2
```

The following variables are only needed with older Docker Compose versions; modern Compose v2 normally does not require them:

```bash
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1
```

### 5. Verify the published image

```bash
docker buildx imagetools inspect \
  <dockerhub-username>/server-php:8.2-v1.0.0
```

Alternatively:

```bash
docker manifest inspect \
  <dockerhub-username>/server-php:8.2-v1.0.0
```

The result should contain manifests for both `linux/amd64` and `linux/arm64`.

### Multi-architecture build notes

- Use `--push` to send multi-platform output directly to a registry. The classic Docker image store cannot load multiple platforms under one local tag.
- Cross-builds may use QEMU and run slower than native builds, especially while compiling PHP extensions.
- Base images and packages installed by Dockerfiles must be available for both architectures.
- Do not run `docker compose up` with publishing configuration before the registry tags exist.
- Do not reuse one tag for different release contents; use explicit version tags such as `v1.0.0`.

See the official [Docker Compose Build Specification](https://docs.docker.com/reference/compose-file/build/) and [Docker multi-platform build documentation](https://docs.docker.com/build/building/multi-platform/).

## Repository structure

```text
.
├── configs/                 # PHP and Supervisor configuration
│   └── supervisor.d/        # Background-worker configurations
├── docker_files/            # Dockerfiles used to build services
├── mysql/                   # MySQL configuration
├── nginx/
│   ├── examples/            # Virtual-host template
│   ├── logs/                # Nginx logs
│   └── templates/           # Configuration generated from env.json
├── scripts/                 # Startup and configuration scripts
├── server/
│   ├── source_php7.4/       # PHP 7.4 projects
│   └── source_php8.2/       # PHP 8.2 projects
├── docker-compose.yml
└── env.json                 # Project and domain definitions
```
