<?php

/**
 * Scholiq Initialize Settings Repair Step
 *
 * Repair step that initializes Scholiq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Scholiq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-26
 */

declare(strict_types=1);

namespace OCA\Scholiq\Repair;

use OCA\Scholiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Scholiq configuration via SettingsService.
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Initialize Scholiq register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Scholiq configuration.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-26
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Scholiq configuration...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed or enabled. Skipping auto-configuration.'
            );
            $this->logger->warning(
                'Scholiq: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            $result = $this->settingsService->loadConfiguration(force: true);

            if ($result['success'] === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(
                    'Scholiq configuration imported successfully (version: '.$version.')'
                );
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning(
                'Scholiq configuration import issue: '.$message
            );
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Scholiq: '.$e->getMessage());
            $this->logger->error(
                'Scholiq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
