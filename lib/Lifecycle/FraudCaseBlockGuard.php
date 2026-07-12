<?php

/**
 * Scholiq Fraud Case Block Guard
 *
 * Lifecycle guard for the GradeEntry schema's `publish` and `republish`
 * transitions. When a GradeEntry carries a `fraudCaseId`, blocks publication
 * while the linked FraudCase is open (`reported`, `hearing-scheduled`,
 * `heard`), or permanently once the case is `decided` with
 * `verdict: fraud-proven` — the only forward path for that GradeEntry is then
 * `invalidate`, never a retried `publish`. Allows the transition once the
 * case is `decided` with `verdict: unfounded`, or `dismissed`. A GradeEntry
 * with no `fraudCaseId` is allowed unconditionally.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards":
 * a cross-schema read guard, mirroring `AttendanceFlagReportGuard`'s shape
 * ("when a [x]Id is set... verifies the linked [Y] has reached [state]. When
 * no [x] is linked, allows the transition unconditionally").
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-permanently-fraud-proven-link-blocks-publish-even-after-decision
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the GradeEntry `publish` and `republish` lifecycle transitions.
 *
 * When `fraudCaseId` is set on the transitioning GradeEntry, fetches the
 * linked FraudCase and blocks unless it is `decided` with
 * `verdict: unfounded` or `dismissed`. When `fraudCaseId` is unset, allows
 * unconditionally.
 *
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
 */
class FraudCaseBlockGuard
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const FRAUD_CASE_SCHEMA = 'fraud-case';

    /**
     * FraudCase lifecycle states that keep the linked GradeEntry blocked from publish.
     *
     * `decided` is handled separately (blocked only when verdict=fraud-proven).
     *
     * @var string[]
     */
    private const OPEN_STATES = ['reported', 'hearing-scheduled', 'heard'];

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
     * Allow the `publish`/`republish` transition unless a linked FraudCase blocks it.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the GradeEntry data array
     *                                               - 'transition' : 'publish' or 'republish'
     *
     * @return bool True if the transition is allowed; false blocks it (HTTP 422).
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-linked-fraudcase-blocks-publish-and-republish
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-a-permanently-fraud-proven-link-blocks-publish-even-after-decision
     */
    public function check(array &$transitionContext): bool
    {
        $entry       = $transitionContext['object'] ?? [];
        $entryId     = $entry['id'] ?? ($entry['uuid'] ?? '');
        $fraudCaseId = $entry['fraudCaseId'] ?? null;

        if ($fraudCaseId === null || $fraudCaseId === '') {
            return true;
        }

        $fraudCase = $this->fetchFraudCase(fraudCaseId: (string) $fraudCaseId);

        if ($fraudCase === null) {
            $this->logger->warning(
                '[FraudCaseBlockGuard] GradeEntry {id} links FraudCase {caseId} which was not found — denying publish (fail closed).',
                ['id' => $entryId, 'caseId' => $fraudCaseId]
            );
            return false;
        }

        $lifecycle = $fraudCase['lifecycle'] ?? '';
        $verdict   = $fraudCase['verdict'] ?? '';

        if (in_array($lifecycle, self::OPEN_STATES, true) === true) {
            $this->logger->info(
                '[FraudCaseBlockGuard] GradeEntry {id} blocked — linked FraudCase {caseId} is still open ({state}).',
                ['id' => $entryId, 'caseId' => $fraudCaseId, 'state' => $lifecycle]
            );
            return false;
        }

        if ($lifecycle === 'decided' && $verdict === 'fraud-proven') {
            $this->logger->info(
                '[FraudCaseBlockGuard] GradeEntry {id} permanently blocked — linked FraudCase {caseId} decided fraud-proven; the only forward path is invalidate.',
                ['id' => $entryId, 'caseId' => $fraudCaseId]
            );
            return false;
        }

        // decided/unfounded or dismissed — publication may proceed.
        return true;

    }//end check()

    /**
     * Fetch the linked FraudCase by id.
     *
     * @param string $fraudCaseId UUID of the FraudCase.
     *
     * @return array<string,mixed>|null The FraudCase data array, or null if not found.
     */
    private function fetchFraudCase(string $fraudCaseId): ?array
    {
        $obj = $this->objectService->find(
            id: $fraudCaseId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::FRAUD_CASE_SCHEMA
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return $obj->jsonSerialize();

    }//end fetchFraudCase()
}//end class
