<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;

final class DatabaseDumpIndex
{
    public function __construct(private readonly string $rootDir)
    {
    }

    public function assertDatabaseName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new HttpException('database.invalid_name', 400);
        }

        return $name;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = $this->read()['dumps'] ?? [];

        return array_values(is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed> */
    public function add(string $engine, string $database, ?string $serverKey, string $file): array
    {
        if (!in_array($engine, ['mysql', 'postgres'], true)) {
            throw new HttpException('database.invalid_engine', 400);
        }
        $this->assertDatabaseName($database);
        if (!preg_match('#^(mysql|postgres)/[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.sql$#', $file)) {
            throw new HttpException('database.invalid_file', 400);
        }
        $data = $this->read();
        $row = [
            'id' => bin2hex(random_bytes(8)),
            'engine' => $engine,
            'database' => $database,
            'server_key' => $serverKey,
            'file' => $file,
            'created_at' => gmdate('c'),
            'status' => 'pending',
        ];
        $data['dumps'][] = $row;
        $this->write($data);

        return $row;
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        foreach ($this->list() as $row) {
            if (($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        throw new HttpException('database.dump_missing', 404);
    }

    /** @return array<string, mixed> */
    public function mark(string $id, string $status): array
    {
        if (!in_array($status, ['pending', 'ready', 'failed'], true)) {
            throw new HttpException('database.invalid_status', 400);
        }
        $data = $this->read();
        foreach ($data['dumps'] as $i => $row) {
            if (($row['id'] ?? '') === $id) {
                $data['dumps'][$i]['status'] = $status;
                $this->write($data);

                return $data['dumps'][$i];
            }
        }
        throw new HttpException('database.dump_missing', 404);
    }

    private function path(): string
    {
        return rtrim($this->rootDir, '/') . '/index.json';
    }

    /** @return array{dumps: list<array<string, mixed>>} */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['dumps' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && is_array($decoded['dumps'] ?? null)
            ? $decoded
            : ['dumps' => []];
    }

    /** @param array{dumps: list<array<string, mixed>>} $data */
    private function write(array $data): void
    {
        $dir = rtrim($this->rootDir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException('database.index_write_failed', 500);
        }
        $ok = AtomicFile::write(
            $this->path(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
        if (!$ok) {
            throw new HttpException('database.index_write_failed', 500);
        }
    }
}
