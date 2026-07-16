<?php

/**
 * Test/static-analysis stub for
 * OCA\OpenRegister\AppHost\Service\GenericActionAuthService.
 *
 * Pre-existing gap fixed while touching LearningRecordImportController
 * (portable-learning-record): `requireAction()`/`getMatrix()`/`setMatrix()`
 * were already called by six other controllers
 * (CoursePackageImportController, QtiImportController,
 * AuditPackExportController, CoursePackageExportController,
 * ExternalTrainingController, RolloverController, ActionMatrixController)
 * with no stub for phpstan to resolve — every one of them silently carried
 * the identical "Call to an undefined method ...::requireAction()" error.
 * Adding this stub (mirroring the existing OCA\OpenRegister\Service\
 * ObjectService / Lifecycle\TransitionEngine stub pattern) fixes all of
 * them at once, per the fleet's "no per-app validation rules" directive.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Service\AppHost
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\IUser;

/**
 * Minimal GenericActionAuthService stub for Scholiq unit tests / static analysis.
 *
 * Concrete (not abstract) methods, mirroring the real class's shape: Scholiq's
 * own `ActionAuthService` (lib/Service/ActionAuthService.php) is a one-line
 * subclass with no method bodies of its own — it relies entirely on the
 * parent's concrete implementations at runtime. Declaring these `abstract`
 * here would force phpstan to demand overrides that don't exist.
 */
class GenericActionAuthService
{
    /**
     * @param string $appId
     * @param mixed  $appConfig
     * @param mixed  $groupManager
     */
    public function __construct($appId, $appConfig, $groupManager)
    {
    }//end __construct()

    /**
     * @param IUser  $user
     * @param string $action
     * @return void
     */
    public function requireAction(IUser $user, string $action): void
    {
    }//end requireAction()

    /**
     * @return array<string,array<int,string>>
     */
    public function getMatrix(): array
    {
        return [];
    }//end getMatrix()

    /**
     * @param array<string,array<int,string>> $matrix
     * @return void
     */
    public function setMatrix(array $matrix): void
    {
    }//end setMatrix()
}//end class
