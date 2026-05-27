<?php

/**
 * Unit tests for CredentialSigningService.
 *
 * Verifies the OB3-compliant JWS signing path introduced to fix:
 *   - GitHub #174: route wiring (credentialVerify#verify) — covered via URL-generator mock assertion.
 *   - GitHub #175: base64url header, kid in JWS header, DataIntegrityProof proof type.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\CredentialSigningService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CredentialSigningService — JWS signing correctness.
 */
class CredentialSigningServiceTest extends TestCase
{
    /**
     * RSA-2048 test private key (self-generated for unit tests only).
     *
     * @var string
     */
    private string $privateKeyPem = '';

    /**
     * Corresponding RSA-2048 test public key PEM.
     *
     * @var string
     */
    private string $publicKeyPem = '';

    /**
     * SHA-256 fingerprint (first 16 hex chars) of the public key.
     *
     * @var string
     */
    private string $fingerprint = '';

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
        $this->fingerprint  = substr(hash('sha256', $this->publicKeyPem), 0, 16);
    }//end setUp()

    /**
     * Build the service under test with mocked dependencies.
     *
     * @param string $tenantId Tenant whose key data is returned by mocks.
     *
     * @return CredentialSigningService
     */
    private function makeService(string $tenantId): CredentialSigningService
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig
            ->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') use ($tenantId): string {
                if (str_ends_with($key, $tenantId) && str_contains($key, '.private.')) {
                    return 'encrypted-private-key';
                }

                if (str_ends_with($key, $tenantId) && str_contains($key, '.public.')) {
                    return $this->publicKeyPem;
                }

                if (str_ends_with($key, $tenantId) && str_contains($key, '.fingerprint.')) {
                    return $this->fingerprint;
                }

                return $default;
            });

        /** @var ICrypto&MockObject $crypto */
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('decrypt')->willReturn($this->privateKeyPem);

        /** @var IURLGenerator&MockObject $urlGenerator */
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator
            ->method('linkToRouteAbsolute')
            ->willReturnCallback(function (string $routeName, array $params): string {
                return 'https://example.test/apps/scholiq/api/credentials/'.$params['id'].'/verify';
            });

        return new CredentialSigningService($appConfig, $crypto, $urlGenerator);
    }//end makeService()

    // -------------------------------------------------------------------------
    // signPayload — JWS header is valid base64url (no +, /, = characters) — Fix #175
    // -------------------------------------------------------------------------

    /**
     * The JWS header must be RFC 4648 §5 base64url — no +, / or = characters.
     *
     * @return void
     */
    public function testJwsHeaderIsBase64Url(): void
    {
        $service = $this->makeService('tenant-1');

        $payload = $service->buildOb3Payload(
            credentialId: 'cred-001',
            learnerId: 'learner-42',
            courseId: null,
            issuedAt: '2026-01-01T00:00:00+00:00',
            expiresAt: null,
            issuerDid: 'did:web:scholiq:tenant-1:aabbcc',
            issuedBy: 'Test School',
            verificationUrl: 'https://example.test/api/credentials/cred-001/verify',
        );

        $jws = $service->signPayload($payload, 'tenant-1');

        self::assertNotNull($jws, 'signPayload() must return a JWS string when a valid key is present');

        // JWS compact with detached payload: "<header>..<signature>" — two dots.
        [$headerB64] = explode('..', $jws, 2);

        // RFC 4648 §5: base64url uses '-' and '_', no '+', '/' or '=' padding.
        self::assertStringNotContainsString('+', $headerB64, 'JWS header must not contain + (plain base64 character)');
        self::assertStringNotContainsString('/', $headerB64, 'JWS header must not contain / (plain base64 character)');
        self::assertStringNotContainsString('=', $headerB64, 'JWS header must not contain = padding (plain base64 character)');
    }//end testJwsHeaderIsBase64Url()

    /**
     * The JWS signature must also be RFC 4648 §5 base64url.
     *
     * @return void
     */
    public function testJwsSignatureIsBase64Url(): void
    {
        $service = $this->makeService('tenant-1');

        $payload = $service->buildOb3Payload(
            credentialId: 'cred-001',
            learnerId: 'learner-42',
            courseId: null,
            issuedAt: '2026-01-01T00:00:00+00:00',
            expiresAt: null,
            issuerDid: 'did:web:scholiq:tenant-1:aabbcc',
            issuedBy: 'Test School',
            verificationUrl: 'https://example.test/api/credentials/cred-001/verify',
        );

        $jws = $service->signPayload($payload, 'tenant-1');

        self::assertNotNull($jws);

        // JWS compact with detached payload: "<header>..<signature>" — two dots.
        [, $sigB64] = explode('..', $jws, 2);

        self::assertStringNotContainsString('+', $sigB64);
        self::assertStringNotContainsString('/', $sigB64);
        self::assertStringNotContainsString('=', $sigB64);
    }//end testJwsSignatureIsBase64Url()

    // -------------------------------------------------------------------------
    // signPayload — kid is included in JWS header — Fix #175
    // -------------------------------------------------------------------------

    /**
     * The JWS protected header must contain a `kid` field matching the key fingerprint.
     *
     * @return void
     */
    public function testJwsHeaderContainsKid(): void
    {
        $service = $this->makeService('tenant-1');

        $payload = $service->buildOb3Payload(
            credentialId: 'cred-001',
            learnerId: 'learner-42',
            courseId: null,
            issuedAt: '2026-01-01T00:00:00+00:00',
            expiresAt: null,
            issuerDid: 'did:web:scholiq:tenant-1:aabbcc',
            issuedBy: 'Test School',
            verificationUrl: 'https://example.test/api/credentials/cred-001/verify',
        );

        $jws    = $service->signPayload($payload, 'tenant-1');
        self::assertNotNull($jws);

        // JWS compact with detached payload: "<header>..<signature>" — two dots.
        [$headerB64] = explode('..', $jws, 2);

        // Pad and decode the base64url header.
        $padded  = str_pad($headerB64, (int) ceil(strlen($headerB64) / 4) * 4, '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), strict: true);
        self::assertIsString($decoded, 'JWS header must be valid base64url');

        $header = json_decode($decoded, associative: true);
        self::assertIsArray($header, 'JWS header must be valid JSON');
        self::assertArrayHasKey('kid', $header, 'JWS header must contain kid');
        self::assertSame(16, strlen($header['kid']), 'kid must be a 16-char hex fingerprint');
    }//end testJwsHeaderContainsKid()

    // -------------------------------------------------------------------------
    // signPayload — RS256 signature verifies against the public key (round-trip)
    // -------------------------------------------------------------------------

    /**
     * The RS256 signature in the JWS must be verifiable with the corresponding public key.
     *
     * This is the key round-trip test: any conformant OB3 verifier will attempt
     * to verify the signature using the public key referenced by `kid`.
     *
     * @return void
     */
    public function testJwsSignatureVerifiesWithPublicKey(): void
    {
        $service = $this->makeService('tenant-1');

        $payload = $service->buildOb3Payload(
            credentialId: 'cred-rtrip',
            learnerId: 'learner-99',
            courseId: 'course-77',
            issuedAt: '2026-06-01T00:00:00+00:00',
            expiresAt: '2027-06-01T00:00:00+00:00',
            issuerDid: 'did:web:scholiq:tenant-1:aabbcc',
            issuedBy: 'Round-Trip School',
            verificationUrl: 'https://example.test/api/credentials/cred-rtrip/verify',
        );

        $jws = $service->signPayload($payload, 'tenant-1');
        self::assertNotNull($jws);

        // JWS compact with detached payload: "<header>..<signature>" — two dots.
        [$headerB64, $sigB64] = explode('..', $jws, 2);

        // Re-derive the signing input (header + '.' + canonicalised payload — detached b64:false mode).
        $canonicalised = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signingInput  = $headerB64.'.'.$canonicalised;

        // Decode base64url signature.
        $padded    = str_pad($sigB64, (int) ceil(strlen($sigB64) / 4) * 4, '=');
        $signature = base64_decode(strtr($padded, '-_', '+/'), strict: true);
        self::assertIsString($signature, 'Signature must be valid base64url');

        $pubKey = openssl_pkey_get_public($this->publicKeyPem);
        $valid  = openssl_verify($signingInput, $signature, $pubKey, OPENSSL_ALGO_SHA256);

        self::assertSame(1, $valid, 'RS256 signature must verify against the tenant public key');
    }//end testJwsSignatureVerifiesWithPublicKey()

    // -------------------------------------------------------------------------
    // check() — proof block uses DataIntegrityProof + correct verificationMethod — Fix #175
    // -------------------------------------------------------------------------

    /**
     * check() must inject a DataIntegrityProof (not the obsolete RsaSignature2018).
     *
     * @return void
     */
    public function testCheckInjectsDataIntegrityProof(): void
    {
        $service = $this->makeService('tenant-test');

        $context = [
            'object' => [
                'id'         => 'cred-di-001',
                'learnerId'  => 'learner-1',
                'courseId'   => null,
                'issuedAt'   => '2026-01-01T00:00:00+00:00',
                'tenant_id'  => 'tenant-test',
                'issuedBy'   => 'School X',
            ],
            'transition' => 'issue',
        ];

        $result = $service->check($context);

        self::assertTrue($result, 'check() must return true on valid context');

        $proof = $context['object']['openbadges3Payload']['proof'] ?? null;
        self::assertIsArray($proof, 'proof must be present in openbadges3Payload');
        self::assertSame('DataIntegrityProof', $proof['type'], 'proof type must be DataIntegrityProof, not RsaSignature2018');
        self::assertArrayHasKey('cryptosuite', $proof, 'proof must declare a cryptosuite');
        self::assertArrayHasKey('verificationMethod', $proof, 'proof must include verificationMethod');
        self::assertStringContainsString('#', $proof['verificationMethod'], 'verificationMethod must include a fragment (#kid)');
    }//end testCheckInjectsDataIntegrityProof()

    /**
     * check() verificationMethod fragment must equal the public key fingerprint (kid).
     *
     * @return void
     */
    public function testCheckVerificationMethodContainsKidFragment(): void
    {
        $service = $this->makeService('tenant-test');

        $context = [
            'object' => [
                'id'        => 'cred-vm-001',
                'learnerId' => 'learner-2',
                'courseId'  => null,
                'issuedAt'  => '2026-01-01T00:00:00+00:00',
                'tenant_id' => 'tenant-test',
                'issuedBy'  => 'School Y',
            ],
            'transition' => 'issue',
        ];

        $service->check($context);

        $vm        = $context['object']['openbadges3Payload']['proof']['verificationMethod'] ?? '';
        $fragment  = substr($vm, strrpos($vm, '#') + 1);

        self::assertSame(16, strlen($fragment), 'verificationMethod fragment must be the 16-char key fingerprint');
        self::assertSame($this->fingerprint, $fragment, 'verificationMethod fragment must match the stored key fingerprint');
    }//end testCheckVerificationMethodContainsKidFragment()

    // -------------------------------------------------------------------------
    // check() — URL generator called with the correct route name — Fix #174
    // -------------------------------------------------------------------------

    /**
     * check() must generate the verification URL via the corrected route name
     * `scholiq.credentialVerify.verify` (not the old `scholiq.credential.verify`).
     *
     * @return void
     */
    public function testCheckUsesCorrectRouteName(): void
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig
            ->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = ''): string {
                if (str_contains($key, '.private.')) {
                    return 'encrypted';
                }

                if (str_contains($key, '.public.')) {
                    return $this->publicKeyPem;
                }

                return $default;
            });

        /** @var ICrypto&MockObject $crypto */
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('decrypt')->willReturn($this->privateKeyPem);

        /** @var IURLGenerator&MockObject $urlGenerator */
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator
            ->expects($this->once())
            ->method('linkToRouteAbsolute')
            ->with(
                'scholiq.credentialVerify.verify',
                $this->arrayHasKey('id')
            )
            ->willReturn('https://example.test/apps/scholiq/api/credentials/cred-123/verify');

        $service = new CredentialSigningService($appConfig, $crypto, $urlGenerator);

        $context = [
            'object' => [
                'id'        => 'cred-123',
                'learnerId' => 'learner-3',
                'tenant_id' => 'tenant-abc',
                'issuedBy'  => 'School Z',
            ],
            'transition' => 'issue',
        ];

        $service->check($context);
    }//end testCheckUsesCorrectRouteName()

    // -------------------------------------------------------------------------
    // signPayload — missing key returns null gracefully
    // -------------------------------------------------------------------------

    /**
     * signPayload() must return null when no private key is stored for the tenant.
     *
     * @return void
     */
    public function testSignPayloadReturnsNullWhenKeyAbsent(): void
    {
        /** @var IAppConfig&MockObject $appConfig */
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        /** @var ICrypto&MockObject $crypto */
        $crypto = $this->createMock(ICrypto::class);
        $crypto->expects($this->never())->method('decrypt');

        $service = new CredentialSigningService(
            $appConfig,
            $crypto,
            $this->createMock(IURLGenerator::class)
        );

        $result = $service->signPayload(['@context' => []], 'tenant-missing');

        self::assertNull($result);
    }//end testSignPayloadReturnsNullWhenKeyAbsent()
}//end class
