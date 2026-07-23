<?php

/**
 * Scholiq Werkproces Grade Emit Handler
 *
 * IEventListener for WerkprocesAssessment lifecycle → `confirmed` (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=werkproces-assessment,
 * to=confirmed). Bridges a confirmed workplace assessment into the `grading`
 * spec's pipeline by emitting (create case) or updating (existing-entry case)
 * exactly one GradeEntry for the assessment's curriculumPlanId/componentId —
 * matching GradeRollupHandler::handleAssessmentResultGraded()'s existing
 * cross-schema write-bridge shape. WerkprocesAssessment computes no final
 * grade itself; the emitted GradeEntry is consumed by grading's existing
 * roll-up (GradeRollupHandler::handleGradeEntryPublished()) once a teacher
 * publishes it.
 *
 * Value mapping: GradeEntry.value is a numeric mark on a GradeScale (no
 * schema change to GradeEntry ships with this change, per design.md — its
 * sourceKind enum and value type are left untouched). WerkprocesAssessment's
 * `beoordeling` is a binary competency outcome, so this handler maps it onto
 * a 0/1 pass-scale: `competent` → 1.0, `nog-niet-competent` → 0.0. sourceKind
 * is stamped `manual` (the closest existing enum value — a human-entered
 * workplace assessment, not an auto-scored assignment/assessment result).
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (WerkprocesAssessment → BpvPlacement/CurriculumPlan lookups → GradeEntry
 * write) that cannot be expressed as a schema declaration.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges WerkprocesAssessment.confirmed → GradeEntry create/update.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */
class WerkprocesGradeEmitHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const ASSESSMENT_SCHEMA  = 'werkproces-assessment';
    private const PLACEMENT_SCHEMA   = 'bpv-placement';
    private const CURRICULUM_SCHEMA  = 'curriculum-plan';
    private const GRADE_ENTRY_SCHEMA = 'grade-entry';

    /**
     * Numeric mapping from `beoordeling` to a 0/1 pass-scale GradeEntry.value.
     *
     * @var array<string,float>
     */
    private const BEOORDELING_VALUE = [
        'competent'          => 1.0,
        'nog-niet-competent' => 0.0,
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::ASSESSMENT_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'confirmed') {
            return;
        }

        $this->emitGradeEntry(event: $event);

    }//end handle()

    /**
     * Emit or update the GradeEntry bridged from a confirmed WerkprocesAssessment.
     *
     * @param ObjectTransitionedEvent $event The confirmed transition event.
     *
     * @return void
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function emitGradeEntry(ObjectTransitionedEvent $event): void
    {
        $assessment   = $event->getObject()->jsonSerialize();
        $bpvPlacement = $assessment['bpvPlacementId'] ?? '';
        $curriculumId = $assessment['curriculumPlanId'] ?? '';
        $componentId  = $assessment['componentId'] ?? '';
        $beoordeling  = $assessment['beoordeling'] ?? '';

        if ($bpvPlacement === '' || $curriculumId === '' || $componentId === '') {
            $this->logger->warning(
                '[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} missing bpvPlacementId/'
                .'curriculumPlanId/componentId — cannot emit GradeEntry.',
                ['id' => $assessment['id'] ?? ($assessment['uuid'] ?? '')]
            );
            return;
        }

        if (array_key_exists($beoordeling, self::BEOORDELING_VALUE) === false) {
            $this->logger->warning(
                '[WerkprocesGradeEmitHandler] Confirmed WerkprocesAssessment {id} has unrecognised beoordeling '
                .'"{b}" — cannot map to a GradeEntry value.',
                ['id' => $assessment['id'] ?? ($assessment['uuid'] ?? ''), 'b' => $beoordeling]
            );
            return;
        }

        $placement = $this->loadObject(schema: self::PLACEMENT_SCHEMA, id: $bpvPlacement);
        if ($placement === null) {
            $this->logger->warning(
                '[WerkprocesGradeEmitHandler] BpvPlacement {id} referenced by WerkprocesAssessment not found.',
                ['id' => $bpvPlacement]
            );
            return;
        }

        $learnerId = $placement['learnerId'] ?? '';
        $tenantId  = $placement['tenant_id'] ?? '';

        if ($learnerId === '') {
            $this->logger->warning(
                '[WerkprocesGradeEmitHandler] BpvPlacement {id} has no learnerId — cannot emit GradeEntry.',
                ['id' => $bpvPlacement]
            );
            return;
        }

        $curriculumPlan = $this->loadObject(schema: self::CURRICULUM_SCHEMA, id: $curriculumId);
        $gradeScaleId   = $curriculumPlan['gradeScaleId'] ?? '';

        $existing = $this->findExistingGradeEntry(
            learnerId: $learnerId,
            curriculumPlanId: $curriculumId,
            componentId: $componentId,
            tenantId: $tenantId
        );

        $data = array_merge(
            $existing ?? [],
            [
                'learnerId'        => $learnerId,
                'curriculumPlanId' => $curriculumId,
                'componentId'      => $componentId,
                'sourceKind'       => 'manual',
                'value'            => self::BEOORDELING_VALUE[$beoordeling],
                'gradeScaleId'     => $gradeScaleId,
                'grader'           => 'praktijkopleider',
                'gradedAt'         => (new DateTimeImmutable())->format(\DATE_ATOM),
                'tenant_id'        => $tenantId,
                'lifecycle'        => $existing['lifecycle'] ?? 'concept',
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::GRADE_ENTRY_SCHEMA,
            object: $data
        );

        $kind = 'created';
        if ($existing !== null) {
            $kind = 'updated';
        }

        $this->logger->info(
            '[WerkprocesGradeEmitHandler] WerkprocesAssessment {aid} confirmed → GradeEntry {kind} for learner '
            .'{learner}, component {component}.',
            [
                'aid'       => $assessment['id'] ?? ($assessment['uuid'] ?? ''),
                'kind'      => $kind,
                'learner'   => $learnerId,
                'component' => $componentId,
            ]
        );

    }//end emitGradeEntry()

    /**
     * Load a single OpenRegister object by id.
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @return array<string,mixed>|null The object data, or null when not found.
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function loadObject(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => ['id' => $id],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        if (is_array($results[0]) === true) {
            return $results[0];
        }

        return $results[0]->jsonSerialize();

    }//end loadObject()

    /**
     * Find an existing GradeEntry for the same learner/plan/component pair, if any.
     *
     * @param string $learnerId        NC user id.
     * @param string $curriculumPlanId Plan UUID.
     * @param string $componentId      Component identifier.
     * @param string $tenantId         Tenant UUID scope filter.
     *
     * @return array<string,mixed>|null The existing GradeEntry data, or null when none exists.
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function findExistingGradeEntry(
        string $learnerId,
        string $curriculumPlanId,
        string $componentId,
        string $tenantId,
    ): ?array {
        $filters = [
            'learnerId'        => $learnerId,
            'curriculumPlanId' => $curriculumPlanId,
            'componentId'      => $componentId,
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::GRADE_ENTRY_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        if (is_array($results[0]) === true) {
            return $results[0];
        }

        return $results[0]->jsonSerialize();

    }//end findExistingGradeEntry()
}//end class
