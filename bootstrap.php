<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'FulltimeTrading\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

FulltimeTrading\Support\EnvLoader::load(__DIR__ . '/.env');

