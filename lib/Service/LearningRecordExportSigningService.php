<?php

/**
 * Scholiq Learning Record Export Signing Service
 *
 * Canonicalises (RFC 8785 JCS) and signs a LearningRecordExport bundle with
 * the tenant's existing RS256 keypair — the SAME `scholiq.credential.signing
 * .{private,public}.{tenantId}` IAppConfig keys `KeyManagementService`
 * generates and `CredentialSigningService` already signs Credentials with.
 * No new key material, no new crypto primitive: the canonicalisation +
 * signing routine is ported verbatim from `CredentialSigningService`
 * (design.md: "reuse or port CredentialSigningService's RFC 8785 JCS
 * canonicalisation" — those methods are private on that class, so porting,
 * not sharing a trait, is the precedented shape; `WerkprocesAssessment`/
 * `PokSignature` are this register's own precedent for a parallel schema
 * over a shared shape instead of a widened one).
 *
 * Legitimate PHP per ADR-031 "Cryptographic operations" — the same
 * exception category `CredentialSigningService`/`KeyManagementService`
 * already occupy.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-the-export-bundle-is-cryptographically-signed-and-its-artefact-retained
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * Signs and verifies a LearningRecordExport bundle with the tenant's
 * existing RS256 keypair.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-2-4
 */
class LearningRecordExportSigningService
{

    /**
     * App config key prefix for encrypted tenant private keys — identical to
     * `KeyManagementService::PRIVATE_KEY_PREFIX`/`CredentialSigningService
     * ::PRIVATE_KEY_PREFIX`.
     */
    private const PRIVATE_KEY_PREFIX = 'scholiq.credential.signing.private.';

    /**
     * App config key prefix for public keys (plain) — identical to
     * `KeyManagementService::PUBLIC_KEY_PREFIX`/`CredentialSigningService
     * ::PUBLIC_KEY_PREFIX`.
     */
    private const PUBLIC_KEY_PREFIX = 'scholiq.credential.signing.public.';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud application config.
     * @param ICrypto    $crypto    Encrypt/decrypt wrapper for private key storage.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ICrypto $crypto,
    ) {
    }//end __construct()

    /**
     * Resolve the tenant's issuer DID the same way
     * `CredentialSigningService::resolveIssuerDid()` does — a synthetic
     * `did:web` derived from the tenant public key's fingerprint.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return string|null DID string, or null when no key has been generated yet.
     *
     * @spec openspec/changes/portable-learning-record/tasks.md#task-2-4
     */
    public function resolveIssuerDid(string $tenantId): ?string
    {
        $publicKey = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );

        if ($publicKey === '') {
            return null;
        }

        $fingerprint = substr(hash('sha256', $publicKey), 0, 32);

        return 'did:web:scholiq:'.$tenantId.':'.$fingerprint;
    }//end resolveIssuerDid()

    /**
     * Canonicalise (RFC 8785 JCS) and sign a bundle with the tenant's RS256
     * private key, producing a compact JWS with a detached payload
     * (`b64:false`, RFC 7797) — identical shape to
     * `CredentialSigningService::signPayload()`.
     *
     * @param array<string,mixed> $bundle   The bundle to canonicalise and sign.
     * @param string              $tenantId Tenant UUID whose key to use.
     *
     * @return string|null Compact JWS string, or null when the key is absent / signing fails.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-the-signature-verifies-against-the-tenant-s-existing-public-key
     */
    public function sign(array $bundle, string $tenantId): ?string
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

        $publicKeyPem = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );

        $kid = 'unknown';
        if ($publicKeyPem !== '') {
            $kid = substr(hash('sha256', $publicKeyPem), 0, 32);
        }

        $canonicalPayload = $this->canonicalise(payload: $bundle);
        $canonicalised    = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalised === false) {
            return null;
        }

        $headerJson   = json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => $kid]);
        $header       = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        $signingInput = $header.'.'.$canonicalised;

        $signature = '';
        $result    = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
        if ($result === false) {
            return null;
        }

        return $header.'..'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }//end sign()

    /**
     * Verify a compact JWS against the tenant's public key, re-canonicalising
     * the same bundle the caller believes was signed — mirrors
     * `CredentialVerifyController::verifyJwsSignature()`.
     *
     * @param string              $jws      Compact JWS string produced by {@see self::sign()}.
     * @param array<string,mixed> $bundle   The bundle believed to have been signed.
     * @param string              $tenantId Tenant UUID whose public key to verify against.
     *
     * @return bool True when the signature is cryptographically valid.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
     */
    public function verify(string $jws, array $bundle, string $tenantId): bool
    {
        $parts = explode('..', $jws, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$headerB64, $sigB64] = $parts;
        if ($headerB64 === '' || $sigB64 === '') {
            return false;
        }

        $publicKeyPem = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            default: ''
        );
        if ($publicKeyPem === '') {
            return false;
        }

        // The stored/downloaded artifact embeds `proof` alongside the signed
        // payload (mirrors Credential.openbadges3Payload.proof); the
        // signing input never included it — strip it before re-canonicalising,
        // exactly as CredentialVerifyController::verifyJwsSignature() does.
        unset($bundle['proof']);

        $canonicalPayload = $this->canonicalise(payload: $bundle);
        $canonicalised    = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonicalised === false) {
            return false;
        }

        $signingInput = $headerB64.'.'.$canonicalised;

        $padded    = str_pad($sigB64, (int) ceil(strlen($sigB64) / 4) * 4, '=');
        $signature = base64_decode(strtr($padded, '-_', '+/'), strict: true);
        if ($signature === false) {
            return false;
        }

        $pubKey = openssl_pkey_get_public($publicKeyPem);
        if ($pubKey === false) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256) === 1;
    }//end verify()

    /**
     * Recursively sort an array's keys (RFC 8785 JCS) for deterministic JSON
     * output — ported verbatim from `CredentialSigningService
     * ::canonicalisePayload()`/`CredentialVerifyController
     * ::canonicalisePayload()`.
     *
     * @param array<string,mixed> $payload The payload array to canonicalise.
     *
     * @return array<string,mixed> The same data with all object keys sorted.
     */
    private function canonicalise(array $payload): array
    {
        $isObject = count(array_filter(array_keys($payload), 'is_string')) > 0;

        if ($isObject === true) {
            ksort($payload, SORT_STRING);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value) === true) {
                $payload[$key] = $this->canonicalise(payload: $value);
            }
        }

        return $payload;
    }//end canonicalise()
}//end class
