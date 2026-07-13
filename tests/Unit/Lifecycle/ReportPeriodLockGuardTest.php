<?php

/**
 * Scholiq ReportPeriodLockGuard unit tests.
 *
 * Covers the composition with FraudCaseBlockGuard (a fraud-blocked GradeEntry
 * MUST stay blocked regardless of ReportPeriod lock state — the fraud-appeal
 * guarantee this guard must never regress), the fail-open "no governing
 * ReportPeriod" posture, the locked-period block, and the admin/mentor/
 * principal override.
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
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
 * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-a-mentor-override-publishes-a-grade-for-a-locked-report-period
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\FraudCaseBlockGuard;
use OCA\Scholiq\Lifecycle\ReportPeriodLockGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportPeriodLockGuard (GradeEntry publish/republish).
 */
class ReportPeriodLockGuardTest extends TestCase
{

    /**
     * Build a guard.
     *
     * @param bool                       $fraudCaseAllows Whether the composed FraudCaseBlockGuard's check() returns true.
     * @param array<int,array<string,mixed>> $reportPeriods  ReportPeriod rows returned by findAll(schema=report-period).
     * @param array<string>              $actorGroups     Group IDs the acting user belongs to.
     * @param bool                       $actorExists     Whether the user manager resolves the actor.
     *
     * @return ReportPeriodLockGuard
     */
    private function makeGuard(
        bool $fraudCaseAllows,
        array $reportPeriods,
        array $actorGroups=[],
        bool $actorExists=true
    ): ReportPeriodLockGuard {
        $fraudCaseGuard = $this->createMock(FraudCaseBlockGuard::class);
        $fraudCaseGuard->method('check')->willReturn($fraudCaseAllows);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($reportPeriods) {
                if ($config['schema'] === 'report-period') {
                    return $reportPeriods;
                }

                return [];
            }
        );

        $user = $this->createMock(IUser::class);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($actorExists === true ? $user : null);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn($actorGroups);

        return new ReportPeriodLockGuard(
            $fraudCaseGuard,
            $objectService,
            $groupManager,
            $userManager,
            $this->createMock(LoggerInterface::class)
        );

    }//end makeGuard()

    /**
     * A fraud-blocked GradeEntry stays blocked regardless of ReportPeriod lock
     * state — the composed FraudCaseBlockGuard check runs first and short-circuits.
     * This is the fraud-appeal guarantee this guard composition must never regress.
     *
     * @return void
     */
    public function testFraudCaseBlockGuardShortCircuitsAndStaysBlocked(): void
    {
        // Even with NO locked ReportPeriod at all, a fraud-case block wins.
        $guard   = $this->makeGuard(fraudCaseAllows: false, reportPeriods: []);
        $context = ['object' => ['id' => 'entry-1', 'fraudCaseId' => 'case-1'], 'actor' => 'admin-1'];

        self::assertFalse($guard->check($context));

    }//end testFraudCaseBlockGuardShortCircuitsAndStaysBlocked()

    /**
     * Fraud-case check passes; no ReportPeriod governs this entry -> fail open,
     * allowed unconditionally.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-publishrepublish-proceeds-unaffected-when-no-reportperiod-governs-the-entry
     */
    public function testNoGoverningReportPeriodAllowsUnconditionally(): void
    {
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: []);
        $context = [
            'object' => ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'actor'  => 'teacher-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testNoGoverningReportPeriodAllowsUnconditionally()

    /**
     * A matching ReportPeriod that is NOT locked allows unconditionally.
     *
     * @return void
     */
    public function testMatchingButUnlockedReportPeriodAllows(): void
    {
        $period  = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => false];
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period]);
        $context = [
            'object' => ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'actor'  => 'teacher-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testMatchingButUnlockedReportPeriodAllows()

    /**
     * A matching, locked ReportPeriod blocks an ordinary teacher (no override role).
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-an-ordinary-teacher-cannot-publish-a-grade-for-a-locked-report-period
     */
    public function testMatchingLockedReportPeriodBlocksOrdinaryTeacher(): void
    {
        $period  = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => true];
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period], actorGroups: ['teacher']);
        $context = [
            'object' => ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'actor'  => 'teacher-1',
        ];

        self::assertFalse($guard->check($context));

    }//end testMatchingLockedReportPeriodBlocksOrdinaryTeacher()

    /**
     * A matching, locked ReportPeriod allows an admin/mentor/principal override.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#scenario-a-mentor-override-publishes-a-grade-for-a-locked-report-period
     */
    public function testMentorOverrideAllowsPublishOnLockedPeriod(): void
    {
        $period  = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['plan-1'], 'isLocked' => true];
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period], actorGroups: ['mentor']);
        $context = [
            'object' => ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'actor'  => 'mentor-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testMentorOverrideAllowsPublishOnLockedPeriod()

    /**
     * A ReportPeriod with a different curriculumPlanIds scope does not match —
     * fail open.
     *
     * @return void
     */
    public function testNonMatchingCurriculumPlanFailsOpen(): void
    {
        $period  = ['id' => 'period-1', 'periodCode' => '1', 'curriculumPlanIds' => ['other-plan'], 'isLocked' => true];
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: [$period], actorGroups: []);
        $context = [
            'object' => ['id' => 'entry-1', 'period' => '1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'actor'  => 'teacher-1',
        ];

        self::assertTrue($guard->check($context));

    }//end testNonMatchingCurriculumPlanFailsOpen()

    /**
     * A GradeEntry with no period/curriculumPlanId set has nothing to match — allowed.
     *
     * @return void
     */
    public function testEmptyPeriodOrCurriculumPlanIdAllowsUnconditionally(): void
    {
        $guard   = $this->makeGuard(fraudCaseAllows: true, reportPeriods: []);
        $context = ['object' => ['id' => 'entry-1'], 'actor' => 'teacher-1'];

        self::assertTrue($guard->check($context));

    }//end testEmptyPeriodOrCurriculumPlanIdAllowsUnconditionally()
}//end class
