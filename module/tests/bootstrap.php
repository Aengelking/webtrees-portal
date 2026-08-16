<?php

/**
 * PHPUnit bootstrap for the portal_api module.
 *
 * The module has no dependencies of its own — it runs inside webtrees and
 * uses what webtrees provides — so its tests need a webtrees checkout to run
 * against. `tools/setup-test-env.sh` creates one at module/.webtrees and
 * symlinks the module into its modules_v4/ directory.
 *
 * Set WEBTREES_DIR to point at an existing checkout instead.
 */

declare(strict_types=1);

$webtrees_dir = getenv('WEBTREES_DIR');

if ($webtrees_dir === false || $webtrees_dir === '') {
    $webtrees_dir = __DIR__ . '/../.webtrees';
}

$autoload = $webtrees_dir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, <<<TEXT

        Cannot find a webtrees checkout to test against.

        Looked for: {$autoload}

        Run  module/tools/setup-test-env.sh  to create one, or set WEBTREES_DIR
        to the path of an existing webtrees installation.


        TEXT);

    exit(1);
}

require $autoload;

// The module under test.
require __DIR__ . '/../portal_api/autoload.php';

// webtrees' own TestCase registers GEDCOM tags — which translate their labels
// — before it initialises I18N. That works in webtrees' suite only because
// some earlier test has already set the static translator. Set it up once here
// so the order does not matter.
(new Fisharebest\Webtrees\Webtrees())->bootstrap();
Fisharebest\Webtrees\I18N::init('en-US', true);

// The tests themselves.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Engelking\\Webtrees\\PortalApi\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
