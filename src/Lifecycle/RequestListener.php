<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Lifecycle;

use ModernORM\Bundle\Profiler\OperationHistory;
use ModernORM\Statistics;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestListener implements EventSubscriberInterface
{
    public function __construct(private readonly Statistics $statistics, private readonly StatisticsCacheLogger $cache, private readonly OperationHistory $history, private readonly \ModernORM\Bundle\Profiler\SqlHistory $sqlHistory)
    {
    }
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->statistics->reset();
        $this->cache->reset();
        $this->history->startRequest();
        $this->sqlHistory->startRequest();
    }
    public function onTerminate(TerminateEvent $event): void
    {
        $this->history->stopRequest();
        $this->sqlHistory->stopRequest();
    }
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 2048], KernelEvents::TERMINATE => ['onTerminate', -2048]];
    }
}
