<?php

/**
 * Scholiq Key Admin Controller
 *
 * Admin-only endpoints for managing the per-tenant RSA keypair used for
 * Open Badges 3.0 credential signing. Wraps KeyManagementService.
 *
 * Legitimate PHP per ADR-031: "Cryptographic operation — admin action that
 * generates / rotates RSA-2048 keypairs via openssl; cannot be expressed as
 * a schema declaration."
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
use OCA\Scholiq\Service\KeyManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only endpoints for RSA keypair generation and status.
 *
 * Non-admin requests are rejected with HTTP 403 by Nextcloud's middleware
 * (no @NoAdminRequired annotation).
 */
class KeyAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The HTTP request.
     * @param KeyManagementService $keyManagementService Key management service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly KeyManagementService $keyManagementService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate or rotate the RSA-2048 signing keypair for a tenant.
     *
     * @return JSONResponse {fingerprint, publicKey} on success; {error} on failure.
     */
    public function generateKey(): JSONResponse
    {
        $tenantId = $this->request->getParam('tenantId', '');

        if ($tenantId === '') {
            return new JSONResponse(['error' => 'tenantId is required'], 400);
        }

        try {
            $result = $this->keyManagementService->generateTenantKeypair(tenantId: $tenantId);
            return new JSONResponse($result, 201);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end generateKey()

    /**
     * Return the public key status (fingerprint + public key PEM) for a tenant.
     *
     * @return JSONResponse {fingerprint, publicKey} or {configured: false}.
     */
    public function keyStatus(): JSONResponse
    {
        $tenantId = $this->request->getParam('tenantId', '');

        if ($tenantId === '') {
            return new JSONResponse(['error' => 'tenantId is required'], 400);
        }

        $status = $this->keyManagementService->getTenantKeyStatus(tenantId: $tenantId);

        if ($status === null) {
            return new JSONResponse(['configured' => false]);
        }

        return new JSONResponse(array_merge(['configured' => true], $status));
    }//end keyStatus()
}//end class
