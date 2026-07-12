<?php

/**
 * Scholiq BsaProgressFlagHandler unit tests.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\BsaProgressFlagHandler;
use OCA\Scholiq\StudyProgress\BsaProgressEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BsaProgressFlagHandler::handle() on GradeEntry → published.
 */
class BsaProgressFlagHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * BsaProgressFlag rows to return from findAll() for idempotency checks,
     * keyed by lifecycle state.
     *
     * @var array<string, array<int, array>>
     */
    private array $existingFlagsByState = [];

    /**
     * Reset the capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects         = [];
        $this->existingFlagsByState = [];

    }//end setUp()

    /**
     * Build a handler with mocked collaborators.
     *
     * @param array<int, string>          $programmeIds Programme IDs the fixture Course belongs to.
     * @param array<string, array>        $trajectories BsaTrajectory rows returned for the trajectory query.
     * @param float                        $ectsEarned   Value returned by the mocked evaluator.
     * @param DateTime                     $now          The "now" the injected ITimeFactory reports.
     *
     * @return BsaProgressFlagHandler
     */
    private function makeHandler(array $programmeIds, array $trajectories, float $ectsEarned, DateTime $now): BsaProgressFlagHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($programmeIds) {
                if ($schema === 'course') {
                    return ['id' => $id, 'programmeIds' => $programmeIds];
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($trajectories) {
                if ($config['schema'] === 'bsa-trajectory') {
                    return $trajectories;
                }

                if ($config['schema'] === 'bsa-progress-flag') {
                    $state = $config['filters']['lifecycle'] ?? '';
                    return $this->existingFlagsByState[$state] ?? [];
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

        $evaluator = $this->createMock(BsaProgressEvaluator::class);
        $evaluator->method('evaluate')->willReturn(['ectsEarned' => $ectsEarned]);

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new BsaProgressFlagHandler($objectService, $evaluator, $timeFactory);

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a GradeEntry → published transition.
     *
     * @param array<string, mixed> $entryData The GradeEntry's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $entryData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($entryData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('grade-entry');
        $event->method('getTo')->willReturn('published');
        $event->method('getFrom')->willReturn('concept');

        return $event;

    }//end makeEvent()

    /**
     * A learner whose recomputed ectsEarned falls below interimNormEcts once
     * the interim-check window has opened gets a BsaProgressFlag (open).
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-falling-behind-pace-ahead-of-the-interim-check-raises-a-flag
     */
    public function testFlagCreatedOnFirstAtRiskCrossing(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $trajectory = [
            'id'               => 'trajectory-1',
            'programmeId'      => 'programme-1',
            'academicYear'     => '2026-2027',
            'interimNormEcts'  => 15,
            'windowOpensAt'    => '2026-02-01',
        ];

        $handler = $this->makeHandler(
            programmeIds: ['programme-1'],
            trajectories: [$trajectory],
            ectsEarned: 10.0,
            now: $now
        );

        $entry = [
            'id'          => 'entry-1',
            'learnerId'   => 'learner-1',
            'courseId'    => 'course-1',
            'tenant_id'   => 'tenant-a',
            'lifecycle'   => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'bsa-progress-flag'));
        self::assertCount(1, $flagSaves);
        self::assertSame('learner-1', $flagSaves[0]['object']['learnerId']);
        self::assertSame('programme-1', $flagSaves[0]['object']['programmeId']);
        self::assertSame('trajectory-1', $flagSaves[0]['object']['bsaTrajectoryId']);
        self::assertSame('2026-2027', $flagSaves[0]['object']['academicYear']);
        self::assertSame(10.0, $flagSaves[0]['object']['ectsEarned']);
        self::assertSame(15.0, $flagSaves[0]['object']['ectsRequiredAtCheck']);
        self::assertSame('open', $flagSaves[0]['object']['lifecycle']);

    }//end testFlagCreatedOnFirstAtRiskCrossing()

    /**
     * No duplicate flag is created when an open flag already exists for the
     * same learner + trajectory (idempotency within the same window).
     *
     * @return void
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    public function testNoDuplicateFlagWhenOneAlreadyOpen(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $trajectory = [
            'id'              => 'trajectory-1',
            'programmeId'     => 'programme-1',
            'academicYear'    => '2026-2027',
            'interimNormEcts' => 15,
            'windowOpensAt'   => '2026-02-01',
        ];

        $handler = $this->makeHandler(
            programmeIds: ['programme-1'],
            trajectories: [$trajectory],
            ectsEarned: 10.0,
            now: $now
        );

        $this->existingFlagsByState['open'] = [['id' => 'existing-flag-1', 'lifecycle' => 'open']];

        $entry = [
            'id'        => 'entry-1',
            'learnerId' => 'learner-1',
            'courseId'  => 'course-1',
            'tenant_id' => 'tenant-a',
            'lifecycle' => 'published',
        ];

        $handler->handle($this->makeEvent($entry));

        $flagSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'bsa-progress-flag'));
        self::assertCount(0, $flagSaves);

    }//end testNoDuplicateFlagWhenOneAlreadyOpen()

    /**
     * No flag is created when the interim-check window has not yet opened.
     *
     * @return void
     */
    public function testNoFlagBeforeWindowOpens(): void
    {
        $now = new DateTime('2026-01-15 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $trajectory = [
            'id'              => 'trajectory-1',
            'programmeId'     => 'programme-1',
            'academicYear'    => '2026-2027',
            'interimNormEcts' => 15,
            'windowOpensAt'   => '2026-02-01',
        ];

        $handler = $this->makeHandler(
            programmeIds: ['programme-1'],
            trajectories: [$trajectory],
            ectsEarned: 10.0,
            now: $now
        );

        $entry = ['id' => 'entry-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

        $handler->handle($this->makeEvent($entry));

        self::assertCount(0, array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'bsa-progress-flag'));

    }//end testNoFlagBeforeWindowOpens()

    /**
     * No flag is created when ectsEarned already meets or exceeds interimNormEcts.
     *
     * @return void
     */
    public function testNoFlagWhenNotAtRisk(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $trajectory = [
            'id'              => 'trajectory-1',
            'programmeId'     => 'programme-1',
            'academicYear'    => '2026-2027',
            'interimNormEcts' => 15,
            'windowOpensAt'   => '2026-02-01',
        ];

        $handler = $this->makeHandler(
            programmeIds: ['programme-1'],
            trajectories: [$trajectory],
            ectsEarned: 20.0,
            now: $now
        );

        $entry = ['id' => 'entry-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

        $handler->handle($this->makeEvent($entry));

        self::assertCount(0, array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'bsa-progress-flag'));

    }//end testNoFlagWhenNotAtRisk()

    /**
     * A trajectory with no interimNormEcts configured is skipped entirely.
     *
     * @return void
     */
    public function testTrajectoryWithoutInterimNormIsSkipped(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $trajectory = [
            'id'              => 'trajectory-1',
            'programmeId'     => 'programme-1',
            'academicYear'    => '2026-2027',
            'interimNormEcts' => null,
            'windowOpensAt'   => '2026-02-01',
        ];

        $handler = $this->makeHandler(
            programmeIds: ['programme-1'],
            trajectories: [$trajectory],
            ectsEarned: 5.0,
            now: $now
        );

        $entry = ['id' => 'entry-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

        $handler->handle($this->makeEvent($entry));

        self::assertCount(0, array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'bsa-progress-flag'));

    }//end testTrajectoryWithoutInterimNormIsSkipped()

    /**
     * A GradeEntry with no courseId (cohort-only scope) is a no-op — no
     * Programme scope to check trajectories against.
     *
     * @return void
     */
    public function testMissingCourseIdIsNoOp(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $handler = $this->makeHandler(programmeIds: ['programme-1'], trajectories: [], ectsEarned: 0.0, now: $now);

        $entry = ['id' => 'entry-1', 'learnerId' => 'learner-1', 'courseId' => null, 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'];

        $handler->handle($this->makeEvent($entry));

        self::assertCount(0, $this->savedObjects);

    }//end testMissingCourseIdIsNoOp()

    /**
     * An event on a different schema/transition is ignored entirely.
     *
     * @return void
     */
    public function testUnrelatedTransitionIsIgnored(): void
    {
        $now = new DateTime('2026-02-05 10:00:00', new DateTimeZone('Europe/Amsterdam'));

        $handler = $this->makeHandler(programmeIds: ['programme-1'], trajectories: [], ectsEarned: 0.0, now: $now);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['id' => 'x']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('attendance-record');
        $event->method('getTo')->willReturn('published');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testUnrelatedTransitionIsIgnored()
}//end class
