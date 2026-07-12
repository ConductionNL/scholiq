<?php

/**
 * Scholiq Fraud Case Hearing Guard
 *
 * Lifecycle guard for the FraudCase schema's `scheduleHearing` transition
 * (`reported → hearing-scheduled`). Blocks the transition unless a
 * `hearingDate` has been supplied — a scheduled hearing with no date is not
 * meaningfully "scheduled".
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards": a
 * single data-completeness precondition on the transition payload, mirroring
 * `AttendanceFlagReportGuard`'s "read the transitioning object" shape but
 * without a cross-schema lookup.
 *
 * Per ADR-008 OR emits the audit-trail entry automatically when the
 * transition completes — this guard records nothing itself.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the FraudCase `scheduleHearing` lifecycle transition.
 *
 * Passes only when `hearingDate` is a non-empty string on the transitioning
 * object. Fails closed otherwise.
 *
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
 */
class FraudCaseHearingGuard
{

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger for guard rejections.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the hearingDate precondition.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `scheduleHearing` transition on a FraudCase object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'     : the case
     *                                               property array
     *                                               - 'transition' : 'scheduleHearing'
     *
     * @return bool True when hearingDate is set; false blocks the transition
     *              (HTTP 422).
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-persist-exam-board-domain-objects-in-openregister
     */
    public function check(array &$transitionContext): bool
    {
        $object      = $transitionContext['object'] ?? [];
        $caseId      = $object['id'] ?? ($object['uuid'] ?? '');
        $hearingDate = $object['hearingDate'] ?? '';

        if (is_string($hearingDate) === false || trim($hearingDate) === '') {
            $this->logger->info(
                '[FraudCaseHearingGuard] FraudCase {id} missing hearingDate — denying scheduleHearing.',
                ['id' => $caseId]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
