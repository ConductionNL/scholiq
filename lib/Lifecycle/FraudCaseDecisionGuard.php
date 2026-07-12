<?php

/**
 * Scholiq Fraud Case Decision Guard
 *
 * Lifecycle guard for the FraudCase schema's `decide` transition
 * (`heard → decided`). Blocks the transition unless `verdict` and
 * `decisionRationale` are set; when `verdict: fraud-proven`, additionally
 * requires a capped sanction (`sanctionType`, `sanctionDurationMonths` ≤ 12,
 * `sanctionScope`) — "up to one-year exclusion" per Universiteit Leiden's
 * fraud process (source 6597) and story 10070. On success, stamps
 * `decidedAt` (now) and `appealDeadline` (`decidedAt` + 42 days, the CBE
 * 6-week appeal window named in journey 1745) onto the transition payload.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards":
 * conditional data-completeness preconditions plus a computed-field stamp
 * that cannot be expressed declaratively — this register's
 * `x-openregister-calculations` DSL has confirmed precedent for `today()`
 * comparisons but NO date-arithmetic primitive at HEAD (grepping the whole
 * register for `date_add`/`dateAdd`/`addDays` returns zero hits), so
 * `appealDeadline` is computed here via `DateTimeImmutable::modify('+42
 * days')`, exactly the pattern `ExternalTrainingVerificationGuard` already
 * uses to stamp `verifiedBy`/`verifiedAt`.
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
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Guards the FraudCase `decide` lifecycle transition.
 *
 * Passes only when `verdict` and `decisionRationale` are set; when
 * `verdict === 'fraud-proven'`, also requires `sanctionType`,
 * `sanctionDurationMonths` (integer, at most 12), and `sanctionScope`. On
 * success, stamps `decidedAt` and `appealDeadline` onto the payload.
 *
 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
 */
class FraudCaseDecisionGuard
{

    /**
     * The verdict value that requires an accompanying sanction.
     */
    private const FRAUD_PROVEN = 'fraud-proven';

    /**
     * Maximum allowed sanction duration in months ("up to one-year exclusion").
     */
    private const MAX_SANCTION_MONTHS = 12;

    /**
     * Days between decidedAt and the stamped appealDeadline (the CBE 6-week window).
     */
    private const APPEAL_WINDOW_DAYS = 42;

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
     * Assert the decision preconditions and stamp decidedAt/appealDeadline.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `decide` transition on a FraudCase object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'     : the case
     *                                               property array
     *                                               - 'transition' : 'decide'
     *                                               - 'payload'    : mutable
     *                                               array; decidedAt/
     *                                               appealDeadline are written
     *                                               here
     *
     * @return bool True when the preconditions are satisfied (and the stamp
     *              has been written); false blocks the transition (HTTP 422).
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-a-decided-fraudcase-stamps-a-42-day-appeal-deadline
     */
    public function check(array &$transitionContext): bool
    {
        $object            = $transitionContext['object'] ?? [];
        $caseId            = $object['id'] ?? ($object['uuid'] ?? '');
        $verdict           = $object['verdict'] ?? '';
        $decisionRationale = $object['decisionRationale'] ?? '';

        if (is_string($verdict) === false || trim($verdict) === ''
            || is_string($decisionRationale) === false || trim($decisionRationale) === ''
        ) {
            $this->logger->info(
                '[FraudCaseDecisionGuard] FraudCase {id} missing verdict and/or decisionRationale — denying decide.',
                ['id' => $caseId]
            );
            return false;
        }

        if ($verdict === self::FRAUD_PROVEN && $this->hasValidSanction(object: $object) === false) {
            $this->logger->info(
                '[FraudCaseDecisionGuard] FraudCase {id} verdict=fraud-proven but sanction incomplete/invalid — denying decide.',
                ['id' => $caseId]
            );
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $transitionContext['payload']['decidedAt']     = $now->format(DateTimeInterface::ATOM);
        $transitionContext['payload']['appealDeadline'] = $now->modify('+'.self::APPEAL_WINDOW_DAYS.' days')->format(DateTimeInterface::ATOM);

        return true;

    }//end check()

    /**
     * Whether the object carries a complete, valid sanction (required when fraud-proven).
     *
     * @param array<string,mixed> $object The FraudCase property array.
     *
     * @return bool True when sanctionType, sanctionScope, and a sanctionDurationMonths
     *              of at most 12 are all set.
     *
     * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#requirement-fraudcase-decisions-require-a-verdict-rationale-and-when-fraud-is-proven-a-capped-sanction
     */
    private function hasValidSanction(array $object): bool
    {
        $sanctionType     = $object['sanctionType'] ?? '';
        $sanctionScope    = $object['sanctionScope'] ?? '';
        $sanctionDuration = $object['sanctionDurationMonths'] ?? null;

        if (is_string($sanctionType) === false || trim($sanctionType) === '') {
            return false;
        }

        if (is_string($sanctionScope) === false || trim($sanctionScope) === '') {
            return false;
        }

        if (is_numeric($sanctionDuration) === false) {
            return false;
        }

        $months = (int) $sanctionDuration;

        if ($months < 1 || $months > self::MAX_SANCTION_MONTHS) {
            return false;
        }

        return true;

    }//end hasValidSanction()
}//end class
