<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal Bindings deliberately pass through without being retained. */
final class SqlStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly SqlHistory $history, private readonly string $sql)
    {
        parent::__construct($statement);
    }
    public function execute(): Result
    {
        $measurement = $this->history->start();
        $error = null;
        try {
            return parent::execute();
        } catch (\Throwable $failure) {
            $error = $failure;
            throw $failure;
        } finally {
            if ($measurement !== null) {
                $this->history->record($measurement, $this->sql, 'execute', $error);
            }
        }
    }
}
