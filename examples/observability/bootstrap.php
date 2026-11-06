<?php

declare(strict_types=1);

// The example owns its SDK dependencies; the bundle and core remain independent.
require __DIR__.'/vendor/autoload.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$corePath = getenv('MODERNORM_CORE_PATH');
if ($corePath !== false && $corePath !== '') {
    if (!is_file($corePath.'/src/Statistics/OperationEvent.php')) {
        throw new RuntimeException('MODERNORM_CORE_PATH must point to a ModernORM checkout.');
    }
    spl_autoload_register(static function (string $class) use ($corePath): void {
        if (str_starts_with($class, 'ModernORM\\') && !str_starts_with($class, 'ModernORM\\Bundle\\')) {
            $path = $corePath.'/src/'.str_replace('\\', '/', substr($class, 10)).'.php';
            if (is_file($path)) {
                require $path;
            }
        }
    }, true, true);
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'ModernORM\\Bundle\\Example\\Observability\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!property_exists(\ModernORM\Statistics\OperationEvent::class, 'bulk')) {
    throw new RuntimeException('This prototype requires the extended core operation events. Set MODERNORM_CORE_PATH to a compatible checkout.');
}
