<?php

/**
 * Scholiq Credential Signing Service
 *
 * Builds an Open Badges 3.0 JSON-LD assertion and signs it with the tenant's
 * RS256 keypair. Legitimate PHP per ADR-031: "Cryptographic operations that
 * must run before a state transition" — referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.issue.requires in scholiq_register.json.
 *
 * OpenRegister's lifecycle engine resolves this class via DI and calls check()
 * before executing the `issue` transition. check() assembles + signs the OB3
 * payload, injects it into the transitionContext object, and returns true to
 * allow the transition.
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

use DateTimeImmutable;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\IURLGenerator;

/**
 * Assembles the Open Badges 3.0 JSON-LD payload and signs it via RS256.
 *
 * Acts as an OR lifecycle guard on the `issue` transition of the Credential
 * schema. Single responsibility: sign. No CRUD, no notifications, no state
 * management — all of that is declared in the schema.
 */
class CredentialSigningService
{
    /**
     * App config key prefix for encrypted tenant private keys.
     */
    private const PRIVATE_KEY_PREFIX = 'scholiq.credential.signing.private.';

    /**
     * App config key prefix for public keys (plain).
     */
    private const PUBLIC_KEY_PREFIX = 'scholiq.credential.signing.public.';

    /**
     * Constructor.
     *
     * @param IAppConfig    $appConfig    Nextcloud application config.
     * @param ICrypto       $crypto       Encrypt/decrypt wrapper for private key storage.
     * @param IURLGenerator $urlGenerator Generates the public verification URL.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ICrypto $crypto,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `issue`
     * transition on a Credential object. Assembles the OB3 payload, signs it,
     * and injects `openbadges3Payload` + `signature` + `verificationUrl` back
     * into the context object so OR persists them in the same write.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Credential data array
     *                                               - 'transition' : 'issue'
     *                                               - 'from'       : null
     *                                               - 'to'         : 'issued'
     *
     * @return bool True if signing succeeded (transition allowed); false blocks transition.
     */
    public function check(array &$transitionContext): bool
    {
        $object = &$transitionContext['object'];

        $credentialId = $object['id'] ?? '';
        $learnerId    = $object['learnerId'] ?? '';
        $courseId     = $object['courseId'] ?? null;
        $issuedAt     = $object['issuedAt'] ?? (new DateTimeImmutable())->format(\DATE_ATOM);
        $expiresAt    = $object['expiresAt'] ?? null;
        $tenantId     = $object['tenant_id'] ?? '';
        $issuedBy     = $object['issuedBy'] ?? '';

        if ($learnerId === '' || $tenantId === '') {
            return false;
        }

        $issuerDid = $this->resolveIssuerDid(tenantId: $tenantId);
        if ($issuerDid === null) {
            return false;
        }

        $verificationUrl = $this->urlGenerator->linkToRouteAbsolute(
            'scholiq.credential.verify',
            ['id' => $credentialId]
        );

        $payload = $this->buildOb3Payload(
            credentialId: $credentialId,
            learnerId: $learnerId,
            courseId: $courseId,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            issuerDid: $issuerDid,
            issuedBy: $issuedBy,
            verificationUrl: $verificationUrl,
        );

        $jws = $this->signPayload(payload: $payload, tenantId: $tenantId);
        if ($jws === null) {
            return false;
        }

        $payload['proof'] = [
            'type'               => 'RsaSignature2018',
            'created'            => (new DateTimeImmutable())->format(\DATE_ATOM),
            'verificationMethod' => $issuerDid.'#keys-1',
            'proofPurpose'       => 'assertionMethod',
            'jws'                => $jws,
        ];

        $object['openbadges3Payload'] = $payload;
        $object['signature']          = $jws;
        $object['issuerDid']          = $issuerDid;
        $object['verificationUrl']    = $verificationUrl;

        return true;
    }//end check()

    /**
     * Assemble the Open Badges 3.0 JSON-LD assertion (without proof).
     *
     * @param string      $credentialId    UUID of the credential being issued.
     * @param string      $learnerId       Opaque learner UUID (never BSN).
     * @param string|null $courseId        UUID of the associated course, or null.
     * @param string      $issuedAt        ISO 8601 issuance timestamp.
     * @param string|null $expiresAt       ISO 8601 expiry timestamp, or null.
     * @param string      $issuerDid       DID of the issuing organisation.
     * @param string      $issuedBy        Display name of the issuing organisation.
     * @param string      $verificationUrl Public URL for unauthenticated verification.
     *
     * @return array<string,mixed> OB3 JSON-LD array (without proof).
     */
    public function buildOb3Payload(
        string $credentialId,
        string $learnerId,
        ?string $courseId,
        string $issuedAt,
        ?string $expiresAt,
        string $issuerDid,
        string $issuedBy,
        string $verificationUrl,
    ): array {
        $payload = [
            '@context'     => [
                'https://www.w3.org/2018/credentials/v1',
                'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
            ],
            'type'         => ['VerifiableCredential', 'OpenBadgeCredential'],
            'id'           => $verificationUrl,
            'issuer'       => [
                'id'   => $issuerDid,
                'type' => 'Profile',
                'name' => $issuedBy,
            ],
            'issuanceDate' => $issuedAt,
        ];

        $achievementId = 'urn:scholiq:manual:'.$credentialId;
        if ($courseId !== null) {
            $achievementId = 'urn:scholiq:course:'.$courseId;
        }

        $payload['credentialSubject'] = [
            'type'        => 'AchievementSubject',
            'id'          => 'urn:scholiq:learner:'.$learnerId,
            'achievement' => [
                'type' => 'Achievement',
                'id'   => $achievementId,
            ],
        ];

        if ($expiresAt !== null) {
            $payload['expirationDate'] = $expiresAt;
        }

        return $payload;
    }//end buildOb3Payload()

    /**
     * Sign a canonicalised payload with the tenant's RSA private key (RS256).
     *
     * @param array<string,mixed> $payload  The JSON-LD payload to sign.
     * @param string              $tenantId The tenant UUID whose key to use.
     *
     * @return string|null RS256 compact JWS string, or null if the key is absent / signing fails.
     */
    public function signPayload(array $payload, string $tenantId): ?string
    {
        $encryptedPrivateKey = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PRIVATE_KEY_PREFIX.$tenantId,
            default: ''
        );

        if ($encryptedPrivateKey === '') {
            return null;
        }

        try {
            $privateKeyPem = $this->crypto->decrypt($encryptedPrivateKey);
        } catch (\Exception) {
            return null;
        }

        $canonicalised = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalised === false) {
            return null;
        }

        $header       = base64_encode(json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64']]));
        $signingInput = $header.'.'.$canonicalised;

        $signature = '';
        $result    = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
        if ($result === false) {
            return null;
        }

        return $header.'..'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }//end signPayload()

    /**
     * Resolve the DID for a tenant from app config.
     *
     * Falls back to a synthetic did:web based on the public key fingerprint
     * stored at setup time.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return string|null DID string, or null if no key has been generated yet.
     */
    private function resolveIssuerDid(string $tenantId): ?string
    {
        $publicKey = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );

        if ($publicKey === '') {
            return null;
        }

        // Synthetic did:web using a fingerprint of the public key.
        $fingerprint = substr(hash('sha256', $publicKey), 0, 16);

        return 'did:web:scholiq:'.$tenantId.':'.$fingerprint;
    }//end resolveIssuerDid()
}//end class
