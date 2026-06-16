<?php

/**
 * Scholiq Initialize Actions Repair Step (AppHost stub)
 *
 * One-line subclass of the OpenRegister AppHost {@see GenericInitializeActions}
 * (ADR-040). The class name must physically exist in Scholiq's namespace
 * because info.xml `<repair-steps>` loads it by class name. The generic seeds
 * the ADR-023 action-authorization matrix from `lib/actions.seed.json` on fresh
 * install if the matrix (IAppConfig `scholiq.actions`) is empty, preserving any
 * admin-customised matrix on upgrade.
 *
 * @category Repair
 * @package  OCA\Scholiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Repair;

use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;

/**
 * AppHost stub for Scholiq's ADR-023 action-matrix seed repair step.
 */
class InitializeActions extends GenericInitializeActions
{
}//end class
