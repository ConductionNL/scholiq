<?php

/**
 * Scholiq Settings Section
 *
 * Defines the Scholiq section in the Nextcloud admin settings.
 *
 * @category Sections
 * @package  OCA\Scholiq\Sections
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the Scholiq section in the Nextcloud admin settings.
 *
 * @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers
 */
class SettingsSection implements IIconSection
{
    /**
     * Constructor for SettingsSection.
     *
     * @param IL10N         $l            The localization service
     * @param IURLGenerator $urlGenerator The URL generator service
     *
     * @return void
     */
    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the section identifier.
     *
     * @return string
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers
     */
    public function getID(): string
    {
        return 'scholiq';
    }//end getID()

    /**
     * Get the display name of this section.
     *
     * @return string
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers
     */
    public function getName(): string
    {
        return $this->l->t('Scholiq');
    }//end getName()

    /**
     * Get the priority for ordering this section.
     *
     * @return int
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers
     */
    public function getPriority(): int
    {
        return 75;
    }//end getPriority()

    /**
     * Get the icon path for this section.
     *
     * @return string
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/nextcloud-app/spec.md#requirement-configure-default-register-and-ai-features-via-openregister-backed-pickers
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: 'scholiq', file: 'app-dark.svg');
    }//end getIcon()
}//end class
