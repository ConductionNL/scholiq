<?php

/**
 * Scholiq Attendance Flag Creation Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent for the special
 * `threshold-crossed` marker event when an AttendanceThreshold's
 * `isThresholdCrossed` calculatedChange flips to true.
 *
 * On a crossing event this handler:
 * 1. Resolves the learner's mentor from LearnerProfile.managerId.
 * 2. Creates an AttendanceFlag (`open`) with windowStart/windowEnd/
 *    metricValue/breachingRecordIds/mentorId.
 * 3. Records the dataExchangeTarget intent on the flag (actual
 *    DataExchangeJob queueing is deferred to the data-exchange spec).
 *
 * IMPORTANT: This handler ONLY creates the flag. It NEVER auto-acts
 * against the learner. The mentor's intervention and any outbound report
 * are tracked via the flag's own lifecycle (open → in-handling → reported
 * → resolved). Human-in-the-loop throughout — mirrors the proctoring-flag
 * rule from the assessment spec (ADR-008).
 *
 * ADR-031 legitimate exception: new-object creation in response to a
 * calculatedChange event cannot be expressed as schema metadata declarations.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Creates an AttendanceFlag when an AttendanceThreshold crossing is detected.
 *
 * @implements IEventListener<Event>
 */
class AttendanceFlagCreationHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER            = 'scholiq';
    private const ATTENDANCE_THRESHOLD_SCHEMA = 'attendance-threshold';
    private const ATTENDANCE_FLAG_SCHEMA      = 'attendance-flag';
    private const LEARNER_PROFILE_SCHEMA      = 'learner-profile';
    private const DATA_EXCHANGE_JOB_SCHEMA    = 'data-exchange-job';

    /**
     * The transition name used by OR when a calculatedChange crossing fires.
     * OR emits ObjectTransitionedEvent with `to = 'threshold-crossed'` for this case.
     */
    private const THRESHOLD_CROSSED_TO = 'threshold-crossed';

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
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::ATTENDANCE_THRESHOLD_SCHEMA) {
            return;
        }

        // OR fires threshold-crossed as the `to` state when a calculatedChange
        // notification with trigger.calculatedChange fires. Filter to this marker.
        if ($event->getTo() !== self::THRESHOLD_CROSSED_TO) {
            return;
        }

        $this->createFlag(event: $event);

    }//end handle()

    /**
     * Create the AttendanceFlag for the crossing.
     *
     * The event context contains the threshold object and, in the transition
     * context, the `learnerId` and window/metric values that triggered the cross.
     *
     * @param ObjectTransitionedEvent $event The threshold-crossed event.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
     */
    private function createFlag(ObjectTransitionedEvent $event): void
    {
        $threshold   = $event->getObject()->jsonSerialize();
        $thresholdId = $threshold['id'] ?? '';
        if ($thresholdId === '') {
            $thresholdId = $threshold['uuid'] ?? '';
        }

        $cohortId = $threshold['cohortId'] ?? null;
        $onCross  = $threshold['onCross'] ?? [];

        // The transition context carries the per-learner crossing details.
        $context   = $event->getContext() ?? [];
        $learnerId = $context['learnerId'] ?? '';
        if ($learnerId === '') {
            $learnerId = $threshold['learnerId'] ?? '';
        }

        $defaultWindowStart = date('Y-m-d', strtotime('-4 weeks'));
        $windowStart        = $context['windowStart'] ?? $defaultWindowStart;
        $windowEnd          = $context['windowEnd'] ?? date('Y-m-d');

        $metricValue = $context['metricValue'] ?? '';
        if ($metricValue === '') {
            $metricValue = $threshold['unexcusedLesuren'] ?? 0;
        }

        $breachingIds = $context['breachingRecordIds'] ?? [];
        $tenantId     = $threshold['tenant_id'] ?? '';

        if ($learnerId === '' || $thresholdId === '') {
            $this->logger->warning(
                '[AttendanceFlagCreationHandler] Threshold {id}: crossing event missing learnerId — skipping.',
                ['id' => $thresholdId]
            );
            return;
        }

        // Idempotency check: do not create a duplicate flag for the same learner+threshold+window.
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ATTENDANCE_FLAG_SCHEMA,
                'filters'  => [
                    'learnerId'             => $learnerId,
                    'attendanceThresholdId' => $thresholdId,
                    'windowStart'           => $windowStart,
                ],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === false) {
            $this->logger->info(
                '[AttendanceFlagCreationHandler] Flag already exists for learner {l}, threshold {t}, window {w} — skipping duplicate.',
                ['l' => $learnerId, 't' => $thresholdId, 'w' => $windowStart]
            );
            return;
        }

        // Resolve mentor from LearnerProfile.managerId.
        $mentorId = $this->resolveMentorId(learnerId: $learnerId);

        $dataExchangeTarget = $onCross['dataExchangeTarget'] ?? null;

        // Queue a DataExchangeJob for the configured target (e.g. 'leerplicht')
        // when the threshold's onCross.dataExchangeTarget is set. The job is created
        // first so its UUID can be set on the flag's dataExchangeJobId field.
        $dataExchangeJobId = null;
        if ($dataExchangeTarget !== null && $dataExchangeTarget !== '') {
            $dataExchangeJobId = $this->queueDataExchangeJob(
                target: $dataExchangeTarget,
                learnerId: $learnerId,
                windowStart: $windowStart,
                windowEnd: $windowEnd,
                tenantId: $tenantId
            );
        }

        $flag = [
            'learnerId'             => $learnerId,
            'attendanceThresholdId' => $thresholdId,
            'cohortId'              => $cohortId,
            'windowStart'           => $windowStart,
            'windowEnd'             => $windowEnd,
            'metricValue'           => (float) $metricValue,
            'breachingRecordIds'    => $breachingIds,
            'dataExchangeJobId'     => $dataExchangeJobId,
            'mentorId'              => $mentorId,
            'lifecycle'             => 'open',
            'tenant_id'             => $tenantId,
        ];

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::ATTENDANCE_FLAG_SCHEMA,
            object: $flag
        );

        $this->logger->info(
            '[AttendanceFlagCreationHandler] Created AttendanceFlag for learner {l}, threshold {t}, metric {m}, window {ws}–{we}.',
            [
                'l'  => $learnerId,
                't'  => $thresholdId,
                'm'  => $metricValue,
                'ws' => $windowStart,
                'we' => $windowEnd,
            ]
        );

    }//end createFlag()

    /**
     * Create and queue a DataExchangeJob for the given target.
     *
     * Called when an AttendanceThreshold's onCross.dataExchangeTarget is set.
     * The job is created in `queued` state; the DataExchangeRunHandler will
     * execute it when the lifecycle engine transitions it to `running`.
     *
     * @param string $target      Named OpenConnector connection (e.g. 'leerplicht').
     * @param string $learnerId   NC user ID of the learner who crossed the threshold.
     * @param string $windowStart Start date of the measurement window (Y-m-d).
     * @param string $windowEnd   End date of the measurement window (Y-m-d).
     * @param string $tenantId    Tenant UUID.
     *
     * @return string|null UUID of the created DataExchangeJob, or null on failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
     */
    private function queueDataExchangeJob(
        string $target,
        string $learnerId,
        string $windowStart,
        string $windowEnd,
        string $tenantId,
    ): ?string {
        $job = [
            'direction'   => 'export',
            'target'      => $target,
            'scope'       => [
                'schema'   => 'attendance-flag',
                'filters'  => ['learnerId' => $learnerId],
                'cohortId' => null,
                'period'   => $windowStart.'/'.$windowEnd,
            ],
            'requestedBy' => 'system',
            'requestedAt' => date('c'),
            'lifecycle'   => 'queued',
            'tenant_id'   => $tenantId,
        ];

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::DATA_EXCHANGE_JOB_SCHEMA,
            object: $job
        );

        if ($saved === null) {
            $this->logger->warning(
                '[AttendanceFlagCreationHandler] Failed to queue DataExchangeJob for target {t}, learner {l}.',
                ['t' => $target, 'l' => $learnerId]
            );
            return null;
        }

        $savedData = $saved;
        if (is_array($saved) === false) {
            $savedData = $saved->jsonSerialize();
        }

        $jobId = $savedData['id'] ?? ($savedData['uuid'] ?? null);

        $this->logger->info(
            '[AttendanceFlagCreationHandler] Queued DataExchangeJob {id} to target {t} for learner {l}.',
            ['id' => $jobId, 't' => $target, 'l' => $learnerId]
        );

        return $jobId;

    }//end queueDataExchangeJob()

    /**
     * Resolve the learner's mentor from their LearnerProfile.managerId.
     *
     * Returns null when no profile is found or managerId is not set.
     * A missing mentor does not block flag creation.
     *
     * @param string $learnerId NC user ID of the learner.
     *
     * @return string|null NC user ID of the mentor, or null.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
     */
    private function resolveMentorId(string $learnerId): ?string
    {
        $profiles = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_PROFILE_SCHEMA,
                'filters'  => ['ncUserId' => $learnerId],
                'limit'    => 1,
            ]
        );

        if (empty($profiles) === true) {
            return null;
        }

        $profile = $profiles[0];
        if (is_array($profiles[0]) === false) {
            $profile = $profiles[0]->jsonSerialize();
        }

        $managerId = $profile['managerId'] ?? null;

        if ($managerId === null || $managerId === '') {
            return null;
        }

        return $managerId;

    }//end resolveMentorId()
}//end class
