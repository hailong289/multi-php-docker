<?php

declare(strict_types=1);

namespace Manager\Support;

use Manager\Models\PhpRuntime;
use Manager\Models\PhpVersionId;

/** Re-runs scripts/docker/trust-local-certs.sh in running PHP containers after site certs change. */
final class LocalCertTrust
{
    public static function refresh(): void
    {
        foreach (PhpRuntime::targets() as $service => $target) {
            foreach ([$target['container'], PhpVersionId::supervisorContainer($service)] as $container) {
                $id = DockerExec::containerIdByName($container);
                if ($id !== null) {
                    // Detached: update-ca-certificates takes ~1s and the result is not needed here.
                    DockerExec::startDetachedExec($id, ['sh', '/var/scripts/docker/trust-local-certs.sh']);
                }
            }
        }
    }
}
