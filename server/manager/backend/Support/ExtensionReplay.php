<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Http\HttpException;

/**
 * Install the next saved extension that the running container does not have yet.
 */
final class ExtensionReplay
{
    /** @var (callable(string): ?list<string>)|null */
    private mixed $modules;

    /**
     * @param (callable(string): ?list<string>)|null $modules container name => php -m names, null when the probe fails
     */
    public function __construct(
        private readonly ExtensionJobs $jobs,
        ?callable $modules = null,
    ) {
        $this->modules = $modules;
    }

    public function kick(string $basePath, string $service, string $container): void
    {
        if ($service === '' || $container === '') {
            return;
        }
        if ($this->composeBlocks($basePath, $service) || $this->jobs->blocks($basePath, $service)) {
            return;
        }
        $loaded = $this->loaded($container);
        if ($loaded === null) {
            return;
        }
        $next = ExtensionManifest::firstMissing($basePath, $service, $loaded);
        if ($next === null) {
            return;
        }
        try {
            $this->jobs->begin($basePath, $service, 'install-ext', $next, $container);
        } catch (HttpException) {
            return;
        }
    }

    private function composeBlocks(string $basePath, string $service): bool
    {
        $lock = JobLock::read(JobLock::path($basePath, JobLock::COMPOSE));
        if (!is_array($lock) || ($lock['service'] ?? null) !== $service) {
            return false;
        }
        $containerId = is_string($lock['container_id'] ?? null) ? $lock['container_id'] : '';

        return $containerId !== '' || JobLock::ageSeconds($lock) <= JobLock::ID_GRACE_SECONDS;
    }

    /**
     * @return list<string>|null
     */
    private function loaded(string $container): ?array
    {
        $modules = $this->modules ?? function (string $container): ?array {
            $id = DockerExec::containerIdByName($container);
            if ($id === null) {
                return null;
            }
            try {
                $result = DockerExec::run($id, ['php', '-m'], '', 12);
            } catch (\Throwable) {
                return null;
            }
            $names = [];
            foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '[')) {
                    continue;
                }
                $names[] = $line;
            }

            return $names;
        };

        try {
            return $modules($container);
        } catch (\Throwable) {
            return null;
        }
    }
}
