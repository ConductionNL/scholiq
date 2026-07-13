<?php

/**
 * Scholiq LessonReleaseEvaluator unit tests.
 *
 * Covers: no conditions/no delay -> available; unmet/met lesson-completed;
 * unmet/met assessment-min-score (score summation, autoScore vs manualScore
 * fallback, null responses, best-of-multiple-attempts); unmet/met
 * availableAfterDays drip (including two learners with different enrolment
 * dates seeing different results from the SAME Lesson row); and an
 * Assessment's absolute availableFrom/availableUntil window (materialised
 * isAvailable) combined correctly with the new per-learner gates.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Release
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
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-supports-drip-release-relative-to-each-learners-own-enrolment-date
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#requirement-assessment-supports-drip-release-relative-to-each-learners-own-enrolment-date
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Release;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Release\LessonReleaseEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LessonReleaseEvaluator::evaluate().
 */
class LessonReleaseEvaluatorTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = [];

    }//end setUp()

    /**
     * Seed a record into the fake datastore.
     *
     * @param string               $schema Schema slug.
     * @param array<string, mixed> $record Record data.
     *
     * @return void
     */
    private function seed(string $schema, array $record): void
    {
        $this->db[$schema][] = $record;

    }//end seed()

    /**
     * Build an evaluator over an in-memory ObjectService double.
     *
     * @return LessonReleaseEvaluator
     */
    private function makeEvaluator(): LessonReleaseEvaluator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, $register=null, $schema=null) {
                foreach (($this->db[$schema] ?? []) as $rec) {
                    if (($rec['id'] ?? null) === $id) {
                        return $rec;
                    }
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema  = $config['schema'];
                $filters = ($config['filters'] ?? []);
                $records = ($this->db[$schema] ?? []);

                return array_values(
                    array_filter(
                        $records,
                        static function (array $rec) use ($filters) {
                            foreach ($filters as $key => $value) {
                                if (($rec[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }
        );

        return new LessonReleaseEvaluator($objectService);

    }//end makeEvaluator()

    /**
     * Build an Enrolment row with a given `@self.created` timestamp.
     *
     * @param string $createdAt ISO-8601 timestamp.
     *
     * @return array<string, mixed>
     */
    private function enrolmentCreatedAt(string $createdAt): array
    {
        return ['@self' => ['created' => $createdAt]];

    }//end enrolmentCreatedAt()

    /**
     * No releaseConditions, no availableAfterDays -> available (today's
     * unconditional-on-publish behaviour, no regression).
     *
     * @return void
     */
    public function testNoConditionsNoDelayIsAvailable(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: ['id' => 'lesson-1', 'tenant_id' => 'tenant-a'],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertTrue($result['available']);
        self::assertNull($result['reason']);
        self::assertNull($result['availableAt']);

    }//end testNoConditionsNoDelayIsAvailable()

    /**
     * An unmet lesson-completed condition blocks with a reason.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#scenario-a-lesson-is-unavailable-until-its-prerequisite-lesson-is-completed
     */
    public function testUnmetLessonCompletedConditionBlocks(): void
    {
        $this->seed('lesson', ['id' => 'lesson-a', 'name' => 'Lesson A']);
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [['kind' => 'lesson-completed', 'lessonId' => 'lesson-a']],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertFalse($result['available']);
        self::assertStringContainsString('Lesson A', (string) $result['reason']);

    }//end testUnmetLessonCompletedConditionBlocks()

    /**
     * A met lesson-completed condition (a matching completed/passed
     * XapiStatement exists) is available.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#scenario-a-lesson-unlocks-once-its-prerequisite-lesson-is-completed
     */
    public function testMetLessonCompletedConditionIsAvailable(): void
    {
        $this->seed('lesson', ['id' => 'lesson-a', 'name' => 'Lesson A']);
        $this->seed(
            'xapi-statement',
            [
                'lessonId'          => 'lesson-a',
                'verified_actor_id' => 'learner-1',
                'verb'              => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
                'tenant_id'         => 'tenant-a',
            ]
        );
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [['kind' => 'lesson-completed', 'lessonId' => 'lesson-a']],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertTrue($result['available']);

    }//end testMetLessonCompletedConditionIsAvailable()

    /**
     * A non-completion xAPI statement (e.g. 'launched') does not satisfy the condition.
     *
     * @return void
     */
    public function testNonCompletionVerbDoesNotSatisfyCondition(): void
    {
        $this->seed('lesson', ['id' => 'lesson-a', 'name' => 'Lesson A']);
        $this->seed(
            'xapi-statement',
            [
                'lessonId'          => 'lesson-a',
                'verified_actor_id' => 'learner-1',
                'verb'              => ['id' => 'http://adlnet.gov/expapi/verbs/launched'],
                'tenant_id'         => 'tenant-a',
            ]
        );
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [['kind' => 'lesson-completed', 'lessonId' => 'lesson-a']],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertFalse($result['available']);

    }//end testNonCompletionVerbDoesNotSatisfyCondition()

    /**
     * An unmet assessment-min-score condition blocks — score sum below minScore.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#scenario-an-assessment-is-unavailable-until-a-minimum-score-on-a-prior-assessment-is-met
     */
    public function testUnmetAssessmentMinScoreConditionBlocks(): void
    {
        $this->seed('assessment', ['id' => 'assessment-a', 'title' => 'Quiz A']);
        $this->seed(
            'assessment-result',
            [
                'assessmentId' => 'assessment-a',
                'learnerId'    => 'learner-1',
                'lifecycle'    => 'graded',
                'tenant_id'    => 'tenant-a',
                'responses'    => [
                    ['itemId' => 'i1', 'autoScore' => 20, 'manualScore' => null],
                    ['itemId' => 'i2', 'autoScore' => 10, 'manualScore' => null],
                ],
            ]
        );
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [
                    ['kind' => 'assessment-min-score', 'assessmentId' => 'assessment-a', 'minScore' => 60],
                ],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertFalse($result['available']);
        self::assertStringContainsString('Quiz A', (string) $result['reason']);

    }//end testUnmetAssessmentMinScoreConditionBlocks()

    /**
     * A met assessment-min-score condition is available — score sum meets
     * minScore, using autoScore with a manualScore fallback and treating a
     * response with neither as 0.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#scenario-an-assessment-unlocks-once-the-learner-meets-the-minimum-score-on-the-prior-assessment
     */
    public function testMetAssessmentMinScoreConditionIsAvailable(): void
    {
        $this->seed('assessment', ['id' => 'assessment-a', 'title' => 'Quiz A']);
        $this->seed(
            'assessment-result',
            [
                'assessmentId' => 'assessment-a',
                'learnerId'    => 'learner-1',
                'lifecycle'    => 'graded',
                'tenant_id'    => 'tenant-a',
                'responses'    => [
                    // autoScore preferred when present.
                    ['itemId' => 'i1', 'autoScore' => 40, 'manualScore' => 5],
                    // manualScore fallback when autoScore is null (essay item).
                    ['itemId' => 'i2', 'autoScore' => null, 'manualScore' => 20],
                    // neither present -> contributes 0.
                    ['itemId' => 'i3', 'autoScore' => null, 'manualScore' => null],
                ],
            ]
        );
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [
                    ['kind' => 'assessment-min-score', 'assessmentId' => 'assessment-a', 'minScore' => 60],
                ],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        // 40 + 20 + 0 = 60 >= 60.
        self::assertTrue($result['available']);

    }//end testMetAssessmentMinScoreConditionIsAvailable()

    /**
     * With multiple graded attempts, the BEST (highest) summed score is used.
     *
     * @return void
     */
    public function testBestOfMultipleAttemptsIsUsed(): void
    {
        $this->seed('assessment', ['id' => 'assessment-a', 'title' => 'Quiz A']);
        $this->seed(
            'assessment-result',
            [
                'assessmentId' => 'assessment-a',
                'learnerId'    => 'learner-1',
                'lifecycle'    => 'graded',
                'tenant_id'    => 'tenant-a',
                'responses'    => [['itemId' => 'i1', 'autoScore' => 30]],
            ]
        );
        $this->seed(
            'assessment-result',
            [
                'assessmentId' => 'assessment-a',
                'learnerId'    => 'learner-1',
                'lifecycle'    => 'graded',
                'tenant_id'    => 'tenant-a',
                'responses'    => [['itemId' => 'i1', 'autoScore' => 90]],
            ]
        );
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                => 'lesson-b',
                'tenant_id'         => 'tenant-a',
                'releaseConditions' => [
                    ['kind' => 'assessment-min-score', 'assessmentId' => 'assessment-a', 'minScore' => 60],
                ],
            ],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertTrue($result['available']);

    }//end testBestOfMultipleAttemptsIsUsed()

    /**
     * availableAfterDays: not yet elapsed for this learner's Enrolment.created blocks.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#scenario-a-lesson-is-locked-until-n-days-after-the-learners-own-enrolment-date
     */
    public function testDripNotYetElapsedBlocks(): void
    {
        $evaluator  = $this->makeEvaluator();
        $threeDaysAgo = (new DateTimeImmutable('-3 days'))->format(DATE_ATOM);

        $result = $evaluator->evaluate(
            item: ['id' => 'lesson-c', 'tenant_id' => 'tenant-a', 'availableAfterDays' => 7],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: $this->enrolmentCreatedAt($threeDaysAgo)
        );

        self::assertFalse($result['available']);
        self::assertNotNull($result['availableAt']);

    }//end testDripNotYetElapsedBlocks()

    /**
     * availableAfterDays: already elapsed is available.
     *
     * @return void
     */
    public function testDripAlreadyElapsedIsAvailable(): void
    {
        $evaluator  = $this->makeEvaluator();
        $tenDaysAgo = (new DateTimeImmutable('-10 days'))->format(DATE_ATOM);

        $result = $evaluator->evaluate(
            item: ['id' => 'lesson-c', 'tenant_id' => 'tenant-a', 'availableAfterDays' => 7],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: $this->enrolmentCreatedAt($tenDaysAgo)
        );

        self::assertTrue($result['available']);

    }//end testDripAlreadyElapsedIsAvailable()

    /**
     * Two learners with different enrolment dates see different availability
     * from the SAME Lesson row — proving the drip instant is genuinely
     * per-learner, not materialised on the item.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#scenario-two-learners-with-different-enrolment-dates-see-different-unlock-dates-for-the-same-lesson
     */
    public function testTwoLearnersDifferentEnrolmentDatesDifferentAvailability(): void
    {
        $evaluator = $this->makeEvaluator();
        $item      = ['id' => 'lesson-c', 'tenant_id' => 'tenant-a', 'availableAfterDays' => 7];

        $learnerA = $evaluator->evaluate(
            item: $item,
            itemSchema: 'lesson',
            learnerId: 'learner-a',
            enrolment: $this->enrolmentCreatedAt((new DateTimeImmutable('-10 days'))->format(DATE_ATOM))
        );
        $learnerB = $evaluator->evaluate(
            item: $item,
            itemSchema: 'lesson',
            learnerId: 'learner-b',
            enrolment: $this->enrolmentCreatedAt((new DateTimeImmutable('-1 day'))->format(DATE_ATOM))
        );

        self::assertTrue($learnerA['available']);
        self::assertFalse($learnerB['available']);

    }//end testTwoLearnersDifferentEnrolmentDatesDifferentAvailability()

    /**
     * No enrolment resolvable (e.g. an admin/teacher preview) skips the drip
     * gate entirely rather than blocking or erroring.
     *
     * @return void
     */
    public function testNoEnrolmentSkipsDripGate(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: ['id' => 'lesson-c', 'tenant_id' => 'tenant-a', 'availableAfterDays' => 7],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertTrue($result['available']);

    }//end testNoEnrolmentSkipsDripGate()

    /**
     * An Assessment outside its absolute availableFrom/availableUntil window
     * (materialised isAvailable: false) blocks even when availableAfterDays
     * has already elapsed — the absolute window and the drip gate must BOTH
     * pass.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#scenario-an-assessment-is-locked-until-n-days-after-the-learners-own-enrolment-date-even-within-its-absolute-availability-window
     */
    public function testAssessmentAbsoluteWindowBlocksEvenWhenDripElapsed(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                 => 'assessment-b',
                'tenant_id'          => 'tenant-a',
                'isAvailable'        => false,
                'availableAfterDays' => 1,
            ],
            itemSchema: 'assessment',
            learnerId: 'learner-1',
            enrolment: $this->enrolmentCreatedAt((new DateTimeImmutable('-10 days'))->format(DATE_ATOM))
        );

        self::assertFalse($result['available']);

    }//end testAssessmentAbsoluteWindowBlocksEvenWhenDripElapsed()

    /**
     * An Assessment inside its absolute window is STILL locked when its own
     * drip delay has not elapsed for this learner — both gates must pass.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#scenario-an-assessment-is-locked-until-n-days-after-the-learners-own-enrolment-date-even-within-its-absolute-availability-window
     */
    public function testAssessmentDripBlocksEvenWhenAbsoluteWindowOpen(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                 => 'assessment-b',
                'tenant_id'          => 'tenant-a',
                'isAvailable'        => true,
                'availableAfterDays' => 7,
            ],
            itemSchema: 'assessment',
            learnerId: 'learner-1',
            enrolment: $this->enrolmentCreatedAt((new DateTimeImmutable('-3 days'))->format(DATE_ATOM))
        );

        self::assertFalse($result['available']);

    }//end testAssessmentDripBlocksEvenWhenAbsoluteWindowOpen()

    /**
     * An Assessment inside its absolute window AND past its drip delay AND
     * with no unmet releaseConditions is available — both gates pass.
     *
     * @return void
     */
    public function testAssessmentAvailableWhenBothGatesPass(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: [
                'id'                 => 'assessment-b',
                'tenant_id'          => 'tenant-a',
                'isAvailable'        => true,
                'availableAfterDays' => 7,
            ],
            itemSchema: 'assessment',
            learnerId: 'learner-1',
            enrolment: $this->enrolmentCreatedAt((new DateTimeImmutable('-10 days'))->format(DATE_ATOM))
        );

        self::assertTrue($result['available']);

    }//end testAssessmentAvailableWhenBothGatesPass()

    /**
     * A Lesson (no `isAvailable` field at all) is never gated by the
     * absolute-window check — that check only applies when the field exists.
     *
     * @return void
     */
    public function testLessonHasNoAbsoluteWindowCheck(): void
    {
        $evaluator = $this->makeEvaluator();

        $result = $evaluator->evaluate(
            item: ['id' => 'lesson-x', 'tenant_id' => 'tenant-a'],
            itemSchema: 'lesson',
            learnerId: 'learner-1',
            enrolment: []
        );

        self::assertTrue($result['available']);

    }//end testLessonHasNoAbsoluteWindowCheck()
}//end class
