<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

/**
 * Host ports published by managed services, stored in the project .env and
 * interpolated by the compose files as ${VAR:-default}.
 */
final class HostPorts
{
    /** service => [key => [env var, default port]] */
    public const MAP = [
        'nginx' => ['http' => ['NGINX_HTTP_PORT', 80], 'https' => ['NGINX_HTTPS_PORT', 443]],
        'mysql' => ['main' => ['MYSQL_HOST_PORT', 3306]],
        'postgres' => ['main' => ['POSTGRES_HOST_PORT', 5432]],
        'redis' => ['main' => ['REDIS_HOST_PORT', 6379]],
        'rabbitmq' => ['amqp' => ['RABBITMQ_HOST_PORT', 5672], 'mgmt' => ['RABBITMQ_MGMT_HOST_PORT', 15672]],
        'kafka' => ['main' => ['KAFKA_HOST_PORT', 9092]],
        'mailpit' => ['smtp' => ['MAILPIT_SMTP_HOST_PORT', 1025], 'web' => ['MAILPIT_WEB_HOST_PORT', 8025]],
        'minio' => ['api' => ['MINIO_API_HOST_PORT', 9000], 'console' => ['MINIO_CONSOLE_HOST_PORT', 9001]],
    ];

    /** Published by manager_container (docker-compose.yml). */
    private const RESERVED = [8080 => 'manager'];

    /** @return array<string, int> key => port */
    public static function read(string $service, ?string $envPath = null): array
    {
        $ports = [];
        foreach (self::MAP[$service] ?? [] as $key => [$var, $default]) {
            $value = ComposeEnv::get($var, $envPath);
            $ports[$key] = $value !== null && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535
                ? (int) $value
                : $default;
        }

        return $ports;
    }

    /**
     * True when the compose file still interpolates every port variable (a hand-edited file may not).
     */
    public static function configurable(string $service, string $composeFile): bool
    {
        $content = (string) @file_get_contents($composeFile);
        foreach (self::MAP[$service] ?? [] as [$var]) {
            if (!str_contains($content, '${' . $var)) {
                return false;
            }
        }

        return isset(self::MAP[$service]);
    }

    /**
     * Validates every key of $service and writes it to .env.
     *
     * @param array<string, mixed> $ports key => port
     */
    public static function save(string $service, array $ports, ?string $envPath = null): void
    {
        $map = self::MAP[$service] ?? null;
        if ($map === null) {
            throw new HttpException('ports.invalid', 400);
        }
        $taken = self::RESERVED;
        foreach (array_keys(self::MAP) as $other) {
            if ($other === $service) {
                continue;
            }
            foreach (self::read($other, $envPath) as $port) {
                $taken[$port] = $other;
            }
        }
        $values = [];
        foreach ($map as $key => [$var]) {
            $port = $ports[$key] ?? null;
            if (!is_int($port) || $port < 1 || $port > 65535 || in_array($port, $values, true)) {
                throw new HttpException('ports.invalid', 400);
            }
            if (isset($taken[$port])) {
                throw new HttpException('ports.conflict', 400, [], ['port' => $port, 'service' => $taken[$port]]);
            }
            $values[$var] = $port;
        }
        if (!ComposeEnv::set(array_map('strval', $values), $envPath)) {
            throw new HttpException('ports.write_failed', 500);
        }
    }
}
