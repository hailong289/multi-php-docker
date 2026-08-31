<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\Config;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

/**
 * List/read/write/delete *.yml|*.yaml under compose/.
 * Core service files (mysql|redis|rabbitmq).yml cannot be deleted.
 */
final class InfraCompose
{
    private const MAX_BYTES = 524288;

    /** @var list<string> */
    public const CORE_FILES = ['mysql.yml', 'redis.yml', 'rabbitmq.yml'];

    private readonly string $projectPath;

    public function __construct(?string $projectPath = null)
    {
        $this->projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
    }

    public function composeDirAbsolute(): string
    {
        $path = $this->projectPath . '/compose';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new HttpException('services.compose_dir_create_failed', 500);
        }
        $real = realpath($path);
        if ($real === false) {
            throw new HttpException('services.compose_dir_invalid', 500);
        }

        return $real;
    }

    /** Relative path under the project root for a known infra service. */
    public function relativePath(string $service): string
    {
        $targets = InfraRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('services.invalid_service', 400);
        }

        return 'compose/' . $service . '.yml';
    }

    public function isCoreFile(string $name): bool
    {
        return in_array(strtolower($name), self::CORE_FILES, true);
    }

    /** Core infra + installer-managed PHP/supervisor fragments cannot be deleted. */
    public function isProtectedFile(string $name): bool
    {
        if ($this->isCoreFile($name)) {
            return true;
        }
        $lower = strtolower($name);

        return (bool) preg_match('/^(php-[0-9]|supervisor).*\.(yml|yaml)$/', $lower);
    }

    /** Map mysql.yml → mysql when it is a managed infra service. */
    public function serviceForFile(string $name): ?string
    {
        return $this->actionContextForFile($name)['service'] ?? null;
    }

    /**
     * Map a compose file to the runtime that controls its primary container.
     *
     * @return array{runtime: 'infra'|'php'|'supervisor'|'compose', service: string, pull_recreate: bool, compose_services?: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>, has_build?: bool, included?: bool}|null
     */
    public function actionContextForFile(string $name, ?string $content = null): ?array
    {
        $stem = basename($name);
        if (!preg_match('/^(.+)\.ya?ml$/i', $stem, $m)) {
            return null;
        }
        $service = $m[1];

        if (preg_match('/^(mysql|redis|rabbitmq)$/i', $service)) {
            return $this->enrichWithComposeServices([
                'runtime' => 'infra',
                'service' => strtolower($service),
                'pull_recreate' => true,
            ], $name, $content);
        }
        if (PhpVersionId::isValidService($service)) {
            return $this->enrichWithComposeServices([
                'runtime' => 'php',
                'service' => $service,
                'pull_recreate' => false,
            ], $name, $content);
        }
        if (PhpVersionId::isValidSupervisorService($service)) {
            return $this->enrichWithComposeServices([
                'runtime' => 'supervisor',
                'service' => $service,
                'pull_recreate' => false,
            ], $name, $content);
        }

        if ($this->isProtectedFile($name)) {
            return null;
        }

        $yaml = $this->readYamlContent($name, $content);
        if ($yaml === null) {
            return null;
        }
        $composeServices = ComposeFileParser::services($yaml);
        if ($composeServices === []) {
            return null;
        }

        return $this->enrichWithComposeServices([
            'runtime' => 'compose',
            'service' => $composeServices[0]['name'],
            'pull_recreate' => false,
        ], $name, $content, $composeServices);
    }

    /**
     * @param array{runtime: string, service: string, pull_recreate: bool} $context
     * @param list<array{name: string, profile: ?string, container: ?string, has_build: bool}>|null $parsed
     * @return array{runtime: string, service: string, pull_recreate: bool, compose_services: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>, has_build: bool, included: bool}
     */
    private function enrichWithComposeServices(array $context, string $name, ?string $content, ?array $parsed = null): array
    {
        $yaml = $this->readYamlContent($name, $content);
        $composeServices = $parsed ?? ($yaml !== null ? ComposeFileParser::services($yaml) : []);

        return [
            ...$context,
            'compose_services' => $composeServices,
            'has_build' => ComposeFileRuntime::hasBuild($composeServices),
            'included' => (new ComposeInclude($this->projectPath))->isIncluded($name),
        ];
    }

    private function readYamlContent(string $name, ?string $content): ?string
    {
        if ($content !== null) {
            return $content;
        }
        $path = $this->projectPath . '/compose/' . basename($name);
        if (!is_file($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param array{runtime?: string, service?: string, pull_recreate?: bool, compose_services?: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>, has_build?: bool, included?: bool} $context
     * @return array{runtime: ?string, service: ?string, pull_recreate: bool, compose_services: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>, has_build: bool, included: bool}
     */
    private function fileActionMeta(array $context): array
    {
        return [
            'runtime' => $context['runtime'] ?? null,
            'service' => $context['service'] ?? null,
            'pull_recreate' => (bool) ($context['pull_recreate'] ?? false),
            'compose_services' => $context['compose_services'] ?? [],
            'has_build' => (bool) ($context['has_build'] ?? false),
            'included' => (bool) ($context['included'] ?? false),
        ];
    }

    /**
     * @param array{runtime?: ?string, service?: ?string, compose_services?: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>} $meta
     */
    private function stateForActionMeta(string $name, array $meta): string
    {
        $runtime = $meta['runtime'] ?? null;
        $service = (string) ($meta['service'] ?? '');
        if ($runtime === 'compose' && ($meta['compose_services'] ?? []) !== []) {
            return (string) ((new ComposeFileRuntime(projectPath: $this->projectPath))
                ->statusForServices($name, $meta['compose_services'])['state'] ?? 'not_created');
        }
        if ($runtime === 'infra' && $service !== '') {
            return (string) ((new InfraRuntime())->statuses()[$service]['state'] ?? 'not_created');
        }
        if ($runtime === 'php' && $service !== '') {
            return (string) ((new PhpRuntime())->statuses()[$service]['state'] ?? 'not_created');
        }
        if ($runtime === 'supervisor' && $service !== '') {
            return (string) ((new SupervisorRuntime())->statuses()[$service]['state'] ?? 'not_created');
        }

        return 'not_created';
    }

    public function defaultContent(): string
    {
        return <<<'YAML'
# Custom Compose fragment — rename "example" to match your service (e.g. postgres, kafka).
# After saving, use Create then Start in the manager UI.
services:
  example:
    profiles: ["example"]
    image: postgres:16
    container_name: example_container
    environment:
      POSTGRES_PASSWORD: postgres
      POSTGRES_USER: postgres
      POSTGRES_DB: postgres
    networks:
      - app-network

networks:
  app-network:
    driver: bridge
YAML;
    }

    public function defaultContentFor(string $name): string
    {
        $stem = preg_replace('/\.ya?ml$/i', '', basename($name));
        if ($stem === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $stem)) {
            return $this->defaultContent();
        }

        return <<<YAML
# Custom Compose fragment for {$stem}.
services:
  {$stem}:
    profiles: ["{$stem}"]
    image: alpine:latest
    container_name: {$stem}_container
    networks:
      - app-network

networks:
  app-network:
    driver: bridge
YAML;
    }

    /** PHP compose fragments are managed on the PHP versions page, not Services → Compose YAML. */
    public function isHiddenInServicesList(string $name): bool
    {
        $lower = strtolower(basename($name));

        return (bool) preg_match('/^php-.*\.(yml|yaml)$/', $lower);
    }

    /** @return list<array{name: string, relative_path: string, size: int, updated_at: string, core: bool, protected: bool, service: ?string, runtime: ?string, pull_recreate: bool, compose_services: list<array{name: string, profile: ?string, container: ?string, has_build: bool}>, has_build: bool, included: bool, state: string}> */
    public function list(): array
    {
        $dir = $this->composeDirAbsolute();
        $files = [];
        $paths = array_merge(
            glob($dir . '/*.yml') ?: [],
            glob($dir . '/*.yaml') ?: []
        );
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isSafeName($name) || $this->isHiddenInServicesList($name)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            $action = $this->actionContextForFile($name, $content);
            $meta = $this->fileActionMeta($action ?? []);
            $state = $this->stateForActionMeta($name, $meta);
            $primary = $meta['compose_services'][0] ?? [];
            $image = $primary['image'] ?? null;
            $imagePresent = is_string($image) && $image !== '' && DockerLiveState::available() && DockerExec::imageExists($image);
            $files[] = [
                'name' => $name,
                'relative_path' => 'compose/' . $name,
                'size' => (int) filesize($path),
                'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
                'core' => $this->isCoreFile($name),
                'protected' => $this->isProtectedFile($name),
                'service' => $meta['service'],
                'runtime' => $meta['runtime'],
                'pull_recreate' => $meta['pull_recreate'],
                'compose_services' => $meta['compose_services'],
                'has_build' => $meta['has_build'],
                'included' => $meta['included'],
                'state' => $state,
                'container' => $primary['container'] ?? null,
                'image' => is_string($image) && $image !== '' ? $image : null,
                'image_present' => $imagePresent,
            ];
        }
        usort($files, static function (array $a, array $b): int {
            if ($a['core'] !== $b['core']) {
                return $a['core'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    /**
     * @return array{name: string, relative_path: string, content: string, size: int, updated_at: string, core: bool, protected: bool, service: ?string, runtime: ?string, pull_recreate: bool}
     */
    public function readFile(string $name): array
    {
        $path = $this->resolvePath($name, false);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new HttpException('services.compose_read_failed', 500);
        }
        if (!preg_match('//u', $content)) {
            $content = (string) iconv('UTF-8', 'UTF-8//IGNORE', $content);
        }
        $base = basename($path);

        $action = $this->actionContextForFile($base, $content);
        $meta = $this->fileActionMeta($action ?? []);
        $state = $this->stateForActionMeta($base, $meta);

        return [
            'name' => $base,
            'relative_path' => 'compose/' . $base,
            'content' => $content,
            'size' => strlen($content),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'core' => $this->isCoreFile($base),
            'protected' => $this->isProtectedFile($base),
            'service' => $meta['service'],
            'runtime' => $meta['runtime'],
            'pull_recreate' => $meta['pull_recreate'],
            'compose_services' => $meta['compose_services'],
            'has_build' => $meta['has_build'],
            'included' => $meta['included'],
            'state' => $state,
        ];
    }

    /**
     * @return array{name: string, relative_path: string, size: int, updated_at: string, core: bool, protected: bool, service: ?string, runtime: ?string, pull_recreate: bool, created: bool}
     */
    public function writeFile(string $name, string $content, bool $create): array
    {
        $path = $this->resolvePath($name, $create);
        if ($create && is_file($path)) {
            throw new HttpException('services.compose_exists', 409);
        }
        if (!$create && !is_file($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new HttpException('services.compose_too_large', 422);
        }
        if ($content === '' || !preg_match('//u', $content)) {
            throw new HttpException('services.compose_invalid_utf8', 422);
        }

        $dir = dirname($path);
        if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
            throw new HttpException('services.compose_not_writable', 500);
        }

        $temp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new HttpException('services.compose_write_failed', 500);
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new HttpException('services.compose_write_failed', 500);
        }
        clearstatcache(true, $path);
        $base = basename($path);

        if (!$this->isProtectedFile($base) && !$this->isCoreFile($base)) {
            (new ComposeInclude($this->projectPath))->ensureIncluded($base);
        }

        $content = (string) file_get_contents($path);
        $action = $this->actionContextForFile($base, $content);
        $meta = $this->fileActionMeta($action ?? []);
        $state = $this->stateForActionMeta($base, $meta);

        return [
            'name' => $base,
            'relative_path' => 'compose/' . $base,
            'size' => (int) filesize($path),
            'updated_at' => date(DATE_ATOM, (int) filemtime($path)),
            'core' => $this->isCoreFile($base),
            'protected' => $this->isProtectedFile($base),
            'service' => $meta['service'],
            'runtime' => $meta['runtime'],
            'pull_recreate' => $meta['pull_recreate'],
            'compose_services' => $meta['compose_services'],
            'has_build' => $meta['has_build'],
            'included' => (new ComposeInclude($this->projectPath))->isIncluded($base),
            'state' => $state,
            'created' => $create,
        ];
    }

    public function deleteFile(string $name): void
    {
        $path = $this->resolvePath($name, false);
        $base = basename($path);
        if ($this->isProtectedFile($base)) {
            throw new HttpException('services.compose_core_protected', 403);
        }
        if (!is_file($path)) {
            throw new HttpException('services.compose_missing', 404);
        }
        if (!is_writable($path) || !unlink($path)) {
            throw new HttpException('services.compose_delete_failed', 500);
        }
        if (!$this->isProtectedFile($base)) {
            (new ComposeInclude($this->projectPath))->removeIncluded($base);
        }
    }

    /** @return array{service: string, relative_path: string, content: string, size: int, updated_at: string} */
    public function read(string $service): array
    {
        $name = $service . '.yml';
        $file = $this->readFile($name);

        return [
            'service' => $service,
            'relative_path' => $file['relative_path'],
            'content' => $file['content'],
            'size' => $file['size'],
            'updated_at' => $file['updated_at'],
        ];
    }

    /** @return array{service: string, relative_path: string, size: int, updated_at: string} */
    public function write(string $service, string $content): array
    {
        $name = $service . '.yml';
        $saved = $this->writeFile($name, $content, false);

        return [
            'service' => $service,
            'relative_path' => $saved['relative_path'],
            'size' => $saved['size'],
            'updated_at' => $saved['updated_at'],
        ];
    }

    private function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(yml|yaml)$/', $name);
    }

    private function resolvePath(string $name, bool $forCreate): string
    {
        $name = basename(str_replace(['\\', "\0"], '', $name));
        if (!$this->isSafeName($name)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        $base = $this->composeDirAbsolute();
        $candidate = $base . DIRECTORY_SEPARATOR . $name;

        if (is_file($candidate)) {
            $real = realpath($candidate);
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new HttpException('services.compose_invalid_name', 400);
            }

            return $real;
        }

        if (!$forCreate && !is_file($candidate)) {
            return $candidate;
        }

        if (!str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpException('services.compose_invalid_name', 400);
        }

        return $candidate;
    }
}
