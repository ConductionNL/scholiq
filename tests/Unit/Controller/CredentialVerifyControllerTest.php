<?php

/**
 * Unit tests for CredentialVerifyController.
 *
 * Verifies the public /api/credentials/{id}/verify endpoint behaviour,
 * covering route-wiring (#174) and JWS proof validation (C1).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\CredentialVerifyController;
use OCA\Scholiq\Service\KeyManagementService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CredentialVerifyController::verify() — fixes #174 + C1 JWS verify.
 *
 * The route credentialVerify#verify must resolve to this controller. These
 * tests confirm the controller behaves correctly for every credential state,
 * meaning any 500 caused by wrong-controller wiring is now a test failure.
 */
class CredentialVerifyControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var CredentialVerifyController
     */
    private CredentialVerifyController $controller;

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * KeyManagementService mock.
     *
     * @var KeyManagementService&MockObject
     */
    private KeyManagementService&MockObject $keyManagementService;

    /**
     * RSA test private key PEM.
     *
     * @var string
     */
    private string $privateKeyPem = '';

    /**
     * RSA test public key PEM.
     *
     * @var string
     */
    private string $publicKeyPem = '';

    /**
     * Set up the controller under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Generate a fresh RSA keypair for tests that need real JWS verification.
        $resource      = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $privateKeyPem = '';
        openssl_pkey_export($resource, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;
        $details             = openssl_pkey_get_details($resource);
        $this->publicKeyPem  = $details['key'];

        $this->objectService        = $this->createMock(ObjectService::class);
        $this->keyManagementService = $this->createMock(KeyManagementService::class);

        $this->controller = new CredentialVerifyController(
            request: $this->createMock(IRequest::class),
            objectService: $this->objectService,
            keyManagementService: $this->keyManagementService,
        );
    }//end setUp()

    /**
     * Create a stub ObjectEntity mock with the given serialized data.
     *
     * @param array<string,mixed> $data The data returned by jsonSerialize().
     *
     * @return ObjectEntity&MockObject
     */
    private function stubEntity(array $data): ObjectEntity&MockObject
    {
        $mock = $this->createMock(ObjectEntity::class);
        $mock->method('jsonSerialize')->willReturn($data);
        return $mock;
    }//end stubEntity()

    /**
     * Build a valid compact JWS with detached payload (matching CredentialSigningService format).
     *
     * @param array<string,mixed> $payloadToSign The OB3 payload (without proof).
     * @param string              $kid           Key fingerprint for the header.
     *
     * @return string Compact JWS string.
     */
    private function buildValidJws(array $payloadToSign, string $kid): string
    {
        $headerJson    = (string) json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => $kid]);
        $headerB64     = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        // H6: sort keys recursively to match CredentialSigningService::canonicalisePayload.
        $canonicalised = (string) json_encode($this->sortKeysRecursive(data: $payloadToSign), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signingInput  = $headerB64.'.'.$canonicalised;

        $signature = '';
        openssl_sign($signingInput, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256);
        $sigB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $headerB64.'..'.$sigB64;
    }//end buildValidJws()

    /**
     * Recursively sort array keys (RFC 8785 JCS) — mirrors CredentialSigningService::canonicalisePayload.
     *
     * @param array<string,mixed> $data The array to sort.
     *
     * @return array<string,mixed> Sorted copy.
     */
    private function sortKeysRecursive(array $data): array
    {
        $isObject = count(array_filter(array_keys($data), 'is_string')) > 0;
        if ($isObject === true) {
            ksort($data, SORT_STRING);
        }

        foreach ($data as $key => $value) {
            if (is_array($value) === true) {
                $data[$key] = $this->sortKeysRecursive(data: $value);
            }
        }

        return $data;
    }//end sortKeysRecursive()

    /**
     * A valid, issued, non-expired credential with a valid JWS returns {valid:true} with 200.
     *
     * @return void
     */
    public function testVerifyReturnsValidForIssuedNonExpiredCredential(): void
    {
        $kid     = substr(hash('sha256', $this->publicKeyPem), 0, 32);
        $payload = [
            '@context'     => ['https://www.w3.org/2018/credentials/v1'],
            'type'         => ['VerifiableCredential'],
            'credentialSubject' => ['id' => 'urn:scholiq:learner:learner-42'],
        ];
        $jws = $this->buildValidJws(payloadToSign: $payload, kid: $kid);

        $this->keyManagementService
            ->method('resolvePublicKeyByFingerprint')
            ->willReturn($this->publicKeyPem);

        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'          => 'issued',
                'isExpired'          => false,
                'issuedAt'           => '2026-01-01T00:00:00+00:00',
                'expiresAt'          => '2027-01-01T00:00:00+00:00',
                'issuedBy'           => 'Test School',
                'tenant_id'          => 'tenant-1',
                'openbadges3Payload' => array_merge($payload, ['proof' => ['type' => 'DataIntegrityProof', 'jws' => $jws]]),
            ]));

        $response = $this->controller->verify('cred-uuid-001');

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());

        $data = $response->getData();
        self::assertTrue($data['valid']);
        self::assertSame('Test School', $data['issuerName']);
    }//end testVerifyReturnsValidForIssuedNonExpiredCredential()

    /**
     * An expired credential (isExpired = true) returns {valid:false}.
     *
     * @return void
     */
    public function testVerifyReturnsFalseForExpiredCredential(): void
    {
        $kid     = substr(hash('sha256', $this->publicKeyPem), 0, 32);
        $payload = ['@context' => ['https://www.w3.org/2018/credentials/v1']];
        $jws     = $this->buildValidJws(payloadToSign: $payload, kid: $kid);

        $this->keyManagementService->method('resolvePublicKeyByFingerprint')->willReturn($this->publicKeyPem);

        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'          => 'issued',
                'isExpired'          => true,
                'issuedAt'           => '2025-01-01T00:00:00+00:00',
                'expiresAt'          => '2026-01-01T00:00:00+00:00',
                'issuedBy'           => 'Test School',
                'tenant_id'          => 'tenant-1',
                'openbadges3Payload' => array_merge($payload, ['proof' => ['jws' => $jws]]),
            ]));

        $response = $this->controller->verify('cred-uuid-002');

        $data = $response->getData();
        self::assertFalse($data['valid']);
    }//end testVerifyReturnsFalseForExpiredCredential()

    /**
     * A revoked credential returns {valid:false, revokedAt, revocationReason}.
     *
     * @return void
     */
    public function testVerifyReturnsRevocationDataForRevokedCredential(): void
    {
        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'        => 'revoked',
                'updatedAt'        => '2026-03-15T10:00:00+00:00',
                'revocationReason' => 'Learner failed re-assessment',
            ]));

        $response = $this->controller->verify('cred-uuid-003');

        $data = $response->getData();
        self::assertFalse($data['valid']);
        self::assertArrayHasKey('revokedAt', $data);
        self::assertArrayHasKey('revocationReason', $data);
        self::assertSame('2026-03-15T10:00:00+00:00', $data['revokedAt']);
    }//end testVerifyReturnsRevocationDataForRevokedCredential()

    /**
     * An unknown credential ID returns {valid:false} with HTTP 404.
     *
     * @return void
     */
    public function testVerifyReturns404ForUnknownCredential(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $response = $this->controller->verify('does-not-exist');

        self::assertSame(404, $response->getStatus());
        self::assertFalse($response->getData()['valid']);
        self::assertSame('not_found', $response->getData()['error']);
    }//end testVerifyReturns404ForUnknownCredential()

    /**
     * C1: a credential with a tampered JWS returns valid:false + signature_invalid.
     *
     * @return void
     */
    public function testVerifyReturnsFalseForInvalidJwsSignature(): void
    {
        $kid = substr(hash('sha256', $this->publicKeyPem), 0, 32);
        // Build a JWS header referencing the known kid but with a garbage signature.
        $headerJson  = (string) json_encode(['alg' => 'RS256', 'b64' => false, 'crit' => ['b64'], 'kid' => $kid]);
        $headerB64   = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        $tamperedJws = $headerB64.'..'.rtrim(strtr(base64_encode(str_repeat('X', 256)), '+/', '-_'), '=');

        $this->keyManagementService
            ->method('resolvePublicKeyByFingerprint')
            ->willReturn($this->publicKeyPem);

        $payload = ['@context' => ['https://www.w3.org/2018/credentials/v1']];

        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'          => 'issued',
                'isExpired'          => false,
                'issuedAt'           => '2026-01-01T00:00:00+00:00',
                'tenant_id'          => 'tenant-1',
                'openbadges3Payload' => array_merge($payload, ['proof' => ['jws' => $tamperedJws]]),
            ]));

        $response = $this->controller->verify('cred-tampered');

        $data = $response->getData();
        self::assertFalse($data['valid']);
        self::assertSame('signature_invalid', $data['error']);
    }//end testVerifyReturnsFalseForInvalidJwsSignature()

    /**
     * C1: a credential whose kid cannot be resolved returns valid:false + signature_invalid.
     *
     * @return void
     */
    public function testVerifyReturnsFalseWhenKidNotResolved(): void
    {
        $kid        = 'unknownfingerprint00000000000000';
        $headerJson = (string) json_encode(['alg' => 'RS256', 'b64' => false, 'kid' => $kid]);
        $headerB64  = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        $jws        = $headerB64.'..fakesig';

        $this->keyManagementService
            ->method('resolvePublicKeyByFingerprint')
            ->willReturn(null);

        $payload = ['@context' => ['https://www.w3.org/2018/credentials/v1']];

        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'          => 'issued',
                'isExpired'          => false,
                'tenant_id'          => 'tenant-1',
                'openbadges3Payload' => array_merge($payload, ['proof' => ['jws' => $jws]]),
            ]));

        $response = $this->controller->verify('cred-no-key');

        $data = $response->getData();
        self::assertFalse($data['valid']);
        self::assertSame('signature_invalid', $data['error']);
    }//end testVerifyReturnsFalseWhenKidNotResolved()

    /**
     * A credential without an openbadges3Payload (legacy pre-signing record) still returns valid:true.
     *
     * @return void
     */
    public function testVerifyAcceptsLegacyCredentialWithoutProof(): void
    {
        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle' => 'issued',
                'isExpired' => false,
                'issuedAt'  => '2025-01-01T00:00:00+00:00',
                'issuedBy'  => 'Legacy School',
                'tenant_id' => 'tenant-1',
            ]));

        $response = $this->controller->verify('cred-legacy');

        $data = $response->getData();
        self::assertTrue($data['valid']);
    }//end testVerifyAcceptsLegacyCredentialWithoutProof()
}//end class
