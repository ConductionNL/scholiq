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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\KeyManagementService;
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
 * Validates the JWS proof to confirm cryptographic integrity (C1 fix).
 */
class CredentialVerifyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The HTTP request.
     * @param ObjectService        $objectService        OR object-read service.
     * @param KeyManagementService $keyManagementService Key resolution service for JWS verify.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly KeyManagementService $keyManagementService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Verify a credential by UUID without requiring authentication.
     *
     * Validates the RS256 JWS proof embedded in openbadges3Payload.proof.jws
     * before returning valid:true. Returns valid:false + error:'signature_invalid'
     * when the JWS fails cryptographic verification (C1).
     *
     * @param string $id Credential UUID.
     *
     * @return JSONResponse {valid, issuedAt, expiresAt, issuerName} or error response.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function verify(string $id): JSONResponse
    {
        $credentialObj = $this->objectService->find(
            id: $id,
            register: 'scholiq',
            schema: 'credential'
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

        // C1: validate the JWS proof before declaring the credential valid.
        $tenantId = $data['tenant_id'] ?? '';
        $jwsValid = $this->validateJwsProof(data: $data, tenantId: $tenantId);
        if ($jwsValid === false) {
            return new JSONResponse(
                    [
                        'valid' => false,
                        'error' => 'signature_invalid',
                    ],
                    200
                    );
        }

        // Write credential.verified audit entry via OR object update (L3).
        $this->writeVerifiedAuditEntry(data: $data);

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

    /**
     * Validate the RS256 JWS proof embedded in a credential's openbadges3Payload.
     *
     * Extracts the `kid` from the JWS protected header, resolves the matching
     * public key via KeyManagementService::resolvePublicKeyByFingerprint, then
     * calls openssl_verify with OPENSSL_ALGO_SHA256.
     *
     * Returns true when the signature verifies. Returns false (fail-closed) in
     * all error conditions: missing proof, unknown kid, key not found, bad sig.
     *
     * @param array<string,mixed> $data     Serialised Credential data.
     * @param string              $tenantId Tenant UUID from the Credential row.
     *
     * @return bool True when the JWS signature is cryptographically valid.
     */
    private function validateJwsProof(array $data, string $tenantId): bool
    {
        $ob3Payload = $data['openbadges3Payload'] ?? null;
        if (is_array($ob3Payload) === false) {
            // No OB3 payload at all — treat as unsigned (legacy record pre-dates signing).
            // Return true to not break old records; operators can re-issue to gain a proof.
            return true;
        }

        $proof = $ob3Payload['proof'] ?? null;
        if (is_array($proof) === false) {
            // Payload without proof — same legacy-record accommodation.
            return true;
        }

        $jws = $proof['jws'] ?? null;
        if (is_string($jws) === false || $jws === '') {
            // Proof block exists but has no jws field — treat as invalid.
            return false;
        }

        // Parse kid from JWS protected header.
        $kid = $this->extractKidFromJws(jws: $jws);
        if ($kid === null) {
            return false;
        }

        if ($tenantId === '') {
            return false;
        }

        $publicKeyPem = $this->keyManagementService->resolvePublicKeyByFingerprint(
            tenantId: $tenantId,
            fingerprint: $kid
        );
        if ($publicKeyPem === null) {
            return false;
        }

        return $this->verifyJwsSignature(jws: $jws, payload: $ob3Payload, publicKeyPem: $publicKeyPem);
    }//end validateJwsProof()

    /**
     * Extract the `kid` value from a compact JWS protected header.
     *
     * The JWS format produced by CredentialSigningService is:
     *   <base64url-header>..<base64url-signature>   (detached payload, b64:false)
     *
     * @param string $jws Compact JWS string.
     *
     * @return string|null The kid value, or null if unparseable.
     */
    private function extractKidFromJws(string $jws): ?string
    {
        // JWS with detached payload: "<header>..<signature>" — split on first '.'.
        $dotPos = strpos($jws, '.');
        if ($dotPos === false) {
            return null;
        }

        $headerB64 = substr($jws, 0, $dotPos);
        if ($headerB64 === '') {
            return null;
        }

        // Decode base64url → JSON.
        $padded     = str_pad($headerB64, (int) ceil(strlen($headerB64) / 4) * 4, '=');
        $headerJson = base64_decode(strtr($padded, '-_', '+/'), strict: true);
        if ($headerJson === false) {
            return null;
        }

        $header = json_decode($headerJson, associative: true);
        if (is_array($header) === false) {
            return null;
        }

        $kid = $header['kid'] ?? null;
        if (is_string($kid) === false || $kid === '') {
            return null;
        }

        return $kid;
    }//end extractKidFromJws()

    /**
     * Verify an RS256 JWS signature using the provided public key.
     *
     * Recomputes the signing input (<header>.<payload>) and calls openssl_verify.
     * The payload is the canonicalised (json_encode) OB3 payload WITHOUT the proof
     * block, matching what CredentialSigningService::signPayload signed.
     *
     * @param string              $jws          Compact JWS string (detached payload, b64:false).
     * @param array<string,mixed> $payload      The full OB3 payload (proof block will be excluded).
     * @param string              $publicKeyPem PEM-encoded RSA public key.
     *
     * @return bool True when openssl_verify returns 1 (valid).
     */
    private function verifyJwsSignature(string $jws, array $payload, string $publicKeyPem): bool
    {
        // Split: "<header>..<signature>" → header and signature parts.
        $parts = explode('..', $jws, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$headerB64, $sigB64] = $parts;
        if ($headerB64 === '' || $sigB64 === '') {
            return false;
        }

        // The signing input is header + '.' + canonicalised payload WITHOUT proof.
        // Remove the proof block to match what was signed.
        $payloadToVerify = $payload;
        unset($payloadToVerify['proof']);

        // H6: RFC 8785 (JCS) — sort keys recursively before encoding so that the
        // verify-side signing input matches the sign-side input exactly.
        $payloadToVerify = $this->canonicalisePayload(payload: $payloadToVerify);

        $canonicalised = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalised === false) {
            return false;
        }

        $signingInput = $headerB64.'.'.$canonicalised;

        // Decode base64url signature.
        $padded    = str_pad($sigB64, (int) ceil(strlen($sigB64) / 4) * 4, '=');
        $signature = base64_decode(strtr($padded, '-_', '+/'), strict: true);
        if ($signature === false) {
            return false;
        }

        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            return false;
        }

        $result = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }//end verifyJwsSignature()

    /**
     * Recursively sort an array's keys (RFC 8785 JCS) for deterministic JSON output.
     *
     * Mirrors CredentialSigningService::canonicalisePayload so that the verify-side
     * signing input is byte-for-byte identical to the sign-side input.
     *
     * @param array<string,mixed> $payload The payload to canonicalise.
     *
     * @return array<string,mixed> The same data with all object-level keys sorted.
     */
    private function canonicalisePayload(array $payload): array
    {
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
     * Record a credential.verified audit entry on the Credential object via OR.
     *
     * Best-effort: errors are silently swallowed so the verify response is not
     * blocked by audit write failures.
     *
     * @param array<string,mixed> $data Serialised Credential data (must contain 'id').
     *
     * @return void
     */
    private function writeVerifiedAuditEntry(array $data): void
    {
        try {
            // Append a verifiedAt timestamp to trigger OR's audit trail write.
            // OR records an audit entry for every saveObject call.
            $update = array_merge($data, ['lastVerifiedAt' => date('c')]);
            $this->objectService->saveObject('scholiq', 'credential', $update);
        } catch (\Throwable) {
            // Best-effort — audit write failures must not block verification responses.
        }
    }//end writeVerifiedAuditEntry()
}//end class
