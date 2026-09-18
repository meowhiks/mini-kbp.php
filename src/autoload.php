<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MiniKbp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

MiniKbp\Bootstrap::init();
