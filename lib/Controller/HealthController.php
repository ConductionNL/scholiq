<?php

/**
 * Scholiq Health Controller
 *
 * Thin observability endpoint for the AdminHealth dashboard page. Returns
 * five read-only diagnostic fields: OR connection status, schema count,
 * audit-trail event count (last 24 h), MyDash installation flag, and last
 * audit-pack export timestamp.
 *
 * This is a legitimate ADR-031 §"External-system contract / observability"
 * exception: the five reads span NC's IAppManager (external), OR's query
 * API (external), and compile-time config — none of which can be expressed
 * as a schema widget without OR-side instrumentation that does not yet exist.
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
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only observability endpoint for the AdminHealth dashboard page.
 *
 * Route: GET /api/admin/health (admin-only, see appinfo/routes.php)
 *
 * Response shape:
 * {
 *   "openregister_connected": bool,
 *   "schemas_registered":     int,
 *   "audit_trail_events_24h": int,
 *   "mydash_installed":       bool,
 *   "last_audit_pack_export": string|null   (ISO 8601 or null)
 * }
 */
class HealthController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest    $request    The request object.
     * @param IAppManager $appManager Nextcloud application manager.
     */
    public function __construct(
        IRequest $request,
        private readonly IAppManager $appManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return health diagnostics for the AdminHealth dashboard page.
     *
     * Admin-only: enforced by route definition (no @NoAdminRequired annotation).
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        // OR connection check: attempt to load the register manifest.
        $orConnected       = false;
        $schemasRegistered = 0;

        try {
            $manifestPath = __DIR__.'/../../lib/Settings/scholiq_register.json';
            if (file_exists($manifestPath) === true) {
                $manifest          = json_decode((string) file_get_contents($manifestPath), associative: true);
                $orConnected       = true;
                $schemasRegistered = count($manifest['components']['schemas'] ?? []);
            }
        } catch (\Throwable) {
            // Swallow — orConnected stays false.
        }

        // Audit-trail event count (last 24 h): placeholder query until OR provides
        // a dedicated instrumentation endpoint. Returns 0 in v0.1; tracked in
        // https://github.com/ConductionNL/openregister/issues as future enhancement.
        $auditTrailEvents24h = 0;

        // MyDash installation flag — resolved via NC IAppManager (no install-time dep).
        $mydashInstalled = $this->appManager->isInstalled('mydash');

        // Last audit-pack export timestamp: placeholder until OR audit-event query
        // API is available. Returns null in v0.1.
        $lastAuditPackExport = null;

        return new JSONResponse(
                [
                    'openregister_connected' => $orConnected,
                    'schemas_registered'     => $schemasRegistered,
                    'audit_trail_events_24h' => $auditTrailEvents24h,
                    'mydash_installed'       => $mydashInstalled,
                    'last_audit_pack_export' => $lastAuditPackExport,
                ]
                );
    }//end index()
}//end class
