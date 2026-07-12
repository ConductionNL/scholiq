<?php

/**
 * Scholiq WerkprocesGradeEmitHandler unit tests.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\WerkprocesGradeEmitHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for WerkprocesGradeEmitHandler::handle() on
 * WerkprocesAssessment → confirmed.
 */
class WerkprocesGradeEmitHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler backed by an ObjectService stub.
     *
     * @param array<string, mixed>|null      $placement       BpvPlacement returned for
     *                                                         bpv-placement lookups.
     * @param array<string, mixed>|null      $curriculumPlan  CurriculumPlan returned for
     *                                                         curriculum-plan lookups.
     * @param array<int, array<string,mixed>> $existingEntries GradeEntry rows returned for
     *                                                         grade-entry lookups.
     *
     * @return WerkprocesGradeEmitHandler
     */
    private function makeHandler(?array $placement, ?array $curriculumPlan, array $existingEntries=[]): WerkprocesGradeEmitHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($placement, $curriculumPlan, $existingEntries) {
                if ($config['schema'] === 'bpv-placement') {
                    return ($placement === null) ? [] : [$placement];
                }

                if ($config['schema'] === 'curriculum-plan') {
                    return ($curriculumPlan === null) ? [] : [$curriculumPlan];
                }

                if ($config['schema'] === 'grade-entry') {
                    return $existingEntries;
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

        return new WerkprocesGradeEmitHandler($objectService, $this->createMock(LoggerInterface::class));

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a WerkprocesAssessment → confirmed transition.
     *
     * @param array<string, mixed> $assessmentData The WerkprocesAssessment's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $assessmentData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($assessmentData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('werkproces-assessment');
        $event->method('getTo')->willReturn('confirmed');
        $event->method('getFrom')->willReturn('submitted');

        return $event;

    }//end makeEvent()

    /**
     * A `competent` assessment with no existing GradeEntry creates a new one with value 1.0,
     * sourceKind 'manual', and no final-grade computation of its own.
     *
     * @return void
     */
    public function testCompetentAssessmentCreatesGradeEntry(): void
    {
        $placement = ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a'];
        $plan      = ['id' => 'plan-1', 'gradeScaleId' => 'scale-pass-fail'];

        $handler = $this->makeHandler($placement, $plan, []);

        $assessment = [
            'id'               => 'wpa-1',
            'bpvPlacementId'   => 'placement-1',
            'curriculumPlanId' => 'plan-1',
            'componentId'      => 'component-bpv',
            'beoordeling'      => 'competent',
        ];

        $handler->handle($this->makeEvent($assessment));

        $gradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        $this->assertCount(1, $gradeSaves);

        $saved = $gradeSaves[0]['object'];
        $this->assertSame('learner-7', $saved['learnerId']);
        $this->assertSame('plan-1', $saved['curriculumPlanId']);
        $this->assertSame('component-bpv', $saved['componentId']);
        $this->assertSame(1.0, $saved['value']);
        $this->assertSame('manual', $saved['sourceKind']);
        $this->assertSame('scale-pass-fail', $saved['gradeScaleId']);
        $this->assertSame('tenant-a', $saved['tenant_id']);
        $this->assertSame('concept', $saved['lifecycle']);

    }//end testCompetentAssessmentCreatesGradeEntry()

    /**
     * A `nog-niet-competent` assessment maps to value 0.0.
     *
     * @return void
     */
    public function testNogNietCompetentMapsToZero(): void
    {
        $placement = ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a'];
        $plan      = ['id' => 'plan-1', 'gradeScaleId' => 'scale-pass-fail'];

        $handler = $this->makeHandler($placement, $plan, []);

        $assessment = [
            'id'               => 'wpa-2',
            'bpvPlacementId'   => 'placement-1',
            'curriculumPlanId' => 'plan-1',
            'componentId'      => 'component-bpv',
            'beoordeling'      => 'nog-niet-competent',
        ];

        $handler->handle($this->makeEvent($assessment));

        $gradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        $this->assertCount(1, $gradeSaves);
        $this->assertSame(0.0, $gradeSaves[0]['object']['value']);

    }//end testNogNietCompetentMapsToZero()

    /**
     * When a GradeEntry already exists for the same learner/plan/component, the handler updates
     * it in place (preserving its id) instead of creating a duplicate.
     *
     * @return void
     */
    public function testExistingGradeEntryIsUpdatedInPlace(): void
    {
        $placement = ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a'];
        $plan      = ['id' => 'plan-1', 'gradeScaleId' => 'scale-pass-fail'];
        $existing  = [
            'id'               => 'entry-existing',
            'learnerId'        => 'learner-7',
            'curriculumPlanId' => 'plan-1',
            'componentId'      => 'component-bpv',
            'value'            => 0.0,
            'lifecycle'        => 'concept',
        ];

        $handler = $this->makeHandler($placement, $plan, [$existing]);

        $assessment = [
            'id'               => 'wpa-3',
            'bpvPlacementId'   => 'placement-1',
            'curriculumPlanId' => 'plan-1',
            'componentId'      => 'component-bpv',
            'beoordeling'      => 'competent',
        ];

        $handler->handle($this->makeEvent($assessment));

        $gradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
        $this->assertCount(1, $gradeSaves);
        $this->assertSame('entry-existing', $gradeSaves[0]['object']['id']);
        $this->assertSame(1.0, $gradeSaves[0]['object']['value']);

    }//end testExistingGradeEntryIsUpdatedInPlace()

    /**
     * A BpvPlacement that cannot be resolved is a safe no-op — no GradeEntry is written.
     *
     * @return void
     */
    public function testUnresolvablePlacementIsNoOp(): void
    {
        $handler = $this->makeHandler(null, null, []);

        $assessment = [
            'id'               => 'wpa-4',
            'bpvPlacementId'   => 'placement-missing',
            'curriculumPlanId' => 'plan-1',
            'componentId'      => 'component-bpv',
            'beoordeling'      => 'competent',
        ];

        $handler->handle($this->makeEvent($assessment));

        $this->assertCount(0, $this->savedObjects);

    }//end testUnresolvablePlacementIsNoOp()

    /**
     * Events for other schemas/states are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvents(): void
    {
        $handler = $this->makeHandler(['id' => 'placement-1', 'learnerId' => 'learner-7'], null, []);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $event        = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('bpv-placement');
        $event->method('getTo')->willReturn('confirmed');

        $handler->handle($event);

        $this->assertCount(0, $this->savedObjects);

    }//end testIgnoresUnrelatedEvents()
}//end class
