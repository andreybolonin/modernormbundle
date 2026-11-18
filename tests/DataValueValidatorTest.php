<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Schema\Column;
use ModernORM\Bundle\DataTransfer\ValueValidator;
use PHPUnit\Framework\TestCase;

final class DataValueValidatorTest extends TestCase
{
    public function testRejectsLossyValuesWithoutRejectingExactRepresentations(): void
    {
        $validator = new ValueValidator();
        $decimal = new Column('amount', 'decimal', ['precision' => 8, 'scale' => 2]);
        self::assertNull($validator->check('1.2300', $decimal));
        self::assertStringContainsString('rounding', $validator->check('1.239', $decimal));
        $negativeScale = new Column('amount', 'decimal', ['precision' => 4, 'scale' => -2]);
        self::assertNull($validator->check('1200', $negativeScale));
        self::assertStringContainsString('rounding', $validator->check('1234', $negativeScale));
        $text = new Column('name', 'string', ['length' => 2]);
        self::assertNull($validator->check('ЖЖ', $text));
        self::assertStringContainsString('length', $validator->check('ЖЖЖ', $text));
        self::assertStringContainsString('NUL', $validator->check("a\0b", $text));
        self::assertStringContainsString('UTF-8', $validator->check("\xff", $text));
        self::assertNull($validator->check("\xff\0", new Column('file', 'blob')));
        self::assertStringContainsString('NULL', $validator->check(null, $text));
        self::assertNull($validator->check(null, new Column('name', 'string', ['notnull' => false])));
        self::assertStringContainsString('0 or 1', $validator->check('2', new Column('flag', 'boolean')));
        self::assertNull($validator->check('1', new Column('flag', 'boolean')));
        self::assertStringContainsString('finite', $validator->check('1e400', new Column('value', 'float')));
        $number = 1.2345678901234567;
        self::assertSame($number, (float) $validator->input($number));
    }

    public function testTimeDurationsAndFractionalSecondLossAreRejected(): void
    {
        $validator = new ValueValidator();
        $time = new Column('clock', 'time');
        self::assertNull($validator->check('23:59:59.123000', $time, 3));
        self::assertStringContainsString('time of day', $validator->check('25:00:00', $time, 6));
        self::assertStringContainsString('time of day', $validator->check('-01:00:00', $time, 6));
        self::assertStringContainsString('rounding', $validator->check('23:59:59.123400', $time, 3));
    }
}
