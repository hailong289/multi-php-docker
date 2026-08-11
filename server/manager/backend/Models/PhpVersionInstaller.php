<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;

/** Writes compose/Dockerfile/ini/source assets for a new PHP version (+ alpine/trixie). */
final class PhpVersionInstaller
{
    public function __construct(private readonly string $projectPath = '')
    {
    }

    private function root(): string
    {
        return rtrim($this->projectPath !== '' ? $this->projectPath : Config::projectPath(), '/');
    }

    public function install(string $version, string $variant = 'default'): array
    {
        $version = trim($version);
        $variant = PhpVersionId::normalizeVariant($variant);
        if (!PhpVersionId::isValidMinor($version)) {
            throw new HttpException('php_controller.invalid_version', 400);
        }

        $service = PhpVersionId::serviceFrom($version, $variant);
        if (PhpVersionId::isDefault($service)) {
            throw new HttpException('php_controller.invalid_action', 400);
        }
        // Allow resume when a previous attempt wrote compose files but failed
        // before updating docker-compose.yml (common on Windows CRLF checkouts).
        if (
            isset(PhpVersionCatalog::versions($this->root())[$service])
            && $this->hasComposeInclude($service)
        ) {
            throw new HttpException('php_controller.version_already_installed', 409);
        }

        $root = $this->root();
        $this->writeDockerfile($version, $variant);
        $this->writeCompose($service, $version, $variant);
        $this->ensureIni($service);
        $this->ensureSourceDir($service);
        $this->ensureSupervisorDir($service);
        $this->ensureLogsDir($service);
        $this->ensureComposeInclude($service);

        $runtime = new PhpRuntime();
        $requestId = $runtime->request($service, 'install-version');
        $labelVersion = match ($variant) {
            'alpine' => $version . ' alpine',
            'trixie' => $version . ' trixie',
            default => $version,
        };

        return [
            'service' => $service,
            'version' => $version,
            'variant' => $variant,
            'request_id' => $requestId,
            'message_key' => 'php_controller.version_install_requested',
            'message_parameters' => ['version' => $labelVersion],
            'php_controllers' => [
                'targets' => PhpRuntime::targets($root),
                'statuses' => $runtime->statuses(),
            ],
            'php_versions' => PhpVersionCatalog::forApi($root),
        ];
    }

    private function writeDockerfile(string $version, string $variant): void
    {
        $dir = $this->root() . '/docker_files/generated';
        $this->mkdir($dir);
        $path = $dir . '/' . PhpVersionId::dockerfileName($version, $variant);
        $tag = PhpVersionId::dockerTag($version, $variant);
        // Alpine uses apk; debian/default and trixie use apt.
        if ($variant === 'alpine') {
            $content = <<<DOCKER
FROM php:{$tag}

RUN apk add --no-cache \\
    \$PHPIZE_DEPS \\
    git \\
    curl \\
    unzip \\
    freetype-dev \\
    libjpeg-turbo-dev \\
    libpng-dev \\
    libzip-dev \\
    libxml2-dev \\
    oniguruma-dev \\
    linux-headers \\
    supervisor \\
    && pecl install redis \\
    && docker-php-ext-enable redis \\
    && docker-php-ext-configure gd --with-freetype --with-jpeg \\
    && docker-php-ext-install pdo_mysql mysqli gd zip sockets pcntl \\
    && curl -sS https://getcomposer.org/installer | php \\
    && mv composer.phar /usr/local/bin/composer \\
    && apk del \$PHPIZE_DEPS

EXPOSE 9000

CMD ["php-fpm"]
DOCKER;
        } else {
            $content = <<<DOCKER
FROM php:{$tag}

RUN apt-get update && apt-get install -y \\
    git \\
    libpng-dev \\
    libjpeg-dev \\
    libfreetype6-dev \\
    libzip-dev \\
    libxml2-dev \\
    unzip \\
    libz-dev \\
    curl \\
    supervisor \\
    && pecl install redis \\
    && docker-php-ext-enable redis \\
    && curl -sS https://getcomposer.org/installer | php \\
    && mv composer.phar /usr/local/bin/composer

RUN docker-php-ext-install pdo_mysql mysqli gd zip sockets pcntl

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

EXPOSE 9000

CMD ["php-fpm"]
DOCKER;
        }
        $this->writeFile($path, $content);
    }

    private function writeCompose(string $service, string $version, string $variant): void
    {
        $container = PhpVersionId::container($service);
        $supervisorContainer = PhpVersionId::supervisorContainer($service);
        $source = PhpVersionId::sourceDirName($service);
        $iniRel = PhpVersionId::iniRelativePath($service);
        $supervisorDir = PhpVersionId::supervisorConfDir($service);
        $dockerfile = 'generated/' . PhpVersionId::dockerfileName($version, $variant);
        $image = 'multi-php-local:' . $service;
        $suffix = PhpVersionId::pathSuffix($service);
        $supervisorName = 'supervisor-' . $version . $suffix;
        $logDir = 'logs/supervisor-' . $version . $suffix;

        $content = <<<YAML
services:
  {$service}:
    profiles: ["{$service}"]
    build:
      context: ./docker_files
      dockerfile: {$dockerfile}
    image: {$image}
    container_name: {$container}
    volumes:
      - ./server/{$source}:/var/www/{$source}
      - ./scripts:/var/scripts
      - ./{$iniRel}:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf
    working_dir: /var/www/{$source}
    networks:
      - app-network

  {$supervisorName}:
    profiles: ["{$supervisorName}"]
    image: {$image}
    container_name: {$supervisorContainer}
    volumes:
      - ./server/{$source}:/var/www/{$source}
      - ./scripts:/var/scripts
      - ./{$iniRel}:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf:ro
      - ./{$supervisorDir}:/etc/supervisor/conf.d:ro
      - ./{$logDir}:/var/log/supervisor
    working_dir: /var/www/{$source}
    command: ["/var/scripts/docker/supervisord.sh"]
    depends_on:
      mysql:
        condition: service_started
        required: false
      redis:
        condition: service_started
        required: false
      rabbitmq:
        condition: service_started
        required: false
    networks:
      - app-network

networks:
  app-network:
    driver: bridge
YAML;
        $this->writeFile($this->root() . '/compose/' . $service . '.yml', $content);
    }

    private function ensureIni(string $service): void
    {
        $path = $this->root() . '/' . PhpVersionId::iniRelativePath($service);
        $this->mkdir(dirname($path));
        if (is_file($path)) {
            return;
        }
        $template = $this->root() . '/configs/php8.1/php.ini';
        if (is_file($template)) {
            $this->writeFile($path, (string) file_get_contents($template));

            return;
        }
        $this->writeFile($path, "memory_limit = 1024M\nupload_max_filesize = 500M\npost_max_size = 500M\n");
    }

    private function ensureSourceDir(string $service): void
    {
        $dir = $this->root() . '/server/' . PhpVersionId::sourceDirName($service);
        $this->mkdir($dir);
        $gitkeep = $dir . '/.gitkeep';
        if (!is_file($gitkeep)) {
            $this->writeFile($gitkeep, '');
        }
    }

    private function ensureSupervisorDir(string $service): void
    {
        $dir = $this->root() . '/' . PhpVersionId::supervisorConfDir($service);
        $this->mkdir($dir);
        $keep = $dir . '/.gitkeep';
        if (!is_file($keep)) {
            $this->writeFile($keep, '');
        }
    }

    private function ensureLogsDir(string $service): void
    {
        $this->mkdir($this->root() . '/logs/supervisor-' . PhpVersionId::minorFromService($service) . PhpVersionId::pathSuffix($service));
    }

    private function normalizeNewlines(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function hasComposeInclude(string $service): bool
    {
        $path = $this->root() . '/docker-compose.yml';
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $content = $this->normalizeNewlines((string) file_get_contents($path));

        return (bool) preg_match(
            '/^  - path: compose\/' . preg_quote($service, '/') . '\.yml\s*$/m',
            $content,
        );
    }

    /** Patch docker-compose.yml include when compose/{service}.yml already exists. */
    public function repairComposeInclude(string $service): void
    {
        if (!PhpVersionId::isValidService($service) || PhpVersionId::isDefault($service)) {
            return;
        }
        if (!is_file($this->root() . '/compose/' . $service . '.yml')) {
            return;
        }
        $this->ensureComposeInclude($service);
    }

    private function ensureComposeInclude(string $service): void
    {
        $path = $this->root() . '/docker-compose.yml';
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('php_controller.compose_unreadable', 500);
        }
        // Windows Git often checks out with CRLF; ^...$ line anchors fail on \r.
        // Allow trailing spaces; keep LF on write (matches .gitattributes).
        $content = $this->normalizeNewlines((string) file_get_contents($path));
        if ($this->hasComposeInclude($service)) {
            return;
        }
        $entry = "  - path: compose/{$service}.yml\n    project_directory: .\n";
        if (preg_match('/^  - path: compose\/redis\.yml\s*$/m', $content)) {
            $content = preg_replace(
                '/^  - path: compose\/redis\.yml\s*$/m',
                rtrim($entry) . "\n  - path: compose/redis.yml",
                $content,
                1,
            );
        } elseif (preg_match('/^  - path: compose\/php-[0-9.a-z-]+\.yml\s*$/m', $content)) {
            $content = preg_replace(
                '/(^  - path: compose\/php-[0-9.a-z-]+\.yml\s*$\n    project_directory: \.\s*\n)(?!(?:  - path: compose\/php-))/m',
                '$1' . $entry,
                $content,
                1,
            );
        } else {
            throw new HttpException('php_controller.compose_unreadable', 500);
        }
        if (!is_string($content) || $content === '') {
            throw new HttpException('php_controller.compose_write_failed', 500);
        }
        $this->writeFile($path, $content);
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('php_controller.version_install_failed', 500);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        $this->mkdir($dir);
        if (!AtomicFile::write($path, $content)) {
            throw new HttpException('php_controller.version_install_failed', 500);
        }
    }
}
