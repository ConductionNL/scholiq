<?php

/**
 * Scholiq PointAwardTriggerHandler unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Grading\GradeFormulaEvaluator;
use OCA\Scholiq\Listener\PointAwardTriggerHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PointAwardTriggerHandler::handle().
 */
class PointAwardTriggerHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * PointRule rows to return from findAll(), keyed by kind.
     *
     * @var array<string, array<int, array>>
     */
    private array $rulesByKind = [];

    /**
     * PointAward rows already "existing" for idempotency checks.
     *
     * @var array<int, array>
     */
    private array $existingAwards = [];

    /**
     * GradeFormulaEvaluator::evaluate() result to return.
     *
     * @var array<string,mixed>
     */
    private array $gradeEvaluatorResult = ['passed' => false];

    /**
     * Reset the capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects         = [];
        $this->rulesByKind          = [];
        $this->existingAwards       = [];
        $this->gradeEvaluatorResult = ['passed' => false];

    }//end setUp()

    /**
     * Build a handler with mocked collaborators.
     *
     * @param DateTime $now The "now" the injected ITimeFactory reports.
     *
     * @return PointAwardTriggerHandler
     */
    private function makeHandler(DateTime $now): PointAwardTriggerHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if ($config['schema'] === 'point-rule') {
                    $kind = $config['filters']['kind'] ?? '';
                    return $this->rulesByKind[$kind] ?? [];
                }

                if ($config['schema'] === 'point-award') {
                    $filters = $config['filters'] ?? [];
                    foreach ($this->existingAwards as $existing) {
                        if (($existing['learnerId'] ?? null) === ($filters['learnerId'] ?? null)
                            && ($existing['pointRuleId'] ?? null) === ($filters['pointRuleId'] ?? null)
                            && ($existing['sourceObjectId'] ?? null) === ($filters['sourceObjectId'] ?? null)
                        ) {
                            return [$existing];
                        }
                    }

                    return [];
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        $evaluator = $this->createMock(GradeFormulaEvaluator::class);
        $evaluator->method('evaluate')->willReturnCallback(fn () => $this->gradeEvaluatorResult);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new PointAwardTriggerHandler($objectService, $evaluator, $timeFactory);

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent.
     *
     * @param string               $schema Schema slug.
     * @param string               $to     Target lifecycle state.
     * @param array<string, mixed> $data   The object's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(string $schema, string $to, array $data): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn($schema);
        $event->method('getTo')->willReturn($to);

        return $event;

    }//end makeEvent()

    /**
     * Filter savedObjects to those matching a schema.
     *
     * @param string $schema Schema slug.
     *
     * @return array<int, array>
     */
    private function savesFor(string $schema): array
    {
        return array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === $schema));

    }//end savesFor()

    /**
     * Enrolment -> completed with an active enrolment-completed rule awards points.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-completing-mandatory-training-awards-enrolment-completed-points
     */
    public function testEnrolmentCompletedAwardsPoints(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['enrolment-completed'] = [
            ['id' => 'rule-1', 'points' => 10, 'scope' => null],
        ];

        $handler = $this->makeHandler(now: $now);

        $entry = ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a'];
        $handler->handle($this->makeEvent('enrolment', 'completed', $entry));

        $saves = $this->savesFor('point-award');
        self::assertCount(1, $saves);
        self::assertSame('learner-1', $saves[0]['object']['learnerId']);
        self::assertSame('rule-1', $saves[0]['object']['pointRuleId']);
        self::assertSame(10.0, $saves[0]['object']['points']);
        self::assertSame('enrolment', $saves[0]['object']['sourceKind']);
        self::assertSame('enrolment-1', $saves[0]['object']['sourceObjectId']);

    }//end testEnrolmentCompletedAwardsPoints()

    /**
     * Submission -> submitted with isLate:false awards every learner on a group submission.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-on-time-submission-awards-submission-on-time-points-a-late-one-does-not
     */
    public function testOnTimeSubmissionAwardsEveryLearner(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['submission-on-time'] = [
            ['id' => 'rule-2', 'points' => 5, 'scope' => null],
        ];

        $handler = $this->makeHandler(now: $now);

        $entry = [
            'id'         => 'submission-1',
            'learnerIds' => ['learner-1', 'learner-2'],
            'isLate'     => false,
            'tenant_id'  => 'tenant-a',
        ];
        $handler->handle($this->makeEvent('submission', 'submitted', $entry));

        $saves       = $this->savesFor('point-award');
        $learnerIds  = array_map(static fn ($s) => $s['object']['learnerId'], $saves);

        self::assertCount(2, $saves);
        self::assertSame(['learner-1', 'learner-2'], $learnerIds);

    }//end testOnTimeSubmissionAwardsEveryLearner()

    /**
     * A late Submission (isLate:true) awards nothing.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-on-time-submission-awards-submission-on-time-points-a-late-one-does-not
     */
    public function testLateSubmissionAwardsNothing(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['submission-on-time'] = [
            ['id' => 'rule-2', 'points' => 5, 'scope' => null],
        ];

        $handler = $this->makeHandler(now: $now);

        $entry = [
            'id'         => 'submission-2',
            'learnerIds' => ['learner-1'],
            'isLate'     => true,
            'tenant_id'  => 'tenant-a',
        ];
        $handler->handle($this->makeEvent('submission', 'submitted', $entry));

        self::assertCount(0, $this->savesFor('point-award'));

    }//end testLateSubmissionAwardsNothing()

    /**
     * GradeEntry -> published with GradeFormulaEvaluator passed:true awards
     * points referencing the curriculumPlanId.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-passing-gradeentry-awards-finalgrade-passed-points
     */
    public function testPassingGradeEntryAwardsPoints(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['finalgrade-passed'] = [
            ['id' => 'rule-3', 'points' => 15, 'scope' => null],
        ];
        $this->gradeEvaluatorResult = ['passed' => true];

        $handler = $this->makeHandler(now: $now);

        $entry = [
            'id'                => 'grade-entry-1',
            'learnerId'         => 'learner-1',
            'curriculumPlanId'  => 'plan-1',
            'tenant_id'         => 'tenant-a',
        ];
        $handler->handle($this->makeEvent('grade-entry', 'published', $entry));

        $saves = $this->savesFor('point-award');
        self::assertCount(1, $saves);
        self::assertSame('plan-1', $saves[0]['object']['sourceObjectId']);
        self::assertSame('grade-entry', $saves[0]['object']['sourceKind']);

    }//end testPassingGradeEntryAwardsPoints()

    /**
     * GradeEntry -> published with GradeFormulaEvaluator passed:false awards nothing.
     *
     * @return void
     */
    public function testFailingGradeEntryAwardsNothing(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['finalgrade-passed'] = [
            ['id' => 'rule-3', 'points' => 15, 'scope' => null],
        ];
        $this->gradeEvaluatorResult = ['passed' => false];

        $handler = $this->makeHandler(now: $now);

        $entry = ['id' => 'grade-entry-1', 'learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'];
        $handler->handle($this->makeEvent('grade-entry', 'published', $entry));

        self::assertCount(0, $this->savesFor('point-award'));

    }//end testFailingGradeEntryAwardsNothing()

    /**
     * Republishing a revised GradeEntry (a second published transition for
     * the same curriculumPlanId) does not duplicate the award.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-republishing-a-revised-gradeentry-does-not-duplicate-the-award
     */
    public function testRepublishDoesNotDuplicateAward(): void
    {
        $now = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $this->rulesByKind['finalgrade-passed'] = [
            ['id' => 'rule-3', 'points' => 15, 'scope' => null],
        ];
        $this->gradeEvaluatorResult = ['passed' => true];
        $this->existingAwards[]     = [
            'learnerId'      => 'learner-1',
            'pointRuleId'    => 'rule-3',
            'sourceObjectId' => 'plan-1',
        ];

        $handler = $this->makeHandler(now: $now);

        $entry = ['id' => 'grade-entry-1', 'learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'];
        $handler->handle($this->makeEvent('grade-entry', 'published', $entry));

        self::assertCount(0, $this->savesFor('point-award'));

    }//end testRepublishDoesNotDuplicateAward()

    /**
     * An event on an unrelated schema/transition is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedTransitionIsIgnored(): void
    {
        $now     = new DateTime('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));
        $handler = $this->makeHandler(now: $now);

        $entry = ['id' => 'x'];
        $handler->handle($this->makeEvent('attendance-record', 'published', $entry));

        self::assertCount(0, $this->savedObjects);

    }//end testUnrelatedTransitionIsIgnored()

    /**
     * PointRule.kind contains no peer-review value -- peer review is unbuilt
     * in the assignments capability and is explicitly out of scope.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-no-pointrule-kind-exists-for-peer-review
     */
    public function testNoPeerReviewKindExistsInRegister(): void
    {
        $registerPath = dirname(__DIR__, 3).'/lib/Settings/scholiq_register.json';
        $register     = json_decode((string) file_get_contents($registerPath), true);

        $enum = $register['components']['schemas']['PointRule']['properties']['kind']['enum'] ?? [];

        self::assertSame(
            ['enrolment-completed', 'submission-on-time', 'finalgrade-passed', 'streak-milestone'],
            $enum
        );
        self::assertNotContains('peer-review', $enum);

    }//end testNoPeerReviewKindExistsInRegister()
}//end class
