<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function __construct(private readonly bool $debug = false) {}

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('modernorm');
        $tree->getRootNode()->children()
            ->scalarNode('url')->defaultValue('%env(resolve:DATABASE_URL)%')->cannotBeEmpty()->end()
            ->scalarNode('server_version')->defaultNull()->end()
            ->arrayNode('mapping_paths')->scalarPrototype()->end()->defaultValue(['%kernel.project_dir%/src/Entity'])->end()
            ->integerNode('batch_size')->min(1)->defaultValue(1)->end()
            ->scalarNode('metadata_cache_pool')->defaultValue('cache.system')->end()
            ->scalarNode('query_cache_pool')->defaultValue('cache.system')->end()
            ->arrayNode('l2_cache')->addDefaultsIfNotSet()->children()
                ->scalarNode('pool')->defaultNull()->end()
                ->scalarNode('namespace')->defaultValue('modernorm')->cannotBeEmpty()->end()
            ->end()->end()
            ->scalarNode('managed_schema_filter')->defaultNull()->end()
            ->arrayNode('migrations')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->arrayNode('paths')->useAttributeAsKey('namespace')->scalarPrototype()->end()->defaultValue(['ModernORMMigrations' => '%kernel.project_dir%/modernorm-migrations'])->end()
                ->scalarNode('table_name')->defaultValue('modernorm_migration_versions')->cannotBeEmpty()->end()
                ->booleanNode('all_or_nothing')->defaultTrue()->end()
            ->end()->end()
            ->arrayNode('fixtures')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()->end()
            ->end()->end()
            ->arrayNode('statistics')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultValue($this->debug)->end()
            ->end()->end()
            ->arrayNode('profiler')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->booleanNode('collect_sql')->defaultFalse()->end()
                ->integerNode('max_queries')->min(0)->max(10000)->defaultValue(200)->end()
                ->integerNode('max_sql_length')->min(128)->max(100000)->defaultValue(10000)->end()
                ->integerNode('max_events')->min(0)->max(10000)->defaultValue(200)->end()
            ->end()->end()
        ->end();
        return $tree;
    }
}
