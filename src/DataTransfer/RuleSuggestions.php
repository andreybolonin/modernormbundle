<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Schema\Column;

/** Full-scan aggregate evidence, independent of retained error examples. */
final class RuleSuggestions
{
    private array $candidates = [];
    private array $issues = [];

    public function observe(string $table, string $name, mixed $value, Column $column, bool $protected): void
    {
        if ($protected || in_array($column->getTypeName(), ['binary', 'blob'], true)) {
            return;
        }
        if ($value === null && $column->getNotnull()) {
            $this->add($table, $name, 'null_required');
        } elseif (is_string($value)) {
            if (in_array($column->getTypeName(), ['string', 'ascii_string', 'text', 'enum', 'guid', 'simple_array'], true) && trim($value, " \t\n\r\v\f") !== $value) {
                $this->add($table, $name, 'trim');
            }
            if (in_array($column->getTypeName(), ['date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable'], true) && preg_match('/^0000-00-00(?: 00:00:00(?:\.0{1,6})?)?$/D', $value) === 1) {
                $this->add($table, $name, $column->getNotnull() ? 'zero_required' : 'zero_nullable', $value);
            }
        }
    }

    private function add(string $table, string $column, string $kind, ?string $from = null): void
    {
        $key = hash('sha256', json_encode([$table, $column, $kind, $from], JSON_THROW_ON_ERROR));
        $this->candidates[$key] ??= ['table' => $table, 'column' => $column, 'kind' => $kind, 'from' => $from, 'count' => 0];
        ++$this->candidates[$key]['count'];
    }

    public function error(string $table, string $column, string $error): void
    {
        $key = hash('sha256', json_encode([$table, $column, $error], JSON_THROW_ON_ERROR));
        $this->issues[$key] ??= ['table' => $table, 'column' => $column, 'error' => $error, 'count' => 0];
        ++$this->issues[$key]['count'];
    }

    public function report(): array
    {
        return ['candidates' => array_values($this->candidates), 'issues' => array_values($this->issues)];
    }
}
