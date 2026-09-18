<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Upstream (kbp.by) pacing for API fetches only — never touches HTML.
 *
 * Every second with upstream activity → +1% slowdown.
 * Every idle second → −1% (recover).
 * If the wait would exceed MAX_WAIT, fail fast (503 via exception) instead of
 * parking PHP workers — that used to make the whole site look dead.
 */
final class UpstreamRateLimiter
{
    /** Floor interval at 0% slowdown. */
    public const MIN_INTERVAL = 0.05;

    /** Cap slowdown (90%). */
    public const MAX_SLOWDOWN = 0.90;

    /**
     * Never sleep longer than this. Above → throw, API returns busy.
     * Keeps worker pool free so / and static assets stay up.
     */
    public const MAX_WAIT = 0.35;

    /** Above this slowdown, reject new upstream work immediately. */
    public const REJECT_SLOWDOWN = 0.50;

    /** @param callable(float):void|null $sleeper */
    /** @param callable():float|null $clock */
    public function __construct(
        private readonly string $statePath,
        private $sleeper = null,
        private $clock = null,
    ) {
    }

    public static function defaultPath(): string
    {
        return Bootstrap::cacheDir() . '/kbp_rate_limit.json';
    }

    /**
     * @throws \RuntimeException when upstream is overloaded (caller → API 503)
     */
    public function acquire(): void
    {
        $wait = 0.0;
        $reject = false;
        $this->withLock(function (array $state) use (&$wait, &$reject): array {
            $now = $this->now();
            $state = $this->applyTicks($state, $now);

            $slowdown = (float) ($state['slowdown'] ?? 0.0);
            if ($slowdown >= self::REJECT_SLOWDOWN) {
                $state['activeUntil'] = (int) floor($now) + 1;
                $state['updatedAt'] = $now;
                $reject = true;
                return $state;
            }

            $factor = max(1.0 - self::MAX_SLOWDOWN, 1.0 - $slowdown);
            $interval = self::MIN_INTERVAL / $factor;

            $nextAllowed = (float) ($state['nextAllowed'] ?? 0.0);
            $wait = max(0.0, $nextAllowed - $now);
            if ($wait > self::MAX_WAIT) {
                $state['activeUntil'] = (int) floor($now) + 1;
                $state['updatedAt'] = $now;
                $reject = true;
                return $state;
            }

            $state['nextAllowed'] = max($now, $nextAllowed) + $interval;
            $state['activeUntil'] = (int) floor($now) + 1;
            $state['updatedAt'] = $now;
            return $state;
        });
        if ($reject) {
            throw new \RuntimeException('upstream busy');
        }
        if ($wait > 0) {
            $this->sleep($wait);
        }
    }

    public function currentSlowdown(): float
    {
        $state = $this->withLock(function (array $state): array {
            return $this->applyTicks($state, $this->now());
        });
        return (float) ($state['slowdown'] ?? 0.0);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function applyTicks(array $state, float $now): array
    {
        $nowSec = (int) floor($now);
        $lastTick = (int) ($state['lastTick'] ?? $nowSec);
        $slowdown = (float) ($state['slowdown'] ?? 0.0);
        $activeUntil = (int) ($state['activeUntil'] ?? 0);

        if ($lastTick > $nowSec) {
            $lastTick = $nowSec;
        }

        for ($sec = $lastTick; $sec < $nowSec; $sec++) {
            if ($activeUntil > $sec) {
                $slowdown = min(self::MAX_SLOWDOWN, $slowdown + 0.01);
            } else {
                $slowdown = max(0.0, $slowdown - 0.01);
            }
        }

        $state['slowdown'] = round($slowdown, 4);
        $state['lastTick'] = $nowSec;
        if (!isset($state['activeUntil'])) {
            $state['activeUntil'] = 0;
        }
        if (!isset($state['nextAllowed'])) {
            $state['nextAllowed'] = 0.0;
        }
        return $state;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    /** @return array<string, mixed> */
    private function withLock(callable $mutator): array
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = fopen($this->statePath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('rate limiter state open failed');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('rate limiter lock failed');
            }
            rewind($fh);
            $raw = stream_get_contents($fh);
            $state = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $state = $decoded;
                }
            }

            $state = $mutator($state);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($fh);
            flock($fh, LOCK_UN);
            return $state;
        } finally {
            fclose($fh);
        }
    }

    private function now(): float
    {
        if ($this->clock !== null) {
            return (float) ($this->clock)();
        }
        return microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }
}
