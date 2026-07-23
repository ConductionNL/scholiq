<?php

/**
 * Scholiq Fraud Case Invalidation Guard
 *
 * Lifecycle guard for the GradeEntry schema's `invalidate` transition
 * (`concept → invalidated`). Allows the transition only when the GradeEntry's
 * `fraudCaseId` refers to a FraudCase that is `decided` with
 * `verdict: fraud-proven`. This guard's sole caller in practice is
 * `FraudCaseDecisionHandler` — a user cannot invoke `invalidate` on their own
 * initiative in any scenario where the guard would pass, because the guard's
 * precondition is exactly the state the handler reacts to.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards":
 * a cross-schema read guard, the mirror image of `FraudCaseBlockGuard`.
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
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the GradeEntry `invalidate` lifecycle transition.
 *
 * Passes only when `fraudCaseId` is set and the linked FraudCase is
 * `decided` with `verdict: fraud-proven`. Fails closed otherwise (no
 * `fraudCaseId`, case not found, case not decided, or a non-fraud-proven
 * verdict).
 *
 * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
 */
class FraudCaseInvalidationGuard
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const FRAUD_CASE_SCHEMA = 'fraud-case';

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
     * Allow the `invalidate` transition only when the linked FraudCase is decided fraud-proven.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the GradeEntry data array
     *                                               - 'transition' : 'invalidate'
     *
     * @return bool True if the transition is allowed; false blocks it (HTTP 422).
     *
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#requirement-gradeentry-invalidate-is-a-guarded-terminal-transition
     */
    public function check(array &$transitionContext): bool
    {
        $entry       = $transitionContext['object'] ?? [];
        $entryId     = $entry['id'] ?? ($entry['uuid'] ?? '');
        $fraudCaseId = $entry['fraudCaseId'] ?? null;

        if ($fraudCaseId === null || $fraudCaseId === '') {
            $this->logger->info(
                '[FraudCaseInvalidationGuard] GradeEntry {id} has no fraudCaseId — denying invalidate.',
                ['id' => $entryId]
            );
            return false;
        }

        $fraudCase = $this->fetchFraudCase(fraudCaseId: (string) $fraudCaseId);

        if ($fraudCase === null) {
            $this->logger->warning(
                '[FraudCaseInvalidationGuard] GradeEntry {id} links FraudCase {caseId} which was not found — denying invalidate (fail closed).',
                ['id' => $entryId, 'caseId' => $fraudCaseId]
            );
            return false;
        }

        $lifecycle = $fraudCase['lifecycle'] ?? '';
        $verdict   = $fraudCase['verdict'] ?? '';

        if ($lifecycle !== 'decided' || $verdict !== 'fraud-proven') {
            $this->logger->info(
                '[FraudCaseInvalidationGuard] GradeEntry {id} linked FraudCase {caseId} is not decided fraud-proven ({state}/{verdict}) — denying invalidate.',
                ['id' => $entryId, 'caseId' => $fraudCaseId, 'state' => $lifecycle, 'verdict' => $verdict]
            );
            return false;
        }

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
