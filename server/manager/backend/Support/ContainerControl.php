<?php

declare(strict_types=1);

namespace Manager\Support;

/**
 * Start, stop, and restart an existing container through the Docker Engine API.
 * Create and install stay on the php-controller queue.
 */
final class ContainerControl
{
    /** @return 'running'|'stopped'|'not_created'|null null when the engine call failed */
    public static function apply(string $container, string $action): ?string
    {
        $container = ltrim($container, '/');
        if ($container === '' || !in_array($action, ['start', 'stop', 'restart'], true)) {
            return null;
        }

        $ok = match ($action) {
            'start' => self::start($container),
            'stop' => DockerExec::stopNamedContainer($container),
            'restart' => DockerExec::restartNamedContainer($container),
        };
        if (!$ok) {
            return null;
        }

        DockerLiveState::resetCache();
        $state = DockerLiveState::stateFor($container);
        if ($action === 'start' || $action === 'restart') {
            return $state === 'running' ? 'running' : null;
        }
        if ($state === 'running') {
            return null;
        }

        return $state === 'not_created' ? 'not_created' : 'stopped';
    }

    public static function writeStatus(string $basePath, string $service, string $state, string $messageKey): bool
    {
        $statusDir = rtrim($basePath, '/') . '/status';
        if (!is_dir($statusDir) && !mkdir($statusDir, 0775, true) && !is_dir($statusDir)) {
            return false;
        }

        $payload = json_encode([
            'service' => $service,
            'state' => $state,
            'message_key' => $messageKey,
            'request_id' => '',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        return AtomicFile::write($statusDir . '/' . $service . '.json', $payload);
    }

    private static function start(string $container): bool
    {
        $code = DockerExec::startNamedContainer($container);

        return $code === 204 || $code === 304;
    }
}
