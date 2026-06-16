<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Register Nextcloud OCP interfaces (provided by nextcloud/ocp dev dependency).
// The OCP package ships as a path-based library without its own autoload entry
// in the installed vendor; we add it explicitly for standalone CI runs.
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$loader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
$loader->register(true);

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
if (file_exists(__DIR__ . '/../../../lib/base.php')) {
    require_once __DIR__ . '/../../../lib/base.php';
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This lets ScholiqToolProvider unit tests run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

// ObjectEntity stub — loaded when the openregister runtime is absent.
// Required by CredentialVerifyControllerTest to mock ObjectService::find().
if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    require_once __DIR__ . '/Stubs/Db/ObjectEntity.php';
}

// AuditTrailMapper stub.
if (class_exists(\OCA\OpenRegister\Db\AuditTrailMapper::class) === false) {
    require_once __DIR__ . '/Stubs/Db/AuditTrailMapper.php';
}

// ObjectService stub.
if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    require_once __DIR__ . '/Stubs/Service/ObjectService.php';
}

// TenantKeyService stub.
if (class_exists(\OCA\OpenRegister\Service\TenantKeyService::class) === false) {
    require_once __DIR__ . '/Stubs/Service/TenantKeyService.php';
}

// AuditHashService stub.
if (class_exists(\OCA\OpenRegister\Service\AuditHashService::class) === false) {
    require_once __DIR__ . '/Stubs/Service/AuditHashService.php';
}

// TransitionEngine stub.
if (class_exists(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class) === false) {
    require_once __DIR__ . '/Stubs/Service/Lifecycle/TransitionEngine.php';
}

// Event stubs.
if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
    require_once __DIR__ . '/Stubs/Event/ObjectCreatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectTransitionedEvent::class) === false) {
    require_once __DIR__ . '/Stubs/Event/ObjectTransitionedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    require_once __DIR__ . '/Stubs/Event/DeepLinkRegistrationEvent.php';
}
