<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Contracts\Service\ResetInterface;

/** SQL text only, never bindings or exception messages. No history outside a profiled request. */
final class SqlHistory implements ResetInterface
{
    private bool $active = false;
    private int $generation = 0;
    private array $queries = [];
    private int $dropped = 0;

    public function __construct(private readonly bool $enabled, private readonly int $limit, private readonly int $maxSqlLength, private readonly ?\Closure $profiler = null)
    {
    }

    public function startRequest(): void
    {
        $this->reset();
        $this->active = true;
    }
    public function stopRequest(): void
    {
        $this->active = false;
    }
    public function start(): ?array
    {
        if (!$this->enabled || !$this->active || $this->profiler === null) {
            return null;
        }
        $profiler = ($this->profiler)();
        return $profiler instanceof Profiler && $profiler->isEnabled() ? [$this->generation, hrtime(true)] : null;
    }
    public function record(array $measurement, string $sql, string $operation, ?\Throwable $error): void
    {
        if ($measurement[0] !== $this->generation || !$this->active) {
            return;
        }
        $duration = hrtime(true) - $measurement[1];
        if (count($this->queries) >= $this->limit) {
            ++$this->dropped;
            return;
        }
        $this->queries[] = [
            'sql' => $this->clip($sql),
            'fingerprint' => hash('sha256', $sql),
            'truncated' => strlen($sql) > $this->maxSqlLength,
            'durationMilliseconds' => $duration / 1_000_000,
            'operation' => $operation,
            'failed' => $error !== null,
            'sqlState' => $error instanceof \Doctrine\DBAL\Driver\Exception ? $error->getSQLState() : null,
        ];
    }
    public function clip(string $sql): string
    {
        // JSON/profile storage requires valid UTF-8 even when an input SQL string was cut mid-character.
        $text = substr($sql, 0, $this->maxSqlLength);
        for ($i = 0; $i < 4; ++$i) {
            if (preg_match('//u', $text) === 1) {
                return $text;
            }
            $text = substr($text, 0, -1);
        }
        return '[SQL contains invalid UTF-8]';
    }
    public function queries(): array
    {
        return $this->queries;
    }
    public function dropped(): int
    {
        return $this->dropped;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function reset(): void
    {
        ++$this->generation;
        $this->active = false;
        $this->queries = [];
        $this->dropped = 0;
    }
}
