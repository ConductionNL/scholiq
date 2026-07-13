<?php

/**
 * Scholiq Engagement Signal Handler
 *
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects — the SAME
 * event LessonProgressHandler independently reacts to — recomputes the
 * learner's per-Course EngagementScore via EngagementScoreEvaluator, saves
 * it, then checks every active EngagementRiskThreshold in scope (per-learner
 * tenant-wide, or scoped to one Cohort the learner belongs to) and creates
 * an idempotency-keyed EngagementRiskFlag when a threshold is crossed and no
 * open/in-handling flag already exists for that learner+threshold.
 *
 * Mirrors BsaProgressFlagHandler's combined evaluate-then-flag shape (the
 * most recently established precedent in this codebase for this exact
 * "recompute a derived signal, then threshold-check it" combination) rather
 * than the older AttendanceThreshold/AttendanceFlagCreationHandler split
 * across a synthetic calculatedChange marker event and a second handler.
 *
 * Detection is a plain arithmetic/threshold comparison — NOT a TimedJob
 * (ADR-022), and NOT an AI/ML inference call of any kind. Any future
 * predictive/AI-assisted at-risk extension is routed through Hermiq's
 * agentaifeature register behind the ADR-005 gate, in a separate change.
 *
 * NOTE (honestly documented, not papered over): the `recency-days-above`
 * metric compares a recency gap computed at THIS event's instant — since
 * lastActivityAt is (re)set to the statement that just fired this handler,
 * that gap is necessarily ~0 immediately after any activity. The metric is
 * evaluated correctly per its literal definition, but a learner only
 * crosses a recency-days-above threshold on the NEXT statement they send
 * after a long gap, not while they remain silent — there is no scheduled/
 * TimedJob recheck (ADR-022 forbids one here). `engagement-score-below`
 * does not share this limitation.
 *
 * ADR-031 legitimate exception: new-object creation in response to a
 * real-event-driven recompute cannot be expressed as schema metadata
 * declarations — mirrors BsaProgressFlagHandler's role for BsaTrajectory.
 *
 * IMPORTANT: This handler ONLY creates the flag. It NEVER auto-acts against
 * the learner — human-in-the-loop throughout, mirroring AttendanceFlag/
 * BsaProgressFlag.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#requirement-at-risk-detection-beyond-bsa-is-a-deterministic-rule-based-threshold--not-aiml
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Analytics\EngagementScoreEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes EngagementScore and raises EngagementRiskFlags on threshold crossings.
 *
 * @implements IEventListener<Event>
 */
class EngagementSignalHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const XAPI_SCHEMA      = 'xapi-statement';
    private const ENGAGEMENT_SCORE_SCHEMA          = 'engagement-score';
    private const ENGAGEMENT_RISK_THRESHOLD_SCHEMA = 'engagement-risk-threshold';
    private const ENGAGEMENT_RISK_FLAG_SCHEMA      = 'engagement-risk-flag';
    private const COHORT_SCHEMA = 'cohort';

    /**
     * EngagementRiskFlag lifecycle states that count as "still open" for
     * idempotency purposes — a resolved flag does not block a fresh one.
     *
     * @var string[]
     */
    private const OPEN_FLAG_STATES = ['open', 'in-handling'];

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService OR object access.
     * @param EngagementScoreEvaluator $evaluator     Engagement calculation engine.
     * @param ITimeFactory             $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly EngagementScoreEvaluator $evaluator,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectCreatedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-falling-below-the-engagement-threshold-raises-a-flag-generalised-beyond-bsa
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        $objectEntity = $event->getObject();

        if ($objectEntity->getRegister() !== self::SCHOLIQ_REGISTER
            || $objectEntity->getSchema() !== self::XAPI_SCHEMA
        ) {
            return;
        }

        $payload   = $objectEntity->jsonSerialize();
        $tenantId  = $payload['tenant_id'] ?? '';
        $learnerId = $payload['verified_actor_id'] ?? null;
        $courseId  = $payload['courseId'] ?? null;

        if ($learnerId === null || $learnerId === '' || $courseId === null) {
            // No verified learner or no course scope — nothing to score.
            return;
        }

        $engagementScore = $this->recomputeEngagementScore(
            learnerId: $learnerId,
            courseId: $courseId,
            tenantId: $tenantId
        );

        $this->checkThresholds(
            learnerId: $learnerId,
            courseId: $courseId,
            tenantId: $tenantId,
            engagementScore: $engagementScore
        );

    }//end handle()

    /**
     * Recompute and persist the learner's EngagementScore for a course.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     * @param string $tenantId  Tenant identifier.
     *
     * @return array<string, mixed> The saved EngagementScore data.
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-time-on-task-accumulates-across-statements
     */
    private function recomputeEngagementScore(string $learnerId, string $courseId, string $tenantId): array
    {
        $existing = $this->findExistingEngagementScore(learnerId: $learnerId, courseId: $courseId);

        $result = $this->evaluator->evaluate(
            learnerId: $learnerId,
            courseId: $courseId,
            previousLastActivityAt: $existing['lastActivityAt'] ?? null
        );

        $data = array_merge(
            $existing ?? [],
            [
                'learnerId'         => $learnerId,
                'courseId'          => $courseId,
                'timeOnTaskMinutes' => $result['timeOnTaskMinutes'],
                'lastActivityAt'    => $result['lastActivityAt'],
                'score'             => $result['score'],
                'tenant_id'         => $tenantId,
            ]
        );

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ENGAGEMENT_SCORE_SCHEMA,
            object: $data
        );

        if (is_array($saved) === false) {
            $saved = $saved->jsonSerialize();
        }

        return $saved;

    }//end recomputeEngagementScore()

    /**
     * Find the learner's existing EngagementScore for a course, if any.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     *
     * @return array<string, mixed>|null
     */
    private function findExistingEngagementScore(string $learnerId, string $courseId): ?array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENGAGEMENT_SCORE_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                ],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $score = $results[0];
        if (is_array($score) === false) {
            $score = $score->jsonSerialize();
        }

        return $score;

    }//end findExistingEngagementScore()

    /**
     * Check every active EngagementRiskThreshold in scope for this learner
     * and raise an idempotency-keyed EngagementRiskFlag on a crossing.
     *
     * @param string               $learnerId       NC user ID of the learner.
     * @param string               $courseId        UUID of the Course.
     * @param string               $tenantId        Tenant identifier.
     * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
     *
     * @return void
     */
    private function checkThresholds(
        string $learnerId,
        string $courseId,
        string $tenantId,
        array $engagementScore,
    ): void {
        $thresholds = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENGAGEMENT_RISK_THRESHOLD_SCHEMA,
                'filters'  => [
                    'lifecycle' => 'active',
                ],
            ]
        );

        foreach ($thresholds as $threshold) {
            if (is_array($threshold) === false) {
                $threshold = $threshold->jsonSerialize();
            }

            $this->checkThreshold(
                threshold: $threshold,
                learnerId: $learnerId,
                courseId: $courseId,
                tenantId: $tenantId,
                engagementScore: $engagementScore
            );
        }

    }//end checkThresholds()

    /**
     * Evaluate a single EngagementRiskThreshold for a learner and create a
     * flag if the metric is crossed and no open/in-handling flag already
     * exists for this learner+threshold.
     *
     * @param array<string, mixed> $threshold       EngagementRiskThreshold data.
     * @param string               $learnerId       NC user ID of the learner.
     * @param string               $courseId        UUID of the Course in context.
     * @param string               $tenantId        Tenant identifier.
     * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-a-resolved-flag-does-not-block-re-flagging-on-a-later-relapse
     */
    private function checkThreshold(
        array $threshold,
        string $learnerId,
        string $courseId,
        string $tenantId,
        array $engagementScore,
    ): void {
        $cohortId = $threshold['cohortId'] ?? null;
        if ($cohortId !== null && $this->learnerInCohort(learnerId: $learnerId, cohortId: $cohortId) === false) {
            // Threshold is scoped to a Cohort the learner does not belong to.
            return;
        }

        $metric = $threshold['metric'] ?? '';
        $limit  = $threshold['limit'] ?? null;
        if ($limit === null) {
            return;
        }

        $crossed = $this->isCrossed(metric: $metric, limit: (float) $limit, engagementScore: $engagementScore);
        if ($crossed === false) {
            return;
        }

        $thresholdId = $threshold['id'] ?? ($threshold['uuid'] ?? '');
        if ($thresholdId === '') {
            return;
        }

        if ($this->hasOpenFlag(learnerId: $learnerId, thresholdId: $thresholdId) === true) {
            // Idempotency: do not duplicate an already-open flag for this
            // learner + threshold.
            return;
        }

        $metricValue = $this->resolveMetricValue(metric: $metric, engagementScore: $engagementScore);
        $now         = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime());

        $engagementScoreId = $engagementScore['id'] ?? ($engagementScore['uuid'] ?? null);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ENGAGEMENT_RISK_FLAG_SCHEMA,
            object: [
                'learnerId'                 => $learnerId,
                'courseId'                  => $courseId,
                'engagementRiskThresholdId' => $thresholdId,
                'engagementScoreId'         => $engagementScoreId,
                'metricValueAtFlag'         => $metricValue,
                'flaggedAt'                 => $now->format(\DATE_ATOM),
                'lifecycle'                 => 'open',
                'tenant_id'                 => $tenantId,
            ]
        );

    }//end checkThreshold()

    /**
     * Determine whether a threshold's metric is crossed by the current
     * EngagementScore.
     *
     * @param string               $metric          One of engagement-score-below|recency-days-above.
     * @param float                $limit           Threshold limit value.
     * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
     *
     * @return bool
     */
    private function isCrossed(string $metric, float $limit, array $engagementScore): bool
    {
        if ($metric === 'engagement-score-below') {
            $score = $engagementScore['score'] ?? null;
            if ($score === null) {
                return false;
            }

            return (float) $score < $limit;
        }

        if ($metric === 'recency-days-above') {
            $recencyDays = $this->recencyDaysNow(lastActivityAt: $engagementScore['lastActivityAt'] ?? null);
            if ($recencyDays === null) {
                return false;
            }

            return $recencyDays > $limit;
        }

        return false;

    }//end isCrossed()

    /**
     * Resolve the metric value to stamp onto a newly-created flag.
     *
     * @param string               $metric          One of engagement-score-below|recency-days-above.
     * @param array<string, mixed> $engagementScore The just-recomputed EngagementScore data.
     *
     * @return float
     */
    private function resolveMetricValue(string $metric, array $engagementScore): float
    {
        if ($metric === 'recency-days-above') {
            return (float) ($this->recencyDaysNow(lastActivityAt: $engagementScore['lastActivityAt'] ?? null) ?? 0);
        }

        return (float) ($engagementScore['score'] ?? 0);

    }//end resolveMetricValue()

    /**
     * Compute the days between lastActivityAt and "now" (the injected time
     * source), mirroring the declarative recencyDays calculation on
     * EngagementScore for use inside this handler's threshold check.
     *
     * @param string|null $lastActivityAt ISO-8601 timestamp, or null.
     *
     * @return int|null
     */
    private function recencyDaysNow(?string $lastActivityAt): ?int
    {
        if ($lastActivityAt === null || $lastActivityAt === '') {
            return null;
        }

        try {
            $last = new DateTimeImmutable($lastActivityAt);
        } catch (\Exception) {
            return null;
        }

        $now = DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime());

        return (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 86400);

    }//end recencyDaysNow()

    /**
     * Check whether a learner is a member of a Cohort (Cohort.learnerIds).
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $cohortId  UUID of the Cohort.
     *
     * @return bool
     */
    private function learnerInCohort(string $learnerId, string $cohortId): bool
    {
        $cohort = $this->objectService->find(
            id: $cohortId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::COHORT_SCHEMA
        );

        if ($cohort === null) {
            return false;
        }

        $cohortData = $cohort;
        if (is_array($cohort) === false) {
            $cohortData = $cohort->jsonSerialize();
        }

        $learnerIds = $cohortData['learnerIds'] ?? [];
        if (is_array($learnerIds) === false) {
            return false;
        }

        return in_array($learnerId, $learnerIds, true);

    }//end learnerInCohort()

    /**
     * Check whether a still-open EngagementRiskFlag already exists for this
     * learner + threshold.
     *
     * @param string $learnerId   NC user ID of the learner.
     * @param string $thresholdId UUID of the EngagementRiskThreshold.
     *
     * @return bool
     */
    private function hasOpenFlag(string $learnerId, string $thresholdId): bool
    {
        foreach (self::OPEN_FLAG_STATES as $state) {
            $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::ENGAGEMENT_RISK_FLAG_SCHEMA,
                    'filters'  => [
                        'learnerId'                 => $learnerId,
                        'engagementRiskThresholdId' => $thresholdId,
                        'lifecycle'                 => $state,
                    ],
                    'limit'    => 1,
                ]
            );

            if (empty($existing) === false) {
                return true;
            }
        }

        return false;

    }//end hasOpenFlag()
}//end class
