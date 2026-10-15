<?php

declare(strict_types=1);

namespace Manager\Controllers;

use Manager\Http\HttpException;
use Manager\Http\Request;
use Manager\Http\Response;
use Manager\Models\DatabaseDump;
use Manager\Models\DatabaseDumpIndex;
use Manager\Models\EnvConfig;
use Manager\Support\Config;
use Manager\Support\DockerExec;

final class DatabaseController extends Controller
{
    private const CONTAINERS = [
        'mysql' => 'mysql_container',
        'postgres' => 'postgres_container',
    ];

    private const SKIP = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
        'postgres',
        'template0',
        'template1',
    ];

    public function index(Request $request, array $params = []): Response
    {
        $dump = $this->dump();
        $index = $this->indexModel();
        $rows = [];
        foreach ($index->list() as $row) {
            $rows[] = $dump->syncStatus($row);
        }

        return Response::json(['dumps' => $rows]);
    }

    public function names(Request $request, array $params = []): Response
    {
        $engine = (string) ($params['engine'] ?? '');
        $container = self::CONTAINERS[$engine] ?? '';
        if ($container === '') {
            throw new HttpException('database.invalid_engine', 400);
        }
        $id = DockerExec::containerIdByName($container);
        if ($id === null) {
            throw new HttpException('database.container_stopped', 409);
        }
        $cmd = $engine === 'mysql'
            ? ['mysql', '-uroot', '-p1', '-N', '-e', 'SHOW DATABASES']
            : ['psql', '-U', 'postgres', '-tAc', 'SELECT datname FROM pg_database'];
        $result = DockerExec::run($id, $cmd);
        if (($result['exit_code'] ?? 1) !== 0) {
            throw new HttpException('database.list_failed', 502);
        }
        $names = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $result['stdout']) ?: [] as $line) {
            $name = trim($line);
            if ($name === '' || in_array($name, self::SKIP, true)) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                $names[] = $name;
            }
        }

        return Response::json(['databases' => $names]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $body = $request->json();
        $engine = (string) ($body['engine'] ?? '');
        $database = (string) ($body['database'] ?? '');
        $serverKey = $this->serverKey($body['server_key'] ?? null);
        $dump = $this->dump();
        $file = $dump->relativeFile($engine, $database);
        $row = $this->indexModel()->add($engine, $database, $serverKey, $file);
        $this->start($engine, $dump->command($engine, $database, $file), $row['id']);

        return Response::json(['dump' => $row], 202);
    }

    public function restore(Request $request, array $params = []): Response
    {
        $row = $this->indexModel()->find((string) ($params['id'] ?? ''));
        if (($row['status'] ?? '') !== 'ready') {
            throw new HttpException('database.dump_not_ready', 409);
        }
        $engine = (string) $row['engine'];
        $database = (string) $row['database'];
        $file = (string) $row['file'];
        $dump = $this->dump();
        $row = $this->indexModel()->mark((string) $row['id'], 'pending');
        $exit = $dump->backupsRoot() . '/' . $file . '.restore.exit';
        if (is_file($exit)) {
            unlink($exit);
        }
        $this->start($engine, $dump->restoreCommand($engine, $database, $file), (string) $row['id']);

        return Response::json(['dump' => $row], 202);
    }

    private function start(string $engine, array $cmd, string $id): void
    {
        $container = self::CONTAINERS[$engine] ?? '';
        $containerId = $container === '' ? null : DockerExec::containerIdByName($container);
        if ($containerId === null || DockerExec::startDetachedExec($containerId, $cmd) === '') {
            $this->indexModel()->mark($id, 'failed');
            throw new HttpException('database.container_stopped', 409);
        }
    }

    /** @return array<string, mixed>|null */
    private function serverKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = (string) $value;
        if (!preg_match('/^SERVER_NAME\d+$/', $key)) {
            throw new HttpException('error.server_missing', 404);
        }
        $servers = (new EnvConfig())->all();
        if (!isset($servers[$key])) {
            throw new HttpException('error.server_missing', 404);
        }

        return $key;
    }

    private function dump(): DatabaseDump
    {
        return new DatabaseDump(Config::projectPath());
    }

    private function indexModel(): DatabaseDumpIndex
    {
        return new DatabaseDumpIndex($this->dump()->backupsRoot());
    }
}
