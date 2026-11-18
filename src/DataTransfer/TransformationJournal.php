<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

/** Bounded samples, complete counters; does not retain source/target payloads. */
final class TransformationJournal
{
    private array $report;

    public function __construct(TransformationRules $rules, string $phase, private readonly int $maxExamples, private readonly ?TransformationLog $log = null)
    {
        if ($maxExamples < 0) {
            throw new \InvalidArgumentException('Maximum change examples cannot be negative.');
        }
        $description = $rules->describe();
        $counts = [];
        foreach ($description['rules'] as $rule) {
            $counts[$rule['id']] = $rule + ['changes' => 0];
        }
        $this->report = ['rules_hash' => $description['hash'], 'phase' => $phase, 'changed_rows' => 0, 'changed_values' => 0, 'rule_changes' => 0, 'rules' => $counts, 'examples' => [], 'examples_truncated' => false];
    }

    public function record(array $rule, array $primaryKey): void
    {
        ++$this->report['rule_changes'];
        ++$this->report['rules'][$rule['id']]['changes'];
        $keys = [];
        foreach ($primaryKey as $column => $value) {
            $text = (string) $value;
            $keys[$column] = preg_match('//u', $text) === 1 && strlen($text) <= 200 ? $text : 'base64:'.base64_encode(substr($text, 0, 150));
        }
        $example = ['rule' => $rule['id'], 'table' => $rule['table'], 'column' => $rule['column'], 'primary_key' => $keys];
        if (count($this->report['examples']) < $this->maxExamples) {
            $this->report['examples'][] = $example;
        }
        // The stream retains full PK strings (or exact base64), unlike bounded display examples.
        if ($this->log === null) {
            return;
        }
        $fullKeys = [];
        foreach ($primaryKey as $column => $value) {
            $text = (string) $value;
            $fullKeys[$column] = preg_match('//u', $text) === 1 ? $text : ['base64' => base64_encode($text)];
        }
        $this->log->write(['event' => 'change', 'phase' => $this->report['phase'], 'rules_hash' => $this->report['rules_hash'], 'rule' => $rule['id'], 'table' => $rule['table'], 'column' => $rule['column'], 'primary_key' => $fullKeys]);
    }

    public function rowChanged(int $columns): void
    {
        if ($columns > 0) {
            ++$this->report['changed_rows'];
            $this->report['changed_values'] += $columns;
        }
    }

    public function report(): array
    {
        $report = $this->report;
        $report['examples_truncated'] = $report['rule_changes'] > count($report['examples']);

        return $report;
    }
}
