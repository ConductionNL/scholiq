<?php

/**
 * Scholiq Settings Controller
 *
 * Controller for managing Scholiq application settings.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for managing Scholiq application settings.
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Retrieve all current settings.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        return new JSONResponse(
            $this->settingsService->getSettings()
        );
    }//end index()

    /**
     * Update settings with provided data.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end create()

    /**
     * Re-import the configuration from scholiq_register.json.
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-26
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function load(): JSONResponse
    {
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()
}//end class
