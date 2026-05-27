<?php

/**
 * Scholiq Submission Window Guard
 *
 * Lifecycle guard for the Submission schema's `submit` transition. Enforces the
 * Assignment's submission window: after dueAt, submission is blocked (HTTP 422) unless
 * allowLateSubmission is true, in which case the target lifecycle state is redirected
 * to `late` via the transitionContext.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Submission → Assignment) and datetime comparison.
 * Referenced from the Submission schema's x-openregister-lifecycle.transitions.submit.requires
 * in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Submission `submit` transition.
 *
 * Behaviour matrix:
 * - dueAt is null → always allow (open-ended assignment).
 * - now <= dueAt  → allow; `to` stays `submitted`.
 * - now > dueAt + allowLateSubmission=false → block (return false).
 * - now > dueAt + allowLateSubmission=true  → redirect `to` to `late` and allow.
 */
class SubmissionWindowGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for fetching the parent Assignment.
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
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `submit`
     * transition on a Submission object. Looks up the parent Assignment to check
     * whether the submission window is still open.
     *
     * When the deadline has passed and late submission is allowed, this guard mutates
     * $transitionContext['to'] = 'late' so OpenRegister lands the Submission in the
     * `late` state rather than `submitted`.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Submission data array
     *                                               - 'transition' : 'submit'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'submitted' (may be mutated to 'late')
     *
     * @return bool True to allow the transition; false blocks it (HTTP 422 from OR engine).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-9
     */
    public function check(array &$transitionContext): bool
    {
        $object       = $transitionContext['object'] ?? [];
        $assignmentId = $object['assignmentId'] ?? null;

        if ($assignmentId === null) {
            $this->logger->info(
                '[SubmissionWindowGuard] Submission has no assignmentId; blocking submit.'
            );
            return false;
        }

        $assignments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'Assignment',
                'filters'  => ['uuid' => $assignmentId],
                'limit'    => 1,
            ]
        );

        if (empty($assignments) === true) {
            $this->logger->info(
                '[SubmissionWindowGuard] Assignment {id} not found; blocking submit.',
                ['id' => $assignmentId]
            );
            return false;
        }

        $assignment = $assignments[0];
        $dueAtRaw   = $assignment['dueAt'] ?? null;

        if ($dueAtRaw === null) {
            // Open-ended assignment — no deadline to enforce.
            return true;
        }

        // #202: use explicit UTC timezone for both timestamps so DST transitions on the
        // server do not cause inconsistent deadline comparisons. Stored dueAt values must
        // include a timezone offset (ISO 8601); if they don't we default to UTC.
        // #219: wrap DateTimeImmutable construction in a try/catch to surface malformed
        // dueAt values as a guard rejection rather than an unhandled 500.
        try {
            $dueAt = new DateTimeImmutable($dueAtRaw, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            $this->logger->warning(
                '[SubmissionWindowGuard] Assignment {id} has malformed dueAt value; blocking submit.',
                ['id' => $assignmentId]
            );
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($now <= $dueAt) {
            // Within the window — normal submit.
            return true;
        }

        $allowLate = (bool) ($assignment['allowLateSubmission'] ?? false);

        if ($allowLate === false) {
            $this->logger->info(
                '[SubmissionWindowGuard] Submission after dueAt and late submission not allowed; blocking.'
            );
            return false;
        }

        // Past deadline but late submission is allowed → redirect lifecycle target to `late`.
        $transitionContext['to'] = 'late';
        $this->logger->info(
            '[SubmissionWindowGuard] Submission after dueAt; redirecting lifecycle to `late`.'
        );

        return true;
    }//end check()
}//end class
