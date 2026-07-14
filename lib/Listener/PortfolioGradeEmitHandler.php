<?php

/**
 * Scholiq Portfolio Grade Emit Handler
 *
 * IEventListener for Portfolio lifecycle → `graded` (the OR
 * ObjectTransitionedEvent with register=scholiq, schema=portfolio,
 * to=graded). Bridges a graded course-bound portfolio into the `grading`
 * spec's pipeline by emitting exactly one `concept` GradeEntry
 * (sourceKind: portfolio) from the teacher-entered Portfolio.gradeValue, then
 * back-links Portfolio.gradeEntryId — matching
 * GradeRollupHandler::handleAssessmentResultGraded()'s existing cross-schema
 * write-bridge shape exactly. Portfolio computes no final grade itself; the
 * emitted GradeEntry is consumed by grading's existing roll-up
 * (GradeRollupHandler::handleGradeEntryPublished()) once a teacher publishes
 * it via the GradebookView.
 *
 * Idempotent: skips emission when Portfolio.gradeEntryId is already set, so a
 * re-processed/redelivered `graded` transition never creates a duplicate
 * GradeEntry.
 *
 * ADR-031 legitimate exception: cross-schema event-to-object-write bridge
 * (Portfolio → CurriculumPlan lookup → GradeEntry write) that cannot be
 * expressed as a schema declaration.
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
 * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-a-graded-course-bound-portfolio-flows-through-the-existing-gradeentry-pipeline-not-a-parallel-one
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
 * Bridges Portfolio.graded → concept GradeEntry creation.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */
class PortfolioGradeEmitHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const PORTFOLIO_SCHEMA   = 'portfolio';
    private const CURRICULUM_SCHEMA  = 'curriculum-plan';
    private const GRADE_ENTRY_SCHEMA = 'grade-entry';

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
     * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::PORTFOLIO_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'graded') {
            return;
        }

        $this->emitGradeEntry(event: $event);

    }//end handle()

    /**
     * Emit the concept GradeEntry bridged from a graded course-bound Portfolio.
     *
     * @param ObjectTransitionedEvent $event The graded transition event.
     *
     * @return void
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#scenario-transitioning-a-course-bound-portfolio-to-graded-emits-a-concept-gradeentry
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#scenario-re-triggering-the-graded-transition-does-not-create-a-duplicate-gradeentry
     */
    private function emitGradeEntry(ObjectTransitionedEvent $event): void
    {
        $portfolio    = $event->getObject()->jsonSerialize();
        $portfolioId  = $portfolio['id'] ?? ($portfolio['uuid'] ?? '');
        $existingId   = $portfolio['gradeEntryId'] ?? null;
        $learnerId    = $portfolio['learnerId'] ?? '';
        $curriculumId = $portfolio['curriculumPlanId'] ?? '';
        $componentId  = $portfolio['curriculumPlanComponentId'] ?? '';
        $gradeValue   = $portfolio['gradeValue'] ?? null;
        $tenantId     = $portfolio['tenant_id'] ?? '';

        // Idempotency: never create a duplicate when gradeEntryId is already set.
        if ($existingId !== null && $existingId !== '') {
            $this->logger->info(
                '[PortfolioGradeEmitHandler] Portfolio {id} already has gradeEntryId {geid}; skipping.',
                ['id' => $portfolioId, 'geid' => $existingId]
            );
            return;
        }

        if ($learnerId === '' || $curriculumId === '' || $componentId === '' || $gradeValue === null) {
            $this->logger->warning(
                '[PortfolioGradeEmitHandler] Graded Portfolio {id} missing learnerId/curriculumPlanId/'
                .'curriculumPlanComponentId/gradeValue — cannot emit GradeEntry.',
                ['id' => $portfolioId]
            );
            return;
        }

        $curriculumPlan = $this->loadObject(schema: self::CURRICULUM_SCHEMA, id: $curriculumId);
        $gradeScaleId   = $curriculumPlan['gradeScaleId'] ?? '';

        $gradeEntry = [
            'learnerId'        => $learnerId,
            'curriculumPlanId' => $curriculumId,
            'componentId'      => $componentId,
            'sourceKind'       => 'portfolio',
            'portfolioId'      => $portfolioId,
            'value'            => (float) $gradeValue,
            'gradeScaleId'     => $gradeScaleId,
            'grader'           => $event->getUserId() ?? '',
            'gradedAt'         => (new DateTimeImmutable())->format(\DATE_ATOM),
            'tenant_id'        => $tenantId,
            'lifecycle'        => 'concept',
        ];

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::GRADE_ENTRY_SCHEMA,
            object: $gradeEntry
        );

        if ($saved === null) {
            return;
        }

        $savedData = $saved;
        if (is_array($saved) === false) {
            $savedData = $saved->jsonSerialize();
        }

        $gradeEntryId = $savedData['id'] ?? null;

        if ($gradeEntryId === null) {
            return;
        }

        // Back-link the GradeEntry to the Portfolio.
        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::PORTFOLIO_SCHEMA,
            object: array_merge($portfolio, ['gradeEntryId' => $gradeEntryId])
        );

        $this->logger->info(
            '[PortfolioGradeEmitHandler] Portfolio {id} graded → GradeEntry {geid} created for learner {learner}, '
            .'component {component}.',
            [
                'id'        => $portfolioId,
                'geid'      => $gradeEntryId,
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
     * @spec openspec/changes/eportfolio/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
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
}//end class
