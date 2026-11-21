<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use ModernORM\Bundle\Schema\SchemaScope;

final class SchemaProvider implements \Doctrine\Migrations\Provider\SchemaProvider
{
    public function __construct(private readonly SchemaScope $scope)
    {
    }

    public function createSchema(): Schema
    {
        if ($this->scope->hasFullText()) {
            throw new \LogicException('DBAL Schema cannot represent FTS assets; generate complete migrations through modernorm:migrations:diff.');
        }
        return $this->scope->schema();
    }
}
