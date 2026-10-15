<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class SiteHealth
{
    /** @var array<string, array{container: string, ports: list<int>}> */
    public const SERVICES = [
        'nginx' => ['container' => 'nginx_container', 'ports' => [80, 443]],
        'mysql' => ['container' => 'mysql_container', 'ports' => [3306]],
        'postgres' => ['container' => 'postgres_container', 'ports' => [5432]],
        'redis' => ['container' => 'redis_container', 'ports' => [6379]],
        'rabbitmq' => ['container' => 'rabbitmq_container', 'ports' => [5672, 15672]],
        'kafka' => ['container' => 'kafka_container', 'ports' => [9092]],
        'mailpit' => ['container' => 'mailpit_container', 'ports' => [1025, 8025]],
        'minio' => ['container' => 'minio_container', 'ports' => [9000, 9001]],
    ];

    /** @return array{status: int|null, ms: int|null, state: string} */
    public function classifyHttp(int $status, int $milliseconds, ?string $error): array
    {
        if ($error !== null && $error !== '') {
            return ['status' => null, 'ms' => null, 'state' => 'error'];
        }
        if ($status >= 200 && $status < 400) {
            return ['status' => $status, 'ms' => $milliseconds, 'state' => 'up'];
        }

        return ['status' => $status, 'ms' => $milliseconds, 'state' => 'down'];
    }

    /**
     * @param list<array{name: string, state: string, ports: list<int>}> $containers
     * @param list<int> $wantedPorts
     * @return list<array{port: int, holder: string}>
     */
    public function conflicts(array $containers, string $targetName, array $wantedPorts): array
    {
        $ownRunning = false;
        foreach ($containers as $container) {
            if ($container['name'] === $targetName && $container['state'] === 'running') {
                $ownRunning = true;
            }
        }
        if ($ownRunning) {
            return [];
        }
        $found = [];
        foreach ($wantedPorts as $port) {
            foreach ($containers as $container) {
                if ($container['name'] === $targetName || $container['state'] !== 'running') {
                    continue;
                }
                if (in_array($port, $container['ports'], true)) {
                    $found[] = ['port' => $port, 'holder' => $container['name']];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array{name: string, state: string, ports: list<int>}>
     */
    public function containersFromEngine(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $names = $item['Names'] ?? [];
            $name = is_array($names) ? ltrim((string) ($names[0] ?? ''), '/') : '';
            $ports = [];
            foreach (is_array($item['Ports'] ?? null) ? $item['Ports'] : [] as $port) {
                if (is_array($port) && isset($port['PublicPort'])) {
                    $ports[] = (int) $port['PublicPort'];
                }
            }
            $out[] = [
                'name' => $name,
                'state' => (string) ($item['State'] ?? ''),
                'ports' => array_values(array_unique($ports)),
            ];
        }

        return $out;
    }

    /** @return array{status: int|null, ms: int|null, state: string} */
    public function probe(string $domain): array
    {
        if ($domain === '' || preg_match('/[\s\/]/', $domain)) {
            return $this->classifyHttp(0, 0, 'unreachable');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Host: {$domain}\r\n",
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);
        $start = hrtime(true);
        $body = @file_get_contents('http://nginx_container/', false, $context);
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);
        if ($body === false) {
            return $this->classifyHttp(0, $ms, 'unreachable');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return $this->classifyHttp($status, $ms, null);
    }

    /** @param list<int> $wantedPorts */
    public function servicePorts(string $service): array
    {
        $row = self::SERVICES[$service] ?? null;
        if ($row === null) {
            throw new HttpException('health.unknown_service', 400);
        }

        return $row['ports'];
    }

    public function serviceContainer(string $service): string
    {
        $row = self::SERVICES[$service] ?? null;
        if ($row === null) {
            throw new HttpException('health.unknown_service', 400);
        }

        return $row['container'];
    }
}
