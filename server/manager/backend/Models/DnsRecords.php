<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;
use Manager\Support\Config;
use Manager\Support\JsonFile;

/**
 * Custom names for containers only (e.g. point api.partner.com at a mock container).
 * A leading "*." matches every subdomain (not the name itself). Stored in runtime/dns-records.json,
 * rendered to runtime/coredns/records.conf, which the Corefile imports (picked up by its reload plugin).
 */
final class DnsRecords
{
    private const MAX = 200;

    private readonly string $runtimePath;

    public function __construct(?string $runtimePath = null)
    {
        $this->runtimePath = rtrim($runtimePath ?? Config::runtimePath(), '/');
    }

    /** @return list<array{name: string, target: string}> */
    public function all(): array
    {
        $records = JsonFile::readObject($this->runtimePath . '/dns-records.json')['records'] ?? [];

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
    }

    /**
     * @param mixed $input list of {name, target}
     * @param list<string> $siteTlds names under these already go to nginx
     * @return list<array{name: string, target: string}>
     */
    public function save(mixed $input, array $siteTlds): array
    {
        $records = self::validate($input, $siteTlds);
        $json = json_encode(['records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!AtomicFile::write($this->runtimePath . '/dns-records.json', $json . "\n")
            || !AtomicFile::write($this->runtimePath . '/coredns/records.conf', self::render($records))) {
            throw new HttpException('dns.record.write_failed', 500);
        }

        return $records;
    }

    /**
     * @param list<string> $siteTlds
     * @return list<array{name: string, target: string}>
     */
    public static function validate(mixed $input, array $siteTlds): array
    {
        if (!is_array($input) || count($input) > self::MAX) {
            throw new HttpException('dns.record.too_many', 422, [], ['max' => self::MAX]);
        }
        $records = [];
        foreach ($input as $row) {
            $name = strtolower(trim((string) (is_array($row) ? ($row['name'] ?? '') : '')));
            $target = strtolower(trim((string) (is_array($row) ? ($row['target'] ?? '') : '')));
            $base = str_starts_with($name, '*.') ? substr($name, 2) : $name;
            // At least two labels (after "*." for a wildcard), so "*.com" cannot take over a whole TLD.
            if (!self::isHostname($base) || !str_contains($base, '.')) {
                throw new HttpException('dns.record.invalid_name', 422, [], ['name' => $name]);
            }
            if (in_array(substr($name, strrpos($name, '.') + 1), $siteTlds, true)) {
                throw new HttpException('dns.record.site_tld', 422, [], ['name' => $name]);
            }
            if (filter_var($target, FILTER_VALIDATE_IP) === false) {
                // Container targets go through Docker DNS, which does not know local site domains.
                if (!self::isHostname($target) || in_array(substr($target, strrpos($target, '.') + 1), $siteTlds, true)) {
                    throw new HttpException('dns.record.invalid_target', 422, [], ['target' => $target]);
                }
            }
            if (isset($records[$name])) {
                throw new HttpException('dns.record.duplicate', 422, [], ['name' => $name]);
            }
            $records[$name] = ['name' => $name, 'target' => $target];
        }

        return array_values($records);
    }

    /** @param list<array{name: string, target: string}> $records */
    public static function render(array $records): string
    {
        $rewrites = [];
        $hosts = [];
        $templates = [];
        foreach ($records as $record) {
            $isIp = filter_var($record['target'], FILTER_VALIDATE_IP) !== false;
            if (str_starts_with($record['name'], '*.')) {
                $base = substr($record['name'], 2);
                $regex = '^(.+)\\.' . str_replace('.', '\\.', $base) . '\\.$';
                if (!$isIp) {
                    $rewrites[] = 'rewrite name regex ' . $regex . ' ' . $record['target'] . '. answer auto';
                    continue;
                }
                // Answer the target's family; the other one empty so clients cannot reach the real host over it.
                $v6 = str_contains($record['target'], ':');
                foreach (['A' => !$v6, 'AAAA' => $v6] as $type => $answer) {
                    $body = $answer
                        ? '    answer "{{ .Name }} 60 IN ' . $type . ' ' . $record['target'] . '"'
                        : '    rcode NOERROR';
                    $templates[] = "template IN {$type} {$base} {\n    match \"{$regex}\"\n{$body}\n    fallthrough\n}";
                }
            } elseif ($isIp) {
                $hosts[] = '    ' . $record['target'] . ' ' . $record['name'];
            } else {
                $rewrites[] = 'rewrite name exact ' . $record['name'] . '. ' . $record['target'] . '. answer auto';
            }
        }
        $out = "# Generated by Manager (DNS records). Do not edit.\n";
        if ($rewrites !== []) {
            $out .= implode("\n", $rewrites) . "\n";
        }
        if ($templates !== []) {
            $out .= implode("\n", $templates) . "\n";
        }
        if ($hosts !== []) {
            // fallthrough: names not listed here continue to forward.
            $out .= "hosts {\n" . implode("\n", $hosts) . "\n    fallthrough\n}\n";
        }

        return $out;
    }

    /** Letters, digits, '-', '_' and dots only: values are written verbatim into the Corefile. */
    public static function isHostname(string $value): bool
    {
        return strlen($value) <= 253
            && preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $value) === 1;
    }
}
