<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\DockerEndpoint;
use Manager\Support\DockerLiveState;
use Manager\Support\JsonFile;

final class ContainerStats
{
    public const MAX_NAMES = 40;

    /**
     * CPU/RAM for the named running containers. CPU is the delta against the previous
     * poll (one-shot stats have no precpu sample), so it is null on the first call.
     *
     * @param list<string> $names
     * @return array<string, array{cpu_percent: float|null, mem_bytes: int, mem_limit: int}>
     */
    public function forNames(array $names): array
    {
        $states = DockerLiveState::statesByName();
        $previous = JsonFile::readObject($this->samplesPath()) ?? [];
        $samples = [];
        $result = [];
        foreach (array_slice(array_unique($names), 0, self::MAX_NAMES) as $name) {
            if (($states[$name] ?? null) !== 'running') {
                continue;
            }
            $raw = DockerEndpoint::request('GET', '/containers/' . rawurlencode($name) . '/stats?stream=false&one-shot=true', timeout: 3.0);
            $stats = $raw === null ? null : json_decode($raw, true);
            if (!is_array($stats)) {
                continue;
            }
            $row = self::compute($stats, is_array($previous[$name] ?? null) ? $previous[$name] : null);
            $samples[$name] = $row['sample'];
            $result[$name] = $row['stats'];
        }
        AtomicFile::write($this->samplesPath(), (string) json_encode($samples + $previous));

        return $result;
    }

    /**
     * @param array<string, mixed> $stats Docker /stats body
     * @param array{cpu?: int|float, system?: int|float}|null $previous
     * @return array{sample: array{cpu: int|float, system: int|float}, stats: array{cpu_percent: float|null, mem_bytes: int, mem_limit: int}}
     */
    public static function compute(array $stats, ?array $previous): array
    {
        $cpu = $stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0;
        $system = $stats['cpu_stats']['system_cpu_usage'] ?? 0;
        $online = (int) ($stats['cpu_stats']['online_cpus'] ?? 0)
            ?: count($stats['cpu_stats']['cpu_usage']['percpu_usage'] ?? []) ?: 1;
        $percent = null;
        if ($previous !== null) {
            $cpuDelta = $cpu - ($previous['cpu'] ?? 0);
            $systemDelta = $system - ($previous['system'] ?? 0);
            if ($cpuDelta >= 0 && $systemDelta > 0) {
                $percent = round($cpuDelta / $systemDelta * $online * 100, 1);
            }
        }
        // Same as `docker stats`: page cache does not count (inactive_file on cgroup v2, cache on v1).
        $memory = $stats['memory_stats'] ?? [];
        $cache = $memory['stats']['inactive_file'] ?? $memory['stats']['cache'] ?? 0;

        return [
            'sample' => ['cpu' => $cpu, 'system' => $system],
            'stats' => [
                'cpu_percent' => $percent,
                'mem_bytes' => max(0, (int) ($memory['usage'] ?? 0) - (int) $cache),
                'mem_limit' => (int) ($memory['limit'] ?? 0),
            ],
        ];
    }

    private function samplesPath(): string
    {
        return Config::runtimePath() . '/container-stats.json';
    }
}
