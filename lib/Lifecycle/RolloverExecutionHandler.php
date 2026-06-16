<?php

/**
 * Scholiq Rollover Execution Handler
 *
 * Listens for the RolloverPlan `previewed → executing` (and `failed → executing`
 * retry) transition and runs the chunked, idempotent rollover via RolloverService,
 * then drives the plan to `completed` or `failed`.
 *
 * Per the fleet jobs-never-ran bug, scholiq does NOT register a background job
 * via `IRegistrationContext::registerJob`; async work in this app is event-driven
 * off OpenRegister's `ObjectTransitionedEvent` (the same mechanism every other
 * Scholiq bridge uses). OR's lifecycle engine fires this event when an admin
 * transitions the plan to `executing`; execution is idempotent and resumable so a
 * `failed` plan can be retried without duplicating already-created cohorts or
 * carried-over enrolments.
 *
 * ADR-031 legitimate exception: the cross-object orchestration (cohort creation,
 * learner movement, NC group sync, enrolment carry-over, OSO queueing) cannot be
 * expressed as declarative schema metadata.
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
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\RolloverService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the rollover when a RolloverPlan transitions to `executing`.
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */
class RolloverExecutionHandler implements IEventListener
{
    /**
     * OpenRegister register slug.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * RolloverPlan schema slug.
     */
    private const SCHEMA = 'rollover-plan';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   OR object access service.
     * @param RolloverService $rolloverService Rollover preview/execution logic.
     * @param LoggerInterface $logger          PSR logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RolloverService $rolloverService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent for a RolloverPlan reaching `executing`.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER
            || $event->getSchema() !== self::SCHEMA
            || $event->getTo() !== 'executing'
        ) {
            return;
        }

        $plan   = (array) $event->getObject()->jsonSerialize();
        $planId = (string) ($plan['id'] ?? ($plan['uuid'] ?? ''));

        // Guard: a plan may only execute from a matching preview (the dry-run gate).
        if ($this->rolloverService->previewMatchesMappings(plan: $plan) === false) {
            $this->logger->warning('[RolloverExecutionHandler] Preview does not match mappings — failing plan {p}.', ['p' => $planId]);
            $this->failPlan(plan: $plan, reason: 'Preview does not match current mappings');
            return;
        }

        try {
            $progress = $this->rolloverService->execute(plan: $plan);

            $plan['perMappingProgress'] = $progress;
            $plan['lifecycle']          = 'completed';
            $plan['executedAt']         = date('c');
            $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::SCHEMA, object: $plan);

            $this->logger->info('[RolloverExecutionHandler] Rollover plan {p} completed.', ['p' => $planId]);
        } catch (Throwable $e) {
            // Record progress so far + fail; the plan is resumable via `retry`.
            $this->logger->error('[RolloverExecutionHandler] Rollover plan {p} failed: {m}.', ['p' => $planId, 'm' => $e->getMessage()]);
            $this->failPlan(plan: $plan, reason: $e->getMessage());
        }
    }//end handle()

    /**
     * Transition a plan to `failed`, recording the reason.
     *
     * @param array<string,mixed> $plan   The plan object.
     * @param string              $reason The failure reason.
     *
     * @return void
     */
    private function failPlan(array $plan, string $reason): void
    {
        $plan['lifecycle']     = 'failed';
        $plan['failureReason'] = $reason;
        $this->objectService->saveObject(register: self::SCHOLIQ_REGISTER, schema: self::SCHEMA, object: $plan);
    }//end failPlan()
}//end class
