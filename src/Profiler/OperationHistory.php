<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Contracts\Service\ResetInterface;

/** History is bounded and exists only inside a profiled main HTTP request. */
final class OperationHistory implements OperationObserver, ResetInterface
{
    private bool $active = false;
    private array $events = [];
    private int $dropped = 0;

    public function __construct(private readonly int $limit, private readonly ?\Closure $profiler = null, private readonly iterable $observers = [])
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

    public function completed(OperationEvent $event): void
    {
        // External exporters can run without the Symfony Profiler or an HTTP request.
        $failure = null;
        foreach ($this->observers as $observer) {
            try {
                $observer->completed($event);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        $profiler = $this->active && $this->profiler !== null ? ($this->profiler)() : null;
        if ($profiler instanceof Profiler && $profiler->isEnabled()) {
            if (count($this->events) < $this->limit) {
                $this->events[] = $event;
            } else {
                ++$this->dropped;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function events(): array
    {
        return $this->events;
    }
    public function dropped(): int
    {
        return $this->dropped;
    }
    public function reset(): void
    {
        $this->active = false;
        $this->events = [];
        $this->dropped = 0;
    }
}
