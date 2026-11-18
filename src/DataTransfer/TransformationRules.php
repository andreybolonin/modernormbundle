<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

/** Immutable, declarative rules. No PHP callbacks, row skipping or key remapping. */
final class TransformationRules
{
    private readonly array $groups;
    private readonly array $rules;
    private readonly ValueValidator $values;

    private function __construct(array $rules)
    {
        $this->values = new ValueValidator();
        // Break caller-owned PHP references as well as loading a file only once.
        $this->rules = json_decode(json_encode($rules, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $groups = [];
        foreach ($this->rules as $rule) {
            $groups[$rule['table']][] = $rule;
        }
        $this->groups = $groups;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Rules file is not a readable regular file.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('Cannot read rules file.');
        }
        try {
            $object = json_decode($contents, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Rules file must contain valid JSON.');
        }
        if (!$object instanceof \stdClass || !isset($object->rules) || !is_array($object->rules)) {
            throw new \InvalidArgumentException('Rules document must be an object with a rules array.');
        }
        $document = get_object_vars($object);
        $document['rules'] = array_map(static fn (mixed $rule): mixed => $rule instanceof \stdClass ? get_object_vars($rule) : $rule, $object->rules);

        return self::fromDocument($document);
    }

    public static function fromDocument(array $document): self
    {
        if (($document['version'] ?? null) !== 1 || array_diff(array_keys($document), ['version', 'rules']) !== [] || !isset($document['rules']) || !is_array($document['rules']) || !array_is_list($document['rules'])) {
            throw new \InvalidArgumentException('Rules require version 1 and a rules list, without unknown document keys.');
        }
        $ids = [];
        foreach ($document['rules'] as $rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Every transformation rule must be an object.');
            }
            foreach (['id', 'table', 'column', 'operation'] as $key) {
                if (!isset($rule[$key]) || !is_string($rule[$key]) || $rule[$key] === '') {
                    throw new \InvalidArgumentException('Every rule requires nonempty id, table, column and operation strings.');
                }
            }
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $rule['id']) !== 1 || isset($ids[$rule['id']])) {
                throw new \InvalidArgumentException('Rule IDs must be unique identifiers.');
            }
            $ids[$rule['id']] = true;
            $arguments = match ($rule['operation']) {
                'trim' => [],
                'replace' => ['from', 'to'],
                'null_to' => ['value'],
                default => throw new \InvalidArgumentException('Unsupported transformation operation for rule '.$rule['id'].'.'),
            };
            if (array_diff(array_keys($rule), array_merge(['id', 'table', 'column', 'operation'], $arguments)) !== []) {
                throw new \InvalidArgumentException('Unknown keys in rule '.$rule['id'].'.');
            }
            foreach ($arguments as $argument) {
                if (!array_key_exists($argument, $rule) || (!is_null($rule[$argument]) && !is_string($rule[$argument]) && !is_int($rule[$argument]) && !is_bool($rule[$argument]))) {
                    throw new \InvalidArgumentException('Rule arguments must be null, strings, integers or booleans; use strings for decimal values.');
                }
            }
        }

        return new self($document['rules']);
    }

    public function describe(): array
    {
        return ['hash' => hash('sha256', json_encode(['version' => 1, 'rules' => $this->rules], JSON_THROW_ON_ERROR)), 'rules' => array_map(static fn (array $rule): array => array_intersect_key($rule, array_flip(['id', 'table', 'column', 'operation'])), $this->rules)];
    }

    public function document(): array
    {
        return ['version' => 1, 'rules' => $this->rules];
    }

    public function errorsForPlan(array $plan): array
    {
        $errors = [];
        foreach ($this->rules as $rule) {
            $table = $plan['tables'][$rule['table']] ?? null;
            $column = $table === null ? null : array_find($table['columns'], static fn (array $column): bool => $column['name'] === $rule['column']);
            if ($table === null || $column === null) {
                $errors[] = 'Rule '.$rule['id'].': table/column is absent from the transfer plan.';
            } elseif (!isset($table['protected_columns'], $column['target_type'])) {
                $errors[] = 'Rule '.$rule['id'].': plan is missing type/key protection metadata.';
            } elseif (in_array($rule['column'], array_merge($table['protected_columns'], $table['primary_key'] ?? []), true)) {
                $errors[] = 'Rule '.$rule['id'].': PK and FK columns cannot be transformed; coordinated key remapping is unsupported.';
            } elseif (in_array($column['target_type'], ['binary', 'blob'], true)) {
                $errors[] = 'Rule '.$rule['id'].': binary transformations are unsupported.';
            } elseif ($rule['operation'] === 'trim' && !in_array($column['target_type'], ['string', 'text', 'ascii_string', 'enum', 'guid', 'simple_array'], true)) {
                $errors[] = 'Rule '.$rule['id'].': trim requires a text-like target type.';
            }
        }

        return $errors;
    }

    public function applyRow(string $table, array $row, array $primaryKey, TransformationJournal $journal): array
    {
        $original = [];
        foreach ($this->groups[$table] ?? [] as $rule) {
            $before = $row[$rule['column']];
            if (!array_key_exists($rule['column'], $original)) {
                $original[$rule['column']] = $before;
            }
            $value = match ($rule['operation']) {
                'trim' => $before === null ? null : trim($this->values->input($before), " \t\n\r\v\f"),
                'null_to' => $before === null ? $rule['value'] : $before,
                'replace' => $this->same($before, $rule['from']) ? $rule['to'] : $before,
                default => throw new \LogicException('Unrecognized operation in validated rules.'),
            };
            $row[$rule['column']] = $value;
            if (!$this->same($before, $value)) {
                $journal->record($rule, $primaryKey);
            }
        }
        $changed = 0;
        foreach ($original as $column => $before) {
            if (!$this->same($before, $row[$column])) {
                ++$changed;
            }
        }
        $journal->rowChanged($changed);

        return $row;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return $this->values->input($left) === $this->values->input($right);
    }
}
