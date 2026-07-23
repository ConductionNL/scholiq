<?php

/**
 * Scholiq Support Request Submit Handler
 *
 * IEventListener for SupportRequest lifecycle → `submitted`
 * (the OR ObjectTransitionedEvent with schema=support-request, to=submitted).
 *
 * Algorithm:
 * 1. Resolve the active `swv`-target DataMappingProfile (if any).
 * 2. Create a DataExchangeJob in `queued` state: target=swv,
 *    scope.schema=support-request, scope.filters={learnerId, supportRequestId}.
 * 3. Stamp the new job's UUID back onto the SupportRequest's dataExchangeJobId.
 * 4. Advance the job into `pending-parent-review` via TransitionEngine — the
 *    same gate the existing OSO overstapdossier flow uses (DataExchangeRunGuard
 *    now blocks `swv` targets from a direct queued → running just as it
 *    already blocks `oso`).
 *
 * The actual dossier composition (resolving LearnerProfile/LearningPlan into
 * the OSO-format payload) happens later, when the job transitions to
 * `running` — that is DataExchangeRunHandler's responsibility
 * (composeSwvDossier()), not this listener's. This listener's single
 * responsibility is: create + queue + advance to pending-parent-review.
 *
 * ADR-031 legitimate exception: cross-schema object creation in response to a
 * lifecycle transition cannot be expressed as schema metadata declarations.
 * Mirrors AttendanceFlagCreationHandler's "queue a DataExchangeJob on this
 * trigger" shape.
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
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-swv-routing-reuses-dataexchangejob-and-the-existing-pending-parent-review-gate
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Auto-queues a DataExchangeJob (target: swv) when a SupportRequest is submitted.
 *
 * @implements IEventListener<Event>
 */
class SupportRequestSubmitHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER       = 'scholiq';
    private const SUPPORT_REQUEST_SCHEMA = 'support-request';
    private const JOB_SCHEMA = 'data-exchange-job';
    private const MAPPING_PROFILE_SCHEMA = 'data-mapping-profile';

    private const SWV_TARGET = 'swv';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine for job state transitions.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
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
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::SUPPORT_REQUEST_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'submitted') {
            return;
        }

        $this->queueSwvJob(event: $event);

    }//end handle()

    /**
     * Create and queue the SWV DataExchangeJob for the submitted SupportRequest.
     *
     * @param ObjectTransitionedEvent $event The submitted-state transition event.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function queueSwvJob(ObjectTransitionedEvent $event): void
    {
        $supportRequest   = $event->getObject()->jsonSerialize();
        $supportRequestId = $supportRequest['id'] ?? ($supportRequest['uuid'] ?? '');

        if ($supportRequestId === '') {
            $this->logger->error(
                '[SupportRequestSubmitHandler] SupportRequest has no id — cannot queue SWV DataExchangeJob.'
            );
            return;
        }

        $learnerId = (string) ($supportRequest['learnerId'] ?? '');
        $raisedBy  = (string) ($supportRequest['raisedBy'] ?? '');
        $tenantId  = (string) ($supportRequest['tenant_id'] ?? '');

        if ($learnerId === '') {
            $this->logger->warning(
                '[SupportRequestSubmitHandler] SupportRequest {id} has no learnerId — cannot queue SWV job.',
                ['id' => $supportRequestId]
            );
            return;
        }

        $mappingProfileId = $this->resolveSwvMappingProfileId(tenantId: $tenantId);

        $requestedBy = 'system';
        if ($raisedBy !== '') {
            $requestedBy = $raisedBy;
        }

        $job = $this->buildSwvJobPayload(
            learnerId: $learnerId,
            supportRequestId: $supportRequestId,
            requestedBy: $requestedBy,
            tenantId: $tenantId,
            mappingProfileId: $mappingProfileId
        );

        $jobId = $this->persistSwvJob(job: $job, supportRequestId: $supportRequestId);

        if ($jobId === null) {
            return;
        }

        $this->finalizeSwvJob(jobId: $jobId, supportRequestId: $supportRequestId);

    }//end queueSwvJob()

    /**
     * Build the DataExchangeJob payload array for the SWV zorgvraag dossier.
     *
     * @param string      $learnerId        NC user ID of the learner.
     * @param string      $supportRequestId UUID of the SupportRequest being submitted.
     * @param string      $requestedBy      NC user ID of the requester (raisedBy, or 'system').
     * @param string      $tenantId         Tenant UUID.
     * @param string|null $mappingProfileId UUID of the resolved swv DataMappingProfile, or null.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function buildSwvJobPayload(
        string $learnerId,
        string $supportRequestId,
        string $requestedBy,
        string $tenantId,
        ?string $mappingProfileId,
    ): array {
        return [
            'direction'        => 'export',
            'target'           => self::SWV_TARGET,
            'mappingProfileId' => $mappingProfileId,
            'scope'            => [
                'schema'   => self::SUPPORT_REQUEST_SCHEMA,
                'filters'  => [
                    'learnerId'        => $learnerId,
                    'supportRequestId' => $supportRequestId,
                ],
                'cohortId' => null,
                'period'   => null,
            ],
            'requestedBy'      => $requestedBy,
            'requestedAt'      => date('c'),
            'lifecycle'        => 'queued',
            'tenant_id'        => $tenantId,
        ];

    }//end buildSwvJobPayload()

    /**
     * Save the DataExchangeJob and return its UUID, logging and returning null
     * on any failure (save failure or a saved row with no resolvable id).
     *
     * @param array<string,mixed> $job              The job payload to save.
     * @param string              $supportRequestId UUID of the originating SupportRequest (for logging).
     *
     * @return string|null UUID of the saved job, or null on failure.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function persistSwvJob(array $job, string $supportRequestId): ?string
    {
        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::JOB_SCHEMA,
            object: $job
        );

        if ($saved === null) {
            $this->logger->error(
                '[SupportRequestSubmitHandler] Failed to queue SWV DataExchangeJob for SupportRequest {id}.',
                ['id' => $supportRequestId]
            );
            return null;
        }

        $savedJob = $saved;
        if (is_array($saved) === false) {
            $savedJob = $saved->jsonSerialize();
        }

        $jobId = $savedJob['id'] ?? ($savedJob['uuid'] ?? null);

        if (is_string($jobId) === false || $jobId === '') {
            $this->logger->error(
                '[SupportRequestSubmitHandler] SWV DataExchangeJob saved but returned no id for SupportRequest {id}.',
                ['id' => $supportRequestId]
            );
            return null;
        }

        return $jobId;

    }//end persistSwvJob()

    /**
     * Stamp the job id back onto the SupportRequest and advance the job into
     * pending-parent-review.
     *
     * @param string $jobId            UUID of the saved DataExchangeJob.
     * @param string $supportRequestId UUID of the SupportRequest.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function finalizeSwvJob(string $jobId, string $supportRequestId): void
    {
        // Stamp the job id back onto the SupportRequest (plain field write — does
        // NOT re-trigger the submit transition).
        $this->saveSupportRequestFields(
            supportRequestId: $supportRequestId,
            fields: ['dataExchangeJobId' => $jobId]
        );

        // Advance the job into pending-parent-review — the same gate the
        // existing OSO overstapdossier flow uses. DataExchangeRunGuard blocks a
        // direct queued → running for swv-target jobs (see GATED_TARGETS), so
        // this is required before the job can ever reach running.
        try {
            $this->transitionEngine->transition($jobId, 'pendingParentReview');
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[SupportRequestSubmitHandler] Could not advance DataExchangeJob {id} to pending-parent-review: {msg}',
                ['id' => $jobId, 'msg' => $e->getMessage()]
            );
        }

        $this->logger->info(
            '[SupportRequestSubmitHandler] Queued SWV DataExchangeJob {jobId} for SupportRequest {id}.',
            ['jobId' => $jobId, 'id' => $supportRequestId]
        );

    }//end finalizeSwvJob()

    /**
     * Resolve the active `swv`-target DataMappingProfile's UUID, if any.
     *
     * A null return is not fatal here — buildPayload()'s MANDATORY_PROFILE_TARGETS
     * will fail the job fail-closed (rather than a wide pass-through export) if no
     * profile is configured by the time the job runs.
     *
     * @param string $tenantId Tenant ID to enforce as a mandatory filter.
     *
     * @return string|null UUID of the active swv DataMappingProfile, or null.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function resolveSwvMappingProfileId(string $tenantId): ?string
    {
        $filters = [
            'target' => self::SWV_TARGET,
            'active' => true,
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::MAPPING_PROFILE_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $profile = $results[0];
        if (is_array($results[0]) === false) {
            $profile = $results[0]->jsonSerialize();
        }

        $profileId = $profile['id'] ?? ($profile['uuid'] ?? null);

        if (is_string($profileId) === false || $profileId === '') {
            return null;
        }

        return $profileId;

    }//end resolveSwvMappingProfileId()

    /**
     * Persist updated fields on the SupportRequest without triggering a lifecycle
     * event loop (mirrors DataExchangeRunHandler::saveJobFields()).
     *
     * @param string              $supportRequestId UUID of the SupportRequest.
     * @param array<string,mixed> $fields           Fields to update.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function saveSupportRequestFields(string $supportRequestId, array $fields): void
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::SUPPORT_REQUEST_SCHEMA,
                'filters'  => ['id' => $supportRequestId],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            $this->logger->warning(
                '[SupportRequestSubmitHandler] SupportRequest {id} not found for field update.',
                ['id' => $supportRequestId]
            );
            return;
        }

        $current = $existing[0];
        if (is_array($existing[0]) === false) {
            $current = $existing[0]->jsonSerialize();
        }

        $updated = array_merge($current, $fields);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::SUPPORT_REQUEST_SCHEMA,
            object: $updated
        );

    }//end saveSupportRequestFields()
}//end class
