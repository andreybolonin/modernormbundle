<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

final class FixtureLoadException extends \RuntimeException
{
    public function __construct(\Throwable $cause, public readonly ?bool $databaseCommitted, public readonly ?\Throwable $cacheFailure = null)
    {
        parent::__construct(match ($databaseCommitted) {
            true => 'Fixture data committed, but cleanup or L2 invalidation failed. Do not rerun the load before recovering the cache.',
            false => 'Fixture load failed and was not committed.',
            null => 'Fixture transaction outcome is unknown. Inspect database and cache before retrying.',
        }, 0, $cause);
    }
}
