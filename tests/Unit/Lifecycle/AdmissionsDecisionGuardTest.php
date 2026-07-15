<?php

/**
 * Scholiq AdmissionsDecisionGuard unit tests.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\AdmissionsDecisionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the AdmissionsDecisionGuard lifecycle guard.
 */
class AdmissionsDecisionGuardTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Set up a fresh mock before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);

    }//end setUp()

    /**
     * Wire ObjectService::find to return the given AdmissionsRound for any admissions-round id.
     *
     * @param array<string,mixed>|null $round The round to return.
     *
     * @return void
     */
    private function wireRound(?array $round): void
    {
        $this->objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($round) {
                if ($schema === 'admissions-round') {
                    return $round;
                }

                return null;
            }
        );

    }//end wireRound()

    /**
     * Wire ObjectService::findAll to return $placed rows for a `placed` lifecycle
     * filter and $converted rows for a `converted` lifecycle filter on application.
     *
     * @param array<int,array<string,mixed>> $placed    Rows for lifecycle=placed.
     * @param array<int,array<string,mixed>> $converted Rows for lifecycle=converted.
     *
     * @return void
     */
    private function wireApplicationCounts(array $placed, array $converted): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($placed, $converted) {
                if (($config['schema'] ?? '') !== 'application') {
                    return [];
                }

                $lifecycle = $config['filters']['lifecycle'] ?? '';
                if ($lifecycle === 'placed') {
                    return $placed;
                }

                if ($lifecycle === 'converted') {
                    return $converted;
                }

                return [];
            }
        );

    }//end wireApplicationCounts()

    /**
     * Build the guard under test.
     *
     * @return AdmissionsDecisionGuard
     */
    private function makeGuard(): AdmissionsDecisionGuard
    {
        return new AdmissionsDecisionGuard($this->objectService, new NullLogger());

    }//end makeGuard()

    /**
     * Base application fixture. Override fields per test.
     *
     * @param array<string,mixed> $overrides Fields to override.
     *
     * @return array<string,mixed>
     */
    private function application(array $overrides = []): array
    {
        return array_merge(
            [
                'id'                 => 'app-1',
                'admissionsRoundId'  => 'round-1',
                'tenant_id'          => 'tenant-a',
                'submittedAt'        => '2026-01-15T10:00:00+01:00',
            ],
            $overrides
        );

    }//end application()

    /**
     * completeIntake is blocked when mandatoryIntake is true and intakeCompleted is false.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
     */
    public function testMandatoryIntakeBlocksCompleteIntakeWhenNotRecorded(): void
    {
        $this->wireRound(['kind' => 'generic', 'mandatoryIntake' => true]);

        $context = ['object' => $this->application(['intakeCompleted' => false]), 'to' => 'intake-completed'];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMandatoryIntakeBlocksCompleteIntakeWhenNotRecorded()

    /**
     * completeIntake succeeds when mandatoryIntake is true and intakeCompleted is true.
     *
     * @return void
     */
    public function testMandatoryIntakeAllowsCompleteIntakeWhenRecorded(): void
    {
        $this->wireRound(['kind' => 'generic', 'mandatoryIntake' => true]);

        $context = ['object' => $this->application(['intakeCompleted' => true]), 'to' => 'intake-completed'];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testMandatoryIntakeAllowsCompleteIntakeWhenRecorded()

    /**
     * completeIntake succeeds regardless of intakeCompleted when mandatoryIntake is false.
     *
     * @return void
     */
    public function testMandatoryIntakeFalseAllowsCompleteIntakeRegardless(): void
    {
        $this->wireRound(['kind' => 'generic', 'mandatoryIntake' => false]);

        $context = ['object' => $this->application(['intakeCompleted' => false]), 'to' => 'intake-completed'];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testMandatoryIntakeFalseAllowsCompleteIntakeRegardless()

    /**
     * A timely, intake-complete MBO application with a given studiekeuzeadvies cannot be
     * rejected without a named decisionReason.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-timely-intake-complete-mbo-application-cannot-be-rejected-without-a-named-reason
     */
    public function testToelatingsrechtBlocksRejectionWithoutNamedReason(): void
    {
        $this->wireRound(
            [
                'kind'                => 'mbo-toelatingsrecht',
                'applicationDeadline' => '2026-04-01',
            ]
        );

        $context = [
            'object' => $this->application(
                [
                    'submittedAt'             => '2026-03-01T10:00:00+01:00',
                    'studiekeuzeadviesGiven'  => true,
                    'decisionReason'          => '',
                ]
            ),
            'to'     => 'rejected',
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testToelatingsrechtBlocksRejectionWithoutNamedReason()

    /**
     * A named prerequisite failure still allows rejection.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-named-prerequisite-failure-still-allows-rejection
     */
    public function testToelatingsrechtAllowsRejectionWithNamedReason(): void
    {
        $this->wireRound(
            [
                'kind'                => 'mbo-toelatingsrecht',
                'applicationDeadline' => '2026-04-01',
            ]
        );

        $context = [
            'object' => $this->application(
                [
                    'submittedAt'            => '2026-03-01T10:00:00+01:00',
                    'studiekeuzeadviesGiven' => true,
                    'decisionReason'         => 'prerequisite diploma not held',
                ]
            ),
            'to'     => 'rejected',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testToelatingsrechtAllowsRejectionWithNamedReason()

    /**
     * A late application (after applicationDeadline) is not protected by the toelatingsrecht
     * safeguard — rejection without a named reason is allowed.
     *
     * @return void
     */
    public function testToelatingsrechtDoesNotBlockLateApplicationRejection(): void
    {
        $this->wireRound(
            [
                'kind'                => 'mbo-toelatingsrecht',
                'applicationDeadline' => '2026-04-01',
            ]
        );

        $context = [
            'object' => $this->application(
                [
                    'submittedAt'            => '2026-05-01T10:00:00+01:00',
                    'studiekeuzeadviesGiven' => true,
                    'decisionReason'         => '',
                ]
            ),
            'to'     => 'rejected',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testToelatingsrechtDoesNotBlockLateApplicationRejection()

    /**
     * A higher doorstroomtoets score without an adjustment or motivation blocks the decision.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-higher-doorstroomtoets-score-without-an-adjustment-or-motivation-blocks-the-decision
     */
    public function testSchooladviesAdjustmentRequiredBlocksDecision(): void
    {
        $this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

        $context = [
            'object' => $this->application(
                [
                    'schooladviesLevel'         => 'vmbo-gt',
                    'doorstroomtoetsLevel'      => 'havo',
                    'schooladviesAdjustedLevel' => 'vmbo-gt',
                    'adjustmentMotivation'      => '',
                ]
            ),
            'to'     => 'placed',
        ];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testSchooladviesAdjustmentRequiredBlocksDecision()

    /**
     * The pro/vmbo-bb exemption allows the decision without adjustment.
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-the-pro-vmbo-bb-exemption-allows-the-decision-without-adjustment
     */
    public function testProVmboBbExemptionAllowsDecision(): void
    {
        $this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

        $context = [
            'object' => $this->application(
                [
                    'schooladviesLevel'         => 'pro',
                    'doorstroomtoetsLevel'      => 'vmbo-bb',
                    'schooladviesAdjustedLevel' => 'pro',
                    'adjustmentMotivation'      => '',
                ]
            ),
            'to'     => 'waitlisted',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testProVmboBbExemptionAllowsDecision()

    /**
     * Raising schooladviesAdjustedLevel to match doorstroomtoetsLevel satisfies the rule.
     *
     * @return void
     */
    public function testAdjustedLevelMatchingDoorstroomtoetsAllowsDecision(): void
    {
        $this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

        $context = [
            'object' => $this->application(
                [
                    'schooladviesLevel'         => 'vmbo-gt',
                    'doorstroomtoetsLevel'      => 'havo',
                    'schooladviesAdjustedLevel' => 'havo',
                    'adjustmentMotivation'      => '',
                ]
            ),
            'to'     => 'placed',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testAdjustedLevelMatchingDoorstroomtoetsAllowsDecision()

    /**
     * A non-empty adjustmentMotivation satisfies the rule even without raising the level.
     *
     * @return void
     */
    public function testMotivatedExceptionAllowsDecision(): void
    {
        $this->wireRound(['kind' => 'vo-schooladvies-doorstroomtoets']);

        $context = [
            'object' => $this->application(
                [
                    'schooladviesLevel'         => 'vmbo-gt',
                    'doorstroomtoetsLevel'      => 'havo',
                    'schooladviesAdjustedLevel' => 'vmbo-gt',
                    'adjustmentMotivation'      => 'Not in the pupil\'s best interest given documented circumstances.',
                ]
            ),
            'to'     => 'rejected',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testMotivatedExceptionAllowsDecision()

    /**
     * A full round routes a new placement attempt to blocked (coordinator must waitlist instead).
     *
     * @return void
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#scenario-a-full-round-routes-a-new-placement-to-the-waitlist
     */
    public function testCapacityReachedBlocksPlacement(): void
    {
        $this->wireRound(['kind' => 'generic', 'capacity' => 2]);
        $this->wireApplicationCounts(placed: [['id' => 'a'], ['id' => 'b']], converted: []);

        $context = ['object' => $this->application(), 'to' => 'placed'];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testCapacityReachedBlocksPlacement()

    /**
     * A round under capacity allows placement.
     *
     * @return void
     */
    public function testCapacityNotReachedAllowsPlacement(): void
    {
        $this->wireRound(['kind' => 'generic', 'capacity' => 2]);
        $this->wireApplicationCounts(placed: [['id' => 'a']], converted: []);

        $context = ['object' => $this->application(), 'to' => 'placed'];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testCapacityNotReachedAllowsPlacement()

    /**
     * A null capacity (uncapped) never blocks placement — no findAll query is even needed.
     *
     * @return void
     */
    public function testNullCapacityNeverBlocksPlacement(): void
    {
        $this->wireRound(['kind' => 'generic', 'capacity' => null]);
        $this->objectService->expects(self::never())->method('findAll');

        $context = ['object' => $this->application(), 'to' => 'placed'];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testNullCapacityNeverBlocksPlacement()

    /**
     * Capacity counts both `placed` and `converted` Applications toward the limit.
     *
     * @return void
     */
    public function testCapacityCountsPlacedAndConverted(): void
    {
        $this->wireRound(['kind' => 'generic', 'capacity' => 2]);
        $this->wireApplicationCounts(placed: [['id' => 'a']], converted: [['id' => 'c']]);

        $context = ['object' => $this->application(), 'to' => 'placed'];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testCapacityCountsPlacedAndConverted()

    /**
     * A missing admissionsRoundId fails closed without querying.
     *
     * @return void
     */
    public function testMissingAdmissionsRoundIdFailsClosed(): void
    {
        $this->objectService->expects(self::never())->method('find');

        $context = ['object' => ['id' => 'app-1', 'tenant_id' => 'tenant-a'], 'to' => 'placed'];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingAdmissionsRoundIdFailsClosed()

    /**
     * A missing AdmissionsRound (find() returns null) fails closed.
     *
     * @return void
     */
    public function testMissingRoundFailsClosed(): void
    {
        $this->wireRound(null);

        $context = ['object' => $this->application(), 'to' => 'placed'];

        self::assertFalse($this->makeGuard()->check($context));

    }//end testMissingRoundFailsClosed()

    /**
     * A transition this guard does not govern (e.g. submit) is always allowed without
     * touching capacity/schooladvies/toelatingsrecht branches.
     *
     * @return void
     */
    public function testUngovernedTransitionAllowed(): void
    {
        $this->wireRound(['kind' => 'mbo-toelatingsrecht']);

        $context = ['object' => $this->application(), 'to' => 'submitted'];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testUngovernedTransitionAllowed()

    /**
     * A generic-kind round only applies the capacity branch — no schooladvies/toelatingsrecht checks.
     *
     * @return void
     */
    public function testGenericKindSkipsToelatingsrechtAndSchooladviesBranches(): void
    {
        $this->wireRound(['kind' => 'generic', 'capacity' => null]);

        $context = [
            'object' => $this->application(
                [
                    'schooladviesLevel'    => 'vmbo-gt',
                    'doorstroomtoetsLevel' => 'vwo',
                    'decisionReason'       => '',
                ]
            ),
            'to'     => 'rejected',
        ];

        self::assertTrue($this->makeGuard()->check($context));

    }//end testGenericKindSkipsToelatingsrechtAndSchooladviesBranches()
}//end class
