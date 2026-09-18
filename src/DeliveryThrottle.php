<?php
declare(strict_types=1);

namespace MiniKbp;

/**
 * Response delivery stays at full speed (local cache / HTML / JSON).
 * Upstream pacing (+1%/−1% per second) lives in UpstreamRateLimiter.
 *
 * Kept for API compatibility (begin/end/send/shared) and tests of the old ladder helpers.
 */
final class DeliveryThrottle
{
    /** @var list<float> */
    public const STEPS = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];

    public const DEFAULT_SPEED = 1.0;
    /** Unused for body delivery; retained so older tests/constants keep meaning. */
    public const HIGH_CONCURRENT = 32;
    public const RECOVER_CONCURRENT = 8;
    public const RECOVER_AFTER = 1;

    private static ?self $shared = null;
    private bool $active = false;

    /** @param callable(float):void|null $sleeper */
    /** @param callable(string):void|null $writer */
    public function __construct(
        private readonly string $statePath,
        private $sleeper = null,
        private $writer = null,
    ) {
    }

    public static function shared(): self
    {
        if (self::$shared === null) {
            self::$shared = new self(Bootstrap::cacheDir() . '/delivery_throttle.json');
        }
        return self::$shared;
    }

    /** @internal tests */
    public static function setShared(?self $instance): void
    {
        self::$shared = $instance;
    }

    public function currentSpeed(): float
    {
        return self::DEFAULT_SPEED;
    }

    public function concurrent(): int
    {
        $state = $this->withLock(static fn (array $s): array => $s);
        return (int) ($state['concurrent'] ?? 0);
    }

    public function begin(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        $this->withLock(function (array $state): array {
            $state['concurrent'] = (int) ($state['concurrent'] ?? 0) + 1;
            $state['speed'] = self::DEFAULT_SPEED;
            $state['updatedAt'] = microtime(true);
            return $state;
        });

        register_shutdown_function(function (): void {
            $this->end();
        });
    }

    public function end(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->withLock(function (array $state): array {
            $state['concurrent'] = max(0, (int) ($state['concurrent'] ?? 0) - 1);
            $state['speed'] = self::DEFAULT_SPEED;
            $state['updatedAt'] = microtime(true);
            return $state;
        });
    }

    /** Always full-speed write — local cache and responses must not trickle. */
    public function send(string $body): void
    {
        $this->write($body);
    }

    private function write(string $chunk): void
    {
        if ($this->writer !== null) {
            ($this->writer)($chunk);
            return;
        }
        echo $chunk;
        if (function_exists('flush')) {
            @flush();
        }
    }

    public function stepDown(float $speed): float
    {
        $idx = $this->stepIndex($speed);
        return self::STEPS[max(0, $idx - 1)];
    }

    public function stepUp(float $speed): float
    {
        $idx = $this->stepIndex($speed);
        return self::STEPS[min(count(self::STEPS) - 1, $idx + 1)];
    }

    private function normalizeSpeed(float $speed): float
    {
        if ($speed <= 0) {
            return self::STEPS[0];
        }
        $best = self::STEPS[0];
        $bestDist = PHP_FLOAT_MAX;
        foreach (self::STEPS as $step) {
            $dist = abs($step - $speed);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $step;
            }
        }
        return $best;
    }

    private function stepIndex(float $speed): int
    {
        $norm = $this->normalizeSpeed($speed);
        $idx = array_search($norm, self::STEPS, true);
        return is_int($idx) ? $idx : (count(self::STEPS) - 1);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    /** @return array<string,mixed> */
    private function withLock(callable $mutator): array
    {
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = fopen($this->statePath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('delivery throttle state open failed');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('delivery throttle lock failed');
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
            if (!isset($state['speed'])) {
                $state['speed'] = self::DEFAULT_SPEED;
            }
            if (!isset($state['concurrent'])) {
                $state['concurrent'] = 0;
            }

            $updatedAt = (float) ($state['updatedAt'] ?? 0);
            if ($updatedAt > 0 && (microtime(true) - $updatedAt) > 120) {
                $state['concurrent'] = 0;
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
}
