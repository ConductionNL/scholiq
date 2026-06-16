<?php

/**
 * Scholiq Admin Settings (AppHost stub)
 *
 * One-line subclass of the OpenRegister AppHost {@see GenericAdminSettings}
 * (ADR-040). The class name must physically exist in Scholiq's namespace
 * because info.xml `<settings><admin>` loads it by class name AND Scholiq's
 * domain controllers (KeyAdminController, ActionMatrixController,
 * AuditPackExportController, SettingsController) reference it in
 * `#[AuthorizedAdminSetting(AdminSettings::class)]`. The generic provides the
 * IDelegatedSettings form (section `scholiq`, priority 10).
 *
 * @category Settings
 * @package  OCA\Scholiq\Settings
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Settings;

use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;

/**
 * AppHost stub for Scholiq's admin settings panel.
 */
class AdminSettings extends GenericAdminSettings
{
}//end class
