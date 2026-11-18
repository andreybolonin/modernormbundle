<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Schema\Column;

/** Describes planned conversions, without claiming to have validated row values. */
final class TypeCompatibility
{
    /** @return array{conversion: string, warning: ?string, error: ?string} */
    public function compare(Column $source, Column $target): array
    {
        $from = $source->getTypeName();
        $to = $target->getTypeName();
        $integers = ['smallint', 'integer', 'bigint'];
        $strings = ['string', 'ascii_string', 'text', 'enum', 'guid'];
        $json = ['json', 'jsonb', 'json_object', 'jsonb_object'];
        $temporal = ['date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable', 'time', 'time_immutable'];
        $conversion = $from.' -> '.$to;
        $warning = $error = null;
        if (in_array($from, $integers, true) && in_array($to, $integers, true)) {
            $warning = 'Integer range, including unsigned values, must be checked for every value.';
        } elseif ($from === 'boolean' && $to === 'boolean') {
            $warning = 'Boolean values must be restricted to 0/1; tinyint(1) does not enforce this.';
        } elseif (in_array($from, $strings, true) && in_array($to, $strings, true)) {
            $warning = 'Check length, encoding, ENUM/UUID values and target collation/uniqueness.';
        } elseif (in_array($from, $json, true) && in_array($to, $json, true)) {
            $warning = 'JSON documents require validation against PostgreSQL JSON/JSONB limits.';
        } elseif ($from === 'decimal' && $to === 'decimal') {
            $warning = 'Decimal precision and scale require exact per-value checks; no rounding is assumed.';
        } elseif (in_array($from, ['float', 'smallfloat'], true) && in_array($to, ['float', 'smallfloat'], true)) {
            $warning = 'Floating-point range and non-finite values require checks.';
        } elseif (in_array($from, $temporal, true) && in_array($to, $temporal, true) && str_replace('_immutable', '', $from) === str_replace('_immutable', '', $to)) {
            $warning = 'Zero dates, fractional seconds, timezone assumptions and TIME durations require checks.';
        } elseif (in_array($from, ['binary', 'blob'], true) && in_array($to, ['binary', 'blob'], true)) {
            $conversion .= ' (raw bytes)';
        } elseif ($from === 'simple_array' && in_array($to, ['simple_array', 'string', 'text'], true)) {
            $warning = 'SET/simple_array values require an explicit comma-separated representation.';
        } else {
            $error = 'Unsupported conversion '.$conversion.'.';
        }
        if (!$source->getNotnull() && $target->getNotnull()) {
            $warning = ($warning === null ? '' : $warning.' ').'NULL values must be rejected by the loader.';
        }

        return ['conversion' => $conversion, 'warning' => $warning, 'error' => $error];
    }
}
