<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the cross-app stub namespaces at test-time on the Composer loader.
// These used to live under composer.json `autoload-dev`, but a dev-built
// vendor/ (as on the shared dev instance) then baked the stubs into the
// RUNTIME classmap, shadowing the real OpenRegister/Talk classes instance-wide
// and 500-ing every app (openregister#2036). Registering them here keeps the
// stubs test-only. Loading is lazy, so ordering vs the OCP registration below
// is irrelevant.
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
$autoloader->addPsr4('OCA\\Talk\\', __DIR__ . '/Stubs/Talk/');

// The nextcloud/ocp package ships the OCP\* interface definitions under
// vendor/nextcloud/ocp/OCP/ but declares an empty Composer autoload block
// (the real Nextcloud server injects these classes at runtime). Register a
// PSR-4 autoloader for the OCP\ namespace so the unit suite can mock OCP
// interfaces (IRequest, IGroupManager, IAppConfig, …) in a standalone
// container without a running Nextcloud server.
$ocpRoot = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_dir($ocpRoot)) {
    spl_autoload_register(static function (string $class) use ($ocpRoot): void {
        if (strncmp($class, 'OCP\\', 4) !== 0) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, 4));
        $file     = $ocpRoot . '/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
    if (file_exists(__DIR__ . '/../../../lib/base.php')) {
        require_once __DIR__ . '/../../../lib/base.php';
    }

    if (file_exists(__DIR__ . '/../../../tests/autoload.php')) {
        require_once __DIR__ . '/../../../tests/autoload.php';
    }

    // Only invoke the Nextcloud app loader when the NC server runtime is present
    // (base.php loaded \OC_App). In a standalone container (docker run php:8.3-cli
    // vendor/bin/phpunit) the unit suite runs against Composer autoloading + the
    // tests/Stubs/ shims only, so guard these NC-only calls.
    if (class_exists('\OC_App')) {
        \OC_App::loadApps();
        \OC_App::loadApp('scholiq');
        \OC_Hook::clear();
    }
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This lets ScholiqToolProvider unit tests run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}
