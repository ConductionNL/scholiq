<?php

/**
 * Unit tests for LearningRecordExportSigningService.
 *
 * Verifies the signature verifies against the tenant's existing public key
 * and that the canonicalised bundle is byte-identical between the sign and
 * verify paths — mirrors CredentialSigningServiceTest/
 * CredentialVerifyController's existing coverage shape.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-6-3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\LearningRecordExportSigningService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearningRecordExportSigningService::sign()/verify()/resolveIssuerDid().
 */
class LearningRecordExportSigningServiceTest extends TestCase
{

    /**
     * RSA-2048 test private key.
     *
     * @var string
     */
    private string $privateKeyPem = '';

    /**
     * Corresponding RSA-2048 test public key.
     *
     * @var string
     */
    private string $publicKeyPem = '';

    /**
     * Generate a fresh RSA-2048 keypair for each test run.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $resource      = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $privateKeyPem = '';
        openssl_pkey_export($resource, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;

        $details            = openssl_pkey_get_details($resource);
        $this->publicKeyPem = $details['key'];
    }//end setUp()

    /**
     * Build a service instance whose IAppConfig mock returns this test's
     * keypair for the given tenant.
     *
     * @param string $tenantId Tenant whose key data is returned by the mock.
     *
     * @return LearningRecordExportSigningService
     */
    private function makeService(string $tenantId): LearningRecordExportSigningService
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($tenantId): string {
                if (str_ends_with($key, $tenantId) && str_contains($key, '.private.')) {
                    return 'encrypted-private-key';
                }

                if (str_ends_with($key, $tenantId) && str_contains($key, '.public.')) {
                    return $this->publicKeyPem;
                }

                return $default;
            }
        );

        /** @var ICrypto&MockObject $crypto */
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('decrypt')->willReturn($this->privateKeyPem);

        return new LearningRecordExportSigningService($appConfig, $crypto);
    }//end makeService()

    /**
     * A signed bundle's signature verifies against the tenant's own public key.
     *
     * @return void
     */
    public function testSignatureVerifiesAgainstTenantKey(): void
    {
        $service = $this->makeService(tenantId: 'tenant-1');

        $bundle = [
            'bundleType' => 'scholiq-learning-record',
            'learnerRef' => 'learner-uuid-1',
            'elm'        => [],
            'scholiqNative' => ['credentials' => []],
        ];

        $jws = $service->sign(bundle: $bundle, tenantId: 'tenant-1');
        self::assertNotNull($jws, 'sign() must return a JWS when a valid key is present.');

        $verified = $service->verify(jws: $jws, bundle: $bundle, tenantId: 'tenant-1');
        self::assertTrue($verified, 'The signature must verify against the same tenant key.');
    }//end testSignatureVerifiesAgainstTenantKey()

    /**
     * The canonicalised bundle used for signing is byte-identical to the one
     * re-canonicalised on the verify path, regardless of key insertion order.
     *
     * @return void
     */
    public function testCanonicalisationIsOrderIndependentBetweenSignAndVerify(): void
    {
        $service = $this->makeService(tenantId: 'tenant-2');

        $bundleA = ['b' => 2, 'a' => 1, 'nested' => ['z' => 1, 'y' => 2]];
        $bundleB = ['nested' => ['y' => 2, 'z' => 1], 'a' => 1, 'b' => 2];

        $jws = $service->sign(bundle: $bundleA, tenantId: 'tenant-2');
        self::assertNotNull($jws);

        self::assertTrue(
            $service->verify(jws: $jws, bundle: $bundleB, tenantId: 'tenant-2'),
            'Differently-ordered but semantically identical bundles must canonicalise identically (RFC 8785 JCS).'
        );
    }//end testCanonicalisationIsOrderIndependentBetweenSignAndVerify()

    /**
     * A tampered bundle fails verification.
     *
     * @return void
     */
    public function testTamperedBundleFailsVerification(): void
    {
        $service = $this->makeService(tenantId: 'tenant-3');

        $bundle = ['learnerRef' => 'learner-uuid-1'];
        $jws    = $service->sign(bundle: $bundle, tenantId: 'tenant-3');
        self::assertNotNull($jws);

        $tampered = ['learnerRef' => 'learner-uuid-EVIL'];
        self::assertFalse($service->verify(jws: $jws, bundle: $tampered, tenantId: 'tenant-3'));
    }//end testTamperedBundleFailsVerification()

    /**
     * `proof` is stripped before re-canonicalising on the verify path — a
     * self-contained artefact that embeds its own proof still verifies.
     *
     * @return void
     */
    public function testVerifyIgnoresEmbeddedProofBlock(): void
    {
        $service = $this->makeService(tenantId: 'tenant-4');

        $bundle = ['learnerRef' => 'learner-uuid-1'];
        $jws    = $service->sign(bundle: $bundle, tenantId: 'tenant-4');
        self::assertNotNull($jws);

        $selfContained            = $bundle;
        $selfContained['proof']   = ['type' => 'DataIntegrityProof', 'jws' => $jws];

        self::assertTrue($service->verify(jws: $jws, bundle: $selfContained, tenantId: 'tenant-4'));
    }//end testVerifyIgnoresEmbeddedProofBlock()

    /**
     * sign() returns null when no private key is stored for the tenant.
     *
     * @return void
     */
    public function testSignReturnsNullWhenKeyAbsent(): void
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        /** @var ICrypto&MockObject $crypto */
        $crypto = $this->createMock(ICrypto::class);
        $crypto->expects($this->never())->method('decrypt');

        $service = new LearningRecordExportSigningService($appConfig, $crypto);

        self::assertNull($service->sign(bundle: ['a' => 1], tenantId: 'tenant-missing'));
    }//end testSignReturnsNullWhenKeyAbsent()

    /**
     * resolveIssuerDid() returns null when no key has been generated yet.
     *
     * @return void
     */
    public function testResolveIssuerDidReturnsNullWhenNoKey(): void
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $service = new LearningRecordExportSigningService($appConfig, $this->createMock(ICrypto::class));

        self::assertNull($service->resolveIssuerDid(tenantId: 'tenant-missing'));
    }//end testResolveIssuerDidReturnsNullWhenNoKey()

    /**
     * resolveIssuerDid() returns a stable did:web derived from the public
     * key fingerprint when a key exists.
     *
     * @return void
     */
    public function testResolveIssuerDidReturnsDidWebForConfiguredTenant(): void
    {
        $service = $this->makeService(tenantId: 'tenant-5');

        $did = $service->resolveIssuerDid(tenantId: 'tenant-5');

        self::assertNotNull($did);
        self::assertStringStartsWith('did:web:scholiq:tenant-5:', $did);
    }//end testResolveIssuerDidReturnsDidWebForConfiguredTenant()
}//end class
