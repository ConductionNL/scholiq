<?php

/**
 * Scholiq Learning Plan Evaluation Handler
 *
 * IEventListener for OpenRegister's ObjectTransitionedEvent. When a
 * LearningPlanEvaluation transitions to `recorded`, this handler:
 *
 * 1. Reads the parent LearningPlan's current goals array.
 * 2. For each goalOutcome in the evaluation, updates the corresponding goal's
 *    `status` field:
 *      - 'met'       → goal.status = 'met'
 *      - 'adjusted'  → goal.status = 'adjusted'
 *      - 'dropped'   → goal.status = 'dropped'
 *      - 'continued' → goal.status unchanged (left as 'open')
 * 3. Writes `LearningPlan.nextReviewAt` from the evaluation's `nextReviewAt`.
 * 4. Persists the updated LearningPlan via ObjectService::saveObject.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write bridge
 * that cannot be expressed as a schema declaration." Single responsibility:
 * translate an evaluation record event into LearningPlan goal-status updates.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Updates LearningPlan goal statuses and next-review date when an evaluation is recorded.
 *
 * @implements IEventListener<Event>
 */
class LearningPlanEvaluationHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER     = 'scholiq';
    private const EVALUATION_SCHEMA    = 'learning-plan-evaluation';
    private const LEARNING_PLAN_SCHEMA = 'learning-plan';

    /**
     * Outcome values that cause a goal status update.
     * 'continued' is intentionally absent — it leaves status as 'open'.
     *
     * @var array<string,string>
     */
    private const OUTCOME_TO_STATUS = [
        'met'      => 'met',
        'adjusted' => 'adjusted',
        'dropped'  => 'dropped',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access.
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
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::EVALUATION_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'recorded') {
            return;
        }

        $this->handleEvaluationRecorded(event: $event);

    }//end handle()

    /**
     * Apply evaluation goal outcomes to the parent LearningPlan.
     *
     * @param ObjectTransitionedEvent $event The transition event.
     *
     * @return void
     */
    private function handleEvaluationRecorded(ObjectTransitionedEvent $event): void
    {
        $evaluation   = $event->getObject()->jsonSerialize();
        $planId       = $evaluation['learningPlanId'] ?? '';
        $goalOutcomes = $evaluation['goalOutcomes'] ?? [];
        $nextReviewAt = $evaluation['nextReviewAt'] ?? null;

        if ($planId === '') {
            $this->logger->warning('[LearningPlanEvaluationHandler] Evaluation has no learningPlanId — skipping.');
            return;
        }

        // Fetch the parent LearningPlan.
        $plans = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNING_PLAN_SCHEMA,
                'filters'  => ['uuid' => $planId],
                'limit'    => 1,
            ]
        );

        if (empty($plans) === true) {
            $this->logger->warning(
                '[LearningPlanEvaluationHandler] LearningPlan {id} not found — skipping.',
                ['id' => $planId]
            );
            return;
        }

        if (is_array($plans[0]) === true) {
            $plan = $plans[0];
        } else {
            $plan = $plans[0]->jsonSerialize();
        }

        $goals = $plan['goals'] ?? [];

        // Build a goalId → outcome map for O(1) lookups.
        $outcomeMap = [];
        foreach ($goalOutcomes as $outcome) {
            $goalId = $outcome['goalId'] ?? null;
            if ($goalId !== null) {
                $outcomeMap[$goalId] = $outcome['outcome'] ?? 'continued';
            }
        }

        // Update goal statuses.
        $changed = false;
        foreach ($goals as &$goal) {
            $goalId  = $goal['goalId'] ?? null;
            $outcome = null;
            if ($goalId !== null) {
                $outcome = $outcomeMap[$goalId] ?? null;
            }

            if ($outcome === null) {
                continue;
            }

            if (isset(self::OUTCOME_TO_STATUS[$outcome]) === true) {
                $newStatus = self::OUTCOME_TO_STATUS[$outcome];
                if (($goal['status'] ?? 'open') !== $newStatus) {
                    $goal['status'] = $newStatus;
                    $changed        = true;
                }
            }

            // 'continued' → leave status as-is.
        }//end foreach

        unset($goal);

        // Update nextReviewAt if provided.
        if ($nextReviewAt !== null) {
            $plan['nextReviewAt'] = $nextReviewAt;
            $changed = true;
        }

        if ($changed === false) {
            $this->logger->info(
                '[LearningPlanEvaluationHandler] No changes needed for LearningPlan {id}.',
                ['id' => $planId]
            );
            return;
        }

        $plan['goals'] = $goals;

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::LEARNING_PLAN_SCHEMA,
            object: $plan
        );

        $this->logger->info(
            '[LearningPlanEvaluationHandler] Updated LearningPlan {id} after evaluation recorded.',
            ['id' => $planId]
        );

    }//end handleEvaluationRecorded()
}//end class
