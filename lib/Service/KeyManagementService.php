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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\IAppConfig;
use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Generates and stores RSA-2048 per-tenant keypairs for OB3 credential signing.
 *
 * Cryptographic operation — legitimate PHP seam per ADR-031.
 */
class KeyManagementService
{
    /**
     * App config key prefix for encrypted private keys.
     */
    private const PRIVATE_KEY_PREFIX = 'scholiq.credential.signing.private.';

    /**
     * App config key prefix for public keys (plain PEM).
     */
    private const PUBLIC_KEY_PREFIX = 'scholiq.credential.signing.public.';

    /**
     * App config key prefix for public key fingerprints.
     */
    private const FINGERPRINT_KEY_PREFIX = 'scholiq.credential.signing.fingerprint.';

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
     * The private key is encrypted via ICrypto before storage. Calling this
     * method when a key already exists for the tenant will overwrite it.
     *
     * @param string $tenantId Tenant UUID.
     *
     * @return array{fingerprint: string, publicKey: string} Public key data.
     *
     * @throws \RuntimeException If OpenSSL key generation fails.
     */
    public function generateTenantKeypair(string $tenantId): array
    {
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
        openssl_pkey_export($resource, $privateKeyPem);

        $details      = openssl_pkey_get_details($resource);
        $publicKeyPem = $details['key'] ?? '';

        if ($privateKeyPem === '' || $publicKeyPem === '') {
            throw new RuntimeException('Failed to export keypair from OpenSSL resource.');
        }

        $fingerprint      = substr(hash('sha256', $publicKeyPem), 0, 16);
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
}//end class
