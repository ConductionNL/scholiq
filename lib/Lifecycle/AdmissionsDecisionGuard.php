<?php

/**
 * Scholiq Admissions Decision Guard
 *
 * Lifecycle guard for the Application schema's completeIntake transition
 * (intake-scheduled -> intake-completed) and its three decision transitions
 * (intake-completed -> placed | waitlisted | rejected) plus the promote
 * transition (waitlisted -> placed) driven by AdmissionsWaitlistPromoter.
 * One PHP class, branching on the referenced AdmissionsRound.kind and the
 * transition's target state — mirrors BsaDecisionGuard's single-class
 * multi-condition shape (design.md "The two guard branches in one class").
 *
 * Branches:
 * - Mandatory-intake gate (any kind, completeIntake transition): blocks
 *   reaching intake-completed while AdmissionsRound.mandatoryIntake is true
 *   and Application.intakeCompleted is still false.
 * - MBO toelatingsrecht (kind: mbo-toelatingsrecht, -> rejected only): blocks
 *   rejection when the applicant applied by applicationDeadline, reached
 *   intake-completed, and studiekeuzeadviesGiven is true — unless
 *   decisionReason names a specific unmet prerequisite.
 * - VO schooladvies-adjustment (kind: vo-schooladvies-doorstroomtoets, any
 *   decision -> placed|waitlisted|rejected): blocks the decision when
 *   doorstroomtoetsLevel outranks schooladviesLevel on the shared ordinal and
 *   schooladviesAdjustedLevel was not raised to match — unless
 *   adjustmentMotivation is non-empty or both levels are pro/vmbo-bb.
 * - Capacity (any kind, -> placed only): blocks placement once the round's
 *   placed/converted Application count reaches AdmissionsRound.capacity —
 *   the transition must target waitlisted instead. Counted live at decision
 *   time (no materialised per-round counter — mirrors BsaTrajectory's
 *   rejected "materialised count" alternative in design.md, avoiding a race
 *   between concurrent placed transitions).
 *
 * Legitimate PHP per ADR-031 "Lifecycle guards" — every branch is a
 * cross-object read (the referenced AdmissionsRound, or a live count of
 * sibling Applications) no single-schema JSON-logic expression can perform.
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
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-a-vo-schooladvies-must-be-adjusted-upward-when-the-doorstroomtoets-scores-higher-unless-motivated
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Application completeIntake / place / waitlist / reject / promote transitions.
 *
 * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
 */
class AdmissionsDecisionGuard
{

    private const SCHOLIQ_REGISTER        = 'scholiq';
    private const ADMISSIONS_ROUND_SCHEMA = 'admissions-round';
    private const APPLICATION_SCHEMA      = 'application';

    /**
     * The shared low->high schooladvies/doorstroomtoets ordinal (design.md
     * "Ordinal levels, not free text").
     *
     * @var string[]
     */
    private const ORDINAL_LEVELS = [
        'pro',
        'vmbo-bb',
        'vmbo-kb',
        'vmbo-gt',
        'havo',
        'vwo',
    ];

    /**
     * Decision transitions the schooladvies/capacity branches apply to.
     *
     * @var string[]
     */
    private const DECISION_TARGETS = ['placed', 'waitlisted', 'rejected'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query service.
     * @param LoggerInterface $logger        PSR logger for guard rejections.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the pre-conditions for the target transition.
     *
     * Called by OpenRegister's lifecycle engine before executing a guarded
     * Application transition.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine:
     *                                               - 'object' : Application
     *                                               property array
     *                                               - 'to'     : target
     *                                               lifecycle state
     *
     * @return bool True when pre-conditions are satisfied; false blocks the transition.
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
     */
    public function check(array &$transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $to     = (string) ($transitionContext['to'] ?? '');

        $roundId = (string) ($object['admissionsRoundId'] ?? '');
        if ($roundId === '') {
            $this->logger->warning('[AdmissionsDecisionGuard] Application has no admissionsRoundId; blocking transition.');
            return false;
        }

        $round = $this->fetchRound(roundId: $roundId);
        if ($round === null) {
            $this->logger->warning(
                '[AdmissionsDecisionGuard] AdmissionsRound {id} not found; blocking transition.',
                ['id' => $roundId]
            );
            return false;
        }

        $kind = (string) ($round['kind'] ?? 'generic');

        if ($to === 'intake-completed') {
            return $this->checkMandatoryIntake(round: $round, object: $object);
        }

        if (in_array($to, self::DECISION_TARGETS, true) === false) {
            // Not a transition this guard governs (e.g. submit, scheduleIntake, withdraw).
            return true;
        }

        if ($kind === 'vo-schooladvies-doorstroomtoets' && $this->schooladviesAdjustmentSatisfied(object: $object) === false) {
            $this->logger->info(
                '[AdmissionsDecisionGuard] Application {id} — doorstroomtoets outranks schooladvies without an '
                .'adjustment, motivation, or exemption; blocking decision.',
                ['id' => $object['id'] ?? ($object['uuid'] ?? '')]
            );
            return false;
        }

        if ($to === 'rejected' && $kind === 'mbo-toelatingsrecht' && $this->toelatingsrechtBlocksRejection(round: $round, object: $object) === true) {
            $this->logger->info(
                '[AdmissionsDecisionGuard] Application {id} — toelatingsrecht conditions met without a named '
                .'decisionReason; blocking rejection.',
                ['id' => $object['id'] ?? ($object['uuid'] ?? '')]
            );
            return false;
        }

        if ($to === 'placed' && $this->capacityReached(round: $round, roundId: $roundId, tenantId: (string) ($object['tenant_id'] ?? '')) === true) {
            $this->logger->info(
                '[AdmissionsDecisionGuard] AdmissionsRound {id} — capacity reached; blocking placement (target waitlisted instead).',
                ['id' => $roundId]
            );
            return false;
        }

        return true;

    }//end check()

    /**
     * Mandatory-intake gate for the completeIntake transition.
     *
     * @param array<string,mixed> $round  AdmissionsRound property array.
     * @param array<string,mixed> $object Application property array.
     *
     * @return bool True when the transition may proceed.
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
     */
    private function checkMandatoryIntake(array $round, array $object): bool
    {
        $mandatory = ($round['mandatoryIntake'] ?? true) === true;
        if ($mandatory === false) {
            return true;
        }

        $completed = ($object['intakeCompleted'] ?? false) === true;
        if ($completed === false) {
            $this->logger->info(
                '[AdmissionsDecisionGuard] Application {id} — mandatoryIntake is true and intakeCompleted is '
                .'still false; blocking completeIntake.',
                ['id' => $object['id'] ?? ($object['uuid'] ?? '')]
            );
        }

        return $completed;

    }//end checkMandatoryIntake()

    /**
     * MBO toelatingsrecht branch: whether the safeguard blocks a rejection.
     *
     * @param array<string,mixed> $round  AdmissionsRound property array.
     * @param array<string,mixed> $object Application property array.
     *
     * @return bool True when rejection MUST be blocked (conditions met, no named reason).
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission
     */
    private function toelatingsrechtBlocksRejection(array $round, array $object): bool
    {
        $deadline    = $round['applicationDeadline'] ?? null;
        $submittedAt = $object['submittedAt'] ?? null;

        $withinDeadline = true;
        if (is_string($deadline) === true && $deadline !== '' && is_string($submittedAt) === true && $submittedAt !== '') {
            $deadlineTs     = strtotime($deadline);
            $submittedTs    = strtotime($submittedAt);
            $withinDeadline = ($deadlineTs !== false && $submittedTs !== false && $submittedTs <= $deadlineTs);
        }

        $studiekeuzeadviesGiven = ($object['studiekeuzeadviesGiven'] ?? false) === true;
        $decisionReason         = trim((string) ($object['decisionReason'] ?? ''));

        return $withinDeadline === true && $studiekeuzeadviesGiven === true && $decisionReason === '';

    }//end toelatingsrechtBlocksRejection()

    /**
     * VO schooladvies-adjustment branch.
     *
     * @param array<string,mixed> $object Application property array.
     *
     * @return bool True when the rule is satisfied (decision may proceed).
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-a-vo-schooladvies-must-be-adjusted-upward-when-the-doorstroomtoets-scores-higher-unless-motivated
     */
    private function schooladviesAdjustmentSatisfied(array $object): bool
    {
        $schooladvies = $object['schooladviesLevel'] ?? null;
        $doorstroom   = $object['doorstroomtoetsLevel'] ?? null;

        if (is_string($schooladvies) === false || is_string($doorstroom) === false) {
            // Nothing recorded to compare — nothing to adjust.
            return true;
        }

        $schooladviesRank = array_search($schooladvies, self::ORDINAL_LEVELS, true);
        $doorstroomRank   = array_search($doorstroom, self::ORDINAL_LEVELS, true);

        if ($schooladviesRank === false || $doorstroomRank === false) {
            // Unrecognised ordinal value — cannot compare; do not block on bad data.
            return true;
        }

        if ($doorstroomRank <= $schooladviesRank) {
            // Toets did not score higher — nothing to adjust.
            return true;
        }

        $adjusted = $object['schooladviesAdjustedLevel'] ?? null;
        if ($adjusted === $doorstroom) {
            return true;
        }

        $motivation = trim((string) ($object['adjustmentMotivation'] ?? ''));
        if ($motivation !== '') {
            return true;
        }

        // Pro/vmbo-bb exemption: both the original advice and the toets result
        // are pro or vmbo-bb.
        if (in_array($schooladvies, ['pro', 'vmbo-bb'], true) === true
            && in_array($doorstroom, ['pro', 'vmbo-bb'], true) === true
        ) {
            return true;
        }

        return false;

    }//end schooladviesAdjustmentSatisfied()

    /**
     * Capacity branch: whether the round's placed/converted count has reached capacity.
     *
     * @param array<string,mixed> $round    AdmissionsRound property array.
     * @param string              $roundId  AdmissionsRound UUID.
     * @param string              $tenantId Tenant ID to scope the query.
     *
     * @return bool True when capacity has been reached (placement must be blocked).
     *
     * @spec openspec/changes/admissions-and-subject-choice/specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up
     */
    private function capacityReached(array $round, string $roundId, string $tenantId): bool
    {
        $capacity = $round['capacity'] ?? null;
        if (is_int($capacity) === false) {
            // Null/uncapped.
            return false;
        }

        $count = 0;
        foreach (['placed', 'converted'] as $lifecycle) {
            $filters = [
                'admissionsRoundId' => $roundId,
                'lifecycle'         => $lifecycle,
            ];
            if ($tenantId !== '') {
                $filters['tenant_id'] = $tenantId;
            }

            $count += count(
                $this->objectService->findAll(
                    [
                        'register' => self::SCHOLIQ_REGISTER,
                        'schema'   => self::APPLICATION_SCHEMA,
                        'filters'  => $filters,
                        'limit'    => 5000,
                    ]
                )
            );
        }

        return $count >= $capacity;

    }//end capacityReached()

    /**
     * Fetch the AdmissionsRound referenced by an Application, normalised to a plain array.
     *
     * @param string $roundId AdmissionsRound UUID.
     *
     * @return array<string,mixed>|null
     */
    private function fetchRound(string $roundId): ?array
    {
        $round = $this->objectService->find(
            id: $roundId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::ADMISSIONS_ROUND_SCHEMA
        );

        if ($round === null) {
            return null;
        }

        if (is_array($round) === true) {
            return $round;
        }

        return $round->jsonSerialize();

    }//end fetchRound()
}//end class
