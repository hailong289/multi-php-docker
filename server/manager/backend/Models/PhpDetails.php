<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class PhpDetails
{
    private readonly PhpRuntime $runtime;
    private readonly PhpIniEditor $ini;

    public function __construct(?PhpRuntime $runtime = null, ?PhpIniEditor $ini = null)
    {
        $this->runtime = $runtime ?? new PhpRuntime();
        $this->ini = $ini ?? new PhpIniEditor();
    }

    public function forService(string $service): array
    {
        $targets = PhpRuntime::targets();
        if (!isset($targets[$service])) {
            throw new HttpException('php_controller.invalid_service', 404);
        }
        $statuses = $this->runtime->statuses();
        $status = $statuses[$service];
        $iniContent = '';
        $iniReadable = true;
        try {
            $iniContent = $this->ini->read($service);
        } catch (HttpException) {
            $iniReadable = false;
        }
        $modulesInfo = $this->runtime->readModules($service);
        if (($status['state'] ?? '') === 'running' && $this->runtime->modulesStale($service)) {
            try {
                $this->runtime->request($service, 'modules');
            } catch (HttpException) {
                // ignore queue races; UI can refresh
            }
        }
        $availableInfo = $this->runtime->readAvailable($service);
        if (($status['state'] ?? '') === 'running' && $this->runtime->availableStale($service)) {
            try {
                $this->runtime->request($service, 'available-ext');
            } catch (HttpException) {
                // ignore queue races; UI can refresh
            }
        }
        $extensions = PhpExtensionCatalog::entries(
            $service,
            $modulesInfo['modules'],
            $iniContent,
        );

        return [
            'service' => $service,
            'target' => $targets[$service],
            'status' => $status,
            'ini' => [
                'relative_path' => PhpIniEditor::relativePath($service),
                'content' => $iniContent,
                'readable' => $iniReadable,
            ],
            'modules' => $modulesInfo,
            'available' => $availableInfo,
            'extensions' => $extensions,
        ];
    }
}
