<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Tests;

use Doctrine\DBAL\Schema\Table;
use ModernORM\Bundle\ReverseEngineering\EntityGenerator;
use PHPUnit\Framework\TestCase;

final class ReverseEngineeringTest extends TestCase
{
    public function testGeneratedEntitiesLoadAsAttributeMetadata(): void
    {
        $users = new Table('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('name', 'string', ['length' => 120]);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['name'], 'users_name_key');
        $orders = new Table('orders');
        $orders->addColumn('id', 'integer', ['autoincrement' => true]);
        $orders->addColumn('user_id', 'integer', ['notnull' => false]);
        $orders->addColumn('total', 'decimal', ['precision' => 12, 'scale' => 2]);
        $orders->setPrimaryKey(['id']);
        $orders->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'SET NULL'], 'orders_user_fk');
        $plan = (new EntityGenerator())->generate(['users' => $users, 'orders' => $orders], 'GeneratedImportTest', 'public');
        self::assertSame([], $plan['warnings']);
        foreach ($plan['files'] as $code) {
            self::assertNotEmpty(token_get_all($code, TOKEN_PARSE));
            eval(substr($code, 5));
        }
        $driver = new \ModernORM\Mapping\Driver\AttributeDriver([]);
        $class = array_find(get_declared_classes(), static fn (string $name): bool => strcmp($name, 'GeneratedImportTest\\Orders') === 0);
        self::assertNotNull($class);
        $metadata = new \ModernORM\Mapping\ClassMetadata($class);
        $metadata->initializeReflection(new \Doctrine\Persistence\Mapping\RuntimeReflectionService());
        $driver->loadMetadataForClass($metadata->name, $metadata);
        self::assertSame('decimal', $metadata->fieldMappings['total']->type);
        self::assertSame('GeneratedImportTest\\Users', $metadata->associationMappings['user']->targetEntity);
        self::assertSame('user_id', $metadata->associationMappings['user']->joinColumns[0]->name);
        $type = (new \ReflectionProperty($class, 'total'))->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
    }

    public function testUnsupportedKeysAndUnselectedForeignTargetsAreReported(): void
    {
        $unsupported = new Table('link');
        $unsupported->addColumn('a', 'integer');
        $unsupported->addColumn('b', 'integer');
        $unsupported->setPrimaryKey(['a', 'b']);
        $table = new Table('record');
        $table->addColumn('id', 'integer');
        $table->addColumn('owner_id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('owners', ['owner_id'], ['id']);
        $plan = (new EntityGenerator())->generate(['link' => $unsupported, 'record' => $table], 'App\\Entity', 'public');
        self::assertCount(1, $plan['files']);
        self::assertCount(2, $plan['warnings']);
        self::assertStringContainsString('public int $ownerId;', $plan['files']['Record.php']);
    }

    public function testCollidingClassNamesFailBeforeWriting(): void
    {
        $a = new Table('a_b');
        $a->addColumn('id', 'integer');
        $a->setPrimaryKey(['id']);
        $this->expectException(\RuntimeException::class);
        (new EntityGenerator())->generate(['a_b' => $a, 'a-b' => clone $a], 'App\\Entity', 'public');
    }

    public function testPropertyCollisionsAndReservedClassNames(): void
    {
        $generator = new EntityGenerator();
        self::assertSame('EntityClass', $generator->className('class'));
        $table = new Table('items');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addColumn('user_id', 'integer');
        $table->addColumn('user-id', 'integer');
        $this->expectException(\RuntimeException::class);
        $generator->generate(['items' => $table], 'App\\Entity', 'public');
    }
}
