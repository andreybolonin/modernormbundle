<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Schema\Column;

/** Loss checks that PostgreSQL input parsing alone cannot guarantee. */
final class ValueValidator
{
    public function check(mixed $value, Column $column, ?int $timePrecision = null): ?string
    {
        if ($value === null) {
            return $column->getNotnull() ? 'NULL is not allowed.' : null;
        }
        $type = $column->getTypeName();
        $text = $this->input($value);
        if (in_array($type, ['binary', 'blob'], true)) {
            return $column->getLength() !== null && strlen($text) > $column->getLength() ? 'Binary value exceeds target length.' : null;
        }
        if (preg_match('//u', $text) !== 1) {
            return 'Value is not valid UTF-8.';
        }
        if (str_contains($text, "\0")) {
            return 'NUL bytes cannot be stored as PostgreSQL text.';
        }
        if ($type === 'boolean' && !in_array($value, [0, 1, '0', '1', false, true], true)) {
            return 'Boolean value must be 0 or 1.';
        }
        if (in_array($type, ['string', 'ascii_string', 'text', 'enum'], true)) {
            if ($column->getLength() !== null && preg_match_all('/./us', $text) > $column->getLength()) {
                return 'String exceeds target character length.';
            }
            if ($type === 'enum' && !in_array($text, $column->getValues(), true)) {
                return 'Value is not a permitted target ENUM value.';
            }
        }
        if ($type === 'decimal' && $column->getPrecision() !== null && preg_match('/^[+-]?(\d+)(?:\.(\d*))?$/D', $text, $parts) === 1) {
            $scale = $column->getScale();
            $fraction = rtrim($parts[2] ?? '', '0');
            if (($scale >= 0 && strlen($fraction) > $scale) || ($scale < 0 && ($fraction !== '' || trim(substr($parts[1], $scale), '0') !== ''))) {
                return 'Decimal would require rounding to the target scale.';
            }
        }
        if (in_array($type, ['float', 'smallfloat'], true) && (!is_numeric($text) || !is_finite((float) $text))) {
            return 'Floating-point value must be finite.';
        }
        if (in_array($type, ['time', 'time_immutable'], true) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?$/D', $text) !== 1) {
            return 'MySQL TIME duration is not a PostgreSQL time of day.';
        }
        if ($timePrecision !== null && preg_match('/\.(\d+)/', $text, $parts) === 1 && strlen(rtrim($parts[1], '0')) > $timePrecision) {
            return 'Fractional seconds would require rounding.';
        }

        return null;
    }

    public function input(mixed $value): string
    {
        if (is_resource($value)) {
            $text = stream_get_contents($value);
            if ($text === false) {
                throw new \RuntimeException('Could not read a binary value.');
            }

            return $text;
        }

        if (is_float($value)) {
            $text = sprintf('%.17g', $value);
            $decimalPoint = localeconv()['decimal_point'];

            return $decimalPoint === '' ? $text : str_replace($decimalPoint, '.', $text);
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
