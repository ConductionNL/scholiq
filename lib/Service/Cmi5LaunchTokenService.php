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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use RuntimeException;
use OCP\Security\ICrypto;

/**
 * Mints RS256 JWT launch tokens for cmi5 AU launches.
 *
 * Per ADR-031: cryptographic operations (JWT signing, key management) are a
 * legitimate PHP seam that cannot be expressed as schema metadata.
 *
 * TODO(course-management change): implement mintLaunchToken() with openssl_sign
 * once the RS256 key-pair generation endpoint (CredentialSigningController) is
 * implemented and the private key is stored in ICrypto.
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
     * Mint a cmi5 AU launch JWT (RS256).
     *
     * Claims per the cmi5 specification §8.1 (Launch Parameters):
     *   - sub  : learnerId (actor account homePage + name)
     *   - iss  : issuer (app URL)
     *   - aud  : lessonId
     *   - iat  : issued-at (Unix timestamp)
     *   - exp  : expiry (iat + 3600 seconds)
     *   - jti  : registrationId (UUID, deduplicate launches)
     *
     * @param string $learnerId      The learner's Nextcloud user ID.
     * @param string $lessonId       The lesson UUID (cmi5 AU identifier).
     * @param string $registrationId A UUID identifying this enrolment attempt.
     *
     * @return string Signed JWT string.
     *
     * @throws RuntimeException When the signing key is not yet provisioned.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * TODO(course-management): implement with openssl_sign once key-pair is provisioned.
     */
    public function mintLaunchToken(string $learnerId, string $lessonId, string $registrationId): string
    {
        // V0.1 stub — signing key provisioned in course-management change.
        // Returns a placeholder token so routes register cleanly in the wedge.
        // Real implementation: decode private key from ICrypto, build JWT header
        // + payload, sign with openssl_sign(OPENSSL_ALGO_SHA256), base64url-encode.
        throw new RuntimeException(
            'Cmi5LaunchTokenService::mintLaunchToken is not yet implemented. '
            .'Full RS256 signing lands in the course-management change.'
        );
    }//end mintLaunchToken()
}//end class
