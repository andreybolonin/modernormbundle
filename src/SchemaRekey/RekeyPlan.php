<?php

declare(strict_types=1);

namespace ModernORM\Bundle\SchemaRekey;

use Symfony\Component\Yaml\Yaml;

/** A saved plan is data, never an SQL execution source. Rebuild it before use. */
final class RekeyPlan
{
    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function seal(array $plan): array
    {
        unset($plan['plan_hash']);
        $plan['plan_hash'] = self::hash($plan);

        return $plan;
    }

    public static function read(string $path): array
    {
        if ($path === '' || str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Plan must be a readable local regular file.');
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException('Cannot open rekey plan.');
        }
        try {
            $text = stream_get_contents($handle, 16 * 1024 * 1024 + 1);
        } finally {
            fclose($handle);
        }
        if ($text === false || strlen($text) > 16 * 1024 * 1024) {
            throw new \InvalidArgumentException('Plan must be a readable YAML file of at most 16 MiB.');
        }
        // Generated plans use quoted scalar values. Reject YAML references/tags before parsing.
        $outsideStrings = preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'/s', '""', $text);
        if ($outsideStrings === null || preg_match('/(?:^|[\s\[{,:])[&*!]|<<\s*:/m', $outsideStrings)) {
            throw new \InvalidArgumentException('YAML aliases, anchors, merge keys and tags are forbidden in rekey plans.');
        }
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'Duplicate key')) {
                throw new \InvalidArgumentException('Duplicate YAML mapping keys are forbidden.');
            }
            return false;
        }, E_USER_DEPRECATED);
        try {
            $plan = Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $error) {
            throw new \InvalidArgumentException(str_contains($error->getMessage(), 'Duplicate key') ? 'Duplicate YAML mapping keys are forbidden.' : 'Invalid rekey YAML plan.');
        } finally {
            restore_error_handler();
        }
        $keys = ['version', 'command', 'schema_rekey', 'rules', 'execution', 'connections', 'target_schema', 'source_fence', 'id_strategy', 'endpoint_identity', 'source_session', 'fingerprints', 'tables', 'foreign_keys', 'load_order', 'blockers', 'plan_hash'];
        if (!is_array($plan) || array_diff(array_keys($plan), $keys) !== [] || array_diff($keys, array_keys($plan)) !== [] || $plan['version'] !== 1 || $plan['command'] !== 'modernorm:data:transfer' || $plan['schema_rekey'] !== true || $plan['execution'] !== 'offline_transactional' || !is_string($plan['target_schema']) || !is_array($plan['connections']) || array_keys($plan['connections']) !== ['source_url_env', 'target_url_env']) {
            throw new \InvalidArgumentException('Unknown fields, version or invalid rekey plan structure.');
        }
        foreach ($plan['connections'] as $name) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Plan connection fields must name environment variables.');
            }
        }
        if (($plan['source_fence'] ?? null) !== ['strategy' => 'mysql_global_read_lock', 'timeout_seconds' => 30] || ($plan['id_strategy'] ?? null) !== ['type' => 'bigint', 'allocation' => 'source_pk_order'] || !is_array($plan['blockers']) || $plan['blockers'] !== []) {
            throw new \InvalidArgumentException('Plan has blockers or unsupported execution policies.');
        }
        if (!is_string($plan['plan_hash']) || !hash_equals(self::seal($plan)['plan_hash'], $plan['plan_hash'])) {
            throw new \InvalidArgumentException('Rekey plan hash does not match its contents.');
        }

        return $plan;
    }

    public static function write(string $path, array $plan): void
    {
        if ($plan['blockers'] !== []) {
            throw new \InvalidArgumentException('A blocked plan cannot be saved for execution.');
        }
        $text = Yaml::dump($plan, 20, 2, Yaml::DUMP_FORCE_DOUBLE_QUOTES_ON_VALUES | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        if ($path === '' || str_contains($path, '://') || file_exists($path) || is_link($path)) {
            throw new \InvalidArgumentException('Plan output must be a new local file.');
        }
        $file = @fopen($path, 'x');
        if ($file === false) {
            throw new \RuntimeException('Plan output must be a new writable file.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($text)) {
                $written = fwrite($file, substr($text, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Cannot finish writing rekey plan.');
                }
                $offset += $written;
            }
        } finally {
            fclose($file);
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
