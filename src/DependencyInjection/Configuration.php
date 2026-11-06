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
                ->arrayNode('advisor')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->integerNode('max_findings')->min(1)->max(50)->defaultValue(10)->end()
                    ->integerNode('repeated_query_threshold')->min(2)->defaultValue(5)->end()
                    ->floatNode('slow_query_ms')->min(0)->defaultValue(10)->end()
                    ->integerNode('managed_entities_threshold')->min(1)->defaultValue(1000)->end()
                    ->floatNode('change_sets_ms')->min(0)->defaultValue(10)->end()
                    ->floatNode('hydration_ms')->min(0)->defaultValue(50.0)->end()
                    ->integerNode('hydrated_entities_threshold')->min(1)->defaultValue(1000)->end()
                    ->integerNode('batch_fallback_threshold')->min(2)->defaultValue(5)->end()
                    ->integerNode('l2_min_lookups')->min(1)->defaultValue(20)->end()
                    ->floatNode('l2_min_hit_rate')->min(0)->max(1)->defaultValue(0.5)->end()
                ->end()->end()
            ->end()->end()
        ->end();
        return $tree;
    }
}
