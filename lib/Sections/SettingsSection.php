<?php

/**
 * Scholiq Settings Section (AppHost stub)
 *
 * One-line subclass of the OpenRegister AppHost {@see GenericSettingsSection}
 * (ADR-040). The class name must physically exist in Scholiq's namespace
 * because info.xml `<settings><admin-section>` loads it by class name. The
 * generic provides the IIconSection (id `scholiq`, name "Scholiq", icon
 * app-dark.svg, priority 75).
 *
 * @category Sections
 * @package  OCA\Scholiq\Sections
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

namespace OCA\Scholiq\Sections;

use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;

/**
 * AppHost stub for Scholiq's admin settings section.
 */
class SettingsSection extends GenericSettingsSection
{
}//end class
