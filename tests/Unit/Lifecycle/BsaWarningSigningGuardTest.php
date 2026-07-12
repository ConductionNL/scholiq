<?php

/**
 * Scholiq BsaWarningSigningGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\TenantKeyService;
use OCA\Scholiq\Lifecycle\BsaWarningSigningGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the BsaWarningSigningGuard lifecycle guard (drafted → issued).
 */
class BsaWarningSigningGuardTest extends TestCase
{

    /**
     * A valid draft warning fixture.
     *
     * @return array<string,mixed>
     */
    private function warningObject(): array
    {
        return [
            'learnerId'         => 'learner-7',
            'programmeId'       => 'programme-1',
            'academicYear'      => '2026-2027',
            'warningDate'       => '2026-01-15',
            'improvementPeriod' => [
                'startDate' => '2026-01-15',
                'endDate'   => '2026-03-15',
            ],
            'offeredGuidance'   => 'Weekly study-advisor check-ins and a referral to the student dean.',
            'tenant_id'         => 'tenant-a',
        ];

    }//end warningObject()

    /**
     * Build a guard with mocked dependencies.
     *
     * @param TenantKeyService $tenantKeyService Tenant-key mock.
     *
     * @return BsaWarningSigningGuard
     */
    private function makeGuard(TenantKeyService $tenantKeyService): BsaWarningSigningGuard
    {
        return new BsaWarningSigningGuard($tenantKeyService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * Happy path: valid improvementPeriod + offeredGuidance + tenant key present → true, signature injected.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-issued-warning-carries-a-verifiable-signature
     */
    public function testIssueStampsSignature(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

        $context = ['object' => $this->warningObject(), 'payload' => []];

        self::assertTrue($this->makeGuard($tenantKeyService)->check($context));
        self::assertArrayHasKey('signature', $context['payload']);
        self::assertArrayHasKey('signingKeyId', $context['payload']);
        self::assertSame(64, strlen($context['payload']['signature']), 'HMAC-SHA256 hex digest is 64 chars');
        self::assertSame(16, strlen($context['payload']['signingKeyId']), 'key fingerprint is 16 hex chars');

    }//end testIssueStampsSignature()

    /**
     * Missing offeredGuidance blocks the issue transition.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-warning-cannot-be-issued-without-offered-guidance
     */
    public function testMissingGuidanceBlocksIssue(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

        $object                     = $this->warningObject();
        $object['offeredGuidance']  = '';

        $context = ['object' => $object, 'payload' => []];

        self::assertFalse($this->makeGuard($tenantKeyService)->check($context));
        self::assertArrayNotHasKey('signature', $context['payload']);

    }//end testMissingGuidanceBlocksIssue()

    /**
     * A whitespace-only offeredGuidance is treated as empty.
     *
     * @return void
     */
    public function testWhitespaceOnlyGuidanceBlocksIssue(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);

        $object                    = $this->warningObject();
        $object['offeredGuidance'] = "   \n\t  ";

        $context = ['object' => $object, 'payload' => []];

        self::assertFalse($this->makeGuard($tenantKeyService)->check($context));

    }//end testWhitespaceOnlyGuidanceBlocksIssue()

    /**
     * Missing improvementPeriod.startDate blocks the issue transition.
     *
     * @return void
     */
    public function testMissingImprovementPeriodStartBlocksIssue(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

        $object                                    = $this->warningObject();
        $object['improvementPeriod']['startDate']   = null;

        $context = ['object' => $object, 'payload' => []];

        self::assertFalse($this->makeGuard($tenantKeyService)->check($context));

    }//end testMissingImprovementPeriodStartBlocksIssue()

    /**
     * A missing improvementPeriod object entirely blocks the issue transition.
     *
     * @return void
     */
    public function testMissingImprovementPeriodBlocksIssue(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);

        $object = $this->warningObject();
        unset($object['improvementPeriod']);

        $context = ['object' => $object, 'payload' => []];

        self::assertFalse($this->makeGuard($tenantKeyService)->check($context));

    }//end testMissingImprovementPeriodBlocksIssue()

    /**
     * Tenant key unavailable (empty string) → guard returns false even though
     * pre-conditions are satisfied.
     *
     * @return void
     */
    public function testUnavailableTenantKeyRejected(): void
    {
        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('');

        $context = ['object' => $this->warningObject(), 'payload' => []];

        self::assertFalse($this->makeGuard($tenantKeyService)->check($context));
        self::assertArrayNotHasKey('signature', $context['payload']);

    }//end testUnavailableTenantKeyRejected()
}//end class
