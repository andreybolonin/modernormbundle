<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use ModernORM\Bundle\Example\Observability\Kernel;
use ModernORM\Bundle\Example\Observability\Workload;
use ModernORM\Bundle\Observability\OpenTelemetry\FlushMetrics;

$kernel = new Kernel();
$kernel->boot();
$container = $kernel->getContainer();
$intervalMillis = max(0, min(60000, (int) ($argv[2] ?? 0)));
$interactive = ($argv[1] ?? '') === '--interactive';
$jobs = $interactive ? PHP_INT_MAX : max(1, min(100, (int) ($argv[1] ?? 3)));
for ($i = 0; $i < $jobs; ++$i) {
    if ($interactive && trim((string) fgets(STDIN)) !== 'job') {
        break;
    }
    $result = $container->get(Workload::class)->run();
    $result['exported'] = $container->get(FlushMetrics::class)->flush();
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
    $container->get('prototype.resetter')->reset();
    if (!$interactive && $intervalMillis > 0 && $i + 1 < $jobs) {
        usleep($intervalMillis * 1000);
    }
}
$container->get('prototype.provider')->shutdown();
$kernel->shutdown();
