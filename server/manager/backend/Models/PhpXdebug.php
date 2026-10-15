<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class PhpXdebug
{
    public function __construct(private readonly string $projectPath = '')
    {
    }

    /** @return array{enabled: bool, client_port: int} */
    public function parse(string $ini): array
    {
        if (!str_contains($ini, '; manager-xdebug-begin')) {
            return ['enabled' => false, 'client_port' => 9003];
        }
        $port = 9003;
        if (preg_match('/xdebug\.client_port=(\d+)/', $ini, $matches)) {
            $port = (int) $matches[1];
        }

        return ['enabled' => true, 'client_port' => $port];
    }

    public function render(string $ini, bool $enabled, int $clientPort): string
    {
        $this->assertPort($clientPort);
        $stripped = preg_replace(
            "/\n*; manager-xdebug-begin\n.*?\n; manager-xdebug-end\n?/s",
            "\n",
            $ini,
        ) ?? $ini;
        $stripped = rtrim($stripped, "\n") . "\n";
        if (!$enabled) {
            return $stripped;
        }

        return $stripped . $this->block($clientPort);
    }

    /** @return array{enabled: bool, client_port: int} */
    public function apply(string $service, bool $enabled, int $clientPort): array
    {
        $editor = new PhpIniEditor($this->projectPath);
        $next = $this->render($editor->read($service), $enabled, $clientPort);
        $editor->write($service, $next);

        return $this->parse($next);
    }

    private function assertPort(int $clientPort): void
    {
        if ($clientPort < 1 || $clientPort > 65535 || $clientPort === 9000) {
            throw new HttpException('xdebug.invalid_port', 400);
        }
    }

    private function block(int $clientPort): string
    {
        return <<<INI
; manager-xdebug-begin
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port={$clientPort}
; manager-xdebug-end

INI;
    }
}
