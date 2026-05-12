<?php

/**
 * Bootstrap for Scholiq integration tests.
 *
 * Mirrors tests/bootstrap.php but loads the full Nextcloud stack so that
 * \OC::$server, ObjectService, and TransitionEngine are available.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// Composer autoloader.
require_once __DIR__ . '/../../vendor/autoload.php';

// Full Nextcloud bootstrap (provides \OC::$server).
if (file_exists(__DIR__ . '/../../../../../../lib/base.php')) {
    require_once __DIR__ . '/../../../../../../lib/base.php';
} elseif (file_exists(__DIR__ . '/../../../../lib/base.php')) {
    require_once __DIR__ . '/../../../../lib/base.php';
}

if (file_exists(__DIR__ . '/../../../../../../tests/autoload.php')) {
    require_once __DIR__ . '/../../../../../../tests/autoload.php';
}

\OC_App::loadApps();
\OC_App::loadApp('scholiq');
OC_Hook::clear();
