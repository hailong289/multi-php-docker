<?php

declare(strict_types=1);

use Manager\Controllers\BootstrapController;
use Manager\Controllers\NginxController;
use Manager\Controllers\PhpControllerController;
use Manager\Controllers\ServerController;
use Manager\Controllers\SessionController;

return [
    ['GET', '/session', [SessionController::class, 'show']],
    ['GET', '/bootstrap', [BootstrapController::class, 'show']],
    ['GET', '/nginx/status', [NginxController::class, 'status']],
    ['POST', '/nginx/reload', [NginxController::class, 'reload']],
    ['GET', '/php-controllers', [PhpControllerController::class, 'index']],
    ['POST', '/php-controllers/(?P<service>php-[0-9.]+)/(?P<action>start|stop|restart)', [PhpControllerController::class, 'action']],
    ['POST', '/servers', [ServerController::class, 'store']],
    ['PUT', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'update']],
    ['DELETE', '/servers/(?P<key>SERVER_NAME\d+)', [ServerController::class, 'destroy']],
];
