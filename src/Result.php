<?php

namespace Sixtec\Monitor;

/**
 * What a probe saw: one component (database, queues, disk…) with a status, a
 * short message and, optionally, numbers for the panel.
 *
 * The message leaves the server: never put a DSN, password, internal host or
 * a raw exception message in it.
 */
final class Result
{
    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly Status $status,
        public readonly ?string $message = null,
        public readonly ?int $latencyMs = null,
        public readonly array $metrics = [],
    ) {}

    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public static function ok(string $key, string $label, ?string $message = null, ?int $latencyMs = null, array $metrics = []): self
    {
        return new self($key, $label, Status::Ok, $message, $latencyMs, $metrics);
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public static function warning(string $key, string $label, ?string $message = null, ?int $latencyMs = null, array $metrics = []): self
    {
        return new self($key, $label, Status::Warning, $message, $latencyMs, $metrics);
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $metrics
     */
    public static function critical(string $key, string $label, ?string $message = null, ?int $latencyMs = null, array $metrics = []): self
    {
        return new self($key, $label, Status::Critical, $message, $latencyMs, $metrics);
    }

    public static function skipped(string $key, string $label, ?string $message = null): self
    {
        return new self($key, $label, Status::Skipped, $message);
    }

    public static function unknown(string $key, string $label, ?string $message = null): self
    {
        return new self($key, $label, Status::Unknown, $message);
    }

    /**
     * Status from a value and two thresholds (higher is worse). A null
     * threshold never fires.
     */
    public static function byThreshold(float $value, ?float $warning, ?float $critical): Status
    {
        return match (true) {
            $critical !== null && $value >= $critical => Status::Critical,
            $warning !== null && $value >= $warning => Status::Warning,
            default => Status::Ok,
        };
    }

    /**
     * @return array{key: string, label: string, status: string, message: ?string, latency_ms: ?int, metrics: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
            'metrics' => $this->metrics,
        ];
    }
}
