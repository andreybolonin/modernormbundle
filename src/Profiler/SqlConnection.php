<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Profiler;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal */
final class SqlConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly SqlHistory $history)
    {
        parent::__construct($connection);
    }
    public function prepare(string $sql): Statement
    {
        $measurement = $this->history->start();
        try {
            return new SqlStatement(parent::prepare($sql), $this->history, $sql);
        } catch (\Throwable $error) {
            if ($measurement !== null) {
                $this->history->record($measurement, $sql, 'prepare', $error);
            }
            throw $error;
        }
    }
    public function query(string $sql): Result
    {
        $measurement = $this->history->start();
        $error = null;
        try {
            return parent::query($sql);
        } catch (\Throwable $failure) {
            $error = $failure;
            throw $failure;
        } finally {
            if ($measurement !== null) {
                $this->history->record($measurement, $sql, 'query', $error);
            }
        }
    }
    public function exec(string $sql): int|string
    {
        $measurement = $this->history->start();
        $error = null;
        try {
            return parent::exec($sql);
        } catch (\Throwable $failure) {
            $error = $failure;
            throw $failure;
        } finally {
            if ($measurement !== null) {
                $this->history->record($measurement, $sql, 'exec', $error);
            }
        }
    }
}
