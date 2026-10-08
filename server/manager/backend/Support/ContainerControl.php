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


    public static function lastDetail(): string
    {
        return DockerEndpoint::lastStatusMessage();
    }

    private static function start(string $container): bool
    {
        if (self::startOnce($container)) {
            return true;
        }
        $detail = DockerEndpoint::lastStatusMessage();
        if (!self::isStaleNetwork($detail) || !self::rebindNetworks($container)) {
            return false;
        }

        return self::startOnce($container);
    }

    private static function startOnce(string $container): bool
    {
        $code = DockerExec::startNamedContainer($container);

        return $code === 204 || $code === 304;
    }

    private static function isStaleNetwork(string $detail): bool
    {
        $detail = strtolower($detail);

        return str_contains($detail, 'network') && str_contains($detail, 'not found');
    }

    /**
     * The container still names a compose network whose id was recreated.
     * Disconnect and connect again so start uses the current network.
     */
    private static function rebindNetworks(string $container): bool
    {
        $raw = DockerEndpoint::request(
            'GET',
            '/containers/' . rawurlencode($container) . '/json',
            null,
            null,
            [200],
        );
        if ($raw === null) {
            return false;
        }
        try {
            $info = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $networks = $info['NetworkSettings']['Networks'] ?? null;
        if (!is_array($networks) || $networks === []) {
            return false;
        }

        $rebound = false;
        foreach (array_keys($networks) as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $payload = json_encode(['Container' => $container, 'Force' => true], JSON_THROW_ON_ERROR);
            DockerEndpoint::request(
                'POST',
                '/networks/' . rawurlencode($name) . '/disconnect',
                $payload,
                'application/json',
                [200, 204],
            );
            $connect = json_encode(['Container' => $container], JSON_THROW_ON_ERROR);
            $ok = DockerEndpoint::request(
                'POST',
                '/networks/' . rawurlencode($name) . '/connect',
                $connect,
                'application/json',
                [200, 201, 204],
            );
            $rebound = $rebound || $ok !== null;
        }

        return $rebound;
    }
}
