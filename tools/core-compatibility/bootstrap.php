<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$core = getenv('MODERNORM_CORE_PATH');
if (!$core || !is_file($core.'/src/Bulk/BulkExecutor.php')) {
    throw new RuntimeException('Set MODERNORM_CORE_PATH to the core checkout being verified.');
}
spl_autoload_register(static function (string $class) use ($core): void {
    if (str_starts_with($class, 'ModernORM\\') && !str_starts_with($class, 'ModernORM\\Bundle\\')) {
        $file = $core.'/src/'.str_replace('\\', '/', substr($class, 10)).'.php';
        if (is_file($file)) { require $file; }
    }
}, true, true);
