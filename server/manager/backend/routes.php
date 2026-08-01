<?php

declare(strict_types=1);

use Manager\Controllers\BootstrapController;
use Manager\Controllers\DomainController;
use Manager\Controllers\HostsController;
use Manager\Controllers\NginxController;
use Manager\Controllers\PhpControllerController;
use Manager\Controllers\ServerController;
use Manager\Controllers\SessionController;

return [
    ['GET', '/session', [SessionController::class, 'show']],
    ['GET', '/bootstrap', [BootstrapController::class, 'show']],
    ['GET', '/nginx/status', [NginxController::class, 'status']],
    ['POST', '/nginx/reload', [NginxController::class, 'reload']],
    ['GET', '/hosts/status', [HostsController::class, 'status']],
    ['POST', '/hosts/sync', [HostsController::class, 'sync']],
    ['GET', '/domains', [DomainController::class, 'index']],
    ['POST', '/domains', [DomainController::class, 'store']],
    ['PUT', '/domains/extra/(?P<domain>[a-zA-Z0-9._-]+)', [DomainController::class, 'updateExtra']],
    ['DELETE', '/domains/extra/(?P<domain>[a-zA-Z0-9._-]+)', [DomainController::class, 'destroyExtra']],
    ['PUT', '/domains/(?P<key>SERVER_NAME\d+)', [DomainController::class, 'update']],
    ['GET', '/php-controllers', [PhpControllerController::class, 'index']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+)/(?P<action>start|stop|restart)', [PhpControllerController::class, 'action']],
    ['POST', '/servers', [ServerController::class, 'store']],
    ['PUT', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'update']],
    ['DELETE', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'destroy']],
];
