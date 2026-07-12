<?php

/**
 * Scholiq BsaDecisionGuard unit tests.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use OCA\Scholiq\Lifecycle\BsaDecisionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the BsaDecisionGuard lifecycle guard (drafted → decided).
 */
class BsaDecisionGuardTest extends TestCase
{

    /**
     * Base decision fixture. Override decisionType/rationale per test.
     *
     * @param string $decisionType Decision type.
     * @param string $rationale    Rationale text.
     *
     * @return array<string,mixed>
     */
    private function decisionObject(string $decisionType, string $rationale = ''): array
    {
        return [
            'learnerId'    => 'learner-7',
            'programmeId'  => 'programme-1',
            'academicYear' => '2026-2027',
            'decisionType' => $decisionType,
            'rationale'    => $rationale,
            'decidedBy'    => 'advisor-1',
            'decisionDate' => '2026-07-01T10:00:00+02:00',
            'tenant_id'    => 'tenant-a',
        ];

    }//end decisionObject()

    /**
     * Build a guard with mocked dependencies.
     *
     * @param ObjectService    $objectService    Object query mock.
     * @param TenantKeyService $tenantKeyService  Tenant-key mock.
     *
     * @return BsaDecisionGuard
     */
    private function makeGuard(ObjectService $objectService, TenantKeyService $tenantKeyService): BsaDecisionGuard
    {
        return new BsaDecisionGuard($objectService, $tenantKeyService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A negative decision without any issued BsaWarning is refused.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-negative-decision-without-a-warning-is-refused
     */
    public function testNegativeWithoutWarningRefused(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

        $context = ['object' => $this->decisionObject('negative', 'Insufficient progress despite guidance.'), 'payload' => []];

        self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));
        self::assertArrayNotHasKey('signature', $context['payload']);

    }//end testNegativeWithoutWarningRefused()

    /**
     * A negative decision with a matching issued BsaWarning is allowed and stamps a signature.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-negative-decision-with-a-logged-warning-is-allowed
     */
    public function testNegativeWithIssuedWarningAllowed(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

        $context = ['object' => $this->decisionObject('negative', 'Insufficient progress despite guidance.'), 'payload' => []];

        self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($context));
        self::assertArrayHasKey('signature', $context['payload']);
        self::assertArrayHasKey('signingKeyId', $context['payload']);

    }//end testNegativeWithIssuedWarningAllowed()

    /**
     * negative-with-recommendation is subject to the same warning requirement as negative.
     *
     * @return void
     */
    public function testNegativeWithRecommendationWithoutWarningRefused(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);

        $context = ['object' => $this->decisionObject('negative-with-recommendation', 'Some progress but below norm.'), 'payload' => []];

        self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));

    }//end testNegativeWithRecommendationWithoutWarningRefused()

    /**
     * A negative decision with an issued warning but empty rationale is refused.
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-negative-decision-without-rationale-is-refused
     */
    public function testNegativeWithoutRationaleRefused(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->expects($this->never())->method('getCurrentTenantKey');

        $context = ['object' => $this->decisionObject('negative-with-recommendation', ''), 'payload' => []];

        self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));

    }//end testNegativeWithoutRationaleRefused()

    /**
     * A positive decision is unaffected by the warning check (no BsaWarning query needed).
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
     */
    public function testPositiveDecisionUnaffectedByWarningCheck(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

        $context = ['object' => $this->decisionObject('positive'), 'payload' => []];

        self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($context));
        self::assertArrayHasKey('signature', $context['payload']);

    }//end testPositiveDecisionUnaffectedByWarningCheck()

    /**
     * A postponed decision is unaffected by the warning check.
     *
     * @return void
     */
    public function testPostponedDecisionUnaffectedByWarningCheck(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('super-secret-key');

        $context = ['object' => $this->decisionObject('postponed'), 'payload' => []];

        self::assertTrue($this->makeGuard($objectService, $tenantKeyService)->check($context));

    }//end testPostponedDecisionUnaffectedByWarningCheck()

    /**
     * Tenant key unavailable blocks even an otherwise-valid negative decision.
     *
     * @return void
     */
    public function testUnavailableTenantKeyRejected(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['id' => 'warning-1', 'lifecycle' => 'issued']]);

        $tenantKeyService = $this->createMock(TenantKeyService::class);
        $tenantKeyService->method('getCurrentTenantKey')->willReturn('');

        $context = ['object' => $this->decisionObject('negative', 'Rationale present.'), 'payload' => []];

        self::assertFalse($this->makeGuard($objectService, $tenantKeyService)->check($context));

    }//end testUnavailableTenantKeyRejected()
}//end class
