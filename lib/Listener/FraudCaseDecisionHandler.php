<?php

/**
 * Scholiq Fraud Case Decision Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent and, when a FraudCase
 * transitions to `decided` with `verdict: fraud-proven`, drives its linked,
 * still-`concept` GradeEntry (via `contestedGradeEntryId`) through the new
 * `invalidate` transition (`concept → invalidated`) — guarded by
 * `FraudCaseInvalidationGuard`. If the GradeEntry has already reached
 * `published` (should be structurally impossible while `FraudCaseBlockGuard`
 * is wired correctly, but defensive coding matters here), the handler logs a
 * warning and takes no action rather than mutating a published, notified
 * grade out from under a learner — that scenario needs a manual, out-of-band
 * correction, deliberately not automated by this change.
 *
 * ADR-031 legitimate exception: cross-object transition bridge — a FraudCase
 * decision must drive a GradeEntry transition. This cannot be expressed as
 * schema metadata declarations. Same cross-schema-side-effect shape as
 * `ExcuseApprovalHandler` / `XapiCompletionHandler`.
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-fraud-proven-decision-invalidates-a-still-concept-contested-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges FraudCase.decided (verdict: fraud-proven) → GradeEntry.invalidate.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-fraud-proven-decision-invalidates-a-still-concept-contested-gradeentry
 */
class FraudCaseDecisionHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const FRAUD_CASE_SCHEMA  = 'fraud-case';
    private const GRADE_ENTRY_SCHEMA = 'grade-entry';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `invalidate` transition.
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
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-fraud-proven-decision-invalidates-a-still-concept-contested-gradeentry
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::FRAUD_CASE_SCHEMA
            || $event->getTo() !== 'decided'
        ) {
            return;
        }

        $this->invalidateContestedGradeEntry(event: $event);

    }//end handle()

    /**
     * Invalidate the linked, still-concept GradeEntry when the verdict is fraud-proven.
     *
     * @param ObjectTransitionedEvent $event The FraudCase-decided transition event.
     *
     * @return void
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-fraud-proven-decision-invalidates-a-still-concept-contested-gradeentry
     */
    private function invalidateContestedGradeEntry(ObjectTransitionedEvent $event): void
    {
        $case   = $event->getObject()->jsonSerialize();
        $caseId = $case['id'] ?? ($case['uuid'] ?? '');

        $verdict              = $case['verdict'] ?? '';
        $contestedGradeEntryId = $case['contestedGradeEntryId'] ?? null;

        if ($verdict !== 'fraud-proven') {
            return;
        }

        if ($contestedGradeEntryId === null || $contestedGradeEntryId === '') {
            $this->logger->info(
                '[FraudCaseDecisionHandler] FraudCase {id} decided fraud-proven with no contestedGradeEntryId — nothing to invalidate.',
                ['id' => $caseId]
            );
            return;
        }

        $entry = $this->fetchGradeEntry(gradeEntryId: (string) $contestedGradeEntryId);

        if ($entry === null) {
            $this->logger->warning(
                '[FraudCaseDecisionHandler] FraudCase {id} contestedGradeEntryId {entryId} not found — skipping.',
                ['id' => $caseId, 'entryId' => $contestedGradeEntryId]
            );
            return;
        }

        $lifecycle = $entry['lifecycle'] ?? '';

        if ($lifecycle !== 'concept') {
            // Defensive: should be structurally impossible while FraudCaseBlockGuard
            // is wired correctly on publish/republish. Never mutate a published,
            // already-notified grade out from under a learner — that needs a manual,
            // out-of-band correction (design.md §4/§8), not automation here.
            $this->logger->warning(
                '[FraudCaseDecisionHandler] FraudCase {id} contestedGradeEntryId {entryId} is not concept ({lifecycle}) — refusing to auto-invalidate.',
                ['id' => $caseId, 'entryId' => $contestedGradeEntryId, 'lifecycle' => $lifecycle]
            );
            return;
        }

        $this->transitionEngine->transition((string) $contestedGradeEntryId, 'invalidate');

        $this->logger->info(
            '[FraudCaseDecisionHandler] FraudCase {id} decided fraud-proven — invalidated GradeEntry {entryId}.',
            ['id' => $caseId, 'entryId' => $contestedGradeEntryId]
        );

    }//end invalidateContestedGradeEntry()

    /**
     * Fetch the contested GradeEntry by id.
     *
     * @param string $gradeEntryId UUID of the GradeEntry.
     *
     * @return array<string,mixed>|null The GradeEntry data array, or null if not found.
     */
    private function fetchGradeEntry(string $gradeEntryId): ?array
    {
        $obj = $this->objectService->find(
            id: $gradeEntryId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::GRADE_ENTRY_SCHEMA
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return $obj->jsonSerialize();

    }//end fetchGradeEntry()
}//end class
