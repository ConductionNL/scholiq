<?php

/**
 * Scholiq CompetencyAttainmentRollupHandler unit tests.
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
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CompetencyAttainmentRollupHandler's two responsibilities:
 * WerkprocesAssessment.competencyId resolution on creation, and the
 * GradeEntry.published / WerkprocesAssessment.confirmed -> CompetencyAttainment
 * roll-up on transition.
 */
class CompetencyAttainmentRollupHandlerTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug. Persists across
     * multiple handle() calls within one test so idempotency (re-processing
     * the same evidence id) and find-or-create upserts can be exercised.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db           = [];
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler backed by an ObjectService stub over $this->db.
     *
     * @return CompetencyAttainmentRollupHandler
     */
    private function makeHandler(): CompetencyAttainmentRollupHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema  = $config['schema'];
                $records = $this->db[$schema] ?? [];
                $filters = $config['filters'] ?? [];

                $matched = array_values(
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

                if (isset($config['limit']) === true) {
                    $matched = array_slice($matched, 0, (int) $config['limit']);
                }

                return $matched;
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                if (isset($object['id']) === false) {
                    $object['id'] = $schema.'-auto-'.(count($this->db[$schema] ?? []) + 1);
                }

                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

                $existingIndex = null;
                foreach (($this->db[$schema] ?? []) as $index => $rec) {
                    if (($rec['id'] ?? null) === $object['id']) {
                        $existingIndex = $index;
                        break;
                    }
                }

                if ($existingIndex !== null) {
                    $this->db[$schema][$existingIndex] = $object;
                } else {
                    $this->db[$schema][] = $object;
                }

                return $object;
            }
        );

        return new CompetencyAttainmentRollupHandler($objectService, $this->createMock(LoggerInterface::class));

    }//end makeHandler()

    /**
     * Seed a record into the fake datastore for a given schema.
     *
     * @param string               $schema Schema slug.
     * @param array<string, mixed> $record Record data (must include 'id').
     *
     * @return void
     */
    private function seed(string $schema, array $record): void
    {
        $this->db[$schema][] = $record;

    }//end seed()

    /**
     * Build a mocked ObjectTransitionedEvent.
     *
     * @param string               $schema Schema slug.
     * @param string               $to     Target lifecycle state.
     * @param array<string, mixed> $data   The transitioning object's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeTransitionEvent(string $schema, string $to, array $data): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn($schema);
        $event->method('getTo')->willReturn($to);
        $event->method('getFrom')->willReturn('concept');

        return $event;

    }//end makeTransitionEvent()

    /**
     * Build a mocked ObjectCreatedEvent.
     *
     * @param string               $schema Schema slug.
     * @param array<string, mixed> $data   The created object's jsonSerialize() payload.
     *
     * @return ObjectCreatedEvent
     */
    private function makeCreatedEvent(string $schema, array $data): ObjectCreatedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($data);
        $objectEntity->method('getRegister')->willReturn('scholiq');
        $objectEntity->method('getSchema')->willReturn($schema);

        $event = $this->createMock(ObjectCreatedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn($schema);

        return $event;

    }//end makeCreatedEvent()

    /**
     * Fetch every saveObject() call recorded for a given schema.
     *
     * @param string $schema Schema slug.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savedFor(string $schema): array
    {
        return array_values(
            array_map(
                static fn (array $s) => $s['object'],
                array_filter($this->savedObjects, static fn (array $s) => $s['schema'] === $schema)
            )
        );

    }//end savedFor()

    /**
     * A published GradeEntry (sourceKind: assignment-submission) from a
     * competency-aligned Assignment creates a CompetencyAttainment row and
     * appends the GradeEntry + Submission ids as evidence.
     *
     * @return void
     */
    public function testAssignmentSubmissionPathCreatesAttainment(): void
    {
        $handler = $this->makeHandler();

        $this->seed('submission', ['id' => 'sub-1', 'assignmentId' => 'assign-1']);
        $this->seed(
            'assignment',
            ['id' => 'assign-1', 'maxPoints' => 100, 'competencyIds' => ['comp-1']]
        );
        $this->seed(
            'competency',
            ['id' => 'comp-1', 'frameworkId' => 'fw-1']
        );
        $this->seed(
            'competency-framework',
            [
                'id'                => 'fw-1',
                'proficiencyLevels' => [
                    ['levelId' => 'basic', 'label' => 'Basic', 'order' => 0, 'minPercent' => 0],
                    ['levelId' => 'proficient', 'label' => 'Proficient', 'order' => 1, 'minPercent' => 70],
                ],
            ]
        );

        $entry = [
            'id'           => 'ge-1',
            'learnerId'    => 'learner-9',
            'sourceKind'   => 'assignment-submission',
            'submissionId' => 'sub-1',
            'value'        => 85.0,
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));

        $attainments = $this->savedFor('competency-attainment');
        $this->assertCount(1, $attainments);
        $this->assertSame('learner-9', $attainments[0]['learnerId']);
        $this->assertSame('comp-1', $attainments[0]['competencyId']);
        $this->assertSame('fw-1', $attainments[0]['frameworkId']);
        $this->assertSame(['ge-1'], $attainments[0]['gradeEntryIds']);
        $this->assertSame(['sub-1'], $attainments[0]['submissionIds']);
        $this->assertSame('proficient', $attainments[0]['proficiencyLevelId']);

    }//end testAssignmentSubmissionPathCreatesAttainment()

    /**
     * A published GradeEntry (sourceKind: assessment-result) from a
     * competency-aligned Assessment creates a CompetencyAttainment row,
     * deriving the percentage from Assessment.itemRefs[].points.
     *
     * @return void
     */
    public function testAssessmentResultPathCreatesAttainment(): void
    {
        $handler = $this->makeHandler();

        $this->seed('assessment-result', ['id' => 'ar-1', 'assessmentId' => 'assess-1']);
        $this->seed(
            'assessment',
            [
                'id'            => 'assess-1',
                'competencyIds' => ['comp-2'],
                'itemRefs'      => [
                    ['itemId' => 'item-1', 'points' => 40],
                    ['itemId' => 'item-2', 'points' => 60],
                ],
            ]
        );
        $this->seed('competency', ['id' => 'comp-2', 'frameworkId' => 'fw-2']);
        $this->seed(
            'competency-framework',
            [
                'id'                => 'fw-2',
                'proficiencyLevels' => [
                    ['levelId' => 'insufficient', 'label' => 'Insufficient', 'order' => 0, 'minPercent' => 0],
                    ['levelId' => 'sufficient', 'label' => 'Sufficient', 'order' => 1, 'minPercent' => 55],
                ],
            ]
        );

        $entry = [
            'id'                 => 'ge-2',
            'learnerId'          => 'learner-3',
            'sourceKind'         => 'assessment-result',
            'assessmentResultId' => 'ar-1',
            'value'              => 60.0,
            'tenant_id'          => 'tenant-a',
        ];

        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));

        $attainments = $this->savedFor('competency-attainment');
        $this->assertCount(1, $attainments);
        $this->assertSame('comp-2', $attainments[0]['competencyId']);
        $this->assertSame(['ge-2'], $attainments[0]['gradeEntryIds']);
        $this->assertSame(['ar-1'], $attainments[0]['assessmentResultIds']);
        // 60/100 = 60% -> meets the 55% threshold for 'sufficient'.
        $this->assertSame('sufficient', $attainments[0]['proficiencyLevelId']);

    }//end testAssessmentResultPathCreatesAttainment()

    /**
     * A confirmed WerkprocesAssessment with a resolved competencyId updates
     * CompetencyAttainment, mapping `competent` onto the framework's highest level.
     *
     * @return void
     */
    public function testWerkprocesConfirmedCompetentMapsToHighestLevel(): void
    {
        $handler = $this->makeHandler();

        $this->seed('bpv-placement', ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a']);
        $this->seed('competency', ['id' => 'comp-3', 'frameworkId' => 'fw-3']);
        $this->seed(
            'competency-framework',
            [
                'id'                => 'fw-3',
                'proficiencyLevels' => [
                    ['levelId' => 'nog-niet-competent', 'label' => 'Nog niet competent', 'order' => 0],
                    ['levelId' => 'competent', 'label' => 'Competent', 'order' => 1],
                ],
            ]
        );

        $assessment = [
            'id'             => 'wpa-1',
            'bpvPlacementId' => 'placement-1',
            'competencyId'   => 'comp-3',
            'beoordeling'    => 'competent',
        ];

        $handler->handle($this->makeTransitionEvent('werkproces-assessment', 'confirmed', $assessment));

        $attainments = $this->savedFor('competency-attainment');
        $this->assertCount(1, $attainments);
        $this->assertSame('learner-7', $attainments[0]['learnerId']);
        $this->assertSame('comp-3', $attainments[0]['competencyId']);
        $this->assertSame(['wpa-1'], $attainments[0]['werkprocesAssessmentIds']);
        $this->assertSame('competent', $attainments[0]['proficiencyLevelId']);

    }//end testWerkprocesConfirmedCompetentMapsToHighestLevel()

    /**
     * `nog-niet-competent` maps to the framework's lowest level.
     *
     * @return void
     */
    public function testWerkprocesConfirmedNogNietCompetentMapsToLowestLevel(): void
    {
        $handler = $this->makeHandler();

        $this->seed('bpv-placement', ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a']);
        $this->seed('competency', ['id' => 'comp-3', 'frameworkId' => 'fw-3']);
        $this->seed(
            'competency-framework',
            [
                'id'                => 'fw-3',
                'proficiencyLevels' => [
                    ['levelId' => 'nog-niet-competent', 'label' => 'Nog niet competent', 'order' => 0],
                    ['levelId' => 'competent', 'label' => 'Competent', 'order' => 1],
                ],
            ]
        );

        $assessment = [
            'id'             => 'wpa-2',
            'bpvPlacementId' => 'placement-1',
            'competencyId'   => 'comp-3',
            'beoordeling'    => 'nog-niet-competent',
        ];

        $handler->handle($this->makeTransitionEvent('werkproces-assessment', 'confirmed', $assessment));

        $attainments = $this->savedFor('competency-attainment');
        $this->assertCount(1, $attainments);
        $this->assertSame('nog-niet-competent', $attainments[0]['proficiencyLevelId']);

    }//end testWerkprocesConfirmedNogNietCompetentMapsToLowestLevel()

    /**
     * Re-processing the same GradeEntry publish (e.g. a re-fired republish)
     * is idempotent — no duplicate entries in the evidence-id arrays, and the
     * same CompetencyAttainment row is updated in place, not duplicated.
     *
     * @return void
     */
    public function testReprocessingSameEvidenceIsIdempotent(): void
    {
        $handler = $this->makeHandler();

        $this->seed('submission', ['id' => 'sub-1', 'assignmentId' => 'assign-1']);
        $this->seed('assignment', ['id' => 'assign-1', 'maxPoints' => 100, 'competencyIds' => ['comp-1']]);
        $this->seed('competency', ['id' => 'comp-1', 'frameworkId' => 'fw-1']);
        $this->seed(
            'competency-framework',
            [
                'id'                => 'fw-1',
                'proficiencyLevels' => [
                    ['levelId' => 'proficient', 'label' => 'Proficient', 'order' => 0, 'minPercent' => 0],
                ],
            ]
        );

        $entry = [
            'id'           => 'ge-1',
            'learnerId'    => 'learner-9',
            'sourceKind'   => 'assignment-submission',
            'submissionId' => 'sub-1',
            'value'        => 85.0,
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));
        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));

        $attainments = $this->savedFor('competency-attainment');
        // Two saveObject calls are expected (upsert-in-place twice), but only
        // one distinct CompetencyAttainment row, and no duplicate evidence ids.
        $this->assertCount(2, $attainments);
        $this->assertSame($attainments[0]['id'], $attainments[1]['id']);
        $this->assertSame(['ge-1'], $attainments[1]['gradeEntryIds']);
        $this->assertSame(['sub-1'], $attainments[1]['submissionIds']);

    }//end testReprocessingSameEvidenceIsIdempotent()

    /**
     * A GradeEntry whose source Assignment has competencyIds: [] performs no write.
     *
     * @return void
     */
    public function testUnalignedAssignmentEvidenceIsNoOp(): void
    {
        $handler = $this->makeHandler();

        $this->seed('submission', ['id' => 'sub-1', 'assignmentId' => 'assign-1']);
        $this->seed('assignment', ['id' => 'assign-1', 'maxPoints' => 100, 'competencyIds' => []]);

        $entry = [
            'id'           => 'ge-1',
            'learnerId'    => 'learner-9',
            'sourceKind'   => 'assignment-submission',
            'submissionId' => 'sub-1',
            'value'        => 85.0,
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));

        $this->assertCount(0, $this->savedFor('competency-attainment'));

    }//end testUnalignedAssignmentEvidenceIsNoOp()

    /**
     * A GradeEntry sourceKind outside {assignment-submission, assessment-result} is ignored.
     *
     * @return void
     */
    public function testUnrelatedSourceKindIsNoOp(): void
    {
        $handler = $this->makeHandler();

        $entry = [
            'id'         => 'ge-9',
            'learnerId'  => 'learner-1',
            'sourceKind' => 'manual',
            'value'      => 1.0,
            'tenant_id'  => 'tenant-a',
        ];

        $handler->handle($this->makeTransitionEvent('grade-entry', 'published', $entry));

        $this->assertCount(0, $this->savedFor('competency-attainment'));

    }//end testUnrelatedSourceKindIsNoOp()

    /**
     * A WerkprocesAssessment with competencyId: null performs no write, and confirmation is not blocked.
     *
     * @return void
     */
    public function testUnresolvedWerkprocesCompetencyIsNoOp(): void
    {
        $handler = $this->makeHandler();

        $this->seed('bpv-placement', ['id' => 'placement-1', 'learnerId' => 'learner-7', 'tenant_id' => 'tenant-a']);

        $assessment = [
            'id'             => 'wpa-3',
            'bpvPlacementId' => 'placement-1',
            'competencyId'   => null,
            'beoordeling'    => 'competent',
        ];

        $handler->handle($this->makeTransitionEvent('werkproces-assessment', 'confirmed', $assessment));

        $this->assertCount(0, $this->savedFor('competency-attainment'));

    }//end testUnresolvedWerkprocesCompetencyIsNoOp()

    /**
     * On WerkprocesAssessment creation, a werkprocesCode matching a Competency.code
     * under an sbb-kwalificatiedossier CompetencyFramework resolves competencyId server-side.
     *
     * @return void
     */
    public function testWerkprocesCreationResolvesCompetencyId(): void
    {
        $handler = $this->makeHandler();

        $this->seed(
            'competency-framework',
            ['id' => 'fw-sbb', 'sourceAuthority' => 'sbb-kwalificatiedossier', 'tenant_id' => 'tenant-a']
        );
        $this->seed(
            'competency',
            ['id' => 'comp-wp1', 'frameworkId' => 'fw-sbb', 'code' => 'WP1', 'tenant_id' => 'tenant-a']
        );

        $assessment = [
            'id'              => 'wpa-new',
            'werkprocesCode'  => 'WP1',
            'competencyId'    => null,
            'tenant_id'       => 'tenant-a',
        ];

        $handler->handle($this->makeCreatedEvent('werkproces-assessment', $assessment));

        $saved = $this->savedFor('werkproces-assessment');
        $this->assertCount(1, $saved);
        $this->assertSame('comp-wp1', $saved[0]['competencyId']);

    }//end testWerkprocesCreationResolvesCompetencyId()

    /**
     * A werkprocesCode with no matching Competency leaves competencyId null and writes nothing.
     *
     * @return void
     */
    public function testWerkprocesCreationNoMatchLeavesCompetencyIdNull(): void
    {
        $handler = $this->makeHandler();

        $this->seed(
            'competency-framework',
            ['id' => 'fw-sbb', 'sourceAuthority' => 'sbb-kwalificatiedossier', 'tenant_id' => 'tenant-a']
        );
        $this->seed(
            'competency',
            ['id' => 'comp-other', 'frameworkId' => 'fw-sbb', 'code' => 'WP-OTHER', 'tenant_id' => 'tenant-a']
        );

        $assessment = [
            'id'             => 'wpa-new-2',
            'werkprocesCode' => 'WP-UNKNOWN',
            'competencyId'   => null,
            'tenant_id'      => 'tenant-a',
        ];

        $handler->handle($this->makeCreatedEvent('werkproces-assessment', $assessment));

        $this->assertCount(0, $this->savedFor('werkproces-assessment'));

    }//end testWerkprocesCreationNoMatchLeavesCompetencyIdNull()

    /**
     * Events for other schemas/states are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedTransitionEvents(): void
    {
        $handler = $this->makeHandler();

        $handler->handle($this->makeTransitionEvent('bpv-placement', 'confirmed', ['id' => 'x']));

        $this->assertCount(0, $this->savedObjects);

    }//end testIgnoresUnrelatedTransitionEvents()

    /**
     * ObjectCreatedEvents for other schemas are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedCreatedEvents(): void
    {
        $handler = $this->makeHandler();

        $handler->handle($this->makeCreatedEvent('lesson', ['id' => 'x']));

        $this->assertCount(0, $this->savedObjects);

    }//end testIgnoresUnrelatedCreatedEvents()
}//end class
