<?php

declare(strict_types=1);

use Manager\Controllers\AuthController;
use Manager\Controllers\BootstrapController;
use Manager\Controllers\DomainController;
use Manager\Controllers\HostsController;
use Manager\Controllers\InfraController;
use Manager\Controllers\NginxController;
use Manager\Controllers\PhpControllerController;
use Manager\Controllers\ServerController;
use Manager\Controllers\SessionController;
use Manager\Controllers\SupervisorController;
use Manager\Controllers\TerminalController;

return [
    ['GET', '/session', [SessionController::class, 'show']],
    ['POST', '/login', [AuthController::class, 'login']],
    ['POST', '/logout', [AuthController::class, 'logout']],
    ['GET', '/bootstrap', [BootstrapController::class, 'show']],
    ['GET', '/nginx/status', [NginxController::class, 'status']],
    ['GET', '/nginx/management', [NginxController::class, 'management']],
    ['GET', '/nginx/templates', [NginxController::class, 'templates']],
    ['GET', '/nginx/templates/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.template)', [NginxController::class, 'templateShow']],
    ['PUT', '/nginx/templates/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.template)', [NginxController::class, 'templateSave']],
    ['POST', '/nginx/soft-reload', [NginxController::class, 'softReload']],
    ['GET', '/nginx/domain-logs', [NginxController::class, 'domainLogIndex']],
    ['GET', '/nginx/domain-logs/(?P<domain>[a-zA-Z0-9][a-zA-Z0-9._-]{0,253})', [NginxController::class, 'domainLogShow']],
    ['POST', '/nginx/domain-logs/(?P<domain>[a-zA-Z0-9][a-zA-Z0-9._-]{0,253})/clear', [NginxController::class, 'domainLogClear']],
    ['POST', '/nginx/logs/clear', [NginxController::class, 'globalLogClear']],
    ['POST', '/nginx/actions/(?P<action>start|stop|restart)', [NginxController::class, 'action']],
    ['POST', '/nginx/test', [NginxController::class, 'test']],
    ['POST', '/nginx/reload', [NginxController::class, 'reload']],
    ['GET', '/hosts/status', [HostsController::class, 'status']],
    ['POST', '/hosts/sync', [HostsController::class, 'sync']],
    ['GET', '/domains', [DomainController::class, 'index']],
    ['POST', '/domains', [DomainController::class, 'store']],
    ['PUT', '/domains/extra/(?P<domain>[a-zA-Z0-9._-]+)', [DomainController::class, 'updateExtra']],
    ['DELETE', '/domains/extra/(?P<domain>[a-zA-Z0-9._-]+)', [DomainController::class, 'destroyExtra']],
    ['PUT', '/domains/(?P<key>SERVER_NAME\d+)', [DomainController::class, 'update']],
    ['GET', '/infra-services', [InfraController::class, 'index']],
    ['GET', '/infra-services/compose-files', [InfraController::class, 'composeFiles']],
    ['POST', '/infra-services/compose-files', [InfraController::class, 'composeFileCreate']],
    ['GET', '/infra-services/compose-files/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.(?:yml|yaml))', [InfraController::class, 'composeFileShow']],
    ['PUT', '/infra-services/compose-files/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.(?:yml|yaml))', [InfraController::class, 'composeFileSave']],
    ['DELETE', '/infra-services/compose-files/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.(?:yml|yaml))', [InfraController::class, 'composeFileDelete']],
    ['GET', '/infra-services/(?P<service>mysql|redis|rabbitmq)/compose', [InfraController::class, 'composeShow']],
    ['PUT', '/infra-services/(?P<service>mysql|redis|rabbitmq)/compose', [InfraController::class, 'composeSave']],
    ['GET', '/infra-services/(?P<service>mysql|redis|rabbitmq)/logs', [InfraController::class, 'logs']],
    ['POST', '/infra-services/(?P<service>mysql|redis|rabbitmq)/(?P<action>start|stop|restart|create|pull-recreate)', [InfraController::class, 'action']],
    ['GET', '/supervisor', [SupervisorController::class, 'index']],
    ['GET', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)', [SupervisorController::class, 'details']],
    ['POST', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/clear-log', [SupervisorController::class, 'clearLog']],
    ['GET', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/configs', [SupervisorController::class, 'configs']],
    ['GET', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/configs/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.conf)', [SupervisorController::class, 'configShow']],
    ['POST', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/configs', [SupervisorController::class, 'configCreate']],
    ['PUT', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/configs/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.conf)', [SupervisorController::class, 'configSave']],
    ['DELETE', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/configs/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.conf)', [SupervisorController::class, 'configDelete']],
    ['POST', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/(?P<action>start|stop|restart|create)', [SupervisorController::class, 'action']],
    ['POST', '/php-controller/start', [PhpControllerController::class, 'startDaemon']],
    ['GET', '/php-controllers', [PhpControllerController::class, 'index']],
    ['GET', '/php-controllers/available-versions', [PhpControllerController::class, 'availableVersions']],
    ['POST', '/php-controllers/install-version', [PhpControllerController::class, 'installVersion']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/(?P<action>start|stop|restart|create)', [PhpControllerController::class, 'action']],
    ['GET', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/details', [PhpControllerController::class, 'details']],
    ['GET', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/logs', [PhpControllerController::class, 'logs']],
    ['PUT', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/ini', [PhpControllerController::class, 'saveIni']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/run', [PhpControllerController::class, 'runSnippet']],
    ['GET', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/scratch', [PhpControllerController::class, 'showScratch']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/scratch', [PhpControllerController::class, 'createScratch']],
    ['PUT', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/scratch', [PhpControllerController::class, 'saveScratch']],
    ['PUT', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/scratch/(?P<id>[a-f0-9]{12})', [PhpControllerController::class, 'saveScratch']],
    ['DELETE', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/scratch/(?P<id>[a-f0-9]{12})', [PhpControllerController::class, 'deleteScratch']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/install', [PhpControllerController::class, 'installExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/uninstall', [PhpControllerController::class, 'uninstallExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/enable', [PhpControllerController::class, 'enableExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/disable', [PhpControllerController::class, 'disableExtension']],
    ['POST', '/servers', [ServerController::class, 'store']],
    ['PUT', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'update']],
    ['DELETE', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'destroy']],
    ['POST', '/terminal/sessions', [TerminalController::class, 'create']],
    ['GET', '/terminal/sessions/(?P<id>[a-f0-9]{16,64})/stream', [TerminalController::class, 'stream']],
    ['GET', '/terminal/sessions/(?P<id>[a-f0-9]{16,64})/output', [TerminalController::class, 'output']],
    ['POST', '/terminal/sessions/(?P<id>[a-f0-9]{16,64})/input', [TerminalController::class, 'input']],
    ['POST', '/terminal/sessions/(?P<id>[a-f0-9]{16,64})/resize', [TerminalController::class, 'resize']],
    ['DELETE', '/terminal/sessions/(?P<id>[a-f0-9]{16,64})', [TerminalController::class, 'destroy']],
];
