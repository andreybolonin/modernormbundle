<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class EnvironmentConnection
{
    public static function open(string $name, string $driver): Connection
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid environment variable name.');
        }
        $url = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_string($url) || $url === '') {
            throw new \InvalidArgumentException('Environment variable '.$name.' is empty or missing.');
        }
        try {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid database URL in '.$name.'.');
        }
        if (($params['driver'] ?? null) !== $driver) {
            throw new \InvalidArgumentException($name.' must use '.$driver.'.');
        }

        return DriverManager::getConnection($params);
    }
}
