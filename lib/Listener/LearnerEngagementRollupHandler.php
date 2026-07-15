<?php

/**
 * Scholiq Learner Engagement Rollup Handler
 *
 * Listens for OpenRegister's ObjectCreatedEvent on PointAward objects,
 * finds-or-creates the learner's LearnerEngagement row, and recomputes it via
 * PointEngagementEvaluator. When the triggering award's sourceKind is not
 * itself streak-milestone (the recursion guard -- a milestone bonus award
 * must not re-trigger its own milestone check), compares the freshly
 * computed currentStreakDays against every active
 * PointRule(kind: streak-milestone)'s milestoneDays; for any threshold newly
 * crossed (previousStreak < milestoneDays <= newStreak, where previousStreak
 * is the LearnerEngagement row's currentStreakDays before this recompute),
 * creates a bonus PointAward(sourceKind: streak-milestone,
 * sourceObjectId: null). That second award re-enters this handler once more
 * to fold the bonus into totalPoints/levelId, but the recursion guard means
 * it terminates after exactly one extra pass -- no infinite loop.
 *
 * ADR-031 legitimate exception: event-to-object-write bridge that cannot be
 * expressed as a schema declaration, mirroring GradeRollupHandler /
 * BsaProgressFlagHandler exactly. Recomputation is triggered by a real
 * PointAward ObjectCreatedEvent -- NOT a TimedJob (ADR-022).
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Scholiq\Engagement\PointEngagementEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Recomputes LearnerEngagement and awards streak-milestone bonuses when a
 * PointAward is created.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-learner-totals-level-and-streak-are-computed-by-a-php-evaluator-not-a-sum-aggregation
 */
class LearnerEngagementRollupHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER          = 'scholiq';
    private const POINT_AWARD_SCHEMA        = 'point-award';
    private const POINT_RULE_SCHEMA         = 'point-rule';
    private const LEARNER_ENGAGEMENT_SCHEMA = 'learner-engagement';
    private const STREAK_MILESTONE_KIND     = 'streak-milestone';

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService OpenRegister object access.
     * @param PointEngagementEvaluator $evaluator     Totals/level/streak calculation engine.
     * @param ITimeFactory             $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly PointEngagementEvaluator $evaluator,
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
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-new-pointaward-recomputes-totals-and-level
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false) {
            return;
        }

        $objectEntity = $event->getObject();

        if ($objectEntity->getRegister() !== self::SCHOLIQ_REGISTER
            || $objectEntity->getSchema() !== self::POINT_AWARD_SCHEMA
        ) {
            return;
        }

        $award      = $objectEntity->jsonSerialize();
        $learnerId  = $award['learnerId'] ?? '';
        $tenantId   = $award['tenant_id'] ?? '';
        $sourceKind = $award['sourceKind'] ?? '';

        if ($learnerId === '') {
            return;
        }

        $existing       = $this->findExistingEngagement(learnerId: $learnerId, tenantId: $tenantId);
        $previousStreak = (int) ($existing['currentStreakDays'] ?? 0);

        $result = $this->evaluator->evaluate(learnerId: $learnerId, tenantId: $tenantId);

        $data = array_merge(
            $existing ?? [],
            [
                'learnerId'         => $learnerId,
                'totalPoints'       => $result['totalPoints'],
                'levelId'           => $result['levelId'],
                'currentStreakDays' => $result['currentStreakDays'],
                'longestStreakDays' => $result['longestStreakDays'],
                'lastActivityDate'  => $result['lastActivityDate'],
                'lastRecomputedAt'  => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
                'tenant_id'         => $tenantId,
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::LEARNER_ENGAGEMENT_SCHEMA,
            object: $data
        );

        if ($sourceKind === self::STREAK_MILESTONE_KIND) {
            // Recursion guard: a milestone bonus award's own rollup must not
            // re-check streak milestones.
            return;
        }

        $this->checkStreakMilestones(
            learnerId: $learnerId,
            tenantId: $tenantId,
            previousStreak: $previousStreak,
            newStreak: $result['currentStreakDays']
        );

    }//end handle()

    /**
     * Find the learner's existing LearnerEngagement row, if any.
     *
     * @param string $learnerId NC user ID.
     * @param string $tenantId  Tenant identifier.
     *
     * @return array<string,mixed>|null
     */
    private function findExistingEngagement(string $learnerId, string $tenantId): ?array
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_ENGAGEMENT_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'tenant_id' => $tenantId,
                ],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            return null;
        }

        if (is_array($existing[0]) === true) {
            return $existing[0];
        }

        return $existing[0]->jsonSerialize();

    }//end findExistingEngagement()

    /**
     * Award a bonus PointAward for every active streak-milestone PointRule
     * whose milestoneDays was newly crossed by this recompute.
     *
     * @param string $learnerId      NC user ID.
     * @param string $tenantId       Tenant identifier.
     * @param int    $previousStreak currentStreakDays before this recompute.
     * @param int    $newStreak      currentStreakDays after this recompute.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-streak-milestone-awards-a-bonus-pointaward-exactly-once
     */
    private function checkStreakMilestones(string $learnerId, string $tenantId, int $previousStreak, int $newStreak): void
    {
        if ($newStreak <= $previousStreak) {
            // No forward progress -- nothing can have been newly crossed.
            return;
        }

        $rules = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::POINT_RULE_SCHEMA,
                'filters'  => [
                    'kind'      => self::STREAK_MILESTONE_KIND,
                    'lifecycle' => 'active',
                    'tenant_id' => $tenantId,
                ],
            ]
        );

        foreach ($rules as $rule) {
            if (is_array($rule) === false) {
                $rule = $rule->jsonSerialize();
            }

            $milestoneDays = $rule['milestoneDays'] ?? null;
            if ($milestoneDays === null) {
                continue;
            }

            $milestoneDays = (int) $milestoneDays;
            if ($previousStreak >= $milestoneDays || $newStreak < $milestoneDays) {
                continue;
            }

            $ruleId = $rule['id'] ?? ($rule['uuid'] ?? null);
            if ($ruleId === null) {
                continue;
            }

            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::POINT_AWARD_SCHEMA,
                object: [
                    'learnerId'      => $learnerId,
                    'pointRuleId'    => $ruleId,
                    'points'         => (float) ($rule['points'] ?? 0),
                    'sourceKind'     => self::STREAK_MILESTONE_KIND,
                    'sourceObjectId' => null,
                    'awardedAt'      => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
                    'tenant_id'      => $tenantId,
                ]
            );
        }//end foreach

    }//end checkStreakMilestones()
}//end class
