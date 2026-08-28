<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

final class PhpControllerDaemon
{
    public const CONTAINER = 'php_controller_container';

    /** @var (callable(): ?string)|null */
    private mixed $stateFor = null;

    /** @var (callable(): int)|null */
    private mixed $starter = null;

    /**
     * @param (callable(): ?string)|null $stateFor
     * @param (callable(): int)|null $starter
     */
    public function __construct(
        ?callable $stateFor = null,
        ?callable $starter = null,
    ) {
        $this->stateFor = $stateFor;
        $this->starter = $starter;
    }

    /**
     * @return array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool}
     */
    public function status(): array
    {
        $live = $this->probe();
        if ($live === 'running') {
            return [
                'container' => self::CONTAINER,
                'state' => 'running',
                'start_available' => false,
            ];
        }
        if ($live === 'stopped') {
            return [
                'container' => self::CONTAINER,
                'state' => 'stopped',
                'start_available' => true,
            ];
        }

        return [
            'container' => self::CONTAINER,
            'state' => 'not_created',
            'start_available' => false,
        ];
    }

    public function assertRunning(): void
    {
        if ($this->status()['state'] !== 'running') {
            throw new HttpException('php_controller.daemon_not_running', 409);
        }
    }

    /**
     * @return array{message_key: string, php_controller_daemon: array{container: string, state: 'running'|'stopped'|'not_created', start_available: bool}}
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
                'php_controller_daemon' => [
                    'container' => self::CONTAINER,
                    'state' => 'running',
                    'start_available' => false,
                ],
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
            'php_controller_daemon' => [
                'container' => self::CONTAINER,
                'state' => 'running',
                'start_available' => false,
            ],
        ];
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
}
