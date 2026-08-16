<?php

/**
 * A PSR-4 autoloader for this module.
 *
 * The module ships no composer dependencies of its own; it uses only what
 * webtrees already provides. Registering one small autoloader keeps the
 * install story to "copy the folder into modules_v4/".
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Engelking\\Webtrees\\PortalApi\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
