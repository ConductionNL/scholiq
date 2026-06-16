<?php

/**
 * Scholiq Action Authorization Service (AppHost stub)
 *
 * One-line subclass of the OpenRegister AppHost {@see GenericActionAuthService}
 * (ADR-040). The class name must physically exist in Scholiq's namespace because
 * five domain controllers (KeyAdmin/ActionMatrix/AuditPackExport/QtiImport/
 * ExternalTraining/Rollover) and the InitializeActions repair step type-hint
 * `OCA\Scholiq\Service\ActionAuthService` in their constructors; the generic
 * carries all ADR-023 behaviour (matrix in IAppConfig under `scholiq.actions`).
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;

/**
 * AppHost stub for Scholiq's ADR-023 action-authorization service.
 */
class ActionAuthService extends GenericActionAuthService
{
}//end class
