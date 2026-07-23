<?php

/**
 * Scholiq Point Award Trigger Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on exactly three real,
 * already-firing transitions and creates an idempotency-keyed PointAward for
 * each active, matching PointRule:
 *
 * 1. Enrolment -> completed -- the transition XapiCompletionHandler already
 *    dispatches when a mandatory-training final lesson's xAPI verb is
 *    completed/passed. No new xAPI parsing.
 * 2. Submission -> submitted, where the materialised isLate calculation is
 *    false -- read directly off the transitioned object's payload (isLate is
 *    materialise: true, so it is present at transition time). A group
 *    Submission's learnerIds[] each receive their own award.
 * 3. GradeEntry -> published (both the publish and republish transitions
 *    target lifecycle "published", mirroring GradeRollupHandler's single
 *    getTo() === 'published' check) -- calls
 *    GradeFormulaEvaluator::evaluate() directly for the pass check rather
 *    than reading FinalGrade.passed off a possibly stale/not-yet-recomputed
 *    object, avoiding an event-ordering dependency on GradeRollupHandler
 *    having already run in the same dispatch cycle (the same reasoning
 *    BsaProgressFlagHandler uses for injecting BsaProgressEvaluator
 *    directly).
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration, mirroring GradeRollupHandler /
 * BsaProgressFlagHandler exactly.
 *
 * Idempotency: before creating a PointAward, an existing award with the same
 * (learnerId, pointRuleId, sourceObjectId) triple is queried for and skipped
 * if found -- mirrors BsaProgressFlagHandler's OPEN_FLAG_STATES existing-row
 * check. This matters because GradeEntry can republish after a revise, and
 * Enrolment.complete should only ever award once per enrolment.
 *
 * Known limitation: PointRule.scope (cohortId/courseId restriction) is
 * matched against the transitioned object's own cohortId/courseId field when
 * present (Enrolment, GradeEntry). Submission carries neither field directly
 * (only assignmentId) -- resolving Assignment.cohortId would require an
 * extra lookup this change does not add, so a scoped submission-on-time
 * PointRule only matches when its scope is null (tenant-wide). No spec
 * scenario in this change exercises a scoped submission-on-time rule.
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Grading\GradeFormulaEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges Enrolment.completed / Submission.submitted / GradeEntry.published
 * transitions into idempotency-keyed PointAward rows.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
 */
class PointAwardTriggerHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const ENROLMENT_SCHEMA   = 'enrolment';
    private const SUBMISSION_SCHEMA  = 'submission';
    private const GRADE_ENTRY_SCHEMA = 'grade-entry';
    private const POINT_RULE_SCHEMA  = 'point-rule';
    private const POINT_AWARD_SCHEMA = 'point-award';

    /**
     * Constructor.
     *
     * @param ObjectService         $objectService OpenRegister object access.
     * @param GradeFormulaEvaluator $evaluator     Pass/fail evaluation engine.
     * @param ITimeFactory          $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly GradeFormulaEvaluator $evaluator,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-points-are-awarded-only-for-real-already-firing-events
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() === self::ENROLMENT_SCHEMA && $event->getTo() === 'completed') {
            $this->handleEnrolmentCompleted(event: $event);
            return;
        }

        if ($event->getSchema() === self::SUBMISSION_SCHEMA && $event->getTo() === 'submitted') {
            $this->handleSubmissionSubmitted(event: $event);
            return;
        }

        if ($event->getSchema() === self::GRADE_ENTRY_SCHEMA && $event->getTo() === 'published') {
            $this->handleGradeEntryPublished(event: $event);
        }

    }//end handle()

    /**
     * Award enrolment-completed points.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-completing-mandatory-training-awards-enrolment-completed-points
     */
    private function handleEnrolmentCompleted(ObjectTransitionedEvent $event): void
    {
        $entry     = $event->getObject()->jsonSerialize();
        $learnerId = $entry['learnerId'] ?? '';
        $tenantId  = $entry['tenant_id'] ?? '';
        $sourceId  = $entry['id'] ?? ($entry['uuid'] ?? null);

        if ($learnerId === '' || $sourceId === null) {
            return;
        }

        $this->tryAward(
            kind: 'enrolment-completed',
            learnerId: $learnerId,
            sourceKind: 'enrolment',
            sourceObjectId: $sourceId,
            tenantId: $tenantId,
            objectCohortId: $entry['cohortId'] ?? null,
            objectCourseId: $entry['courseId'] ?? null
        );

    }//end handleEnrolmentCompleted()

    /**
     * Award submission-on-time points to every learner on an on-time Submission.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-on-time-submission-awards-submission-on-time-points-a-late-one-does-not
     */
    private function handleSubmissionSubmitted(ObjectTransitionedEvent $event): void
    {
        $entry = $event->getObject()->jsonSerialize();

        if (($entry['isLate'] ?? false) !== false) {
            // Late submissions never award submission-on-time points.
            return;
        }

        $tenantId   = $entry['tenant_id'] ?? '';
        $sourceId   = $entry['id'] ?? ($entry['uuid'] ?? null);
        $learnerIds = $entry['learnerIds'] ?? [];

        if ($sourceId === null || is_array($learnerIds) === false) {
            return;
        }

        foreach ($learnerIds as $learnerId) {
            if (is_string($learnerId) === false || $learnerId === '') {
                continue;
            }

            $this->tryAward(
                kind: 'submission-on-time',
                learnerId: $learnerId,
                sourceKind: 'submission',
                sourceObjectId: $sourceId,
                tenantId: $tenantId,
                objectCohortId: null,
                objectCourseId: null
            );
        }

    }//end handleSubmissionSubmitted()

    /**
     * Award finalgrade-passed points when GradeFormulaEvaluator confirms a pass.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-passing-gradeentry-awards-finalgrade-passed-points
     */
    private function handleGradeEntryPublished(ObjectTransitionedEvent $event): void
    {
        $entry            = $event->getObject()->jsonSerialize();
        $learnerId        = $entry['learnerId'] ?? '';
        $curriculumPlanId = $entry['curriculumPlanId'] ?? '';
        $tenantId         = $entry['tenant_id'] ?? '';

        if ($learnerId === '' || $curriculumPlanId === '') {
            return;
        }

        $result = $this->evaluator->evaluate(curriculumPlanId: $curriculumPlanId, learnerId: $learnerId);

        if (($result['passed'] ?? null) !== true) {
            return;
        }

        $this->tryAward(
            kind: 'finalgrade-passed',
            learnerId: $learnerId,
            sourceKind: 'grade-entry',
            sourceObjectId: $curriculumPlanId,
            tenantId: $tenantId,
            objectCohortId: $entry['cohortId'] ?? null,
            objectCourseId: $entry['courseId'] ?? null
        );

    }//end handleGradeEntryPublished()

    /**
     * Look up active, scope-matching PointRules of the given kind and create
     * an idempotency-keyed PointAward for each one not already awarded.
     *
     * @param string      $kind           PointRule.kind to match.
     * @param string      $learnerId      NC user ID of the learner to award.
     * @param string      $sourceKind     PointAward.sourceKind to stamp.
     * @param string      $sourceObjectId The idempotency-key source object id.
     * @param string      $tenantId       Tenant identifier.
     * @param string|null $objectCohortId The transitioned object's own cohortId, if any.
     * @param string|null $objectCourseId The transitioned object's own courseId, if any.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
     */
    private function tryAward(
        string $kind,
        string $learnerId,
        string $sourceKind,
        string $sourceObjectId,
        string $tenantId,
        ?string $objectCohortId,
        ?string $objectCourseId,
    ): void {
        $rules = $this->fetchActiveRules(kind: $kind, tenantId: $tenantId);

        foreach ($rules as $rule) {
            if ($this->ruleScopeMatches(rule: $rule, objectCohortId: $objectCohortId, objectCourseId: $objectCourseId) === false) {
                continue;
            }

            $ruleId = $rule['id'] ?? ($rule['uuid'] ?? null);
            if ($ruleId === null) {
                continue;
            }

            if ($this->hasExistingAward(learnerId: $learnerId, pointRuleId: $ruleId, sourceObjectId: $sourceObjectId) === true) {
                continue;
            }

            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::POINT_AWARD_SCHEMA,
                object: [
                    'learnerId'      => $learnerId,
                    'pointRuleId'    => $ruleId,
                    'points'         => (float) ($rule['points'] ?? 0),
                    'sourceKind'     => $sourceKind,
                    'sourceObjectId' => $sourceObjectId,
                    'awardedAt'      => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
                    'tenant_id'      => $tenantId,
                ]
            );
        }//end foreach

    }//end tryAward()

    /**
     * Fetch every active PointRule of the given kind.
     *
     * @param string $kind     PointRule.kind to match.
     * @param string $tenantId Tenant identifier.
     *
     * @return array<int, array>
     */
    private function fetchActiveRules(string $kind, string $tenantId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::POINT_RULE_SCHEMA,
                'filters'  => [
                    'kind'      => $kind,
                    'lifecycle' => 'active',
                    'tenant_id' => $tenantId,
                ],
            ]
        );

        return array_map(
            static function ($rule) {
                if (is_array($rule) === true) {
                    return $rule;
                }

                return $rule->jsonSerialize();
            },
            $results
        );

    }//end fetchActiveRules()

    /**
     * True when the rule's scope (if any) matches the transitioned object's
     * own cohortId/courseId. A null scope always matches (tenant-wide).
     *
     * @param array<string,mixed> $rule           PointRule data.
     * @param string|null         $objectCohortId The transitioned object's own cohortId, if any.
     * @param string|null         $objectCourseId The transitioned object's own courseId, if any.
     *
     * @return bool
     */
    private function ruleScopeMatches(array $rule, ?string $objectCohortId, ?string $objectCourseId): bool
    {
        $scope = $rule['scope'] ?? null;
        if (is_array($scope) === false || empty($scope) === true) {
            return true;
        }

        $scopeCohortId = $scope['cohortId'] ?? null;
        if (is_string($scopeCohortId) === true && $scopeCohortId !== '' && $scopeCohortId !== $objectCohortId) {
            return false;
        }

        $scopeCourseId = $scope['courseId'] ?? null;
        if (is_string($scopeCourseId) === true && $scopeCourseId !== '' && $scopeCourseId !== $objectCourseId) {
            return false;
        }

        return true;

    }//end ruleScopeMatches()

    /**
     * Check whether a PointAward already exists for this
     * (learnerId, pointRuleId, sourceObjectId) triple.
     *
     * @param string $learnerId      NC user ID.
     * @param string $pointRuleId    UUID of the PointRule.
     * @param string $sourceObjectId The idempotency-key source object id.
     *
     * @return bool
     */
    private function hasExistingAward(string $learnerId, string $pointRuleId, string $sourceObjectId): bool
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::POINT_AWARD_SCHEMA,
                'filters'  => [
                    'learnerId'      => $learnerId,
                    'pointRuleId'    => $pointRuleId,
                    'sourceObjectId' => $sourceObjectId,
                ],
                'limit'    => 1,
            ]
        );

        return empty($existing) === false;

    }//end hasExistingAward()
}//end class
