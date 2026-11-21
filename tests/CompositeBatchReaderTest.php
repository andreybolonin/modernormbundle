<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Doctrine\DBAL\Schema\Table;
use ModernORM\Bundle\DataTransfer\BatchReader;
use PHPUnit\Framework\TestCase;

final class CompositeBatchReaderTest extends TestCase
{
    public function testCompositeCursorPreservesUnsignedPrecisionAndOrdersBothColumns(): void
    {
        $source = $this->createMock(Connection::class);
        $source->method('getDatabase')->willReturn('source');
        $source->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $source->method('fetchOne')->willReturn('InnoDB');
        $schema = $this->createMock(MySQLSchemaManager::class);
        $table = new Table('links');
        $table->addColumn('owner_id', 'bigint', ['unsigned' => true]);
        $table->addColumn('label_id', 'integer');
        $schema->method('introspectTableByQuotedName')->with('links')->willReturn($table);
        $source->method('createSchemaManager')->willReturn($schema);
        $first = ['owner_id' => '18446744073709551614', 'label_id' => 1];
        $second = ['owner_id' => '18446744073709551614', 'label_id' => 2];
        $third = ['owner_id' => '18446744073709551615', 'label_id' => 1];
        $calls = 0;
        $source->expects(self::exactly(4))->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $parameters) use (&$calls, $first, $second, $third): array {
            self::assertStringContainsString('ORDER BY `owner_id`, `label_id` LIMIT 1', $sql);
            if ($calls === 0) {
                self::assertSame([], $parameters);
                self::assertStringNotContainsString(' WHERE ', $sql);
            } else {
                self::assertStringContainsString('WHERE (`owner_id`, `label_id`) > (CAST(? AS UNSIGNED), CAST(? AS SIGNED))', $sql);
                self::assertSame(array_values([$first, $second, $third][$calls - 1]), $parameters);
            }

            return [[$first], [$second], [$third], []][$calls++];
        });
        $plan = ['primary_key' => ['owner_id', 'label_id'], 'batch_size' => 1, 'columns' => [['name' => 'owner_id'], ['name' => 'label_id']]];
        self::assertSame([[$first], [$second], [$third]], iterator_to_array((new BatchReader())->batches($source, 'links', $plan)));
    }
}
