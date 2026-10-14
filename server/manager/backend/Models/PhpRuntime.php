<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\ActionLogReader;
use Manager\Support\AtomicFile;
use Manager\Support\ComposeJobs;
use Manager\Support\Config;
use Manager\Support\ContainerControl;
use Manager\Support\DockerExec;
use Manager\Support\DockerImageIndex;
use Manager\Support\DockerLiveState;
use Manager\Support\ExtensionJobs;
use Manager\Support\ExtensionReplay;
use Manager\Support\JsonFile;

final class PhpRuntime
{
    private readonly string $basePath;

    private readonly ?string $projectPath;

    private readonly ComposeJobs $jobs;

    private readonly ExtensionJobs $extensions;

    public function __construct(
        ?string $basePath = null,
        ?ComposeJobs $jobs = null,
        ?ExtensionJobs $extensions = null,
        ?string $projectPath = null,
    ) {
        $this->basePath = rtrim($basePath ?? Config::phpControllerPath(), '/');
        $this->projectPath = $projectPath;
        $this->jobs = $jobs ?? new ComposeJobs();
        $this->extensions = $extensions ?? new ExtensionJobs();
    }

    public function controllerBase(): string
    {
        return $this->basePath;
    }

    public function projectRoot(): string
    {
        return rtrim($this->projectPath ?? Config::projectPath(), '/');
    }

    public static function targets(?string $projectPath = null): array
    {
        $projectPath = rtrim($projectPath ?? Config::projectPath(), '/');
        $targets = [];
        foreach (PhpVersionCatalog::versions($projectPath) as $service => $config) {
            $profile = $config['profile'];
            $create = $profile === null
                ? ('docker compose create ' . $service)
                : ('docker compose --profile ' . $profile . ' create ' . $service);
            $image = self::imageFromCompose($projectPath, $service);
            $imagePresent = $image !== null && DockerImageIndex::contains($image);
            $targets[$service] = [
                'label' => $config['label'],
                'container' => $config['container'],
                'profile' => $profile,
                'create_command' => $create,
                'supervisor_service' => PhpVersionId::supervisorService($service),
                'image' => $image,
                'image_present' => $imagePresent,
                'removable' => PhpVersionInstaller::isRemovable($service, $projectPath),
            ];
        }

        return $targets;
    }

    private static function imageFromCompose(string $projectPath, string $service): ?string
    {
        $path = $projectPath . '/compose/' . $service . '.yml';
        if (!is_readable($path)) {
            return null;
        }
        $parsed = ComposeFileParser::services((string) file_get_contents($path));
        foreach ($parsed as $entry) {
            if (($entry['name'] ?? '') === $service) {
                $image = $entry['image'] ?? null;

                return is_string($image) && $image !== '' ? $image : null;
            }
        }

        return null;
    }

    public function statuses(): array
    {
        $allowedStates = ['running', 'stopped', 'not_created', 'busy', 'error'];
        $statuses = [];

        foreach (PhpVersionCatalog::versions($this->projectPath) as $service => $config) {
            $status = [
                'service' => $service,
                'state' => 'not_created',
                'message_key' => 'php_controller.status_unavailable',
                'request_id' => '',
                'updated_at' => '',
            ];
            $statusFile = $this->basePath . '/status/' . $service . '.json';
            $decoded = JsonFile::readObject($statusFile);
            if (
                is_array($decoded)
                && ($decoded['service'] ?? null) === $service
                && in_array(($decoded['state'] ?? null), $allowedStates, true)
            ) {
                $status = array_merge($status, array_intersect_key($decoded, $status));
            }
            if ($this->hasBlockingRequests($service)) {
                $status['state'] = 'busy';
                $status['message_key'] = 'php_controller.processing';
            } else {
                $status = DockerLiveState::apply(
                    $status,
                    (string) ($config['container'] ?? ''),
                    'php_controller.status_refreshed'
                );
            }
            $statuses[$service] = $status;
        }

        return $statuses;
    }

    /** True while a compose helper or extension exec for this service is still running. */
    public function hasBlockingRequests(string $service): bool
    {
        return $this->jobs->blocks($this->basePath, $service)
            || $this->extensions->blocks($this->basePath, $service);
    }

    public function request(string $service, string $action, ?string $extension = null): string
    {
        $versions = PhpVersionCatalog::versions($this->projectPath);
        if (!isset($versions[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
        $allowed = ['start', 'stop', 'restart', 'create', 'recreate', 'install-version', 'modules', 'available-ext', 'install-ext', 'uninstall-ext'];
        if (!in_array($action, $allowed, true)) {
            throw new HttpException('php_controller.invalid_action', 400);
        }
        if (($action === 'create' || $action === 'recreate' || $action === 'install-version') && ($versions[$service]['profile'] ?? null) === null) {
            throw new HttpException('php_controller.invalid_action', 400);
        }
        if ($action === 'install-ext' || $action === 'uninstall-ext') {
            $extension = $extension !== null ? strtolower($extension) : null;
            if ($extension === null || !PhpExtensionCatalog::isValidName($extension)) {
                throw new HttpException('php_controller.invalid_extension', 400);
            }
            if (PhpExtensionCatalog::unsupportedOn($service, $extension)) {
                throw new HttpException('php_controller.unsupported_extension', 400);
            }
        } elseif ($extension !== null) {
            throw new HttpException('php_controller.invalid_action', 400);
        }

        if (in_array($action, ['start', 'stop', 'restart'], true)) {
            $state = ContainerControl::apply((string) $versions[$service]['container'], $action);
            if ($state === null) {
                throw new HttpException('php_controller.action_failed', 502);
            }
            $this->persistStatus($service, $state, 'php_controller.action_success', '');
            if ($action === 'start' && $state === 'running') {
                (new ExtensionReplay($this->extensions))->kick(
                    $this->basePath,
                    $service,
                    (string) $versions[$service]['container'],
                );
            }

            return bin2hex(random_bytes(16));
        }

        if ($action === 'modules' || $action === 'available-ext') {
            $this->probe($service, (string) $versions[$service]['container'], $action);

            return bin2hex(random_bytes(16));
        }

        if ($action === 'install-ext' || $action === 'uninstall-ext') {
            return $this->extensions->begin(
                $this->basePath,
                $service,
                $action,
                (string) $extension,
                (string) $versions[$service]['container'],
            );
        }

        return $this->jobs->begin($this->basePath, [
            'service' => $service,
            'action' => $action,
        ]);
    }

    private function probe(string $service, string $container, string $action): void
    {
        $id = DockerExec::containerIdByName($container);
        if ($id === null) {
            throw new HttpException('php_controller.run_unavailable', 503);
        }
        if ($action === 'modules') {
            $result = DockerExec::run($id, ['php', '-m'], '', 12);
            $modules = [];
            foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '[')) {
                    continue;
                }
                $modules[] = $line;
            }
            $ok = $result['exit_code'] === 0 && $modules !== [];
            $this->writeSidecar($service, 'modules.json', [
                'service' => $service,
                'modules' => $modules,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'request_id' => '',
                'ok' => $ok,
            ]);

            return;
        }

        $result = DockerExec::run($id, ['/bin/sh', '-c', 'docker-php-ext-install 2>&1'], '', 15);
        $names = [];
        $grab = false;
        foreach (preg_split('/\r?\n/', $result['stdout'] . "\n" . $result['stderr']) ?: [] as $line) {
            if (str_starts_with($line, 'Possible values for ext-name:')) {
                $grab = true;
                continue;
            }
            if (!$grab || trim($line) === '') {
                continue;
            }
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $name) {
                $name = strtolower($name);
                if (PhpExtensionCatalog::isValidName($name)) {
                    $names[] = $name;
                }
            }
            $grab = false;
        }
        $this->writeSidecar($service, 'available-ext.json', [
            'service' => $service,
            'extensions' => array_values(array_unique($names)),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => '',
            'ok' => $names !== [],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeSidecar(string $service, string $suffix, array $payload): void
    {
        $dir = $this->basePath . '/status';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('php_controller.request_failed', 500);
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!AtomicFile::write($dir . '/' . $service . '.' . $suffix, $json)) {
            throw new HttpException('php_controller.request_failed', 500);
        }
    }

    public function deleteContainer(string $service): void
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }

        $container = (string) $targets[$service]['container'];
        DockerLiveState::resetCache();
        if (!DockerExec::removeNamedContainer($container)) {
            throw new HttpException('services.delete_failed', 500);
        }
        DockerLiveState::resetCache();
        $this->persistStatus($service, 'not_created', 'services.deleted', '');
    }

    public function deleteImage(string $service): void
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }

        $container = (string) $targets[$service]['container'];
        DockerLiveState::resetCache();
        if (DockerExec::containerIdByName($container) !== null) {
            throw new HttpException('services.delete_image_container_exists', 400);
        }

        $image = $targets[$service]['image'] ?? null;
        if (!is_string($image) || $image === '') {
            throw new HttpException('services.no_image', 400);
        }
        if (!DockerExec::removeImage($image)) {
            throw new HttpException('services.delete_image_failed', 500);
        }
    }

    private function persistStatus(string $service, string $state, string $messageKey, string $requestId): void
    {
        $statusDir = $this->basePath . '/status';
        if (!is_dir($statusDir) && !mkdir($statusDir, 0775, true) && !is_dir($statusDir)) {
            throw new HttpException('php_controller.request_failed', 500);
        }

        $payload = json_encode([
            'service' => $service,
            'state' => $state,
            'message_key' => $messageKey,
            'request_id' => $requestId,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (!AtomicFile::write($statusDir . '/' . $service . '.json', $payload)) {
            throw new HttpException('php_controller.request_failed', 500);
        }
    }

    public function readModules(string $service): array
    {
        $defaults = [
            'service' => $service,
            'modules' => [],
            'updated_at' => '',
            'request_id' => '',
            'ok' => false,
        ];
        $file = $this->basePath . '/status/' . $service . '.modules.json';
        if (!is_file($file) || !is_readable($file)) {
            return $defaults;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || ($decoded['service'] ?? null) !== $service) {
            return $defaults;
        }
        $modules = $decoded['modules'] ?? [];
        if (!is_array($modules)) {
            $modules = [];
        }
        $modules = array_values(array_filter($modules, 'is_string'));

        return [
            'service' => $service,
            'modules' => $modules,
            'updated_at' => (string) ($decoded['updated_at'] ?? ''),
            'request_id' => (string) ($decoded['request_id'] ?? ''),
            'ok' => (bool) ($decoded['ok'] ?? false),
        ];
    }

    public function modulesStale(string $service, int $maxAgeSeconds = 30): bool
    {
        $info = $this->readModules($service);
        if ($info['updated_at'] === '') {
            return true;
        }
        $ts = strtotime($info['updated_at']);
        if ($ts === false) {
            return true;
        }

        return (time() - $ts) > $maxAgeSeconds;
    }

    public function readAvailable(string $service): array
    {
        $defaults = [
            'service' => $service,
            'extensions' => PhpExtensionCatalog::NAMES,
            'updated_at' => '',
            'request_id' => '',
            'ok' => false,
        ];
        $file = $this->basePath . '/status/' . $service . '.available-ext.json';
        $names = PhpExtensionCatalog::NAMES;
        if (is_file($file) && is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded) && ($decoded['service'] ?? null) === $service) {
                $listed = $decoded['extensions'] ?? [];
                if (is_array($listed)) {
                    foreach ($listed as $item) {
                        if (!is_string($item)) {
                            continue;
                        }
                        $item = strtolower(trim($item));
                        if (PhpExtensionCatalog::isValidName($item) && !in_array($item, $names, true)) {
                            $names[] = $item;
                        }
                    }
                }
                sort($names);

                return [
                    'service' => $service,
                    'extensions' => array_values($names),
                    'updated_at' => (string) ($decoded['updated_at'] ?? ''),
                    'request_id' => (string) ($decoded['request_id'] ?? ''),
                    'ok' => (bool) ($decoded['ok'] ?? false),
                ];
            }
        }
        $defaults['extensions'] = array_values($names);

        return $defaults;
    }

    public function availableStale(string $service, int $maxAgeSeconds = 300): bool
    {
        $info = $this->readAvailable($service);
        if ($info['updated_at'] === '') {
            return true;
        }
        $ts = strtotime($info['updated_at']);
        if ($ts === false) {
            return true;
        }

        return (time() - $ts) > $maxAgeSeconds;
    }

    /**
     * @return array{
     *     service: string,
     *     state: string,
     *     message_key: string,
     *     request_id: string,
     *     available: bool,
     *     content: string,
     *     create_log: string,
     *     start_log: string,
     *     updated_at: string
     * }
     */
    public function actionLogs(string $service): array
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }

        return array_merge(
            ['service' => $service],
            ActionLogReader::bundle(
                $this->basePath . '/status',
                $service,
                static fn (array $decoded): bool => ($decoded['service'] ?? null) === $service,
            ),
        );
    }

    /**
     * @return array{service: string, container: string, available: bool, content: string, truncated: bool, updated_at: string}
     */
    public function logs(string $service, int $tail = 300): array
    {
        $targets = self::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
        $container = (string) $targets[$service]['container'];
        $tail = max(1, min(2000, $tail));
        $content = DockerExec::containerLogs($container, $tail);
        $available = $content !== null;
        $text = $available ? (string) $content : '';

        return [
            'service' => $service,
            'container' => $container,
            'available' => $available,
            'content' => $text,
            'truncated' => $available && strlen($text) >= 262144,
            'updated_at' => date(DATE_ATOM),
        ];
    }
}
