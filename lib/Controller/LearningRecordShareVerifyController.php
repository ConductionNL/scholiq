<?php

/**
 * Scholiq Learning Record Share Verify Controller
 *
 * Public (unauthenticated) endpoint for verifying a `LearningRecordShare`'s
 * shared bundle. External employers, receiving-school admissions offices,
 * or anyone holding the share link call
 * `GET /api/learning-record-shares/{id}/verify` to see the shared,
 * cryptographically-verified bundle without a Nextcloud session — the same
 * public/unauthenticated, JWS-verifying, fail-closed pattern
 * `CredentialVerifyController` already establishes.
 *
 * Legitimate PHP per ADR-031 "External-system contract — public
 * verification surface that must bypass NC session middleware via
 *
 * @PublicPage + @NoCSRFRequired."
 *
 * Read-only except for `lastAccessedAt`/`accessCount`, which this
 * controller stamps on every SUCCESSFUL verification — never on a denied
 * one, so a probing attempt against a revoked/expired/invalid share does
 * not pollute the learner's own "was this viewed" signal.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\LearningRecordExportSigningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * Public verification endpoint for a LearningRecordShare's shared bundle.
 *
 * No session auth, no CSRF. Denies (no partial data) when revoked, expired,
 * or signature-invalid. On success returns only the bundle content.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-3-2
 */
class LearningRecordShareVerifyController extends Controller
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const SHARE_SCHEMA     = 'learning-record-share';
    private const EXPORT_SCHEMA    = 'learning-record-export';

    /**
     * Constructor.
     *
     * @param IRequest                           $request        HTTP request.
     * @param ObjectService                      $objectService  OR object read/update service.
     * @param LearningRecordExportSigningService $signingService JWS verification.
     * @param IRootFolder                        $rootFolder     NC root folder for reading the bundle file.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly LearningRecordExportSigningService $signingService,
        private readonly IRootFolder $rootFolder,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Verify a LearningRecordShare by UUID without requiring authentication.
     *
     * @param string $id LearningRecordShare UUID.
     *
     * @return JSONResponse `{valid: true, bundle: {...}}` on success, `{valid: false, reason}` otherwise.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-an-expired-share-is-denied-even-though-its-lifecycle-is-still-active
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function verify(string $id): JSONResponse
    {
        $share = $this->fetchObject(id: $id, schema: self::SHARE_SCHEMA);
        if ($share === null) {
            return new JSONResponse(['valid' => false, 'reason' => 'not_found'], 404);
        }

        $lifecycle = $share['lifecycle'] ?? '';
        if ($lifecycle === 'revoked') {
            return new JSONResponse(['valid' => false, 'reason' => 'revoked'], 200);
        }

        if (($share['isExpired'] ?? false) === true) {
            return new JSONResponse(['valid' => false, 'reason' => 'expired'], 200);
        }

        $exportId = $share['learningRecordExportId'] ?? '';
        $export   = null;
        if ($exportId !== '') {
            $export = $this->fetchObject(id: (string) $exportId, schema: self::EXPORT_SCHEMA);
        }

        if ($export === null) {
            return new JSONResponse(['valid' => false, 'reason' => 'export_not_found'], 200);
        }

        $bundle = $this->readBundle(export: $export);
        if ($bundle === null) {
            return new JSONResponse(['valid' => false, 'reason' => 'bundle_unreadable'], 200);
        }

        $jws      = (string) ($export['bundleSignature'] ?? '');
        $tenantId = (string) ($export['tenant_id'] ?? '');
        if ($jws === '' || $this->signingService->verify(jws: $jws, bundle: $bundle, tenantId: $tenantId) === false) {
            return new JSONResponse(['valid' => false, 'reason' => 'signature_invalid'], 200);
        }

        $this->stampAccess(share: $share);

        return new JSONResponse(['valid' => true, 'bundle' => $bundle], 200);
    }//end verify()

    /**
     * Read and decode the signed bundle file referenced by a
     * `LearningRecordExport.bundleRef`, owned by the export's `learnerId`.
     *
     * @param array<string,mixed> $export The LearningRecordExport data array.
     *
     * @return array<string,mixed>|null The decoded bundle, or null when unreadable.
     */
    private function readBundle(array $export): ?array
    {
        $bundleRef = (string) ($export['bundleRef'] ?? '');
        $ownerUid  = (string) ($export['learnerId'] ?? '');
        if ($bundleRef === '' || $ownerUid === '') {
            return null;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($ownerUid);
            $node       = $userFolder->get(ltrim($bundleRef, '/'));
            if (($node instanceof File) === false) {
                return null;
            }

            $decoded = json_decode($node->getContent(), associative: true);

            if (is_array($decoded) === true) {
                return $decoded;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }//end readBundle()

    /**
     * Stamp `lastAccessedAt`/`accessCount` on a successful verification.
     *
     * @param array<string,mixed> $share The LearningRecordShare data array (with its own `id`).
     *
     * @return void
     */
    private function stampAccess(array $share): void
    {
        $now         = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $accessCount = (int) ($share['accessCount'] ?? 0);

        $updated = $share;
        $updated['lastAccessedAt'] = $now;
        $updated['accessCount']    = $accessCount + 1;

        try {
            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::SHARE_SCHEMA,
                object: $updated
            );
        } catch (\Throwable) {
            // Best-effort — a failed access-stamp must never block a valid verification response.
        }
    }//end stampAccess()

    /**
     * Fetch an object by id/schema, normalising to an array whether OR returns
     * an array or an object exposing jsonSerialize() — mirrors
     * `LeaderboardController::fetchObject()`.
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        $obj = $this->objectService->find(id: $id, register: self::SCHOLIQ_REGISTER, schema: $schema);

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $serialized = $obj->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end fetchObject()
}//end class
