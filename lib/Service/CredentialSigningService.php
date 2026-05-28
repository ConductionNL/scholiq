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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
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
            'scholiq.credentialVerify.verify',
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

        $publicKey = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );

        if ($publicKey !== '') {
            // Use 32-char fingerprint (128-bit) to match KeyManagementService. Fixes #193.
            $kid = substr(hash('sha256', $publicKey), 0, 32);
        } else {
            $kid = 'unknown';
        }

        $payload['proof'] = [
            'type'               => 'DataIntegrityProof',
            'cryptosuite'        => 'rsa-signature-2025',
            'created'            => (new DateTimeImmutable())->format(\DATE_ATOM),
            'verificationMethod' => $issuerDid.'#'.$kid,
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
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

        // #190: credentialSubject.id must never embed a BSN, email, or NC UID.
        // We UUID-validate learnerId and fall back to a hash-based pseudonym if
        // it looks like an e-mail address or otherwise non-UUID identifier, ensuring
        // PII is never embedded in a publicly shareable verifiable credential.
        $subjectId = $this->buildSubjectId(learnerId: $learnerId);

        $payload['credentialSubject'] = [
            'type'        => 'AchievementSubject',
            'id'          => $subjectId,
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
     * Produces an RFC 7515 compact JWS with detached payload (b64:false, RFC 7797).
     * Header and signature are base64url-encoded per RFC 4648 §5. The `kid` value
     * is derived from the SHA-256 fingerprint of the tenant's public key so that
     * external verifiers can identify the correct verification method in the DID
     * document without holding prior state.
     *
     * @param array<string,mixed> $payload  The JSON-LD payload to sign.
     * @param string              $tenantId The tenant UUID whose key to use.
     *
     * @return string|null RS256 compact JWS string, or null if the key is absent / signing fails.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
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

        // Derive kid from the public-key fingerprint stored at key-generation time.
        $publicKeyPem = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );
        if ($publicKeyPem !== '') {
            // Use 32-char fingerprint (128-bit) to match KeyManagementService. Fixes #193.
            $kid = substr(hash('sha256', $publicKeyPem), 0, 32);
        } else {
            $kid = 'unknown';
        }

        // H6: RFC 8785 (JCS) — sort keys recursively before encoding so the
        // signing input is deterministic regardless of PHP version or array
        // construction order. Without this, re-assembling the same payload data
        // in a different call path could produce a different byte string and
        // fail signature verification.
        $canonicalPayload = $this->canonicalisePayload(payload: $payload);
        $canonicalised    = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalised === false) {
            return null;
        }

        // RFC 7515: header must be base64url — NOT plain base64 ('+', '/', '=' are invalid in JWS).
        $headerJson   = json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => $kid]);
        $header       = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        $signingInput = $header.'.'.$canonicalised;

        $signature = '';
        $result    = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
        if ($result === false) {
            return null;
        }

        return $header.'..'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }//end signPayload()

    /**
     * Build a privacy-safe credentialSubject.id from learnerId.
     *
     * If the learnerId is a valid UUID it is used verbatim as a URN (safe, opaque).
     * Otherwise — to prevent embedding an email, NC UID, or BSN — the value is
     * replaced with a deterministic SHA-256 pseudonym so no PII appears in the
     * publicly shareable credential. Fixes #190.
     *
     * @param string $learnerId Raw learner identifier from the Credential record.
     *
     * @return string `urn:scholiq:learner:{uuid-or-pseudonym}`.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    /**
     * Recursively sort an array's keys (RFC 8785 JCS) for deterministic JSON output.
     *
     * Guarantees that the signing input produced by signPayload() is identical to
     * the input reconstructed by CredentialVerifyController::verifyJwsSignature(),
     * regardless of the order in which PHP populates the payload array.
     *
     * Arrays with integer keys (JSON arrays) are NOT key-sorted — only associative
     * maps (objects in JSON) are sorted.
     *
     * @param array<string,mixed> $payload The payload array to canonicalise.
     *
     * @return array<string,mixed> The same data with all object keys sorted.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    private function canonicalisePayload(array $payload): array
    {
        // Detect whether this level is a JSON object (string keys) or JSON array (int keys).
        $isObject = count(array_filter(array_keys($payload), 'is_string')) > 0;

        if ($isObject === true) {
            ksort($payload, SORT_STRING);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value) === true) {
                $payload[$key] = $this->canonicalisePayload(payload: $value);
            }
        }

        return $payload;

    }//end canonicalisePayload()

    /**
     * Build a privacy-safe credentialSubject.id from learnerId.
     *
     * If the learnerId is a valid UUID it is used verbatim as a URN (safe, opaque).
     * Otherwise — to prevent embedding an email, NC UID, or BSN — the value is
     * replaced with a deterministic SHA-256 pseudonym so no PII appears in the
     * publicly shareable credential. Fixes #190.
     *
     * @param string $learnerId Raw learner identifier from the Credential record.
     *
     * @return string `urn:scholiq:learner:{uuid-or-pseudonym}`.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    private function buildSubjectId(string $learnerId): string
    {
        // UUID v4 regex — only format guaranteed to be opaque / non-PII.
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        if (preg_match($uuidPattern, $learnerId) === 1) {
            return 'urn:scholiq:learner:'.$learnerId;
        }

        // Non-UUID: pseudonymise with a stable one-way hash to avoid embedding PII.
        $pseudonym = 'pseudo-'.hash('sha256', 'scholiq-learner:'.$learnerId);
        return 'urn:scholiq:learner:'.$pseudonym;
    }//end buildSubjectId()

    /**
     * Resolve the DID for a tenant from app config.
     *
     * Falls back to a synthetic did:web based on the public key fingerprint
     * stored at setup time.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return string|null DID string, or null if no key has been generated yet.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
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
        // Use 32-char fingerprint (128-bit) to match KeyManagementService.
        $fingerprint = substr(hash('sha256', $publicKey), 0, 32);

        return 'did:web:scholiq:'.$tenantId.':'.$fingerprint;
    }//end resolveIssuerDid()
}//end class
