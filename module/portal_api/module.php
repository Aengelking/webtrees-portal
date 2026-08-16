<?php

/**
 * webtrees member portal - API module.
 *
 * Copy this folder into webtrees' modules_v4/ directory. webtrees loads this
 * file in a static scope and expects it to return a ModuleCustomInterface.
 */

declare(strict_types=1);

use Engelking\Webtrees\PortalApi\PortalApiModule;

require_once __DIR__ . '/autoload.php';

return new PortalApiModule();
