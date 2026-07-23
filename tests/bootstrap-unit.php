<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__.'/../vendor/autoload.php';

// Register the cross-app stub namespaces at test-time on the Composer loader.
// These used to live under composer.json `autoload-dev`, but a dev-built
// vendor/ (as on the shared dev instance) then baked the stubs into the
// RUNTIME classmap, shadowing the real OpenRegister/Talk classes instance-wide
// and 500-ing every app (openregister#2036). Registering them here keeps the
// stubs test-only. Loading is lazy, so ordering vs the OCP/NCU registration
// below is irrelevant.
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__.'/Stubs/');
$autoloader->addPsr4('OCA\\Talk\\', __DIR__.'/Stubs/Talk/');

// Register Nextcloud OCP interfaces (provided by nextcloud/ocp dev dependency).
// The OCP package ships as a path-based library without its own autoload entry
// in the installed vendor; we add it explicitly for standalone CI runs.
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
$loader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
$loader->register(true);

// Bootstrap Nextcloud — since we run inside the Docker container,
// the full environment (including \OC::$server) is available.
if (file_exists(__DIR__.'/../../../lib/base.php')) {
    include_once __DIR__.'/../../../lib/base.php';
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__.'/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('Test\\', $serverTestsLib);
    $loader->register(true);
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This lets ScholiqToolProvider unit tests run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
    include_once __DIR__.'/Stubs/Mcp/IMcpToolProvider.php';
}

// ObjectEntity stub — loaded when the openregister runtime is absent.
// Required by CredentialVerifyControllerTest to mock ObjectService::find().
if (class_exists(\OCA\OpenRegister\Db\ObjectEntity::class) === false) {
    include_once __DIR__.'/Stubs/Db/ObjectEntity.php';
}

// AuditTrailMapper stub.
if (class_exists(\OCA\OpenRegister\Db\AuditTrailMapper::class) === false) {
    include_once __DIR__.'/Stubs/Db/AuditTrailMapper.php';
}

// ObjectService stub.
if (class_exists(\OCA\OpenRegister\Service\ObjectService::class) === false) {
    include_once __DIR__.'/Stubs/Service/ObjectService.php';
}

// TenantKeyService stub.
if (class_exists(\OCA\OpenRegister\Service\TenantKeyService::class) === false) {
    include_once __DIR__.'/Stubs/Service/TenantKeyService.php';
}

// AuditHashService stub.
if (class_exists(\OCA\OpenRegister\Service\AuditHashService::class) === false) {
    include_once __DIR__.'/Stubs/Service/AuditHashService.php';
}

// TransitionEngine stub.
if (class_exists(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class) === false) {
    include_once __DIR__.'/Stubs/Service/Lifecycle/TransitionEngine.php';
}

// Event stubs.
if (class_exists(\OCA\OpenRegister\Event\ObjectCreatedEvent::class) === false) {
    include_once __DIR__.'/Stubs/Event/ObjectCreatedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectCreatingEvent::class) === false) {
    include_once __DIR__.'/Stubs/Event/ObjectCreatingEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\ObjectTransitionedEvent::class) === false) {
    include_once __DIR__.'/Stubs/Event/ObjectTransitionedEvent.php';
}

if (class_exists(\OCA\OpenRegister\Event\DeepLinkRegistrationEvent::class) === false) {
    include_once __DIR__.'/Stubs/Event/DeepLinkRegistrationEvent.php';
}

// TalkLinkService stub.
if (class_exists(\OCA\OpenRegister\Service\TalkLinkService::class) === false) {
    include_once __DIR__.'/Stubs/Service/TalkLinkService.php';
}

// OC\Hooks\Emitter stub — loaded when the live Nextcloud server runtime (which ships
// lib/private/Hooks/Emitter.php) is absent. OCP\Files\IRootFolder extends this OC-internal
// (non-OCP) interface; the nextcloud/ocp Composer package only ships OCP\* definitions, so
// mocking IRootFolder (PortfolioShareGrantHandlerTest, eportfolio) fails to resolve its full
// interface hierarchy without this stub in a standalone `docker run php:8.3-cli` run.
if (interface_exists(\OC\Hooks\Emitter::class) === false) {
    include_once __DIR__.'/Stubs/Hooks/Emitter.php';
}
