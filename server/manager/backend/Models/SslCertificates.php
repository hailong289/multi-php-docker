<?php

declare(strict_types=1);

namespace Manager\Models;

use Manager\Http\HttpException;
use Manager\Support\AtomicFile;

final class SslCertificates
{
    public const MODE_GENERATED = 'generated';
    public const MODE_UPLOADED = 'uploaded';
    public const MAX_PEM_BYTES = 262144;

    public function __construct(private readonly string $rootDir)
    {
    }

    public static function isEnabled(array $server): bool
    {
        return EnvConfig::isEnabled(['ENABLED' => $server['SSL_ENABLED'] ?? false]);
    }

    public static function mode(array $server): ?string
    {
        if (!self::isEnabled($server)) {
            return null;
        }
        $mode = strtolower(trim((string) ($server['SSL_MODE'] ?? self::MODE_GENERATED)));
        if ($mode === self::MODE_UPLOADED) {
            return self::MODE_UPLOADED;
        }

        return self::MODE_GENERATED;
    }

    public function directoryFor(string $appName): string
    {
        $this->assertSafeAppName($appName);

        return rtrim($this->rootDir, '/') . '/nginx/ssl/' . $appName;
    }

    public function filesPresent(string $appName): bool
    {
        $dir = $this->directoryFor($appName);

        return is_file($dir . '/cert.pem') && is_readable($dir . '/cert.pem')
            && is_file($dir . '/key.pem') && is_readable($dir . '/key.pem');
    }

    public function namesMatch(string $appName, string $domainName): bool
    {
        if (!$this->filesPresent($appName)) {
            return false;
        }
        $certPem = (string) file_get_contents($this->directoryFor($appName) . '/cert.pem');

        return $this->certificateCoversDomain($certPem, $domainName);
    }

    public function generate(string $appName, string $domainName): void
    {
        $this->assertSafeAppName($appName);
        $domainName = strtolower(trim($domainName));
        if ($domainName === '') {
            throw new HttpException('validation.domain', 422, [
                'domain_name' => ['key' => 'validation.domain'],
            ]);
        }

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privateKey === false) {
            throw new HttpException('error.ssl_write', 422);
        }

        $configPath = $this->writeOpensslConfig($domainName);
        try {
            $csr = openssl_csr_new(
                ['commonName' => $domainName],
                $privateKey,
                ['digest_alg' => 'sha256', 'config' => $configPath, 'req_extensions' => 'v3_req']
            );
            if ($csr === false) {
                throw new HttpException('error.ssl_write', 422);
            }
            $cert = openssl_csr_sign(
                $csr,
                null,
                $privateKey,
                825,
                ['digest_alg' => 'sha256', 'config' => $configPath, 'x509_extensions' => 'v3_req']
            );
            if ($cert === false) {
                throw new HttpException('error.ssl_write', 422);
            }
            if (!openssl_x509_export($cert, $certPem) || !openssl_pkey_export($privateKey, $keyPem)) {
                throw new HttpException('error.ssl_write', 422);
            }
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }
        }

        $this->writePair($appName, $certPem, $keyPem);
    }

    public function writeUploaded(string $appName, string $certificatePem, string $privateKeyPem): void
    {
        $this->assertSafeAppName($appName);
        $certificatePem = trim($certificatePem);
        $privateKeyPem = trim($privateKeyPem);

        if ($certificatePem === '' || strlen($certificatePem) > self::MAX_PEM_BYTES
            || !str_contains($certificatePem, 'BEGIN CERTIFICATE')) {
            throw new HttpException('validation.failed', 422, [
                'ssl_certificate' => ['key' => 'validation.ssl_certificate'],
            ]);
        }
        if ($privateKeyPem === '' || strlen($privateKeyPem) > self::MAX_PEM_BYTES
            || !preg_match('/BEGIN (?:RSA )?PRIVATE KEY|BEGIN ENCRYPTED PRIVATE KEY/', $privateKeyPem)) {
            throw new HttpException('validation.failed', 422, [
                'ssl_private_key' => ['key' => 'validation.ssl_private_key'],
            ]);
        }

        $cert = openssl_x509_read($certificatePem);
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($cert === false) {
            throw new HttpException('validation.failed', 422, [
                'ssl_certificate' => ['key' => 'validation.ssl_certificate'],
            ]);
        }
        if ($key === false) {
            throw new HttpException('validation.failed', 422, [
                'ssl_private_key' => ['key' => 'validation.ssl_private_key'],
            ]);
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new HttpException('validation.failed', 422, [
                'ssl_private_key' => ['key' => 'validation.ssl_key_mismatch'],
            ]);
        }

        $this->writePair($appName, $certificatePem . "\n", $privateKeyPem . "\n");
    }

    public function persist(array $previous, array $next, string $certificatePem = '', string $privateKeyPem = ''): void
    {
        $nextName = (string) ($next['APP_NAME'] ?? '');
        $previousName = (string) ($previous['APP_NAME'] ?? '');
        if ($previousName !== '' && $nextName !== '' && strcasecmp($previousName, $nextName) !== 0) {
            $this->renameApp($previousName, $nextName);
        }

        if (!self::isEnabled($next)) {
            return;
        }

        $mode = self::mode($next) ?? self::MODE_GENERATED;
        $domain = strtolower(trim((string) ($next['DOMAIN_NAME'] ?? '')));
        $previousDomain = strtolower(trim((string) ($previous['DOMAIN_NAME'] ?? '')));
        $hasPems = trim($certificatePem) !== '' && trim($privateKeyPem) !== '';

        if ($hasPems) {
            $this->writeUploaded($nextName, $certificatePem, $privateKeyPem);
            return;
        }

        if ($mode === self::MODE_UPLOADED) {
            return;
        }

        $needsGenerate = !$this->filesPresent($nextName)
            || $previousDomain === ''
            || $previousDomain !== $domain
            || !$this->namesMatch($nextName, $domain);
        if ($needsGenerate) {
            $this->generate($nextName, $domain);
        }
    }

    public function deleteApp(string $appName): void
    {
        if ($appName === '') {
            return;
        }
        $dir = $this->directoryFor($appName);
        if (!is_dir($dir)) {
            return;
        }
        foreach (['cert.pem', 'key.pem'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function enrich(array $server): array
    {
        $appName = (string) ($server['APP_NAME'] ?? '');
        $domain = strtolower(trim((string) ($server['DOMAIN_NAME'] ?? '')));
        $enabled = self::isEnabled($server);
        $present = $appName !== '' && $this->filesPresent($appName);
        $server['ssl_enabled'] = $enabled;
        $server['ssl_mode'] = self::mode($server);
        $server['ssl_files_present'] = $present;
        $server['ssl_names_match'] = !$enabled || ($present && $this->namesMatch($appName, $domain));
        unset($server['ssl_certificate'], $server['ssl_private_key'], $server['SSL_CERTIFICATE'], $server['SSL_PRIVATE_KEY']);

        return $server;
    }

    private function renameApp(string $oldAppName, string $newAppName): void
    {
        $this->assertSafeAppName($oldAppName);
        $this->assertSafeAppName($newAppName);
        $from = $this->directoryFor($oldAppName);
        $to = $this->directoryFor($newAppName);
        if (!is_dir($from)) {
            return;
        }
        if (is_dir($to)) {
            $this->deleteApp($newAppName);
        }
        $parent = dirname($to);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new HttpException('error.ssl_write', 500);
        }
        if (!@rename($from, $to)) {
            throw new HttpException('error.ssl_write', 500);
        }
    }

    private function writePair(string $appName, string $certPem, string $keyPem): void
    {
        $dir = $this->directoryFor($appName);
        if (!AtomicFile::write($dir . '/cert.pem', $certPem) || !AtomicFile::write($dir . '/key.pem', $keyPem)) {
            throw new HttpException('error.ssl_write', 500);
        }
        @chmod($dir . '/cert.pem', 0644);
        @chmod($dir . '/key.pem', 0644);
    }

    private function writeOpensslConfig(string $domainName): string
    {
        $path = sys_get_temp_dir() . '/mgr-ssl-' . bin2hex(random_bytes(8)) . '.cnf';
        $safe = str_replace(['\\', '"'], ['\\\\', '\\"'], $domainName);
        $cnf = <<<CNF
[ req ]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no
[ req_distinguished_name ]
CN = {$safe}
[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:{$safe}
CNF;
        if (file_put_contents($path, $cnf) === false) {
            throw new HttpException('error.ssl_write', 422);
        }

        return $path;
    }

    private function certificateCoversDomain(string $certPem, string $domainName): bool
    {
        $domainName = strtolower(trim($domainName));
        $parsed = openssl_x509_parse($certPem);
        if (!is_array($parsed) || $domainName === '') {
            return false;
        }
        $names = [];
        $cn = $parsed['subject']['CN'] ?? '';
        if (is_string($cn) && $cn !== '') {
            $names[] = strtolower($cn);
        }
        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        foreach (preg_split('/\s*,\s*/', $san) ?: [] as $entry) {
            if (stripos($entry, 'DNS:') === 0) {
                $names[] = strtolower(substr($entry, 4));
            }
        }

        return in_array($domainName, $names, true);
    }

    private function assertSafeAppName(string $appName): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/', $appName)) {
            throw new HttpException('validation.failed', 422, [
                'app_name' => ['key' => 'validation.app_name'],
            ]);
        }
    }
}
