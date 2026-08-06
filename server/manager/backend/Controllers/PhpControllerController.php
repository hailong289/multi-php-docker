<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\PhpDetails;
use Manager\Models\PhpExtensionCatalog;
use Manager\Models\PhpIniEditor;
use Manager\Models\PhpRuntime;
use Manager\Models\DockerHubPhpTags;
use Manager\Models\PhpVersionInstaller;

final class PhpControllerController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $runtime = new PhpRuntime();

        return Response::json([
            'targets' => PhpRuntime::targets(),
            'statuses' => $runtime->statuses(),
        ]);
    }

    public function availableVersions(Request $request, array $params = []): Response
    {
        $page = max(1, (int) $request->queryParam('page', 1));
        $perPage = (int) $request->queryParam('per_page', 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        $q = strtolower(trim((string) $request->queryParam('q', '')));
        $variantFilter = strtolower(trim((string) $request->queryParam('variant', 'all')));
        if (!in_array($variantFilter, ['all', 'default', 'alpine', 'trixie'], true)) {
            $variantFilter = 'all';
        }
        $bundle = (new DockerHubPhpTags())->page($page, $perPage, $q, $variantFilter);

        return Response::json([
            'versions' => $bundle['versions'],
            'pagination' => [
                'page' => $bundle['page'],
                'per_page' => $bundle['per_page'],
                'total' => $bundle['total'],
                'total_pages' => $bundle['total_pages'],
            ],
            'filters' => [
                'q' => $q,
                'variant' => $variantFilter,
                'name' => $bundle['name'] ?? 'fpm',
            ],
        ]);
    }

    public function installVersion(Request $request, array $params = []): Response
    {
        $version = $request->json()['version'] ?? null;
        $variant = $request->json()['variant'] ?? 'default';
        if (!is_string($version) || $version === '') {
            throw new HttpException('php_controller.invalid_version', 400);
        }
        if (!is_string($variant)) {
            $variant = 'default';
        }
        $result = (new PhpVersionInstaller())->install($version, $variant);

        return Response::json($result);
    }

    public function action(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $action = (string) ($params['action'] ?? '');
        $runtime = new PhpRuntime();
        $requestId = $runtime->request($service, $action);
        $target = PhpRuntime::targets()[$service];

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'php_controller.requested',
            'message_parameters' => [
                'action' => 'php_controller.' . $action,
                'version' => $target['label'],
            ],
            'php_controllers' => [
                'targets' => PhpRuntime::targets(),
                'statuses' => $runtime->statuses(),
            ],
        ]);
    }

    public function details(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');

        return Response::json([
            'php_details' => (new PhpDetails())->forService($service),
        ]);
    }

    public function saveIni(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $content = $request->json()['content'] ?? null;
        if (!is_string($content)) {
            throw new HttpException('php_controller.ini_invalid', 400);
        }
        (new PhpIniEditor())->write($service, $content);

        return Response::json([
            'message_key' => 'php_controller.ini_saved',
            'php_details' => (new PhpDetails())->forService($service),
        ]);
    }

    public function installExtension(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = strtolower((string) ($params['name'] ?? ''));
        $runtime = new PhpRuntime();
        $state = $runtime->statuses()[$service]['state'] ?? 'not_created';
        if ($state === 'busy' || $runtime->hasBlockingRequests($service)) {
            throw new HttpException('php_controller.busy', 409);
        }
        if ($state !== 'running') {
            throw new HttpException('php_controller.container_not_running', 409);
        }
        $requestId = $runtime->request($service, 'install-ext', $name);

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'php_controller.extension_install_requested',
            'message_parameters' => ['extension' => $name],
        ]);
    }

    public function enableExtension(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = (string) ($params['name'] ?? '');
        if (!PhpExtensionCatalog::isCurated($name)) {
            throw new HttpException('php_controller.invalid_extension', 400);
        }
        (new PhpIniEditor())->enableExtension($service, $name);

        return Response::json([
            'message_key' => 'php_controller.extension_enabled',
            'php_details' => (new PhpDetails())->forService($service),
        ]);
    }

    public function uninstallExtension(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = strtolower((string) ($params['name'] ?? ''));
        $runtime = new PhpRuntime();
        $state = $runtime->statuses()[$service]['state'] ?? 'not_created';
        if ($state === 'busy' || $runtime->hasBlockingRequests($service)) {
            throw new HttpException('php_controller.busy', 409);
        }
        if ($state !== 'running') {
            throw new HttpException('php_controller.container_not_running', 409);
        }
        $requestId = $runtime->request($service, 'uninstall-ext', $name);
        try {
            (new PhpIniEditor())->removeExtension($service, $name);
        } catch (HttpException) {
            // Prefer container uninstall even if ini cleanup fails.
        }

        return Response::json([
            'request_id' => $requestId,
            'message_key' => 'php_controller.extension_uninstall_requested',
            'message_parameters' => ['extension' => $name],
        ]);
    }
}
