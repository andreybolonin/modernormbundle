<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Column;
use ModernORM\Bundle\DataTransfer\ValueValidator;

/** Target-only values are checked constants, never executable expressions from a plan. */
final class TargetProjections
{
    private const TYPES = ['bool', 'int2', 'int4', 'int8', 'numeric', 'float4', 'float8', 'text', 'varchar', 'bpchar', 'bytea', 'date', 'time', 'timetz', 'timestamp', 'timestamptz', 'uuid', 'json', 'jsonb'];

    public function describe(Connection $target, Column $column, array $definition, array $catalog): array
    {
        if ($catalog['type_schema'] !== 'pg_catalog' || !in_array($catalog['type_name'], self::TYPES, true)) {
            throw new \InvalidArgumentException('Target-only projections require a supported built-in PostgreSQL type.');
        }
        $sql = $catalog['column_default'];
        $value = null;
        if ($sql !== null) {
            $expression = self::literalExpression($sql);
            if ($expression === null) {
                throw new \InvalidArgumentException('Only literal constant defaults are supported; functions and expressions are blocked.');
            }
            if ($target->fetchOne('SHOW standard_conforming_strings') !== 'on') {
                throw new \InvalidArgumentException('Literal default inspection requires standard_conforming_strings=on.');
            }
            // Only the recognized literal and a pg_catalog-qualified cast are evaluated.
            $value = $target->fetchOne('SELECT ('.$expression.')::pg_catalog.text');
            if ($value !== null && $column->getTypeName() === 'boolean') {
                $value = match ($value) {
                    'true' => '1',
                    'false' => '0',
                    default => $value,
                };
            }
            if ($value !== null && in_array($column->getTypeName(), ['binary', 'blob'], true)) {
                if (preg_match('/^\\\\x(?:[0-9a-f]{2})*$/Di', $value) !== 1) {
                    throw new \InvalidArgumentException('Binary constant defaults require PostgreSQL hex bytea input.');
                }
                $value = hex2bin(substr($value, 2));
            }
        }
        $date = '[0-9]{4,6}-[0-9]{2}-[0-9]{2}';
        $time = '[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?(?::?[0-9]{2})?)?';
        $temporal = match ($column->getTypeName()) {
            'date', 'date_immutable' => $date,
            'time', 'time_immutable' => $time,
            'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable' => $date.'(?:[ T]'.$time.')?',
            default => null,
        };
        if ($value !== null && $temporal !== null && preg_match('/^'.$temporal.'$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Temporal constant defaults must use absolute date/time values, not relative or special inputs.');
        }
        $error = (new ValueValidator())->check($value, $column, $definition['datetime_precision'] === null ? null : (int) $definition['datetime_precision']);
        if ($error !== null) {
            throw new \InvalidArgumentException('Invalid target-only projection: '.$error);
        }
        $binary = in_array($column->getTypeName(), ['binary', 'blob'], true);
        // Verify casts and ranges even for empty source tables, without inserting a trial row.
        $target->fetchOne('SELECT '.($binary ? "decode(CAST(? AS text), 'hex')" : 'CAST(? AS '.$definition['sql_type'].')'), [$binary && $value !== null ? bin2hex($value) : $value], [$value === null ? ParameterType::NULL : ParameterType::STRING]);

        return ['operation' => $sql === null ? 'null' : 'constant', 'target_type' => $column->getTypeName(), 'encoding' => $binary ? 'base64' : 'text', 'value' => $binary && $value !== null ? base64_encode($value) : $value];
    }

    /** Recognize one literal and an optional built-in cast; never accept SQL operators/calls. */
    public static function literalExpression(string $sql): ?string
    {
        $sql = trim($sql);
        for ($depth = 0; $depth < 8 && str_starts_with($sql, '(') && str_ends_with($sql, ')'); ++$depth) {
            $sql = trim(substr($sql, 1, -1));
        }
        $literal = "(?:E'(?:[^'\\\\]|''|\\\\.)*'|'(?:[^']|'')*'|NULL|true|false|[+-]?(?:[0-9]+(?:\\.[0-9]*)?|\\.[0-9]+)(?:[eE][+-]?[0-9]+)?)";
        $base = '(?:character\\s+varying|double\\s+precision|timestamptz|timestamp|timetz|time|smallint|integer|bigint|int2|int4|int8|numeric|decimal|float4|float8|real|boolean|bool|text|varchar|character|char|bpchar|bytea|date|uuid|jsonb|json)';
        $pattern = '/\\A(?<literal>'.$literal.')(?:\\s*::\\s*(?:pg_catalog\\.)?(?<type>'.$base.')(?<modifier>\\(\\s*[0-9]+\\s*(?:,\\s*-?[0-9]+\\s*)?\\))?(?<zone>\\s+(?:with|without)\\s+time\\s+zone)?)?\\z/isD';
        if (preg_match($pattern, $sql, $parts) !== 1) {
            return null;
        }
        if (!isset($parts['type']) || $parts['type'] === '') {
            return $parts['literal'];
        }
        $type = strtolower(preg_replace('/\\s+/', ' ', $parts['type']));
        $type = match ($type) {
            'smallint' => 'int2', 'integer' => 'int4', 'bigint' => 'int8',
            'decimal' => 'numeric', 'real' => 'float4', 'double precision' => 'float8',
            'boolean' => 'bool', 'character varying' => 'varchar', 'character', 'char' => 'bpchar',
            default => $type,
        };
        $zone = strtolower(trim(preg_replace('/\\s+/', ' ', $parts['zone'] ?? '')));
        if ($zone !== '') {
            if (!in_array($type, ['time', 'timestamp'], true)) {
                return null;
            }
            if ($zone === 'with time zone') {
                $type .= 'tz';
            }
        }
        $modifier = $parts['modifier'] ?? '';
        if ($modifier !== '' && !in_array($type, ['numeric', 'varchar', 'bpchar', 'time', 'timetz', 'timestamp', 'timestamptz'], true)) {
            return null;
        }

        return 'CAST('.$parts['literal'].' AS pg_catalog.'.$type.$modifier.')';
    }

    public function populate(Connection $target, array $table, array $stage): void
    {
        $assignments = $parameters = $types = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($table['target_projections'] as $column => $projection) {
            [$expression, $value] = $this->binding($projection, $table['definitions'][$column]['sql_type']);
            $assignments[] = $quote($column).' = '.$expression;
            $parameters[] = $value;
            $types[] = $value === null ? ParameterType::NULL : ParameterType::STRING;
        }
        if ($assignments !== []) {
            $target->executeStatement('UPDATE '.$stage['table'].' SET '.implode(', ', $assignments), $parameters, $types);
        }
    }

    public function verify(Connection $target, array $table, array $stage): void
    {
        $mismatches = $parameters = $types = [];
        $quote = $target->getDatabasePlatform()->quoteSingleIdentifier(...);
        foreach ($table['target_projections'] as $column => $projection) {
            [$expected, $value] = $this->binding($projection, $table['definitions'][$column]['sql_type']);
            $actual = $quote($column);
            if (in_array($projection['target_type'], ['string', 'ascii_string', 'text', 'enum', 'guid'], true)) {
                $actual = "convert_to(".$actual."::text, 'UTF8')";
                $expected = "convert_to((".$expected.")::text, 'UTF8')";
            } elseif (in_array($projection['target_type'], ['json', 'jsonb', 'json_object', 'jsonb_object'], true)) {
                $actual .= '::text';
                $expected = '('.$expected.')::text';
            }
            $mismatches[] = $actual.' IS DISTINCT FROM '.$expected;
            $parameters[] = $value;
            $types[] = $value === null ? ParameterType::NULL : ParameterType::STRING;
        }
        if ($mismatches !== [] && (int) $target->fetchOne('SELECT count(*) FROM '.$stage['table'].' WHERE '.implode(' OR ', $mismatches), $parameters, $types) !== 0) {
            throw new \RuntimeException('Target-only staging values differ from the planned NULL/constant projections.');
        }
    }

    private function binding(array $projection, string $sqlType): array
    {
        $value = $projection['value'];
        if ($projection['encoding'] === 'base64') {
            $value = $value === null ? null : base64_decode($value, true);
            if ($value === false) {
                throw new \LogicException('Invalid binary constant projection.');
            }

            return ["decode(CAST(? AS text), 'hex')", $value === null ? null : bin2hex($value)];
        }

        return ['CAST(? AS '.$sqlType.')', $value];
    }
}
