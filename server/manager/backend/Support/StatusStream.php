<?php

declare(strict_types=1);

namespace Manager\Support;

final class StatusStream
{
    private const LIFETIME_SECONDS = 1200;
    private const PING_SECONDS = 15;
    private const SLEEP_BUSY_US = 1_000_000;
    private const SLEEP_IDLE_US = 2_500_000;

    /**
     * @param callable(): array<string, mixed> $payloadFactory
     * @param callable(array<string, mixed>): bool|null $isBusy
     */
    public static function emitLoop(callable $payloadFactory, ?callable $isBusy = null): void
    {
        self::begin();
        $lastHash = '';
        $lastPing = time();
        $started = time();
        while (!connection_aborted()) {
            if (self::maybeReconnect($started)) {
                return;
            }

            $payload = $payloadFactory();
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = md5($json);
            if ($hash !== $lastHash) {
                $lastHash = $hash;
                echo 'id: ' . $hash . "\n";
                echo 'data: ' . $json . "\n\n";
                flush();
            }

            $lastPing = self::maybePing($lastPing);
            $busy = $isBusy !== null && $isBusy($payload);
            usleep($busy ? self::SLEEP_BUSY_US : self::SLEEP_IDLE_US);
        }
    }

    /**
     * One connection, many named SSE events (event: servers|nginx|...).
     * Avoids exhausting php -S workers when many subsystems are live.
     *
     * @param array<string, array{
     *     factory: callable(): array<string, mixed>,
     *     busy?: callable(array<string, mixed>): bool,
     *     fingerprint?: callable(array<string, mixed>): mixed
     * }> $channels
     */
    public static function emitNamedLoop(array $channels): void
    {
        self::begin();
        /** @var array<string, string> $lastHashes */
        $lastHashes = [];
        $lastPing = time();
        $started = time();
        while (!connection_aborted()) {
            if (self::maybeReconnect($started)) {
                return;
            }

            $anyBusy = false;
            foreach ($channels as $name => $channel) {
                $factory = $channel['factory'];
                $payload = $factory();
                $fingerprint = $channel['fingerprint'] ?? null;
                $hashSource = is_callable($fingerprint) ? $fingerprint($payload) : $payload;
                $jsonHash = json_encode($hashSource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $hash = md5($jsonHash);
                if (($lastHashes[$name] ?? '') !== $hash) {
                    $lastHashes[$name] = $hash;
                    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    echo 'event: ' . $name . "\n";
                    echo 'id: ' . $name . '-' . $hash . "\n";
                    echo 'data: ' . $json . "\n\n";
                    flush();
                }
                $busyFn = $channel['busy'] ?? null;
                if (is_callable($busyFn) && $busyFn($payload)) {
                    $anyBusy = true;
                }
            }

            $lastPing = self::maybePing($lastPing);
            usleep($anyBusy ? self::SLEEP_BUSY_US : self::SLEEP_IDLE_US);
        }
    }

    /** @param array<string, mixed>|list<mixed> $statuses */
    public static function mapHasBusyState(array $statuses): bool
    {
        foreach ($statuses as $row) {
            if (is_array($row) && ($row['state'] ?? '') === 'busy') {
                return true;
            }
        }

        return false;
    }

    private static function begin(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        echo ':' . str_repeat(' ', 2048) . "\n\n";
        flush();
    }

    private static function maybeReconnect(int $started): bool
    {
        if ((time() - $started) <= self::LIFETIME_SECONDS) {
            return false;
        }
        echo "event: reconnect\ndata: {}\n\n";
        flush();

        return true;
    }

    private static function maybePing(int $lastPing): int
    {
        if ((time() - $lastPing) < self::PING_SECONDS) {
            return $lastPing;
        }
        echo ": ping\n\n";
        flush();

        return time();
    }
}
