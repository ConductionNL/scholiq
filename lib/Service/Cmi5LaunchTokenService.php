<?php

/**
 * Scholiq cmi5 Launch Token Service
 *
 * Mints RS256 JWT launch tokens for cmi5 AU (Assignable Unit) launches.
 * This is a legitimate PHP service per ADR-031 §"What apps SHOULD still write
 * in PHP" — cryptographic JWT signing cannot be delegated to OpenRegister's
 * schema-declarative abstractions.
 *
 * The full JWT signing implementation (openssl_sign with the RS256 private
 * key stored in OCP\Security\ICrypto under key 'scholiq.cmi5.launch.private')
 * ships in the course-management change. This stub satisfies the DI
 * registration requirement for the v0.1 wedge and is unit-testable with
 * a mock ICrypto.
 *
 * CR-4 (wave-7): The original stub threw RuntimeException, causing ALL cmi5
 * launches to return HTTP 500. Replaced with a graceful disabled path:
 *   - isEnabled() returns false until the course-management change ships.
 *   - mintLaunchToken() returns an empty string (no exception) so a future
 *     controller can call isEnabled() first and return HTTP 503 cleanly.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\Security\ICrypto;

/**
 * Mints RS256 JWT launch tokens for cmi5 AU launches.
 *
 * Per ADR-031: cryptographic operations (JWT signing, key management) are a
 * legitimate PHP seam that cannot be expressed as schema metadata.
 *
 * Status: DISABLED until the course-management change provisions the RS256
 * key-pair. Callers MUST check isEnabled() and return HTTP 503 when false.
 *
 * Full implementation requirements (cmi5 §8.2 Launch Token JWT):
 *   - Header: {"alg":"RS256","typ":"JWT"}
 *   - Claims: iss (app URL), sub (learnerId), aud (lessonId),
 *             iat (Unix now), exp (iat + 3600), jti (registrationId),
 *             activityId (AU xAPI activity IRI), registration (UUID)
 *   - Sign with RS256 private key from ICrypto::decrypt(PRIVATE_KEY_NAME)
 *   - Return base64url(header).base64url(payload).base64url(signature)
 *
 * TODO(course-management): implement mintLaunchToken() once key-pair generation
 * endpoint (CredentialSigningController) is implemented and the private key is
 * stored in ICrypto under PRIVATE_KEY_NAME. Tracking: see GitHub issue filed
 * with CR-4 wave-7 fix.
 */
class Cmi5LaunchTokenService
{
    /**
     * ICrypto key name for the cmi5 RS256 launch private key.
     * Used by mintLaunchToken() once the course-management change implements signing.
     *
     * @phpstan-ignore classConstant.unused
     */
    public const PRIVATE_KEY_NAME = 'scholiq.cmi5.launch.private';

    /**
     * Constructor.
     *
     * @param ICrypto $crypto Nextcloud crypto service for encrypted key storage.
     */
    public function __construct(
        // @phpstan-ignore-next-line
        private readonly ICrypto $crypto,
    ) {
    }//end __construct()

    /**
     * Returns whether cmi5 launch token minting is available.
     *
     * Controllers MUST call this before mintLaunchToken() and return HTTP 503
     * with a human-readable body when false. Example:
     *
     *   if ($this->cmi5LaunchTokenService->isEnabled() === false) {
     *       return new JSONResponse(['error' => 'cmi5_not_available'], 503);
     *   }
     *   $token = $this->cmi5LaunchTokenService->mintLaunchToken(...);
     *
     * Returns true once the course-management change ships the RS256 key-pair.
     *
     * @return bool False until the course-management change provisions the signing key.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
     */
    public function isEnabled(): bool
    {
        // CR-4 mitigation: cmi5 is disabled until the course-management change
        // provisions the RS256 key-pair. Change this to true (or a key-existence
        // check via ICrypto) once the signing key is stored.
        return false;
    }//end isEnabled()

    /**
     * Mint a cmi5 AU launch JWT (RS256).
     *
     * Claims per the cmi5 specification §8.2 (Launch Token):
     *   - iss  : issuer (app URL)
     *   - sub  : learnerId (actor account homePage + name)
     *   - aud  : lessonId (cmi5 AU IRI)
     *   - iat  : issued-at (Unix timestamp)
     *   - exp  : expiry (iat + 3600 seconds)
     *   - jti  : registrationId (UUID, deduplicates launches)
     *   - activityId : AU xAPI activity IRI
     *   - registration : enrolment UUID
     *
     * IMPORTANT: Always call isEnabled() first. Returns an empty string when
     * cmi5 is disabled (course-management change not yet deployed) — callers
     * that skip the isEnabled() guard will silently receive an empty token.
     *
     * @param string $learnerId      The learner's Nextcloud user ID.
     * @param string $lessonId       The lesson UUID (cmi5 AU identifier).
     * @param string $registrationId A UUID identifying this enrolment attempt.
     *
     * @return string Signed JWT string, or empty string when cmi5 is disabled.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * TODO(course-management): implement with openssl_sign once key-pair is provisioned.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
     */
    public function mintLaunchToken(string $learnerId, string $lessonId, string $registrationId): string
    {
        // CR-4 mitigation: cmi5 is not yet implemented.
        // Callers that check isEnabled() first return HTTP 503, not HTTP 500.
        // Full RS256 signing lands in the course-management change.
        // Implementation steps: (1) decrypt private key via ICrypto; (2) build
        // JWT header+payload with iss/sub/aud/iat/exp/jti/activityId/registration;
        // (3) openssl_sign with OPENSSL_ALGO_SHA256; (4) base64url-encode + concat.
        return '';
    }//end mintLaunchToken()
}//end class
