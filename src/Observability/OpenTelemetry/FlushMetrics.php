<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Observability\OpenTelemetry;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** Explicit flush boundaries for synchronous PHP SDK readers; not a resettable service. */
final class FlushMetrics implements EventSubscriberInterface
{
    private int $failures = 0;

    private readonly \Closure $flush;

    public function __construct(callable $flush)
    {
        $this->flush = $flush(...);
    }

    public function flush(): bool
    {
        try {
            if (($this->flush)()) {
                return true;
            }
        } catch (\Throwable) {
            // Monitoring failures must not become application failures.
        }
        ++$this->failures;
        error_log('ModernORM OTLP metrics export failed; telemetry delivery is best effort.');
        return false;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public static function getSubscribedEvents(): array
    {
        // Terminate runs after the response is sent. Workers flush before Messenger resets services.
        return [
            KernelEvents::TERMINATE => ['flush', -3072],
            ConsoleEvents::TERMINATE => ['flush', -3072],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent' => ['flush', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent' => ['flush', -1024],
        ];
    }
}
