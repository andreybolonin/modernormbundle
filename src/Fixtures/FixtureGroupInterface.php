<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

interface FixtureGroupInterface
{
    /** @return list<string> */
    public static function getGroups(): array;
}
