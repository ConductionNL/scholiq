<?php

/**
 * Scholiq Exemption Grant Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when an
 * ExemptionCase transitions to `granted`, creates a GradeEntry
 * (`sourceKind: exemption`, `value: null`) copying `curriculumPlanId`/
 * `componentId`/`learnerId` from the case, links `exemptionCaseId`, then
 * drives that GradeEntry through its *existing* `publish` transition — not a
 * raw field write — so the standard audit trail and `gradePublished`
 * notification fire unchanged. Back-links the case's
 * `resultingGradeEntryId`.
 *
 * ADR-031 legitimate exception: cross-object create+transition bridge — an
 * ExemptionCase grant must create a GradeEntry and drive it through the
 * existing publish path. This cannot be expressed as schema metadata
 * declarations. Same cross-schema-side-effect shape as `ExcuseApprovalHandler`.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-granted-exemption-feeds-grading-through-the-existing-publish-path
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges ExemptionCase.granted → GradeEntry (sourceKind: exemption) create + publish.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-granted-exemption-feeds-grading-through-the-existing-publish-path
 */
class ExemptionGrantHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER      = 'scholiq';
    private const EXEMPTION_CASE_SCHEMA = 'exemption-case';
    private const GRADE_ENTRY_SCHEMA    = 'grade-entry';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `publish` transition.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
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
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-granted-exemption-feeds-grading-through-the-existing-publish-path
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::EXEMPTION_CASE_SCHEMA
            || $event->getTo() !== 'granted'
        ) {
            return;
        }

        $this->createAndPublishGradeEntry(event: $event);

    }//end handle()

    /**
     * Create the exemption GradeEntry and drive it through the existing publish transition.
     *
     * @param ObjectTransitionedEvent $event The ExemptionCase-granted transition event.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-granted-exemption-feeds-grading-through-the-existing-publish-path
     */
    private function createAndPublishGradeEntry(ObjectTransitionedEvent $event): void
    {
        $case   = $event->getObject()->jsonSerialize();
        $caseId = $case['id'] ?? ($case['uuid'] ?? '');

        $learnerId        = $case['learnerId'] ?? '';
        $curriculumPlanId = $case['curriculumPlanId'] ?? '';
        $componentId      = $case['componentId'] ?? '';
        $tenantId         = $case['tenant_id'] ?? '';

        if ($learnerId === '' || $curriculumPlanId === '' || $componentId === '') {
            $this->logger->warning(
                '[ExemptionGrantHandler] ExemptionCase {id} missing learnerId/curriculumPlanId/componentId — skipping.',
                ['id' => $caseId]
            );
            return;
        }

        $gradeEntry = [
            'learnerId'        => $learnerId,
            'curriculumPlanId' => $curriculumPlanId,
            'componentId'      => $componentId,
            'sourceKind'       => 'exemption',
            'exemptionCaseId'  => $caseId,
            'value'            => null,
            'gradeScaleId'     => $case['gradeScaleId'] ?? '',
            'grader'           => $case['decidedBy'] ?? 'examboard',
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
            $this->logger->warning(
                '[ExemptionGrantHandler] ExemptionCase {id} — GradeEntry creation returned null; not publishing.',
                ['id' => $caseId]
            );
            return;
        }

        $savedData = $saved;
        if (is_array($saved) === false) {
            $savedData = $saved->jsonSerialize();
        }

        $gradeEntryId = $savedData['id'] ?? ($savedData['uuid'] ?? null);

        if ($gradeEntryId === null) {
            $this->logger->warning(
                '[ExemptionGrantHandler] ExemptionCase {id} — created GradeEntry has no id; not publishing.',
                ['id' => $caseId]
            );
            return;
        }

        // Drive the *existing* publish transition — not a raw field write — so
        // OR's lifecycle engine fires the gradePublished notification and audit
        // trail exactly as it would for any other published entry.
        $this->transitionEngine->transition((string) $gradeEntryId, 'publish');

        // Back-link the case to the GradeEntry it produced.
        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::EXEMPTION_CASE_SCHEMA,
            object: array_merge($case, ['resultingGradeEntryId' => $gradeEntryId])
        );

        $this->logger->info(
            '[ExemptionGrantHandler] ExemptionCase {id} granted — created and published GradeEntry {gradeEntryId}.',
            ['id' => $caseId, 'gradeEntryId' => $gradeEntryId]
        );

    }//end createAndPublishGradeEntry()
}//end class
