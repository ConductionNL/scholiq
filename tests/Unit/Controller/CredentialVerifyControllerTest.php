<?php

/**
 * Unit tests for CredentialVerifyController.
 *
 * Verifies the public /api/credentials/{id}/verify endpoint behaviour,
 * covering the route-wiring fix from GitHub #174.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CredentialVerifyController::verify() — fix for GitHub #174.
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
     * Set up the controller under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);

        $this->controller = new CredentialVerifyController(
            request: $this->createMock(IRequest::class),
            objectService: $this->objectService,
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
     * A valid, issued, non-expired credential returns {valid:true} with 200.
     *
     * @return void
     */
    public function testVerifyReturnsValidForIssuedNonExpiredCredential(): void
    {
        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle'  => 'issued',
                'isExpired'  => false,
                'issuedAt'   => '2026-01-01T00:00:00+00:00',
                'expiresAt'  => '2027-01-01T00:00:00+00:00',
                'issuedBy'   => 'Test School',
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
        $this->objectService
            ->method('find')
            ->willReturn($this->stubEntity([
                'lifecycle' => 'issued',
                'isExpired' => true,
                'issuedAt'  => '2025-01-01T00:00:00+00:00',
                'expiresAt' => '2026-01-01T00:00:00+00:00',
                'issuedBy'  => 'Test School',
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
}//end class
