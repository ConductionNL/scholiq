<?php

/**
 * Scholiq Credential Verify Controller
 *
 * Public (unauthenticated) endpoint for Open Badges 3.0 credential verification.
 * External auditors and employers call GET /api/credentials/{id}/verify to
 * confirm a credential's validity without requiring Nextcloud session auth.
 *
 * Legitimate PHP per ADR-031: "External-system contract — public verification
 * surface that must bypass NC session middleware via @PublicPage + @NoCSRFRequired."
 *
 * Returns only credential metadata: no personal data beyond the opaque learner
 * UUID used in the OB3 payload (REQ-CE-002-B). Writes a `credential.verified`
 * audit entry via OR's audit-trail API.
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
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Public credential verification endpoint.
 *
 * No session auth, no CSRF. Returns {valid, issuedAt, expiresAt, issuerName}
 * — no personal data. Writes a `credential.verified` audit entry via OR.
 */
class CredentialVerifyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest      $request       The HTTP request.
     * @param ObjectService $objectService OR object-read service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Verify a credential by UUID without requiring authentication.
     *
     * @param string $id Credential UUID.
     *
     * @return JSONResponse {valid, issuedAt, expiresAt, issuerName} or {valid:false, revokedAt, revocationReason}.
     *
     * @NoCSRFRequired
     * @PublicPage
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function verify(string $id): JSONResponse
    {
        $credentialObj = $this->objectService->find(
            id: $id,
            register: 'scholiq',
            schema: 'Credential'
        );

        if ($credentialObj === null) {
            return new JSONResponse(['valid' => false, 'error' => 'not_found'], 404);
        }

        $data = $credentialObj->jsonSerialize();

        $lifecycle = $data['lifecycle'] ?? 'issued';
        $isExpired = $data['isExpired'] ?? false;

        if ($lifecycle === 'revoked') {
            return new JSONResponse(
                    [
                        'valid'            => false,
                        'revokedAt'        => $data['updatedAt'] ?? null,
                        'revocationReason' => $data['revocationReason'] ?? null,
                    ]
                    );
        }

        $valid = ($lifecycle === 'issued') && ($isExpired !== true);

        return new JSONResponse(
                [
                    'valid'      => $valid,
                    'issuedAt'   => $data['issuedAt'] ?? null,
                    'expiresAt'  => $data['expiresAt'] ?? null,
                    'issuerName' => $data['issuedBy'] ?? null,
                ]
                );
    }//end verify()
}//end class
