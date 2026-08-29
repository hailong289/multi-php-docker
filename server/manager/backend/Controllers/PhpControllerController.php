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
use Manager\Models\PhpScratchPad;
use Manager\Models\PhpSnippetRunner;
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
        if ($action === 'create') {
            // Repair missing include after a partial install (files written, queue failed).
            (new PhpVersionInstaller())->repairComposeInclude($service);
        }
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

    public function runSnippet(Request $request, array $params = []): Response
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $service = (string) ($params['service'] ?? '');
        $code = $request->json()['code'] ?? null;
        if (!is_string($code)) {
            throw new HttpException('php_controller.code_empty', 400);
        }
        $result = (new PhpSnippetRunner())->run($service, $code);
        $sessionId = $request->json()['session_id'] ?? null;
        $scratch = (new PhpScratchPad())->write(
            $service,
            $code,
            $result,
            true,
            is_string($sessionId) && PhpScratchPad::isValidId($sessionId) ? $sessionId : null,
        );

        return Response::json([
            'message_key' => $result['timed_out']
                ? 'php_controller.run_timed_out'
                : 'php_controller.run_finished',
            'php_run' => $result,
            'php_scratch' => $scratch,
        ]);
    }

    public function showScratch(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');

        return Response::json([
            'php_scratch' => (new PhpScratchPad())->read($service),
        ]);
    }

    public function createScratch(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $name = $request->json()['name'] ?? '';
        if (!is_string($name)) {
            $name = '';
        }
        $scratch = (new PhpScratchPad())->create($service, $name);

        return Response::json([
            'message_key' => 'php_controller.session_created',
            'php_scratch' => $scratch,
        ]);
    }

    public function saveScratch(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $id = (string) ($params['id'] ?? '');
        $body = $request->json();
        if ($id === '') {
            $id = is_string($body['session_id'] ?? null) ? $body['session_id'] : '';
        }
        $pad = new PhpScratchPad();
        if ($id !== '' && !PhpScratchPad::isValidId($id)) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        $code = $body['code'] ?? null;
        $name = $body['name'] ?? null;
        $activate = !empty($body['activate']);
        $scratch = $pad->read($service);
        if ($id === '') {
            $id = (string) $scratch['active_id'];
        }
        if (is_string($name)) {
            $scratch = $pad->rename($service, $id, $name);
        }
        if (is_string($code)) {
            $result = $body['result'] ?? null;
            $scratch = $pad->write(
                $service,
                $code,
                is_array($result) ? $result : null,
                true,
                $id,
            );
        } elseif ($activate || $id !== $scratch['active_id']) {
            $scratch = $pad->activate($service, $id);
        }

        return Response::json([
            'message_key' => is_string($name) && !is_string($code)
                ? 'php_controller.session_renamed'
                : 'php_controller.scratch_saved',
            'php_scratch' => $scratch,
        ]);
    }

    public function deleteScratch(Request $request, array $params = []): Response
    {
        $service = (string) ($params['service'] ?? '');
        $id = (string) ($params['id'] ?? '');
        if (!PhpScratchPad::isValidId($id)) {
            throw new HttpException('php_controller.session_not_found', 404);
        }
        $scratch = (new PhpScratchPad())->delete($service, $id);

        return Response::json([
            'message_key' => 'php_controller.session_deleted',
            'php_scratch' => $scratch,
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
