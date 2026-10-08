<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

final class PhpControllerDaemon
{
    public const CONTAINER = 'php_controller_container';
    public const COMPOSE_SERVICE = 'php-controller';

    /** @var (callable(): ?string)|null */
    private mixed $stateFor = null;

    /** @var (callable(): int)|null */
    private mixed $starter = null;

    /** @var (callable(): bool)|null */
    private mixed $stopper = null;

    /** @var (callable(): bool)|null */
    private mixed $restarter = null;

    /** @var (callable(): bool)|null */
    private mixed $remover = null;

    /** @var (callable(): bool)|null */
    private mixed $creator = null;

    /** @var (callable(): ?array)|null */
    private mixed $inspector = null;

    /** @var (callable(int): ?string)|null */
    private mixed $logsReader = null;

    /**
     * @param (callable(): ?string)|null $stateFor
     * @param (callable(): int)|null $starter
     * @param (callable(): bool)|null $stopper
     * @param (callable(): bool)|null $restarter
     * @param (callable(): bool)|null $remover
     * @param (callable(): ?array)|null $inspector
     * @param (callable(int): ?string)|null $logsReader
     * @param (callable(): bool)|null $creator
     */
    public function __construct(
        ?callable $stateFor = null,
        ?callable $starter = null,
        ?callable $stopper = null,
        ?callable $restarter = null,
        ?callable $remover = null,
        ?callable $inspector = null,
        ?callable $logsReader = null,
        ?callable $creator = null,
    ) {
        $this->stateFor = $stateFor;
        $this->starter = $starter;
        $this->stopper = $stopper;
        $this->restarter = $restarter;
        $this->remover = $remover;
        $this->inspector = $inspector;
        $this->logsReader = $logsReader;
        $this->creator = $creator;
    }

    /**
     * @return array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}
     */
    public function status(): array
    {
        $live = $this->probe();
        if ($live === 'running') {
            return [
                'container' => self::CONTAINER,
                'state' => 'running',
                'start_available' => false,
                'create_available' => false,
            ];
        }
        if ($live === 'stopped') {
            return [
                'container' => self::CONTAINER,
                'state' => 'stopped',
                'start_available' => true,
                'create_available' => false,
            ];
        }

        return [
            'container' => self::CONTAINER,
            'state' => 'not_created',
            'start_available' => false,
            'create_available' => true,
        ];
    }

    /**
     * @return array{
     *   container: string,
     *   state: 'running'|'stopped'|'not_created',
     *   start_available: bool,
     *   create_available: bool,
     *   image: ?string,
     *   created: ?string,
     *   started_at: ?string
     * }
     */
    public function details(): array
    {
        $status = $this->status();
        $out = [
            'container' => $status['container'],
            'state' => $status['state'],
            'start_available' => $status['start_available'],
            'create_available' => $status['create_available'],
            'image' => null,
            'created' => null,
            'started_at' => null,
        ];

        if ($status['state'] === 'not_created') {
            return $out;
        }

        $inspect = $this->inspect();
        if ($inspect === null) {
            return $out;
        }

        $config = is_array($inspect['Config'] ?? null) ? $inspect['Config'] : [];
        $state = is_array($inspect['State'] ?? null) ? $inspect['State'] : [];
        $image = $config['Image'] ?? ($inspect['Image'] ?? null);
        $out['image'] = is_string($image) && $image !== '' ? $image : null;
        $created = $inspect['Created'] ?? null;
        $out['created'] = is_string($created) && $created !== '' ? $created : null;
        $started = $state['StartedAt'] ?? null;
        $out['started_at'] = is_string($started) && $started !== '' && $started !== '0001-01-01T00:00:00Z'
            ? $started
            : null;

        return $out;
    }

    public function assertRunning(): void
    {
        if ($this->status()['state'] !== 'running') {
            throw new HttpException('php_controller.daemon_not_running', 409);
        }
    }

    /**
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}}
     */
    public function start(): array
    {
        $live = $this->probe();
        if ($live === null) {
            throw new HttpException('php_controller.daemon_docker_unavailable', 503);
        }
        if ($live === 'running') {
            return [
                'message_key' => 'php_controller.daemon_already_running',
                'php_controller_daemon' => $this->statusPayload('running'),
            ];
        }
        if ($live === 'not_created') {
            throw new HttpException('php_controller.daemon_not_created', 409);
        }

        $code = $this->engineStart();
        if ($code === 0) {
            throw new HttpException('php_controller.daemon_docker_unavailable', 503);
        }
        if ($code === 404) {
            throw new HttpException('php_controller.daemon_not_created', 409);
        }
        if ($code !== 204 && $code !== 304) {
            throw new HttpException('php_controller.daemon_start_failed', 502);
        }

        return [
            'message_key' => 'php_controller.daemon_started',
            'php_controller_daemon' => $this->statusPayload('running'),
        ];
    }

    /**
     * Install via `docker compose up -d php-controller` (ephemeral docker:cli helper).
     *
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}}
     */
    public function create(): array
    {
        $live = $this->probe();
        if ($live === null) {
            throw new HttpException('php_controller.daemon_docker_unavailable', 503);
        }
        if ($live === 'running') {
            return [
                'message_key' => 'php_controller.daemon_already_running',
                'php_controller_daemon' => $this->statusPayload('running'),
            ];
        }
        if ($live === 'stopped') {
            return [
                'message_key' => 'php_controller.daemon_already_installed',
                'php_controller_daemon' => $this->statusPayload('stopped'),
            ];
        }

        if (!$this->engineCreate()) {
            throw new HttpException('php_controller.daemon_create_failed', 502);
        }

        $after = $this->probe();
        if ($after !== 'running' && $after !== 'stopped') {
            throw new HttpException('php_controller.daemon_create_failed', 502);
        }

        return [
            'message_key' => 'php_controller.daemon_created',
            'php_controller_daemon' => $this->statusPayload($after === 'running' ? 'running' : 'stopped'),
        ];
    }

    /**
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}}
     */
    public function stop(): array
    {
        $live = $this->requireExisting();
        if ($live === 'stopped') {
            return [
                'message_key' => 'php_controller.daemon_already_stopped',
                'php_controller_daemon' => $this->statusPayload('stopped'),
            ];
        }

        if (!$this->engineStop()) {
            throw new HttpException('php_controller.daemon_stop_failed', 502);
        }

        return [
            'message_key' => 'php_controller.daemon_stopped',
            'php_controller_daemon' => $this->statusPayload('stopped'),
        ];
    }

    /**
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}}
     */
    public function restart(): array
    {
        $this->requireExisting();

        if (!$this->engineRestart()) {
            throw new HttpException('php_controller.daemon_restart_failed', 502);
        }

        return [
            'message_key' => 'php_controller.daemon_restarted',
            'php_controller_daemon' => $this->statusPayload('running'),
        ];
    }

    /**
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}}
     */
    public function remove(): array
    {
        $this->requireExisting();

        if (!$this->engineRemove()) {
            throw new HttpException('php_controller.daemon_remove_failed', 502);
        }

        return [
            'message_key' => 'php_controller.daemon_removed',
            'php_controller_daemon' => $this->statusPayload('not_created'),
        ];
    }

    /**
     * @return array{container: string, content: string, truncated: bool}
     */
    public function logs(int $tail = 300): array
    {
        $this->requireExisting();
        $tail = max(1, min(2000, $tail));
        $content = $this->readLogs($tail);
        if ($content === null) {
            throw new HttpException('php_controller.daemon_logs_unavailable', 502);
        }

        return [
            'container' => self::CONTAINER,
            'content' => $content,
            'truncated' => false,
        ];
    }

    /**
     * @param 'running'|'stopped'|'not_created' $state
     * @return array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool, create_available: bool}
     */
    private function statusPayload(string $state): array
    {
        return [
            'container' => self::CONTAINER,
            'state' => $state,
            'start_available' => $state === 'stopped',
            'create_available' => $state === 'not_created',
        ];
    }

    /**
     * @return 'running'|'stopped'
     */
    private function requireExisting(): string
    {
        $live = $this->probe();
        if ($live === null) {
            throw new HttpException('php_controller.daemon_docker_unavailable', 503);
        }
        if ($live === 'not_created') {
            throw new HttpException('php_controller.daemon_not_created', 409);
        }

        return $live;
    }

    private function probe(): ?string
    {
        if (is_callable($this->stateFor)) {
            $live = ($this->stateFor)();
            if ($live === null || $live === 'running' || $live === 'stopped' || $live === 'not_created') {
                return $live;
            }

            return 'not_created';
        }

        return DockerLiveState::stateFor(self::CONTAINER);
    }

    private function engineStart(): int
    {
        if (is_callable($this->starter)) {
            return (int) ($this->starter)();
        }

        return DockerExec::startNamedContainer(self::CONTAINER);
    }

    private function engineStop(): bool
    {
        if (is_callable($this->stopper)) {
            return (bool) ($this->stopper)();
        }

        return DockerExec::stopNamedContainer(self::CONTAINER);
    }

    private function engineRestart(): bool
    {
        if (is_callable($this->restarter)) {
            return (bool) ($this->restarter)();
        }

        return DockerExec::restartNamedContainer(self::CONTAINER);
    }

    private function engineRemove(): bool
    {
        if (is_callable($this->remover)) {
            return (bool) ($this->remover)();
        }

        return DockerExec::removeNamedContainer(self::CONTAINER);
    }

    private function engineCreate(): bool
    {
        if (is_callable($this->creator)) {
            return (bool) ($this->creator)();
        }

        return DockerExec::composeUpService(self::COMPOSE_SERVICE);
    }

    /** @return array<string, mixed>|null */
    private function inspect(): ?array
    {
        if (is_callable($this->inspector)) {
            $result = ($this->inspector)();

            return is_array($result) ? $result : null;
        }

        return DockerExec::inspectNamedContainer(self::CONTAINER);
    }

    private function readLogs(int $tail): ?string
    {
        if (is_callable($this->logsReader)) {
            $result = ($this->logsReader)($tail);

            return is_string($result) ? $result : null;
        }

        return DockerExec::containerLogs(self::CONTAINER, $tail);
    }
}
