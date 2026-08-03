[Tiếng Việt](README.md) | [English](README.en.md)

# PHP Development Environment with Docker

This repository provides a local development environment with Nginx, PHP 7.4, PHP 8.0, PHP 8.1, PHP 8.2, MySQL, Redis, and RabbitMQ. Every service uses a ready-to-use multi-architecture image from Docker Hub by default; `docker-compose.yml` does not build images. Dockerfiles remain in the repository as references or for creating custom images. Nginx generates virtual hosts from a local `env.json` based on [`env.example.json`](env.example.json), allowing multiple projects to use different domains and PHP versions.

## Default services and ports

| Service | Container | Host ports | Default configuration |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Serves domains defined in `env.json` |
| PHP 8.0 | `php8.0_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| PHP 8.1 | `php8.1_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| PHP 8.2 | `php8.2_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| PHP 7.4 | `php7.4_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | No password |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor_container` | Not published | Runs PHP 8.2 background workers |
| Server Manager | `manager_container` | `127.0.0.1:8080` | Manages virtual servers in `env.json` |
| PHP Controller | `php_controller_container` | Not published | Controls the allowlisted PHP containers through the Docker socket |
| Env Init | `env_init_container` | Not published | Creates a missing `env.json`, then exits with code `0` |

PHP 8.2 is the default version. `docker compose up -d` starts only PHP 8.2; PHP 7.4, 8.0, and 8.1 are assigned to separate profiles and remain disabled by default.

The following images are provided:

| Service | Image |
| --- | --- |
| `nginx` | `long301001/multi-php-docker:nginx` |
| `php-8.0` | `long301001/multi-php-docker:php-8.0` |
| `php-8.1` | `long301001/multi-php-docker:php-8.1` |
| `php-8.2`, `supervisor`, `manager` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `long301001/multi-php-docker:php-7.4` |
| `php-controller` | `docker:cli` |
| `mysql` | `long301001/multi-php-docker:mysql` |
| `redis` | `long301001/multi-php-docker:redis-alpine` |
| `rabbitmq` | `long301001/multi-php-docker:rabbitmq-3-management` |
| `env-init` | `alpine:latest` |

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

`env.json` contains machine-specific project configuration and must not be pushed to Git. Only `env.example.json` is committed as the configuration template. On the first Compose run, the `env-init` service automatically copies `env.example.json` to `env.json`; an existing file is always preserved. Compose mounts the project directory (not the optional `env.json` file itself) so bind-mount behavior stays consistent on Windows and macOS.

To create the file before starting Docker, copy it manually:

```bash
cp env.example.json env.json
```

### 2. Place the source code in the correct directory

- PHP 8.0: `server/source_php8.0/<project-name>`
- PHP 8.1: `server/source_php8.1/<project-name>`
- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

Each directory is mounted at the matching `/var/www/source_php<version>` path inside its container.

```text
server/
├── source_php8.0/
│   └── my-php80-app/
├── source_php8.1/
│   └── my-php81-app/
├── source_php8.2/
│   └── my-php82-app/
└── source_php7.4/
    └── my-php7-app/
```

### 3. Define projects in `env.json`



Each project uses one `SERVER_NAME<N>` entry:

```json
{
  "SERVER_NAME1": {
    "APP_NAME": "my-php80-app",
    "DOMAIN_NAME": "my-php80-app.test",
    "SERVER_PATH": "/var/www/source_php8.0/my-php80-app/public",
    "CONTAINER_PHP_VERSION": "php8.0_container"
  },
  "SERVER_NAME2": {
    "APP_NAME": "my-php81-app",
    "DOMAIN_NAME": "my-php81-app.test",
    "SERVER_PATH": "/var/www/source_php8.1/my-php81-app/public",
    "CONTAINER_PHP_VERSION": "php8.1_container"
  },
  "SERVER_NAME3": {
    "APP_NAME": "my-php82-app",
    "DOMAIN_NAME": "my-php82-app.test",
    "SERVER_PATH": "/var/www/source_php8.2/my-php82-app/public",
    "CONTAINER_PHP_VERSION": "php8.2_container"
  },
  "SERVER_NAME4": {
    "APP_NAME": "my-php7-app",
    "DOMAIN_NAME": "my-php7-app.test",
    "SERVER_PATH": "/var/www/source_php7.4/my-php7-app/public",
    "CONTAINER_PHP_VERSION": "php7.4_container"
  }
}
```

| Field | Description |
| --- | --- |
| `APP_NAME` | Project name and generated Nginx configuration filename |
| `DOMAIN_NAME` | Domain used on the local machine |
| `SERVER_PATH` | Absolute document-root path **inside the container** |
| `CONTAINER_PHP_VERSION` | `php8.0_container`, `php8.1_container`, `php8.2_container`, or `php7.4_container` |

For Laravel or frameworks with a separate public directory, `SERVER_PATH` must point to `public`, `webroot`, or the directory containing `index.php`.

### 4. Add domains to the `hosts` file

The stack starts without mounting the OS hosts file. Manager reads the latest status from `runtime/hosts.status.json`, which is written by the optional helper running on the host. Before the helper runs, domains show **Unknown** and the UI still provides a manual hosts fallback.

**Write hosts (needs host script + admin):**

1. Run once to register the `multi-php-hosts:` helper/protocol. This setup is only needed for automatic hosts writing; it is not required to start Docker or create PHP containers:

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\ensure_hosts_env.ps1
```

Unregister: `powershell -ExecutionPolicy Bypass -File .\scripts\ensure_hosts_env.ps1 -UnregisterProtocol`

macOS:

```bash
chmod +x scripts/ensure_hosts_env.sh scripts/add_hostname.sh scripts/hosts_protocol_macos.sh
./scripts/ensure_hosts_env.sh
```

Unregister: `./scripts/ensure_hosts_env.sh --unregister-protocol`

2. In Manager use **Add domain** / **Write hosts (Admin)**. The browser opens `multi-php-hosts:write` → writes hosts (UAC on Windows / admin prompt on macOS). Allow the app if the browser prompts.

Linux / WSL (no browser protocol):

```bash
chmod +x scripts/add_hostname.sh
./scripts/add_hostname.sh --watch
```

The script only edits the `# multi-php-docker-serve:managed:*` block in the hosts file.

**One-shot (without Manager):**

```bash
./scripts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\add_hostname.ps1
```

The script reads every `DOMAIN_NAME` in `env.json` (plus `runtime/hosts.extra.json` if present) and maps it to `127.0.0.1`. To configure domains manually, edit:

- macOS/Linux: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

```text
127.0.0.1 my-php8-app.test
127.0.0.1 my-php7-app.test
```

### 5. Pull images and start the environment

On the first run, pull the images and start directly. Zero-config startup does not require a `.env` file:

```powershell
docker compose pull
docker compose up -d
```

macOS / Linux / WSL:

```bash
docker compose pull
docker compose up -d
```

The command above starts PHP 8.2 together with Nginx, MySQL, Redis, RabbitMQ, Supervisor, Server Manager, and PHP Controller. Older PHP versions are not started.

`php-controller` infers `HOST_PROJECT_PATH` from the `/project` bind mount. Existing `.env` overrides and the `scripts/compose.*` wrappers remain backward-compatible, but neither is required. The host helper is an optional OS integration with a manual hosts fallback.

### Enable an optional PHP version

Each older version has a Compose profile with the same name:

```bash
# Enable PHP 8.1
docker compose --profile php-8.1 up -d

# Enable PHP 8.0
docker compose --profile php-8.0 up -d

# Enable PHP 7.4
docker compose --profile php-7.4 up -d
```

Multiple versions can be enabled together:

```bash
docker compose \
  --profile php-8.0 \
  --profile php-8.1 \
  up -d
```

Stop and remove an optional version with:

```bash
docker compose stop php-8.1
docker compose rm -f php-8.1
```

When a project in `env.json` uses `php8.0_container`, `php8.1_container`, or `php7.4_container`, enable the matching profile before starting or recreating Nginx. Otherwise, Nginx cannot connect to that PHP upstream.

## Manage servers in the web interface

After running `docker compose up -d`, open:

[http://127.0.0.1:8080](http://127.0.0.1:8080)

Server Manager can:

- List the virtual servers currently stored in `env.json`.
- Add, edit, and delete servers.
- Select PHP 7.4, 8.0, 8.1, or 8.2.
- Reject duplicate application names and domains.
- Restrict document roots to the selected PHP version's source directory.
- Display the profiles and commands required to apply the configuration.
- Request virtual-host regeneration and an Nginx reload with **Apply & Reload Nginx**.
- Support Vietnamese and English; the first visit follows the browser language and the selected locale is then remembered in the session.
- Support **System**, **Light**, and **Dark** appearances; the selection is stored in the browser, and System mode follows the operating-system preference.
- Manage PHP container state directly with **Create**, **Start**, **Stop**, and **Restart** controls.

The **PHP Versions** card shows `Running`, `Stopped`, `Not created`, or `Processing`. PHP 8.2 is created by default. For an optional PHP container that has never been created, click **Create** in the UI (equivalent to `docker compose --profile … create …`), then use **Start**. You can still create manually:

```bash
docker compose --profile php-8.1 create php-8.1
```

Then refresh Server Manager to use the controls. The controller accepts PHP 8.2, 8.1, 8.0, and 7.4 plus Create (profiled services only), Start, Stop, and Restart; it does not delete containers. The controller infers the repository path on the Docker host from the `/project` mount; `HOST_PROJECT_PATH` in `.env` is only a backward-compatible override.

The `php-controller` service publishes no ports and is the only container that mounts `/var/run/docker.sock`. Docker socket access is effectively root-level access to the Docker host, so only run the stack from trusted source and never expose Server Manager publicly.

If `env.json` does not exist, the `env-init` service creates it from `env.example.json` before Server Manager and Nginx start. This short-lived service never overwrites existing configuration.

The UI is bound to `127.0.0.1` only and is not directly exposed to the LAN. The Docker socket is not mounted into Server Manager. PHP control requests pass through a separate runtime directory to `php-controller`. When **Apply & Reload Nginx** is clicked, the UI writes a signal file to the shared `runtime/` directory. A watcher inside the Nginx container regenerates the templates, runs `nginx -t`, and reloads only when the configuration is valid. If validation fails, the previous configuration is restored.

For the default PHP 8.2 runtime, click **Apply & Reload Nginx** after adding, editing, or deleting a server. The container does not need to be restarted.

The reload button does not start optional PHP profiles. If the server uses PHP 8.1, 8.0, or 7.4, run the profile command shown by the UI first. For example, with PHP 8.1:

```bash
docker compose --profile php-8.1 up -d
```

Then click **Apply & Reload Nginx**. Refresh the page to see the latest result below the button. Detailed errors are written to `runtime/nginx.reload.log`; `runtime/` contains temporary data and is ignored by Git.

New domains must still be added to the `hosts` file:

```bash
./scripts/add_hostname.sh
```

Stop the UI separately when it is not needed:

```bash
docker compose stop manager
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

Valid service names: `env-init`, `nginx`, `php-8.0`, `php-8.1`, `php-8.2`, `php-7.4`, `supervisor`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`, `manager`, `php-controller`, `mysql`, `redis`, and `rabbitmq`.

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

Each Supervisor container contains one PHP runtime. PHP (+ Supervisor) lives in `compose/php-X.Y.yml`. MySQL, Redis, and RabbitMQ are shared and defined in `compose/mysql.yml`, `compose/redis.yml`, and `compose/rabbitmq.yml`. The root `docker-compose.yml` `include`s those files.

| PHP-FPM service | Supervisor service | File | Shared image |
| --- | --- | --- | --- |
| `php-8.2` | `supervisor` | `compose/php-8.2.yml` | `long301001/multi-php-docker:php-8.2` |
| `php-8.1` | `supervisor-8.1` | `compose/php-8.1.yml` | `long301001/multi-php-docker:php-8.1` |
| `php-8.0` | `supervisor-8.0` | `compose/php-8.0.yml` | `long301001/multi-php-docker:php-8.0` |
| `php-7.4` | `supervisor-7.4` | `compose/php-7.4.yml` | PHP 7.4 image (must include Supervisor) |

Do not add `build` to a Supervisor service. For a custom image, only the matching PHP-FPM service declares `build`; the Supervisor service reuses the same image name to prevent duplicate builds.

Per-version workers: put `.conf` files in `configs/supervisor.d/` (default PHP 8.2) or `configs/supervisor.d/php8.1`, `php8.0`, `php7.4`.

#### Supervisor for PHP 8.1 / 8.0 (already in compose)

```bash
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.1/worker.conf
# Edit directory/command in worker.conf

docker compose --profile php-8.1 up -d php-8.1 supervisor-8.1
docker compose exec supervisor-8.1 supervisorctl status
```

Same pattern for profile `php-8.0` / service `supervisor-8.0`.

#### PHP 7.4 Supervisor example

The provided PHP 7.4 image does not currently include Supervisor. To run `supervisor-7.4`, create a custom image: add `supervisor` to the package list in `docker_files/php7.Dockerfile`, change the image of `php-7.4` (and `supervisor-7.4` in `compose/php-7.4.yml`) to your own name, and add `build` as described under **Build custom images**.

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

```bash
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php7.4/worker.conf

docker compose build php-7.4
docker compose --profile php-7.4 up -d php-7.4 supervisor-7.4
docker compose exec supervisor-7.4 supervisorctl status
```

#### PHP 8.3 or newer Supervisor example

Create `compose/php-8.3.yml` (PHP-FPM + Supervisor sharing one image), then add a matching `include` in `docker-compose.yml` with `project_directory: .`. Example Supervisor service:

```yaml
  supervisor-8.3:
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

```bash
mkdir -p configs/supervisor.d/php8.3
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf

docker compose build php-8.3
docker compose up -d php-8.3 supervisor-8.3
docker compose exec supervisor-8.3 supervisorctl status
```

Update `directory` in each `worker.conf` to match the PHP version's source path. Separate configuration and log directories per version prevent workers from being loaded by the wrong runtime.

### Run commands inside containers

```bash
docker compose exec php-8.2 sh
docker compose exec php-8.0 php -v
docker compose exec php-8.1 php -v
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

Add a service at the same level as `php-8.0`, `php-8.1`, `php-8.2`, and `php-7.4`:

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
- Confirm that the source is in the correct PHP 7.4, 8.0, 8.1, or 8.2 directory.

### A port is already in use

Stop the application using the port or change the host side of the mapping in `compose/mysql.yml`. For example, change `"3306:3306"` to `"3307:3306"` to expose MySQL on host port `3307`.

### PHP cannot connect to MySQL, Redis, or RabbitMQ

Inside a container, use `mysql`, `redis`, and `rabbitmq` as hostnames instead of `localhost`. Check container status with `docker compose ps`.

### An image fails to build

- View the full build output with `docker compose build --no-cache <service-name>`.
- Check the network connection and Docker daemon.
- Verify Dockerfile names, especially `docker_files/rabbitMQ.Dockerfile` for RabbitMQ.

## Repository structure

```text
.
├── compose/                 # Compose fragments (PHP+Supervisor, mysql, redis, rabbitmq)
│   ├── mysql.yml
│   ├── php-7.4.yml
│   ├── php-8.0.yml
│   ├── php-8.1.yml
│   ├── php-8.2.yml
│   ├── rabbitmq.yml
│   └── redis.yml
├── configs/                 # PHP and Supervisor configuration
│   └── supervisor.d/        # Default (8.2) workers; php8.1/, php8.0/, php7.4/ per version
├── docker_files/            # Dockerfiles used to build services
├── mysql/                   # MySQL configuration
├── nginx/
│   ├── examples/            # Virtual-host template
│   ├── logs/                # Nginx logs
│   └── templates/           # Configuration generated from env.json
├── scripts/                 # Startup and configuration scripts
├── server/
│   ├── manager/             # env.json management UI
│   ├── source_php7.4/       # PHP 7.4 projects
│   ├── source_php8.0/       # PHP 8.0 projects
│   ├── source_php8.1/       # PHP 8.1 projects
│   └── source_php8.2/       # PHP 8.2 projects
├── docker-compose.yml       # Root: include + nginx/manager/php-controller/env-init
├── env.example.json         # Committed project/domain template
└── env.json                 # Local configuration ignored by Git
```
