<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Example\Observability;

use ModernORM\Bundle\ModernORMBundle;
use ModernORM\Bundle\Observability\OpenTelemetry\FlushMetrics;
use ModernORM\Bundle\Observability\OpenTelemetry\OperationMetrics;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('prototype', false);
    }
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ModernORMBundle();
    }
    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
    public function getCacheDir(): string
    {
        return getenv('MODERNORM_OTEL_CACHE_DIR') ?: sys_get_temp_dir().'/modernorm-otel-prototype-cache';
    }
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/modernorm-otel-prototype-log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'local-prototype', 'router' => ['utf8' => true]]);
        $container->extension('modernorm', [
            'url' => '%env(resolve:MODERNORM_TEST_URL)%',
            'server_version' => '17',
            'mapping_paths' => [__DIR__.'/Entity'],
            'statistics' => ['enabled' => true],
            'profiler' => ['enabled' => false],
            'l2_cache' => ['pool' => 'cache.app', 'namespace' => 'otel-prototype'],
        ]);
        $services = $container->services();
        $services->set('prototype.provider', MeterProvider::class)->factory([MetricsProvider::class, 'create'])->public();
        $services->set('prototype.meter', \OpenTelemetry\API\Metrics\MeterInterface::class)
            ->factory([new Reference('prototype.provider'), 'getMeter'])->args(['modernorm']);
        $services->set(OperationMetrics::class)->args([new Reference('prototype.meter')])->tag('modernorm.statistics_observer');
        $services->set(\ModernORM\Bundle\Observability\OpenTelemetry\CacheMetrics::class)->args([new Reference('prototype.meter'), [Entity\Product::class => 'product']])->tag('modernorm.cache_logger');
        $services->set(FlushMetrics::class)->args([new Reference('prototype.flush_callback')])->tag('kernel.event_subscriber')->public();
        // The invokable service flushes the SDK at lifecycle boundaries, never inside a SQL callback.
        $services->set('prototype.flush_callback', ProviderFlush::class)->args([new Reference('prototype.provider')]);
        $services->set(Workload::class)->args([new Reference('modernorm.entity_manager'), new Reference('modernorm.statistics'), new Reference('modernorm.cache_logger')])->public();
        $services->set('prototype.resetter', Resetter::class)->args([new Reference('modernorm.entity_manager_factory'), new Reference('modernorm.statistics'), new Reference('modernorm.cache_logger')])->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('workload', '/workload')->controller([Workload::class, '__invoke']);
    }
}
