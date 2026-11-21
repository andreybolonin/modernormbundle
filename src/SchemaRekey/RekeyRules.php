<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use ModernORM\Bundle\DataTransfer\DataValidator;
use ModernORM\Bundle\DataTransfer\RuleSuggestions;
use ModernORM\Bundle\DataTransfer\TransformationLog;
use ModernORM\Bundle\DataTransfer\TransformationRules;

/** Adapts the shared scalar-rule contract; no second rule engine. */
final class RekeyRules
{
    public static function validationPlan(array $plan, int $batchSize): array
    {
        $tables = [];
        foreach ($plan['tables'] as $name => $table) {
            $protected = array_merge($table['source_key'], $table['target_key']);
            foreach (['source_structure', 'target_structure'] as $structure) {
                foreach ($table[$structure]['indexes'] as $index) {
                    if ($index['unique']) {
                        $protected = array_merge($protected, $index['columns']);
                        $physical = array_keys($table[$structure]['columns'] ?? array_fill_keys(array_column($table['columns'], 'name'), true));
                        if (array_diff($index['columns'], $physical) !== []) {
                            // Expression-index dependencies cannot be inferred from column names.
                            $protected = array_merge($protected, array_column($table['columns'], 'name'));
                        }
                    }
                }
                foreach ($table[$structure]['foreign_keys'] as $fk) {
                    $protected = array_merge($protected, $fk['columns']);
                }
            }
            foreach ($plan['foreign_keys'] as $edge) {
                if ($edge['parent'] === $name) {
                    $protected = array_merge($protected, $edge['referenced_columns']);
                }
            }
            $tables[$name] = $table + ['primary_key' => $table['source_key'], 'protected_columns' => array_values(array_unique($protected)), 'batch_size' => min($batchSize, max(1, intdiv(65535, max(1, count($table['columns'])))))];
        }

        return ['tables' => $tables];
    }

    public static function bind(array $plan, TransformationRules $rules): array
    {
        $plan['rules'] = $rules->describe();
        $plan['blockers'] = array_values(array_unique(array_merge($plan['blockers'], $rules->errorsForPlan(self::validationPlan($plan, 1000)))));

        return RekeyPlan::seal($plan);
    }

    public function validate(Connection $source, Connection $target, array $plan, int $batchSize, TransformationRules $rules, int $maxErrors, int $maxChanges, ?TransformationLog $log = null, ?RuleSuggestions $suggestions = null): array
    {
        return (new DataValidator())->validate($source, $target, self::validationPlan($plan, $batchSize), $maxErrors, $rules, $maxChanges, $log, $suggestions);
    }
}
