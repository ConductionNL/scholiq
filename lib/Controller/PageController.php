<?php

/**
 * Scholiq Page Controller
 *
 * Renders the SPA shell and serves the bundled app manifest (ADR-024 §4).
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Renders the main SPA template and serves the bundled app manifest.
 *
 * Per ADR-024 §4: the /api/manifest endpoint returns the bundled manifest
 * blob unchanged (v0.1). A partial-override hook from IAppConfig is deferred
 * to v0.2 — the frontend loader's silent-fallback path is exercised in v0.1.
 */
class PageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest $request The request object.
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main SPA page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude framework glue — returns the static index TemplateResponse that boots the Vue SPA; no business behavior
     */
    public function index(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end index()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to index().
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec exclude framework glue — deep-link catch-all that delegates to index() so Vue Router can resolve the path; no business behavior
     */
    public function catchAll(): TemplateResponse
    {
        return $this->index();
    }//end catchAll()

    /**
     * Return the bundled app manifest as JSON (ADR-024 §4).
     *
     * V0.1: returns the bundled src/manifest.json blob unchanged.
     * V0.2 (deferred): will merge partial overrides from IAppConfig for
     * admin-customised menu order / hidden pages.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-5
     */
    public function manifest(): JSONResponse
    {
        $manifestPath = __DIR__.'/../../src/manifest.json';
        $manifestJson = file_get_contents($manifestPath);
        $manifest     = json_decode($manifestJson, associative: true);

        return new JSONResponse($manifest);
    }//end manifest()
}//end class
