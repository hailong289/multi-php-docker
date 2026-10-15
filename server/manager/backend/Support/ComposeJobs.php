<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;
use Manager\Models\PhpVersionId;

/**
 * One detached docker:cli helper for compose actions. The helper reads status/exec.json.
 */
final class ComposeJobs
{
    /** @var (callable(): string)|null */
    private mixed $starter;

    /** @var (callable(string): ?array)|null */
    private mixed $inspector;

    /** @var (callable(string): void)|null */
    private mixed $remover;

    /**
     * @param (callable(): string)|null $starter container id, or '' on failure
     * @param (callable(string): ?array)|null $inspector inspect JSON, null when the container is gone
     * @param (callable(string): void)|null $remover
     */
    public function __construct(
        ?callable $starter = null,
        ?callable $inspector = null,
        ?callable $remover = null,
    ) {
        $this->starter = $starter;
        $this->inspector = $inspector;
        $this->remover = $remover;
    }

    /**
     * @param array<string, mixed> $payload service/action or compose_file/queue_key/action
     */
    public function begin(string $basePath, array $payload): string
    {
        $this->reconcile($basePath);
        if ($this->lock($basePath) !== null) {
            throw new HttpException('php_controller.busy', 409);
        }

        $requestId = bin2hex(random_bytes(16));
        $record = $payload;
        $record['request_id'] = $requestId;
        $record['started_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $record['container_id'] = '';
        $path = JobLock::path($basePath, JobLock::COMPOSE);
        if (!JobLock::exclusiveCreate($path, $record)) {
            throw new HttpException('php_controller.busy', 409);
        }

        try {
            $starter = $this->starter ?? static fn (): string => DockerExec::startComposeHelper();
            $containerId = $starter();
            if ($containerId === '') {
                throw new HttpException('php_controller.action_failed', 502);
            }
            if (!JobLock::merge($path, ['container_id' => $containerId])) {
                $this->removeContainer($containerId);
                throw new HttpException('php_controller.action_failed', 502);
            }
        } catch (HttpException $e) {
            JobLock::release($path);
            throw $e;
        } catch (\Throwable) {
            JobLock::release($path);
            throw new HttpException('php_controller.action_failed', 502);
        }

        return $requestId;
    }

    public function blocks(string $basePath, string $key): bool
    {
        $this->reconcile($basePath);
        $lock = $this->lock($basePath);
        if ($lock === null || $key === '') {
            return false;
        }

        return ($lock['service'] ?? null) === $key || ($lock['queue_key'] ?? null) === $key;
    }

    public function reconcile(string $basePath): void
    {
        $path = JobLock::path($basePath, JobLock::COMPOSE);
        $lock = JobLock::read($path);
        if ($lock === null) {
            return;
        }

        $containerId = is_string($lock['container_id'] ?? null) ? $lock['container_id'] : '';
        $age = JobLock::ageSeconds($lock);
        if ($containerId === '') {
            if ($age > JobLock::ID_GRACE_SECONDS) {
                JobLock::markError($basePath, $lock);
                JobLock::release($path);
            }

            return;
        }

        $info = $this->inspect($containerId);
        $running = is_array($info) && !empty($info['State']['Running']);
        if ($info === null || $age > JobLock::MAX_SECONDS) {
            if (!$running) {
                JobLock::markError($basePath, $lock);
                $this->removeContainer($containerId);
                JobLock::release($path);
            }

            return;
        }
        if ($running) {
            return;
        }

        $service = is_string($lock['service'] ?? null) ? $lock['service'] : '';
        $action = is_string($lock['action'] ?? null) ? $lock['action'] : '';
        $this->removeContainer($containerId);
        JobLock::release($path);
        $this->replayExtensions($basePath, $service, $action);
    }

    private function replayExtensions(string $basePath, string $service, string $action): void
    {
        if ($this->starter !== null || !in_array($action, ['recreate', 'install-version'], true)) {
            return;
        }
        if (!PhpVersionId::isValidService($service)) {
            return;
        }
        $container = PhpVersionId::container($service);
        if (DockerLiveState::stateFor($container) !== 'running') {
            return;
        }
        (new ExtensionReplay(new ExtensionJobs()))->kick($basePath, $service, $container);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lock(string $basePath): ?array
    {
        return JobLock::read(JobLock::path($basePath, JobLock::COMPOSE));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(string $containerId): ?array
    {
        $inspector = $this->inspector ?? static fn (string $id): ?array => DockerExec::inspectLocalContainer($id);

        return $inspector($containerId);
    }

    private function removeContainer(string $containerId): void
    {
        if ($containerId === '') {
            return;
        }
        $remover = $this->remover ?? static function (string $id): void {
            DockerExec::removeLocalContainer($id);
        };
        $remover($containerId);
    }
}
