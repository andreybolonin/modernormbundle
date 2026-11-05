<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use ModernORM\Bundle\DependencyInjection\ModernORMExtension;
use ModernORM\Bundle\Fixtures\Fixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SchemaConfigurationTest extends TestCase
{
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new ModernORMExtension())->load([$config], $container);
        return $container;
    }
    public function testIntegrationsAreOptional(): void
    {
        $container = $this->container([]);
        self::assertFalse($container->hasDefinition('modernorm.migrations.dependency_factory'));
        self::assertFalse($container->hasDefinition('modernorm.fixtures.command'));
    }
    public function testEnabledIntegrationsRegisterNamespacedCommandsAndOnlyModernOrmFixtures(): void
    {
        $container = $this->container(['managed_schema_filter' => '~^modernorm_~', 'migrations' => ['enabled' => true], 'fixtures' => ['enabled' => true]]);
        self::assertTrue($container->hasDefinition('modernorm.migrations.command.diff'));
        self::assertTrue($container->hasDefinition('modernorm.migrations.command.migrate'));
        self::assertTrue($container->hasDefinition('modernorm.fixtures.command'));
        self::assertArrayHasKey(Fixture::class, $container->getAutoconfiguredInstanceof());
        self::assertArrayNotHasKey(\Doctrine\Common\DataFixtures\FixtureInterface::class, $container->getAutoconfiguredInstanceof());
    }
    public function testEnablingWithoutAnExplicitScopeFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->container(['migrations' => ['enabled' => true]]);
    }
}
