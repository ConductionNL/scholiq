<?php

/**
 * Scholiq AttestationSigningGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use OCA\Scholiq\Lifecycle\AttestationSigningGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AttestationSigningGuard lifecycle guard (drafted → signed).
 */
class AttestationSigningGuardTest extends TestCase
{
    /**
     * The signed-object fixture used across tests.
     *
     * @return array<string,mixed>
     */
    private function attestationObject(): array
    {
        return [
            'learnerId'      => 'learner-7',
            'lessonId'       => 'lesson-3',
            'courseId'       => 'course-1',
            'regulationSlug' => 'NIS2',
            'score'          => 88,
            'tenant_id'      => 'tenant-a',
        ];
    }//end attestationObject()

    /**
     * Build a guard with mocked dependencies.
     *
     * @param ObjectService    $objectService    Object query mock.
     * @param TenantKeyService $tenantKeyService Tenant-key mock.
     *
     * @return AttestationSigningGuard
     */
    private function makeGuard(ObjectService $objectService, TenantKeyService $tenantKeyService): AttestationSigningGuard
    {
        return new AttestationSigningGuard($objectService, $tenantKeyService, $this->createMock(LoggerInterface::class));
    }//end makeGuard()

    /**
     * Happy path: completion exists + tenant key present → returns true and injects signature.
     *
     * @return void
     */
    public function testValidPreconditionInjectsSignature(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['uuid' => 'xapi-1']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

        $context = ['object' => $this->attestationObject(), 'payload' => []];

        $this->assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($context));
        $this->assertArrayHasKey('signature', $context['payload']);
        $this->assertArrayHasKey('signingKeyId', $context['payload']);
        $this->assertSame(64, strlen($context['payload']['signature']), 'HMAC-SHA256 hex digest is 64 chars');
        $this->assertSame(16, strlen($context['payload']['signingKeyId']), 'key fingerprint is 16 hex chars');
    }//end testValidPreconditionInjectsSignature()

    /**
     * No matching xAPI completion → guard returns false, no signature.
     *
     * @return void
     */
    public function testMissingCompletionRejected(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

        $context = ['object' => $this->attestationObject(), 'payload' => []];

        $this->assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));
        $this->assertArrayNotHasKey('signature', $context['payload']);
    }//end testMissingCompletionRejected()

    /**
     * Tenant key unavailable (empty string) → guard returns false even though completion exists.
     *
     * @return void
     */
    public function testUnavailableTenantKeyRejected(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['uuid' => 'xapi-1']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('');

        $context = ['object' => $this->attestationObject(), 'payload' => []];

        $this->assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));
    }//end testUnavailableTenantKeyRejected()

    /**
     * Missing learnerId or lessonId → guard rejects without querying.
     *
     * @return void
     */
    public function testMissingIdentifiersRejected(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $context = ['object' => ['tenant_id' => 'tenant-a'], 'payload' => []];

        $this->assertFalse($this->makeGuard($objectService, $this->createMock(TenantKeyService::class))->check($context));
    }//end testMissingIdentifiersRejected()

    /**
     * The HMAC input is stable: signature/signingKeyId/lifecycle do not affect the digest.
     *
     * @return void
     */
    public function testSignatureIsStableUnderSelfFields(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['uuid' => 'xapi-1']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('k');

        $guard = $this->makeGuard($objectService, $tenantKeyService);

        $base = ['object' => $this->attestationObject(), 'payload' => []];
        $guard->check($base);
        $first = $base['payload']['signature'];

        $withSelf = ['object' => $this->attestationObject(), 'payload' => []];
        $withSelf['object']['signature']    = 'stale';
        $withSelf['object']['signingKeyId'] = 'stale-key';
        $withSelf['object']['lifecycle']    = 'signed';
        $guard->check($withSelf);

        $this->assertSame($first, $withSelf['payload']['signature']);
    }//end testSignatureIsStableUnderSelfFields()
}//end class
