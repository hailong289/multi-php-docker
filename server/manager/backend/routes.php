<?php

declare(strict_types=1);

use Manager\Controllers\BootstrapController;
use Manager\Controllers\DomainController;
use Manager\Controllers\HostsController;
use Manager\Controllers\InfraController;
use Manager\Controllers\NginxController;
use Manager\Controllers\PhpControllerController;
use Manager\Controllers\ServerController;
use Manager\Controllers\SessionController;
use Manager\Controllers\SupervisorController;

return [
    ['GET', '/session', [SessionController::class, 'show']],
    ['GET', '/bootstrap', [BootstrapController::class, 'show']],
    ['GET', '/nginx/status', [NginxController::class, 'status']],
    ['GET', '/nginx/management', [NginxController::class, 'management']],
    ['GET', '/nginx/templates', [NginxController::class, 'templates']],
    ['GET', '/nginx/templates/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.template)', [NginxController::class, 'templateShow']],
    ['PUT', '/nginx/templates/(?P<name>[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\\.template)', [NginxController::class, 'templateSave']],
    ['POST', '/nginx/soft-reload', [NginxController::class, 'softReload']],
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
    ['POST', '/infra-services/(?P<service>mysql|redis|rabbitmq)/(?P<action>start|stop|restart|create)', [InfraController::class, 'action']],
    ['GET', '/supervisor', [SupervisorController::class, 'index']],
    ['GET', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)', [SupervisorController::class, 'details']],
    ['POST', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/clear-log', [SupervisorController::class, 'clearLog']],
    ['POST', '/supervisor/(?P<service>supervisor(?:-[0-9.]+(?:-alpine|-trixie)?)?)/(?P<action>start|stop|restart|create)', [SupervisorController::class, 'action']],
    ['GET', '/php-controllers', [PhpControllerController::class, 'index']],
    ['GET', '/php-controllers/available-versions', [PhpControllerController::class, 'availableVersions']],
    ['POST', '/php-controllers/install-version', [PhpControllerController::class, 'installVersion']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/(?P<action>start|stop|restart|create)', [PhpControllerController::class, 'action']],
    ['GET', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/details', [PhpControllerController::class, 'details']],
    ['PUT', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/ini', [PhpControllerController::class, 'saveIni']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/install', [PhpControllerController::class, 'installExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/uninstall', [PhpControllerController::class, 'uninstallExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/enable', [PhpControllerController::class, 'enableExtension']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+(?:-alpine|-trixie)?)/extensions/(?P<name>[a-z0-9_]+)/disable', [PhpControllerController::class, 'disableExtension']],
    ['POST', '/servers', [ServerController::class, 'store']],
    ['PUT', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'update']],
    ['DELETE', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'destroy']],
];
