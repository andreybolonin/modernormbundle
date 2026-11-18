<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

final class LoadFailure extends \RuntimeException
{
    public function __construct(public readonly array $report, string $reason)
    {
        parent::__construct($reason);
    }
}
