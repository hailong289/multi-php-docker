<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

/**
 * Detached Engine Exec inside a PHP container for install-ext / uninstall-ext.
 */
final class ExtensionJobs
{
    /** @var (callable(string, string, string): string)|null */
    private mixed $starter;

    /** @var (callable(string): ?array)|null */
    private mixed $inspector;

    /** @var (callable(string): bool)|null */
    private mixed $reloader;

    private ?ExtensionReplay $replay = null;

    /**
     * @param (callable(string, string, string): string)|null $starter (container, action, extension) => exec id
     * @param (callable(string): ?array)|null $inspector exec inspect, null when missing
     * @param (callable(string): bool)|null $reloader container name => graceful reload succeeded
     */
    public function __construct(?callable $starter = null, ?callable $inspector = null, ?callable $reloader = null)
    {
        $this->starter = $starter;
        $this->inspector = $inspector;
        $this->reloader = $reloader;
    }

    public function setReplay(ExtensionReplay $replay): void
    {
        $this->replay = $replay;
    }

    public function begin(string $basePath, string $service, string $action, string $extension, string $container): string
    {
        $composeLock = JobLock::read(JobLock::path($basePath, JobLock::COMPOSE));
        if (is_array($composeLock) && ($composeLock['service'] ?? null) === $service) {
            $containerId = is_string($composeLock['container_id'] ?? null) ? $composeLock['container_id'] : '';
            $fresh = $containerId !== '' || JobLock::ageSeconds($composeLock) <= JobLock::ID_GRACE_SECONDS;
            if ($fresh) {
                throw new HttpException('php_controller.busy', 409);
            }
        }

        $path = JobLock::path($basePath, JobLock::extensionName($service));
        $this->reconcileOne($basePath, $service);
        if (JobLock::read($path) !== null) {
            throw new HttpException('php_controller.busy', 409);
        }
        if ($action === 'install-ext') {
            ExtensionManifest::forgetFailure($basePath, $service, $extension);
        }

        $requestId = bin2hex(random_bytes(16));
        $record = [
            'request_id' => $requestId,
            'service' => $service,
            'action' => $action,
            'extension' => $extension,
            'container' => $container,
            'exec_id' => '',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if (!JobLock::exclusiveCreate($path, $record)) {
            throw new HttpException('php_controller.busy', 409);
        }

        $this->writeStatus($basePath, $service, 'busy', 'php_controller.processing', $requestId);
        $this->writeLog($basePath, $service, $action, '');
        try {
            $starter = $this->starter ?? static fn (string $c, string $a, string $e): string => DockerExec::startExtensionExec($c, $a, $e);
            $execId = $starter($container, $action, $extension);
            if ($execId === '') {
                throw new HttpException('php_controller.action_failed', 502);
            }
            if (!JobLock::merge($path, ['exec_id' => $execId])) {
                throw new HttpException('php_controller.action_failed', 502);
            }
        } catch (HttpException $e) {
            JobLock::markError($basePath, $record);
            JobLock::release($path);
            throw $e;
        } catch (\Throwable) {
            JobLock::markError($basePath, $record);
            JobLock::release($path);
            throw new HttpException('php_controller.action_failed', 502);
        }

        return $requestId;
    }

    public function blocks(string $basePath, string $service): bool
    {
        $this->reconcileOne($basePath, $service);

        return JobLock::read(JobLock::path($basePath, JobLock::extensionName($service))) !== null;
    }

    public function reconcileService(string $basePath, string $service): void
    {
        $this->reconcileOne($basePath, $service);
    }

    private function reconcileOne(string $basePath, string $service): void
    {
        $path = JobLock::path($basePath, JobLock::extensionName($service));
        $lock = JobLock::read($path);
        if ($lock === null) {
            return;
        }
        $execId = is_string($lock['exec_id'] ?? null) ? $lock['exec_id'] : '';
        $age = JobLock::ageSeconds($lock);
        if ($execId === '') {
            if ($age > JobLock::ID_GRACE_SECONDS) {
                JobLock::markError($basePath, $lock);
                JobLock::release($path);
            }

            return;
        }

        $info = $this->inspect($execId);
        $running = is_array($info) && !empty($info['Running']);
        if ($info === null || ($age > JobLock::MAX_SECONDS && !$running)) {
            JobLock::markError($basePath, $lock);
            JobLock::release($path);

            return;
        }
        if ($running) {
            $container = is_string($lock['container'] ?? null) ? $lock['container'] : '';
            $action = is_string($lock['action'] ?? null) ? $lock['action'] : 'install-ext';
            $this->captureLog($basePath, $service, $container, $action);

            return;
        }

        $exit = (int) ($info['ExitCode'] ?? 1);
        $container = is_string($lock['container'] ?? null) ? $lock['container'] : '';
        $action = is_string($lock['action'] ?? null) ? $lock['action'] : 'install-ext';
        $this->captureLog($basePath, $service, $container, $action);
        $state = $exit === 0 ? 'running' : 'running';
        $key = $exit === 0 ? 'php_controller.action_success' : 'php_controller.action_failed';
        if ($exit !== 0) {
            $live = $container !== '' ? DockerLiveState::stateFor($container) : null;
            $state = $live ?? 'error';
        }
        $requestId = is_string($lock['request_id'] ?? null) ? $lock['request_id'] : '';
        $extension = is_string($lock['extension'] ?? null) ? $lock['extension'] : '';
        $this->writeStatus($basePath, $service, $state, $key, $requestId);
        @unlink(rtrim($basePath, '/') . '/status/' . $service . '.modules.json');
        JobLock::release($path);
        if ($exit === 0) {
            $this->reloadFpm($basePath, $service, $container, $action);
        }
        if ($exit === 0 && $action === 'install-ext') {
            ExtensionManifest::add($basePath, $service, $extension);
            $this->continueReplay($basePath, $service, $container);
        } elseif ($exit === 0 && $action === 'uninstall-ext') {
            ExtensionManifest::remove($basePath, $service, $extension);
        } elseif ($action === 'install-ext') {
            ExtensionManifest::fail($basePath, $service, $extension);
        }
    }

    private function reloadFpm(string $basePath, string $service, string $container, string $action): void
    {
        if ($container === '') {
            return;
        }
        if ($this->reloader === null && $this->starter !== null) {
            return;
        }
        $reloader = $this->reloader ?? static fn (string $name): bool => DockerExec::reloadPhpFpm($name);
        $ok = false;
        try {
            $ok = $reloader($container);
        } catch (\Throwable) {
            $ok = false;
        }
        if ($ok) {
            return;
        }
        $logName = $action === 'uninstall-ext' ? '.last-uninstall.log' : '.last-install.log';
        $path = rtrim($basePath, '/') . '/status/' . $service . $logName;
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $line = "php-fpm reload failed\n";
        if (!str_ends_with($existing, $line)) {
            AtomicFile::write($path, $existing . $line);
        }
    }

    private function continueReplay(string $basePath, string $service, string $container): void
    {
        if ($this->replay !== null) {
            $this->replay->kick($basePath, $service, $container);

            return;
        }
        if ($this->starter !== null) {
            return;
        }
        (new ExtensionReplay($this))->kick($basePath, $service, $container);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(string $execId): ?array
    {
        $inspector = $this->inspector ?? static fn (string $id): ?array => DockerExec::inspectExec($id);

        return $inspector($execId);
    }

    private function captureLog(string $basePath, string $service, string $container, string $action): void
    {
        if ($container === '' || $this->starter !== null) {
            return;
        }
        $id = DockerExec::containerIdByName($container);
        if ($id === null) {
            return;
        }
        try {
            $result = DockerExec::run($id, ['/bin/sh', '-c', 'cat /tmp/m-ext.log 2>/dev/null || true'], '', 5);
        } catch (\Throwable) {
            return;
        }
        $text = $result['stdout'] . $result['stderr'];
        if ($text === '') {
            return;
        }
        $this->writeLog($basePath, $service, $action, $text);
    }

    private function writeLog(string $basePath, string $service, string $action, string $text): void
    {
        $logName = $action === 'uninstall-ext' ? '.last-uninstall.log' : '.last-install.log';
        AtomicFile::write(rtrim($basePath, '/') . '/status/' . $service . $logName, $text);
    }

    private function writeStatus(string $basePath, string $service, string $state, string $messageKey, string $requestId): void
    {
        $payload = json_encode([
            'service' => $service,
            'state' => $state,
            'message_key' => $messageKey,
            'request_id' => $requestId,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        AtomicFile::write(rtrim($basePath, '/') . '/status/' . $service . '.json', $payload);
    }
}
