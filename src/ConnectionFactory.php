<?php

declare(strict_types=1);

namespace ModernORM\Bundle;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;

final class ConnectionFactory
{
    public static function create(string $url, ?string $serverVersion, Statistics $statistics, ?\ModernORM\Bundle\Profiler\SqlHistory $sqlHistory = null): Connection
    {
        $params = (new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        if (($params['driver'] ?? null) !== 'pdo_pgsql' && ($params['driver'] ?? null) !== 'pgsql') {
            throw new \InvalidArgumentException('ModernORM requires a PostgreSQL connection URL.');
        }
        if ($serverVersion !== null) {
            $params['serverVersion'] = $serverVersion;
        }
        $config = new Configuration();
        $middlewares = [new Middleware($statistics)];
        if ($sqlHistory?->isEnabled()) { $middlewares[] = new \ModernORM\Bundle\Profiler\SqlMiddleware($sqlHistory); }
        $config->setMiddlewares($middlewares);
        return DriverManager::getConnection($params, $config);
    }
}
