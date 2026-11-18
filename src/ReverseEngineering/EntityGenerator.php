<?php

declare(strict_types=1);

namespace ModernORM\Bundle\ReverseEngineering;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;

/**
 * Generates attributes only; database expressions are never evaluated as PHP.
 * Field/index reconstruction follows Doctrine ORM's former DatabaseDriver;
 * see docs/reverse-engineering.md for provenance and differences.
 */
final class EntityGenerator
{
    public function className(string $name): string
    {
        $name = str_replace(' ', '', ucwords((string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $name)));
        if ($name === '' || !ctype_alpha($name[0])) {
            $name = 'Entity'.$name;
        }
        // Prefixing all names avoids PHP keywords and built-in type names.
        if (in_array(strtolower($name), ['class', 'interface', 'trait', 'enum', 'match', 'readonly', 'abstract', 'final', 'static', 'self', 'parent', 'new', 'clone', 'function', 'const', 'return', 'default', 'switch', 'case', 'break', 'continue', 'if', 'else', 'elseif', 'while', 'do', 'for', 'foreach', 'try', 'catch', 'finally', 'throw', 'extends', 'implements', 'namespace', 'use', 'public', 'protected', 'private', 'var', 'yield', 'echo', 'print', 'include', 'require', 'list', 'array', 'callable', 'string', 'int', 'float', 'bool', 'object', 'mixed', 'void', 'never', 'null', 'true', 'false', 'iterable', 'resource', 'unset', 'isset', 'empty', 'eval', 'exit', 'die', 'global', 'goto', 'instanceof', 'insteadof', 'as', 'and', 'or', 'xor', 'declare', 'enddeclare', 'endif', 'endwhile', 'endfor', 'endforeach', 'endswitch', 'fn', 'includeonce', 'requireonce', 'haltcompiler', 'orm'], true)) {
            $name = 'Entity'.$name;
        }

        return $name;
    }

    /** @param array<string, Table> $tables
     *  @return array{files: array<string, string>, warnings: list<string>}
     */
    public function generate(array $tables, string $namespace, string $schema, array $sequences = [], ?AbstractPlatform $platform = null): array
    {
        $platform ??= new PostgreSQLPlatform();
        $files = $warnings = $classes = [];
        foreach ($tables as $name => $table) {
            $pk = $this->primaryColumns($table);
            if (count($pk) !== 1) {
                $warnings[] = $name.': skipped: requires one scalar primary key.';
                continue;
            }
            $class = $this->className($name);
            foreach ($classes as $existing) {
                if (strcasecmp($existing, $class) === 0) {
                    throw new \RuntimeException('Class name collision: '.$class);
                }
            }
            $classes[$name] = $class;
        }
        foreach ($classes as $name => $class) {
            $table = $tables[$name];
            $pk = $this->primaryColumns($table)[0];
            $attributes = ['#[ORM\\Entity]', '#[ORM\\Table(name: '.$this->literal('`'.$name.'`').', schema: '.$this->literal($schema).')]'];
            $uniqueNames = [];
            foreach ($table->getUniqueConstraints() as $constraint) {
                $uniqueNames[] = $constraint->getName();
                $columns = array_map(static fn ($column): string => $column->toSQL($platform), $constraint->getColumnNames());
                $attributes[] = '#[ORM\\UniqueConstraint(name: '.$this->literal($constraint->getName()).', columns: '.$this->literal($columns).')]';
            }
            foreach ($table->getIndexes() as $index) {
                if ($index->isPrimary() || in_array($index->getName(), $uniqueNames, true)) {
                    continue;
                }
                if ($index->getFlags() !== [] || $index->getOptions() !== [] || array_any($index->getIndexedColumns(), static fn ($column): bool => $column->getLength() !== null)) {
                    $warnings[] = $name.': index '.$index->getName().' requires manual review.';
                    continue;
                }
                $columns = $index->getQuotedColumns($platform);
                $attributes[] = '#[ORM\\'.($index->isUnique() ? 'UniqueConstraint' : 'Index').'(name: '.$this->literal($index->getName()).', columns: '.$this->literal($columns).')]';
            }
            $relations = [];
            foreach ($table->getForeignKeys() as $fk) {
                $local = array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $fk->getReferencingColumnNames());
                $target = $fk->getReferencedTableName();
                $targetName = $target->getUnqualifiedName()->getValue();
                $qualifier = $target->getQualifier()?->getValue();
                $foreign = array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $fk->getReferencedColumnNames());
                if (count($local) !== 1 || $local[0] === $pk || ($qualifier !== null && $qualifier !== $schema) || !isset($classes[$targetName]) || $this->primaryColumns($tables[$targetName]) !== $foreign || isset($relations[$local[0]])) {
                    $warnings[] = $name.': foreign key '.$fk->getName().' requires manual association mapping; scalar columns retained.';
                    continue;
                }
                $relations[$local[0]] = [$classes[$targetName], $foreign[0], $fk->hasOption('onDelete') ? $fk->getOption('onDelete') : null];
            }
            $properties = $used = [];
            foreach ($table->getColumns() as $column) {
                $columnName = $column->getObjectName()->getIdentifier()->getValue();
                $propertyName = isset($relations[$columnName]) ? preg_replace('/_id$/i', '', $columnName) : $columnName;
                $property = lcfirst($this->className($propertyName));
                if (isset($used[$property])) {
                    throw new \RuntimeException($name.': property name collision: '.$property);
                }
                $used[$property] = true;
                if (isset($relations[$columnName])) {
                    [$targetClass, $targetColumn, $onDelete] = $relations[$columnName];
                    $args = ['name: '.$this->literal('`'.$columnName.'`'), 'referencedColumnName: '.$this->literal('`'.$targetColumn.'`'), 'nullable: '.($column->getNotnull() ? 'false' : 'true')];
                    if ($onDelete !== null) {
                        $args[] = 'onDelete: '.$this->literal($onDelete);
                    }
                    $nullable = !$column->getNotnull();
                    if ($column->getDefault() !== null) {
                        $warnings[] = $name.'.'.$columnName.': association SQL default requires manual mapping.';
                    }
                    $properties[] = '    #[ORM\\ManyToOne(targetEntity: '.$targetClass.'::class)]'."\n".'    #[ORM\\JoinColumn('.implode(', ', $args).')]'."\n".'    public '.($nullable ? '?' : '').$targetClass.' $'.$property.($nullable ? ' = null' : '').';';
                    continue;
                }
                $type = $column->getTypeName();
                $phpType = match ($type) {
                    'smallint', 'integer' => 'int',
                    'bigint' => $column->getUnsigned() ? 'int|string' : 'int',
                    'boolean' => 'bool', 'float', 'smallfloat' => 'float',
                    'decimal', 'string', 'ascii_string', 'text', 'guid', 'enum' => 'string',
                    'json', 'jsonb', 'simple_array' => 'array',
                    'json_object', 'jsonb_object' => 'mixed',
                    'date', 'datetime', 'datetimetz', 'time' => '\\DateTime',
                    'date_immutable', 'datetime_immutable', 'datetimetz_immutable', 'time_immutable' => '\\DateTimeImmutable',
                    'binary', 'blob' => 'mixed',
                    default => throw new \RuntimeException($name.'.'.$columnName.': unsupported type '.$type),
                };
                $lines = [];
                if ($columnName === $pk) {
                    if (!in_array($type, ['smallint', 'integer', 'bigint', 'string', 'ascii_string', 'text', 'guid'], true)) {
                        throw new \RuntimeException($name.': unsupported primary-key type '.$type);
                    }
                    $lines[] = '#[ORM\\Id]';
                    if ($column->getAutoincrement()) {
                        $lines[] = "#[ORM\\GeneratedValue(strategy: 'IDENTITY')]";
                    } elseif (isset($sequences[$name][$columnName])) {
                        $lines[] = "#[ORM\\GeneratedValue(strategy: 'SEQUENCE')]";
                        $lines[] = '#[ORM\\SequenceGenerator(sequenceName: '.$this->literal($sequences[$name][$columnName]).', allocationSize: 1)]';
                    }
                }
                $args = ['name: '.$this->literal('`'.$columnName.'`'), 'type: '.$this->literal($type)];
                if (!$column->getNotnull()) {
                    $args[] = 'nullable: true';
                }
                if ($column->getLength() !== null) {
                    $args[] = 'length: '.$column->getLength();
                }
                if ($type === 'decimal' && $column->getPrecision() !== null) {
                    $args[] = 'precision: '.$column->getPrecision();
                    $args[] = 'scale: '.$column->getScale();
                }
                $options = $column->getPlatformOptions();
                if ($column->getUnsigned()) {
                    $options['unsigned'] = true;
                }
                if ($type === 'enum') {
                    $options['values'] = $column->getValues();
                    $warnings[] = $name.'.'.$columnName.': ENUM values retained; verify target database support.';
                }
                if ($column->getComment() !== '') {
                    $options['comment'] = $column->getComment();
                }
                if ($column->getDefault() !== null && !$column->getAutoincrement()) {
                    $default = $column->getDefault();
                    if ($default instanceof DefaultExpression) {
                        $warnings[] = $name.'.'.$columnName.': SQL expression default '.$default->toSQL($platform).' requires manual mapping and was omitted.';
                    } else {
                        $options['default'] = $default;
                    }
                    if (!$default instanceof DefaultExpression && !isset($sequences[$name][$columnName])) {
                        $warnings[] = $name.'.'.$columnName.': SQL default retained in mapping; review expression defaults.';
                    }
                }
                if ($column->getFixed()) {
                    $options['fixed'] = true;
                }
                if ($options !== []) {
                    $args[] = 'options: '.$this->literal($options);
                }
                $lines[] = '#[ORM\\Column('.implode(', ', $args).')]';
                $nullable = !$column->getNotnull() || ($columnName === $pk && $column->getAutoincrement());
                $declarationType = $nullable && $phpType !== 'mixed' ? (str_contains($phpType, '|') ? $phpType.'|null' : '?'.$phpType) : $phpType;
                $lines[] = 'public '.$declarationType.' $'.$property.($nullable ? ' = null' : '').';';
                $properties[] = '    '.implode("\n    ", $lines);
            }
            $files[$class.'.php'] = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\nuse ModernORM\\Mapping as ORM;\n\n".implode("\n", $attributes)."\nclass ".$class."\n{\n".implode("\n\n", $properties)."\n}\n";
        }

        return ['files' => $files, 'warnings' => $warnings];
    }

    private function literal(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = (array_is_list($value) ? '' : var_export($key, true).' => ').$this->literal($item);
            }

            return '['.implode(', ', $items).']';
        }

        return var_export($value, true);
    }

    /** @return list<string> */
    private function primaryColumns(Table $table): array
    {
        return array_map(static fn ($column): string => $column->getIdentifier()->getValue(), $table->getPrimaryKeyConstraint()?->getColumnNames() ?? []);
    }
}
