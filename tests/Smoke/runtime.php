<?php

declare(strict_types=1);

namespace ModernORM\Bundle\RuntimeSmoke;

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ModernORM\Bundle\ModernORMBundle;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping as ORM;
use ModernORM\Statistics;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class RuntimeKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ModernORMBundle();
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return sys_get_temp_dir().'/modernorm-bundle-runtime-'.getmypid(); }
    public function getLogDir(): string { return $this->getCacheDir().'/log'; }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'modernorm-runtime-smoke']);
        $container->extension('modernorm', [
            'url' => getenv('MODERNORM_TEST_URL') ?: throw new \RuntimeException('Set MODERNORM_TEST_URL.'),
            'server_version' => '17',
            'mapping_paths' => [],
            'statistics' => ['enabled' => true],
            'profiler' => ['enabled' => false],
        ]);
        $container->services()->alias('smoke.manager', 'modernorm.entity_manager')->public();
        $container->services()->alias('smoke.statistics', 'modernorm.statistics')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void {}
}

#[ORM\Entity]
#[ORM\Table(name: 'modernorm_runtime_smoke')]
class RuntimeEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    public int $id;
}

foreach ([
    \Symfony\Bundle\TwigBundle\TwigBundle::class,
    \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class,
    \Doctrine\Migrations\DependencyFactory::class,
    \Doctrine\Common\DataFixtures\Loader::class,
    \Doctrine\ORM\EntityManagerInterface::class,
] as $optional) {
    if (class_exists($optional) || interface_exists($optional)) {
        throw new \RuntimeException('Run smoke after composer install --no-dev: '.$optional.' is still installed.');
    }
}

$kernel = new RuntimeKernel('prod', false);
try {
    $kernel->boot();
    $manager = $kernel->getContainer()->get('smoke.manager');
    $statistics = $kernel->getContainer()->get('smoke.statistics');
    if (!$manager instanceof EntityManagerInterface || !$statistics instanceof Statistics) {
        throw new \RuntimeException('ModernORM services were not registered.');
    }
    if ($manager->getClassMetadata(RuntimeEntity::class)->getTableName() !== 'modernorm_runtime_smoke') {
        throw new \RuntimeException('Attribute metadata failed.');
    }
    if ((int) $manager->getConnection()->fetchOne('SELECT 42') !== 42 || $statistics->snapshot()->sqlCount !== 1) {
        throw new \RuntimeException('PostgreSQL connection or SQL statistics failed.');
    }
    $manager->close();
    $kernel->getContainer()->get('services_resetter')->reset();
    if (!$manager->isOpen() || $statistics->snapshot()->sqlCount !== 0) {
        throw new \RuntimeException('Closed EntityManager recovery or statistics reset failed.');
    }
    echo "ModernORMBundle production runtime smoke passed.\n";
} finally {
    $kernel->shutdown();
    (new Filesystem())->remove($kernel->getCacheDir());
}
