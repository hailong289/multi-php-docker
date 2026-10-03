<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Support\StatusStream;

final class StatusController extends Controller
{
    /**
     * Single SSE connection emitting named events per subsystem.
     * Prefer this over per-subsystem streams on php -S (worker pool is small).
     */
    public function stream(Request $request, array $params = []): Response
    {
        return Response::stream(function (): void {
            StatusStream::emitNamedLoop([
                'servers' => [
                    'factory' => fn (): array => $this->serversPayload(),
                ],
                'nginx' => [
                    'factory' => fn (): array => $this->nginxPayload(),
                    // Access log tails change constantly; keep them in the payload but
                    // ignore content for change detection so SSE does not spam.
                    'fingerprint' => static function (array $payload): array {
                        $fp = $payload;
                        if (isset($fp['nginx_management']['logs']['access']['content'])) {
                            $fp['nginx_management']['logs']['access']['content'] = '';
                        }

                        return $fp;
                    },
                    'busy' => static fn (array $payload): bool => ($payload['nginx_management']['state'] ?? '') === 'busy',
                ],
                'hosts' => [
                    'factory' => fn (): array => $this->hostsPayload(),
                    'busy' => static fn (array $payload): bool => ($payload['hosts_status']['status'] ?? '') === 'busy',
                ],
                'php' => [
                    'factory' => fn (): array => $this->phpPayload(),
                    'busy' => static fn (array $payload): bool => StatusStream::mapHasBusyState(
                        $payload['php_controllers']['statuses'] ?? [],
                    ),
                ],
                'infra' => [
                    'factory' => fn (): array => $this->infraPayload(),
                    'busy' => static fn (array $payload): bool => self::infraPayloadBusy($payload),
                ],
                'supervisor' => [
                    'factory' => fn (): array => $this->supervisorPayload(),
                    'busy' => static fn (array $payload): bool => StatusStream::mapHasBusyState(
                        $payload['supervisor_services']['statuses'] ?? [],
                    ),
                ],
            ]);
        });
    }

    public function streamServers(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(static fn (): array => (new self())->serversPayload());
        });
    }

    public function streamNginx(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(
                static fn (): array => (new self())->nginxPayload(),
                static fn (array $payload): bool => ($payload['nginx_management']['state'] ?? '') === 'busy',
            );
        });
    }

    public function streamHosts(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(
                static fn (): array => (new self())->hostsPayload(),
                static fn (array $payload): bool => ($payload['hosts_status']['status'] ?? '') === 'busy',
            );
        });
    }

    public function streamPhp(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(
                static fn (): array => (new self())->phpPayload(),
                static fn (array $payload): bool => StatusStream::mapHasBusyState(
                    $payload['php_controllers']['statuses'] ?? [],
                ),
            );
        });
    }

    public function streamInfra(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(
                static fn (): array => (new self())->infraPayload(),
                static fn (array $payload): bool => self::infraPayloadBusy($payload),
            );
        });
    }

    public function streamSupervisor(Request $request, array $params = []): Response
    {
        return Response::stream(static function (): void {
            StatusStream::emitLoop(
                static fn (): array => (new self())->supervisorPayload(),
                static fn (array $payload): bool => StatusStream::mapHasBusyState(
                    $payload['supervisor_services']['statuses'] ?? [],
                ),
            );
        });
    }

    /** @param array<string, mixed> $payload */
    private static function infraPayloadBusy(array $payload): bool
    {
        $infra = $payload['infra_services'] ?? [];
        if (StatusStream::mapHasBusyState(is_array($infra['statuses'] ?? null) ? $infra['statuses'] : [])) {
            return true;
        }
        foreach ($infra['compose_files'] ?? [] as $file) {
            if (is_array($file) && ($file['state'] ?? '') === 'busy') {
                return true;
            }
        }

        return false;
    }
}
