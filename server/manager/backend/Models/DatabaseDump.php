<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;

final class DatabaseDump
{
    public function __construct(private readonly string $projectPath)
    {
    }

    public function backupsRoot(): string
    {
        return rtrim($this->projectPath, '/') . '/server/backups';
    }

    public function relativeFile(string $engine, string $database): string
    {
        if (!in_array($engine, ['mysql', 'postgres'], true)) {
            throw new HttpException('database.invalid_engine', 400);
        }
        (new DatabaseDumpIndex($this->backupsRoot()))->assertDatabaseName($database);

        return $engine . '/' . $database . '-' . gmdate('Ymd\THis\Z') . '.sql';
    }

    /** @return list<string> */
    public function command(string $engine, string $database, string $relativeFile): array
    {
        return ['sh', '-c', $this->script($engine, $database, $relativeFile, false)];
    }

    /** @return list<string> */
    public function restoreCommand(string $engine, string $database, string $relativeFile): array
    {
        return ['sh', '-c', $this->script($engine, $database, $relativeFile, true)];
    }

    public function deleteFiles(string $relativeFile): void
    {
        if (!preg_match('#^(mysql|postgres)/[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.sql$#', $relativeFile)) {
            throw new HttpException('database.invalid_file', 400);
        }
        $root = $this->backupsRoot();
        foreach ([$relativeFile, $relativeFile . '.exit', $relativeFile . '.restore.exit'] as $name) {
            $path = $root . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param array<string, mixed> $row */
    public function syncStatus(array $row): array
    {
        $index = new DatabaseDumpIndex($this->backupsRoot());
        if (($row['status'] ?? '') !== 'pending') {
            return $row;
        }
        $file = (string) ($row['file'] ?? '');
        if (!preg_match('#^(mysql|postgres)/[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.sql$#', $file)) {
            return $row;
        }
        $root = $this->backupsRoot();
        $restore = $root . '/' . $file . '.restore.exit';
        $dump = $root . '/' . $file . '.exit';
        $exitPath = is_file($restore) ? $restore : $dump;
        if (!is_file($exitPath)) {
            return $row;
        }
        $code = trim((string) file_get_contents($exitPath));

        return $index->mark((string) $row['id'], $code === '0' ? 'ready' : 'failed');
    }

    private function script(string $engine, string $database, string $relativeFile, bool $restore): string
    {
        if (!in_array($engine, ['mysql', 'postgres'], true)) {
            throw new HttpException('database.invalid_engine', 400);
        }
        (new DatabaseDumpIndex($this->backupsRoot()))->assertDatabaseName($database);
        if (!preg_match('#^(mysql|postgres)/[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.sql$#', $relativeFile)
            || !str_starts_with($relativeFile, $engine . '/')) {
            throw new HttpException('database.invalid_file', 400);
        }
        $dest = '/backups/' . $relativeFile;
        $exit = $dest . ($restore ? '.restore.exit' : '.exit');
        $dir = escapeshellarg(dirname($dest));
        $destArg = escapeshellarg($dest);
        $exitArg = escapeshellarg($exit);
        $dbArg = escapeshellarg($database);
        if ($engine === 'mysql') {
            $body = $restore
                ? "mysql -uroot -p1 < $destArg"
                : "mysqldump -uroot -p1 --single-transaction --databases $dbArg > $destArg";
        } else {
            $body = $restore
                ? "PGPASSWORD=1 psql -U postgres -d $dbArg < $destArg"
                : "PGPASSWORD=1 pg_dump -U postgres -d $dbArg > $destArg";
        }

        return "mkdir -p $dir && $body; echo \$? > $exitArg";
    }
}
