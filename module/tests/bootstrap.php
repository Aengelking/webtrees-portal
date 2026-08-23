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

// The module writes its diagnostics with `error_log()` — a tree that cannot be
// served, a record that vanished between an invitation and its acceptance —
// and several tests exercise exactly those paths on purpose. In the CLI SAPI
// those lines land on the runner's own output, where PHPUnit 12 counts them as
// unexpected output and marks the test risky; this suite fails on risky, and
// should keep doing so. So they go to a file instead. Nothing reads it: the
// tests that care about a failure being *recorded* read the module's own
// table, and the one test that reads the log sets this itself and puts it back.
ini_set('error_log', sys_get_temp_dir() . '/portal_api-tests.log');

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
