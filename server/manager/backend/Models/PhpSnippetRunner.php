<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\DockerExec;
use Manager\Support\DockerLiveState;

final class PhpSnippetRunner
{
    public const MAX_CODE_BYTES = 65536;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_OUTPUT_BYTES = 262144;

    public static function normalizeCode(string $code): string
    {
        if (str_starts_with($code, "\xEF\xBB\xBF")) {
            $code = substr($code, 3);
        }
        if (!preg_match('/\A\s*<\?(php|=)/i', $code)) {
            return "<?php\n" . $code;
        }

        return $code;
    }

    /**
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exit_code: int,
     *     timed_out: bool,
     *     truncated: bool,
     *     duration_ms: int,
     *     php_version: string
     * }
     */
    public function run(string $service, string $code): array
    {
        $targets = PhpRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 400);
        }
        if (strlen($code) > self::MAX_CODE_BYTES) {
            throw new HttpException('php_controller.code_too_large', 400);
        }
        if (trim($code) === '') {
            throw new HttpException('php_controller.code_empty', 400);
        }

        $runtime = new PhpRuntime();
        $state = $runtime->statuses()[$service]['state'] ?? 'not_created';
        if ($state !== 'running') {
            throw new HttpException('php_controller.container_not_running', 409);
        }

        $containerName = (string) ($targets[$service]['container'] ?? '');
        $containerId = DockerExec::containerIdByName($containerName);
        if ($containerId === null || DockerLiveState::stateFor($containerName) !== 'running') {
            throw new HttpException('php_controller.container_not_running', 409);
        }

        $script = self::normalizeCode($code);
        $workingDir = PhpVersionId::sourcePrefix($service);
        $bootstrap = '$p="/tmp/.mgr-php-run-".uniqid("",true).".php";'
            . 'register_shutdown_function(function () use ($p) { @unlink($p); });'
            . 'file_put_contents($p, base64_decode(getenv("MGR_PHP_B64")));'
            . 'include $p;';
        set_time_limit(self::TIMEOUT_SECONDS + 15);
        $started = microtime(true);
        $result = DockerExec::run(
            $containerId,
            [
                'php',
                '-d',
                'display_errors=1',
                '-d',
                'error_reporting=E_ALL',
                '-d',
                'max_execution_time=' . self::TIMEOUT_SECONDS,
                '-r',
                $bootstrap,
            ],
            '',
            self::TIMEOUT_SECONDS + 2,
            self::MAX_OUTPUT_BYTES,
            $workingDir,
            ['MGR_PHP_B64=' . base64_encode($script)],
        );
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $stderr = preg_replace(
            '/^PHP Warning:\s+Module "[^"]+" is already loaded in Unknown on line 0\R?/m',
            '',
            $result['stderr'],
        ) ?? $result['stderr'];
        $stdout = preg_replace('/\/tmp\/\.mgr-php-run-[^\s:]+\.php/', 'snippet.php', $result['stdout']) ?? $result['stdout'];
        $stderr = preg_replace('/\/tmp\/\.mgr-php-run-[^\s:]+\.php/', 'snippet.php', $stderr) ?? $stderr;

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $result['exit_code'],
            'timed_out' => $result['timed_out'],
            'truncated' => $result['truncated'],
            'duration_ms' => $durationMs,
            'php_version' => PhpVersionId::label($service),
        ];
    }
}
