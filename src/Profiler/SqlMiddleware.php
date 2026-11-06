<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Connection;

final class SqlMiddleware implements Middleware
{
    public function __construct(private readonly SqlHistory $history)
    {
    }
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->history) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly SqlHistory $history)
            {
                parent::__construct($driver);
            }
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new SqlConnection(parent::connect($params), $this->history);
            }
        };
    }
}
