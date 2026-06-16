<?php

/**
 * Scholiq Key Management Service
 *
 * Generates and stores RSA-2048 keypairs for per-tenant credential signing.
 * Legitimate PHP per ADR-031: "Cryptographic operations" exception. The private
 * key is encrypted at rest via OCP\Security\ICrypto; the public key and
 * fingerprint are stored plain for verification.
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

use OCP\IAppConfig;
use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Generates and stores RSA-2048 per-tenant keypairs for OB3 credential signing.
 *
 * Keys are stored in an append-only history model: on rotation the new keypair
 * becomes active while the old keypair is retained as verification-only (so that
 * previously-issued credentials remain verifiable). Per OB3 spec, verification
 * keys must remain resolvable for the lifetime of issued credentials.
 *
 * Cryptographic operation — legitimate PHP seam per ADR-031.
 */
class KeyManagementService
{
    /**
     * App config key prefix for encrypted private keys (active key only).
     */
    private const PRIVATE_KEY_PREFIX = 'scholiq.credential.signing.private.';

    /**
     * App config key prefix for public keys (plain PEM, active key only).
     */
    private const PUBLIC_KEY_PREFIX = 'scholiq.credential.signing.public.';

    /**
     * App config key prefix for public key fingerprints.
     */
    private const FINGERPRINT_KEY_PREFIX = 'scholiq.credential.signing.fingerprint.';

    /**
     * App config key prefix for archived (verification-only) public keys (JSON array of PEM strings).
     */
    private const ARCHIVED_KEYS_PREFIX = 'scholiq.credential.signing.archived_keys.';

    /**
     * Maximum number of archived public keys retained per tenant.
     * Keys beyond this cap are pruned oldest-first (L2).
     */
    private const MAX_ARCHIVED_KEYS = 32;

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud application configuration.
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
     * Generate an RSA-2048 keypair for a tenant and persist it.
     *
     * The private key is encrypted via ICrypto before storage. When a key already
     * exists for the tenant the previous public key is archived (verification-only)
     * before the new key becomes active — old credentials signed under the previous
     * key remain verifiable. Fixes #176.
     *
     * @param string $tenantId Tenant UUID (must be non-empty). Fixes #211.
     *
     * @return array{fingerprint: string, publicKey: string} Public key data.
     *
     * @throws \InvalidArgumentException If tenantId is empty.
     * @throws \RuntimeException         If OpenSSL key generation fails.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    public function generateTenantKeypair(string $tenantId): array
    {
        // #211: validate tenantId before touching key storage.
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenantId must not be empty.');
        }

        $resource = openssl_pkey_new(
                [
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                    'private_key_bits' => 2048,
                ]
                );

        if ($resource === false) {
            throw new RuntimeException('OpenSSL key generation failed: '.openssl_error_string());
        }

        $privateKeyPem = '';
        // #212: check openssl_pkey_export return value — silent failure stores null key.
        if (openssl_pkey_export($resource, $privateKeyPem) === false) {
            throw new RuntimeException('openssl_pkey_export() failed: '.openssl_error_string());
        }

        $details      = openssl_pkey_get_details($resource);
        $publicKeyPem = $details['key'] ?? '';

        if ($privateKeyPem === '' || $publicKeyPem === '') {
            throw new RuntimeException('Failed to export keypair from OpenSSL resource.');
        }

        // #193: use 32-char (full 128-bit) fingerprint to reduce birthday-attack collision risk.
        $fingerprint = substr(hash('sha256', $publicKeyPem), 0, 32);

        // #176: archive the current public key before overwriting the active key so that
        // previously-issued credentials (which embed the old kid) remain verifiable.
        $this->archiveCurrentPublicKey(tenantId: $tenantId);

        $encryptedPrivKey = $this->crypto->encrypt($privateKeyPem);

        $this->appConfig->setValueString(
            app: 'scholiq',
            key: self::PRIVATE_KEY_PREFIX.$tenantId,
            value: $encryptedPrivKey
        );
        $this->appConfig->setValueString(
            app: 'scholiq',
            key: self::PUBLIC_KEY_PREFIX.$tenantId,
            value: $publicKeyPem
        );
        $this->appConfig->setValueString(
            app: 'scholiq',
            key: self::FINGERPRINT_KEY_PREFIX.$tenantId,
            value: $fingerprint
        );

        return [
            'fingerprint' => $fingerprint,
            'publicKey'   => $publicKeyPem,
        ];
    }//end generateTenantKeypair()

    /**
     * Return the public key and fingerprint for a tenant, or null if absent.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array{fingerprint: string, publicKey: string}|null Key data, or null if not configured.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-3
     */
    public function getTenantKeyStatus(string $tenantId): ?array
    {
        $publicKey   = $this->appConfig->getValueString(app: 'scholiq', key: self::PUBLIC_KEY_PREFIX.$tenantId, default: '');
        $fingerprint = $this->appConfig->getValueString(app: 'scholiq', key: self::FINGERPRINT_KEY_PREFIX.$tenantId, default: '');

        if ($publicKey === '') {
            return null;
        }

        return [
            'fingerprint' => $fingerprint,
            'publicKey'   => $publicKey,
        ];
    }//end getTenantKeyStatus()

    /**
     * Resolve a historical (archived or active) public key by its fingerprint.
     *
     * Verifiers that receive a credential signed under a rotated key can call this
     * method to locate the matching public key without holding prior state. Fixes #176.
     *
     * @param string $tenantId    Tenant UUID.
     * @param string $fingerprint 32-char hex fingerprint (first 32 chars of SHA-256 of PEM).
     *
     * @return string|null PEM string of the matching public key, or null if not found.
     */
    public function resolvePublicKeyByFingerprint(string $tenantId, string $fingerprint): ?string
    {
        // Check the active key first.
        $activePublicKey = $this->appConfig->getValueString(app: 'scholiq', key: self::PUBLIC_KEY_PREFIX.$tenantId, default: '');
        if ($activePublicKey !== '' && substr(hash('sha256', $activePublicKey), 0, 32) === $fingerprint) {
            return $activePublicKey;
        }

        // Check the archived keys.
        $archivedJson = $this->appConfig->getValueString(app: 'scholiq', key: self::ARCHIVED_KEYS_PREFIX.$tenantId, default: '[]');
        $archived     = json_decode($archivedJson, true);
        if (is_array($archived) === false) {
            return null;
        }

        foreach ($archived as $pemEntry) {
            if (is_string($pemEntry) === true && substr(hash('sha256', $pemEntry), 0, 32) === $fingerprint) {
                return $pemEntry;
            }
        }

        return null;
    }//end resolvePublicKeyByFingerprint()

    /**
     * Archive the current active public key before rotation.
     *
     * Appends the current active public key (if any) to the tenant's archived-key
     * list so previously-issued credentials can still be verified. Private keys are
     * NOT archived — only the public key is needed for verification. Fixes #176.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return void
     */
    private function archiveCurrentPublicKey(string $tenantId): void
    {
        $current = $this->appConfig->getValueString(app: 'scholiq', key: self::PUBLIC_KEY_PREFIX.$tenantId, default: '');

        if ($current === '') {
            // No active key to archive — first-time generation.
            return;
        }

        $archivedJson = $this->appConfig->getValueString(app: 'scholiq', key: self::ARCHIVED_KEYS_PREFIX.$tenantId, default: '[]');
        $archived     = json_decode($archivedJson, true);
        if (is_array($archived) === false) {
            $archived = [];
        }

        // Avoid duplicates.
        if (in_array($current, $archived, strict: true) === false) {
            $archived[] = $current;
        }

        // L2: cap archive at MAX_ARCHIVED_KEYS — prune oldest entries first.
        if (count($archived) > self::MAX_ARCHIVED_KEYS) {
            $archived = array_slice($archived, count($archived) - self::MAX_ARCHIVED_KEYS);
        }

        $this->appConfig->setValueString(
            app: 'scholiq',
            key: self::ARCHIVED_KEYS_PREFIX.$tenantId,
            value: (string) json_encode($archived)
        );
    }//end archiveCurrentPublicKey()
}//end class
