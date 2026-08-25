<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;

final class PhpScratchPad
{
    public const DEFAULT_CODE = "<?php\n\necho 'PHP ' . PHP_VERSION . PHP_EOL;\n";

    public const DEFAULT_NAME = 'Snippet 1';

    public const MAX_SESSIONS = 50;

    public const MAX_NAME_BYTES = 80;

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? (Config::runtimePath() . '/php-scratch'), '/');
    }

    /**
     * @return array{
     *     service: string,
     *     active_id: string,
     *     sessions: list<array{id: string, name: string, code: string, result: ?array, updated_at: string}>,
     *     id: string,
     *     name: string,
     *     code: string,
     *     result: ?array,
     *     updated_at: string
     * }
     */
    public function read(string $service): array
    {
        $pad = $this->loadPad($service);
        if ($this->needsPersist($service)) {
            return $this->persist($pad);
        }

        return $this->present($pad);
    }

    /**
     * @param array<string, mixed>|null $result
     * @return array<string, mixed>
     */
    public function write(
        string $service,
        string $code,
        ?array $result = null,
        bool $preserveResult = true,
        ?string $sessionId = null,
    ): array {
        $pad = $this->loadPad($service);
        $id = $sessionId ?: $pad['active_id'];
        $index = $this->indexOf($pad, $id);
        if ($index === null) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        if (strlen($code) > PhpSnippetRunner::MAX_CODE_BYTES) {
            throw new HttpException('php_controller.code_too_large', 400);
        }
        $existing = $pad['sessions'][$index];
        $nextResult = $result !== null
            ? $this->sanitizeResult($result)
            : ($preserveResult ? $existing['result'] : null);
        $pad['sessions'][$index] = [
            'id' => $existing['id'],
            'name' => $existing['name'],
            'code' => $code,
            'result' => $nextResult,
            'updated_at' => date(DATE_ATOM),
        ];
        $pad['active_id'] = $existing['id'];

        return $this->persist($pad);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $service, string $name = ''): array
    {
        $pad = $this->loadPad($service);
        if (count($pad['sessions']) >= self::MAX_SESSIONS) {
            throw new HttpException('php_controller.session_limit', 400);
        }
        $session = $this->makeSession(
            self::DEFAULT_CODE,
            null,
            '',
            $this->uniqueName($pad['sessions'], $name !== '' ? $name : $this->nextDefaultName($pad['sessions'])),
        );
        $pad['sessions'][] = $session;
        $pad['active_id'] = $session['id'];

        return $this->persist($pad);
    }

    /**
     * @return array<string, mixed>
     */
    public function rename(string $service, string $id, string $name): array
    {
        $pad = $this->loadPad($service);
        $index = $this->indexOf($pad, $id);
        if ($index === null) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            throw new HttpException('php_controller.session_name_invalid', 400);
        }
        $others = $pad['sessions'];
        unset($others[$index]);
        $pad['sessions'][$index]['name'] = $this->uniqueName(array_values($others), $normalized);
        $pad['sessions'][$index]['updated_at'] = date(DATE_ATOM);
        $pad['active_id'] = $id;

        return $this->persist($pad);
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $service, string $id): array
    {
        $pad = $this->loadPad($service);
        if ($this->indexOf($pad, $id) === null) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        $pad['active_id'] = $id;

        return $this->persist($pad);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $service, string $id): array
    {
        $pad = $this->loadPad($service);
        $index = $this->indexOf($pad, $id);
        if ($index === null) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        array_splice($pad['sessions'], $index, 1);
        if ($pad['sessions'] === []) {
            $pad['sessions'][] = $this->makeSession(self::DEFAULT_CODE, null, '', self::DEFAULT_NAME);
        }
        if ($pad['active_id'] === $id || $this->indexOf($pad, $pad['active_id']) === null) {
            $pad['active_id'] = $pad['sessions'][count($pad['sessions']) - 1]['id'];
        }

        return $this->persist($pad);
    }

    /**
     * @return array{service: string, active_id: string, sessions: list<array<string, mixed>>}
     */
    private function loadPad(string $service): array
    {
        $this->assertService($service);
        $path = $this->path($service);
        if (!is_file($path) || !is_readable($path)) {
            return $this->emptyPad($service);
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->emptyPad($service);
        }
        if (!is_array($decoded)) {
            return $this->emptyPad($service);
        }
        if (isset($decoded['sessions']) && is_array($decoded['sessions'])) {
            return $this->normalizePad($service, $decoded);
        }

        $session = $this->makeSession(
            is_string($decoded['code'] ?? null) ? $decoded['code'] : self::DEFAULT_CODE,
            $decoded['result'] ?? null,
            is_string($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : '',
            is_string($decoded['name'] ?? null) ? $decoded['name'] : self::DEFAULT_NAME,
        );

        return [
            'service' => $service,
            'active_id' => $session['id'],
            'sessions' => [$session],
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{service: string, active_id: string, sessions: list<array<string, mixed>>}
     */
    private function normalizePad(string $service, array $decoded): array
    {
        $sessions = [];
        foreach ($decoded['sessions'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = is_string($item['id'] ?? null) && self::isValidId($item['id'])
                ? $item['id']
                : $this->newId($sessions);
            $code = $item['code'] ?? '';
            if (!is_string($code) || $code === '') {
                $code = self::DEFAULT_CODE;
            }
            $sessions[] = [
                'id' => $id,
                'name' => $this->normalizeName(is_string($item['name'] ?? null) ? $item['name'] : '') ?: $this->nextDefaultName($sessions),
                'code' => $code,
                'result' => $this->sanitizeResult($item['result'] ?? null),
                'updated_at' => is_string($item['updated_at'] ?? null) ? $item['updated_at'] : '',
            ];
            if (count($sessions) >= self::MAX_SESSIONS) {
                break;
            }
        }
        if ($sessions === []) {
            return $this->emptyPad($service);
        }
        $activeId = is_string($decoded['active_id'] ?? null) ? $decoded['active_id'] : '';
        if ($this->indexOf(['sessions' => $sessions], $activeId) === null) {
            $activeId = $sessions[0]['id'];
        }

        return [
            'service' => $service,
            'active_id' => $activeId,
            'sessions' => $sessions,
        ];
    }

    /**
     * @return array{service: string, active_id: string, sessions: list<array<string, mixed>>}
     */
    private function emptyPad(string $service): array
    {
        $session = $this->makeSession(self::DEFAULT_CODE, null, '', self::DEFAULT_NAME);

        return [
            'service' => $service,
            'active_id' => $session['id'],
            'sessions' => [$session],
        ];
    }

    /**
     * @param array{service: string, active_id: string, sessions: list<array<string, mixed>>} $pad
     * @return array<string, mixed>
     */
    private function persist(array $pad): array
    {
        $payload = [
            'service' => $pad['service'],
            'active_id' => $pad['active_id'],
            'sessions' => $pad['sessions'],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (!AtomicFile::write($this->path($pad['service']), $json)) {
            throw new HttpException('php_controller.scratch_write_failed', 500);
        }

        return $this->present($payload);
    }

    /**
     * @param array{service: string, active_id: string, sessions: list<array<string, mixed>>} $pad
     * @return array<string, mixed>
     */
    private function present(array $pad): array
    {
        $active = $pad['sessions'][0];
        $index = $this->indexOf($pad, $pad['active_id']);
        if ($index !== null) {
            $active = $pad['sessions'][$index];
        }

        return [
            'service' => $pad['service'],
            'active_id' => $active['id'],
            'sessions' => $pad['sessions'],
            'id' => $active['id'],
            'name' => $active['name'],
            'code' => $active['code'],
            'result' => $active['result'],
            'updated_at' => $active['updated_at'],
        ];
    }

    /**
     * @param array{sessions: list<array<string, mixed>>} $pad
     */
    private function indexOf(array $pad, string $id): ?int
    {
        foreach ($pad['sessions'] as $index => $session) {
            if (($session['id'] ?? '') === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @return array{id: string, name: string, code: string, result: ?array, updated_at: string}
     */
    private function makeSession(string $code, mixed $result, string $updatedAt, string $name): array
    {
        if ($code === '') {
            $code = self::DEFAULT_CODE;
        }

        return [
            'id' => $this->newId([]),
            'name' => $this->normalizeName($name) ?: self::DEFAULT_NAME,
            'code' => $code,
            'result' => $this->sanitizeResult($result),
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param list<array<string, mixed>> $sessions
     */
    private function nextDefaultName(array $sessions): string
    {
        $n = count($sessions) + 1;

        return 'Snippet ' . $n;
    }

    /**
     * @param list<array<string, mixed>> $sessions
     */
    private function uniqueName(array $sessions, string $wanted): string
    {
        $wanted = $this->normalizeName($wanted);
        if ($wanted === '') {
            $wanted = $this->nextDefaultName($sessions);
        }
        $names = [];
        foreach ($sessions as $session) {
            if (is_string($session['name'] ?? null)) {
                $names[] = $session['name'];
            }
        }
        if (!in_array($wanted, $names, true)) {
            return $wanted;
        }
        $i = 2;
        while (in_array($wanted . ' (' . $i . ')', $names, true)) {
            $i++;
        }

        return $wanted . ' (' . $i . ')';
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, self::MAX_NAME_BYTES);
        }

        return substr($name, 0, self::MAX_NAME_BYTES);
    }

    /**
     * @param list<array<string, mixed>> $sessions
     */
    private function newId(array $sessions): string
    {
        do {
            $id = bin2hex(random_bytes(6));
        } while ($this->indexOf(['sessions' => $sessions], $id) !== null);

        return $id;
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{12}$/', $id);
    }

    private function needsPersist(string $service): bool
    {
        $path = $this->path($service);
        if (!is_file($path) || !is_readable($path)) {
            return true;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }

        return !is_array($decoded) || !isset($decoded['sessions']) || !is_array($decoded['sessions']);
    }

    private function path(string $service): string
    {
        return $this->basePath . '/' . $service . '.json';
    }

    private function assertService(string $service): void
    {
        if (!PhpVersionId::isValidService($service)) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
    }

    /**
     * @param mixed $result
     * @return array<string, mixed>|null
     */
    private function sanitizeResult(mixed $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }

        return [
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'exit_code' => (int) ($result['exit_code'] ?? 0),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'php_version' => (string) ($result['php_version'] ?? ''),
        ];
    }
}
