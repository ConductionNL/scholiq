<?php

/**
 * Scholiq Data Exchange Run Handler
 *
 * IEventListener for DataExchangeJob lifecycle → `running`
 * (the OR ObjectTransitionedEvent with schema=data-exchange-job, to=running).
 *
 * Algorithm:
 * 1. Load the DataMappingProfile referenced by mappingProfileId (if set).
 * 2. Query the Scholiq source objects per scope via ObjectService::findAll.
 * 3. Apply the fieldMappings (small in-PHP transformer):
 *    - bsn-to-pseudonym  → use LearnerProfile.eckId, NEVER bsnEncrypted.
 *    - date-iso8601      → ensure ISO 8601 date format.
 *    - cohort-to-brin    → look up the Cohort's school BRIN from Cohort.brinNumber.
 *    - null (passthrough)→ copy value unchanged.
 * 4. Delegate to OpenConnector via REST API (POST /apps/openconnector/api/sources/run).
 *    If OpenConnector is not available, the job moves to `failed` with a clear
 *    errorMessage. Scholiq implements NO Edukoppeling/StUF/OSO-XML/Digikoppeling
 *    wire protocols — all of that lives in OpenConnector.
 * 5. Record connectorRunId + result (counts, validation report, artefactRef).
 * 6. Transition the job to succeeded / partial / failed via lifecycle transitions.
 *
 * This is the ADR-031 "external-system bridge" exception: single responsibility
 * — orchestrate the OpenConnector call. No protocol code lives here.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Handles DataExchangeJob lifecycle → running.
 *
 * Loads the mapping profile, builds the payload, delegates to OpenConnector,
 * and records the result. Implements no wire protocols.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
 */
class DataExchangeRunHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const JOB_SCHEMA       = 'data-exchange-job';
    private const MAPPING_PROFILE_SCHEMA = 'data-mapping-profile';
    private const COHORT_SCHEMA          = 'cohort';

    /**
     * Target that composes the verzuimloket dossier (attendance-flag +
     * breachingRecordIds + interventions) instead of the flat fieldMappings
     * export. Mirrors the "OSO dossier composer" pattern described in the
     * data-exchange spec's "What" section.
     */
    private const LEERPLICHT_TARGET        = 'leerplicht';
    private const ATTENDANCE_RECORD_SCHEMA = 'attendance-record';

    /**
     * Target that composes the SWV zorgvraag care-request dossier from the
     * originating SupportRequest's linked LearnerProfile + (optional)
     * LearningPlan, mirroring the leerplicht dossier composer above and the
     * OSO overstapdossier pattern the data-exchange spec describes. See
     * composeSwvDossier() for the minimal-disclosure whitelist.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private const SWV_TARGET = 'swv';
    private const SUPPORT_REQUEST_SCHEMA = 'support-request';
    private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
    private const LEARNING_PLAN_SCHEMA   = 'learning-plan';

    /**
     * The OpenConnector REST endpoint for triggering a source run.
     * Assumption documented in design: POST /apps/openconnector/api/sources/{name}/run
     * returns { runId, status, recordsProcessed, recordsAccepted, recordsRejected,
     *           validationReport, artefactRef }.
     * If this endpoint path changes in OpenConnector, update the constant.
     */
    private const OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run';

    /**
     * App-config key for the OpenConnector internal API token.
     * Admins must set `scholiq.openconnector_api_token` to a valid app-password
     * or API token for the internal source-run call to succeed. Fixes #189.
     */
    private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine for job state transitions.
     * @param IClientService   $clientService    NC HTTP client factory.
     * @param IURLGenerator    $urlGenerator     NC URL generator for internal requests.
     * @param IAppConfig       $appConfig        NC app config for token lookup.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::JOB_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'running') {
            return;
        }

        $this->runJob(event: $event);

    }//end handle()

    /**
     * Maximum records per data-exchange run page.
     *
     * A value of 10 000 silently truncates exports larger than this. A configurable
     * limit with pagination is the proper fix; for now we raise the guard to 100 000
     * and log a warning when we hit the ceiling. Fixes #188.
     */
    private const QUERY_LIMIT = 100000;

    /**
     * Execute the data exchange job.
     *
     * @param ObjectTransitionedEvent $event The running-state transition event.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function runJob(ObjectTransitionedEvent $event): void
    {
        $job   = $event->getObject()->jsonSerialize();
        $jobId = $job['id'] ?? ($job['uuid'] ?? '');

        if ($jobId === '') {
            $this->logger->error('[DataExchangeRunHandler] DataExchangeJob has no id — cannot execute.');
            return;
        }

        $target           = $job['target'] ?? '';
        $mappingProfileId = $job['mappingProfileId'] ?? null;
        $scope            = $job['scope'] ?? [];
        $jobTenantId      = $job['tenant_id'] ?? '';

        // Record startedAt.
        $this->saveJobFields(jobId: $jobId, fields: ['startedAt' => date('c')]);

        // 1. Load the DataMappingProfile.
        $profile = null;
        if ($mappingProfileId !== null && $mappingProfileId !== '') {
            $profile = $this->loadMappingProfile(profileId: $mappingProfileId);
        }

        // 2. Query Scholiq source objects per scope (tenant-scoped — fixes #186).
        // M5: querySourceObjects throws RuntimeException when count >= QUERY_LIMIT.
        // 3. Build payload by applying fieldMappings.
        // #206: bsn-to-pseudonym throws \RuntimeException when eckId is absent — catch
        // here and fail the job fail-closed rather than shipping null pseudonym values.
        // C3: buildPayload throws when mandatory-profile target has no profile.
        try {
            $sourceObjects = $this->querySourceObjects(scope: $scope, tenantId: $jobTenantId);
            $payload       = $this->buildPayload(objects: $sourceObjects, profile: $profile, target: $target);
        } catch (\RuntimeException $e) {
            $this->logger->error(
                '[DataExchangeRunHandler] Job {id} aborted during query/payload build: {msg}',
                ['id' => $jobId, 'msg' => $e->getMessage()]
            );
            // C4 fix: persist result fields first, then drive lifecycle via transition engine
            // so OR's audit-trail and declared transition guards run correctly.
            $this->saveJobFields(
                jobId: $jobId,
                fields: [
                    'finishedAt'   => date('c'),
                    'errorMessage' => $e->getMessage(),
                ],
            );
            $this->transitionEngine->transition($jobId, 'fail');
            return;
        }

        // 4. Delegate to OpenConnector.
        $connectorResult = $this->callOpenConnector(target: $target, payload: $payload);

        if ($connectorResult === null) {
            // OpenConnector not available or returned an error.
            $errorMsg = sprintf(
                "OpenConnector connection '%s' not found or returned an error."
                ." Ensure OpenConnector is installed and the '%s' source is configured.",
                $target,
                $target
            );
            // C4 fix: persist error fields first, then drive lifecycle via transition engine.
            $this->saveJobFields(
                jobId: $jobId,
                fields: [
                    'finishedAt'   => date('c'),
                    'errorMessage' => $errorMsg,
                ],
            );
            $this->transitionEngine->transition($jobId, 'fail');
            return;
        }

        // 5. Record result.
        $resultData = [
            'recordsProcessed' => $connectorResult['recordsProcessed'] ?? 0,
            'recordsAccepted'  => $connectorResult['recordsAccepted'] ?? 0,
            'recordsRejected'  => $connectorResult['recordsRejected'] ?? 0,
            'validationReport' => $connectorResult['validationReport'] ?? [],
            'artefactRef'      => $connectorResult['artefactRef'] ?? null,
        ];

        $connectorRunId = $connectorResult['runId'] ?? null;
        $rejected       = $resultData['recordsRejected'];
        $processed      = $resultData['recordsProcessed'];
        $accepted       = $resultData['recordsAccepted'];

        // 6. Determine outcome state.
        $nextState = 'succeed';
        if ($rejected > 0 && $accepted > 0) {
            $nextState = 'partial';
        }

        if ($rejected > 0 && $accepted === 0 && $processed > 0) {
            $nextState = 'fail';
        }

        // C4 fix: persist result fields first (no lifecycle), then drive lifecycle via
        // the transition engine so OR's audit-trail and declared transition guards fire.
        $this->saveJobFields(
            jobId: $jobId,
            fields: [
                'finishedAt'     => date('c'),
                'result'         => $resultData,
                'connectorRunId' => $connectorRunId,
            ],
        );
        $this->transitionEngine->transition($jobId, $nextState);

        // Once a swv-target job succeeds, the originating SupportRequest tracks
        // through to routed-to-swv (learning-plan spec "SupportRequest tracks the
        // routed job through to decision"). Best-effort: a missing/unresolvable
        // SupportRequest does not fail the job — it is logged and skipped.
        // The target/nextState applicability check lives inside the callee (not
        // here) to keep this already-complex method's branching flat.
        $this->routeSupportRequestToSwv(target: $target, nextState: $nextState, scope: $scope, tenantId: $jobTenantId);

        $this->logger->info(
            '[DataExchangeRunHandler] Job {id} → {state}. target={t}, processed={p}, accepted={a}, rejected={r}.',
            [
                'id'    => $jobId,
                'state' => $nextState,
                't'     => $target,
                'p'     => $processed,
                'a'     => $accepted,
                'r'     => $rejected,
            ]
        );

    }//end runJob()

    /**
     * Transition the SupportRequest that originated a succeeded swv-target
     * DataExchangeJob to `routed-to-swv`.
     *
     * No-ops for any target other than `swv` or any state other than
     * `succeed` — callers may invoke this unconditionally after every job
     * outcome; the applicability check lives here, not in the caller, to
     * keep runJob()'s branching flat.
     *
     * Resolves the SupportRequest via `scope.filters.supportRequestId`
     * (stamped by SupportRequestSubmitHandler when it queues the job).
     *
     * @param string              $target    The job's target slug.
     * @param string              $nextState The lifecycle state the job just transitioned to.
     * @param array<string,mixed> $scope     The job's scope (schema, filters, ...).
     * @param string              $tenantId  Tenant ID to enforce as a mandatory filter.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-swv-routing-reuses-dataexchangejob-and-the-existing-pending-parent-review-gate
     */
    private function routeSupportRequestToSwv(string $target, string $nextState, array $scope, string $tenantId): void
    {
        if ($target !== self::SWV_TARGET || $nextState !== 'succeed') {
            return;
        }

        $filters          = $scope['filters'] ?? [];
        $supportRequestId = $filters['supportRequestId'] ?? null;

        if (is_string($supportRequestId) === false || $supportRequestId === '') {
            $this->logger->warning(
                '[DataExchangeRunHandler] Succeeded swv job has no scope.filters.supportRequestId — cannot '
                .'route the originating SupportRequest to routed-to-swv.'
            );
            return;
        }

        $idFilters = ['id' => $supportRequestId];
        if ($tenantId !== '') {
            $idFilters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::SUPPORT_REQUEST_SCHEMA,
                'filters'  => $idFilters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            $this->logger->warning(
                '[DataExchangeRunHandler] SupportRequest {id} not found — cannot route to routed-to-swv.',
                ['id' => $supportRequestId]
            );
            return;
        }

        try {
            $this->transitionEngine->transition($supportRequestId, 'routeToSwv');
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[DataExchangeRunHandler] Could not transition SupportRequest {id} to routed-to-swv: {msg}',
                ['id' => $supportRequestId, 'msg' => $e->getMessage()]
            );
        }

    }//end routeSupportRequestToSwv()

    /**
     * Load a DataMappingProfile by UUID.
     *
     * @param string $profileId UUID of the DataMappingProfile.
     *
     * @return array<string,mixed>|null The profile data, or null if not found.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function loadMappingProfile(string $profileId): ?array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::MAPPING_PROFILE_SCHEMA,
                'filters'  => ['id' => $profileId],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        if (is_array($results[0]) === true) {
            return $results[0];
        }

        return $results[0]->jsonSerialize();

    }//end loadMappingProfile()

    /**
     * Query Scholiq source objects per the job scope.
     *
     * @param array<string,mixed> $scope    The job scope (schema, filters, cohortId, period).
     * @param string              $tenantId Tenant ID to enforce as a mandatory filter. Fixes #186.
     *
     * @return array<int,array<string,mixed>> Source objects.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function querySourceObjects(array $scope, string $tenantId): array
    {
        $schema   = $scope['schema'] ?? '';
        $filters  = $scope['filters'] ?? [];
        $cohortId = $scope['cohortId'] ?? null;

        if ($schema === '') {
            return [];
        }

        if ($cohortId !== null) {
            $filters['cohortId'] = $cohortId;
        }

        // #186: always force tenant_id so a malicious scope.filters targeting a
        // different tenant's register/schema returns no data.
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => $filters,
                // #188: raised from 10 000 to 100 000; full pagination is a follow-up.
                'limit'    => self::QUERY_LIMIT,
            ]
        );

        // M5: fail hard when we hit the limit ceiling — silent truncation must not ship partial PII.
        if (count($results) >= self::QUERY_LIMIT) {
            throw new \RuntimeException(
                "querySourceObjects hit QUERY_LIMIT (".self::QUERY_LIMIT.") for schema '{$schema}'; "
                .'pagination required. Aborting to prevent incomplete data export.'
            );
        }

        return array_map(
            static function ($item) {
                if (is_array($item) === true) {
                    return $item;
                }

                return $item->jsonSerialize();
            },
            $results
        );

    }//end querySourceObjects()

    /**
     * Build the payload by applying fieldMappings from the profile.
     *
     * Transforms each source object according to the mapping rules:
     * - bsn-to-pseudonym: use eckId, NEVER bsnEncrypted (privacy/AVG rule).
     * - date-iso8601: ensure ISO 8601 format.
     * - cohort-to-brin: look up the Cohort's brinNumber.
     * - null: passthrough (copy value unchanged).
     *
     * @param array<int,array<string,mixed>> $objects Source objects.
     * @param array<string,mixed>|null       $profile DataMappingProfile data, or null.
     *
     * @return array<int,array<string,mixed>> Mapped payload records.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    /**
     * Per-target allowlist of mandatory profile slugs.
     * When a target is listed here, a null profile (no data mapping) is a hard failure
     * rather than pass-through, to prevent unredacted PII from being shipped (C3).
     *
     * Fixes a pre-existing mismatch: this list previously named 'oso-transfer',
     * which is never an actual DataExchangeJob.target value (the real target
     * string is 'oso' — see the "OSO transfer dossier" DataMappingProfile seed
     * and DataExchangeJob.target's own description). That meant an 'oso' job
     * with no configured profile silently fell through to the PII-stripped
     * pass-through branch instead of hard-failing — a real gap for a
     * discretionary, consent-gated transfer. Corrected here to 'oso' while
     * adding 'swv' (openspec/changes/zorgvraag-swv-tlv-chain design.md
     * "Minimal disclosure via DataMappingProfile whitelist, not object-level
     * ACLs" — fail-closed: an unset profile MUST yield no export, never a
     * wider one).
     *
     * @var string[]
     */
    private const MANDATORY_PROFILE_TARGETS = ['bron-rod', 'bron-vo', 'oso', 'edukoppeling', 'swv'];

    /**
     * Build the payload array for OpenConnector from source objects and an optional mapping profile.
     *
     * Applies field mappings from the profile when present; falls back to a PII-stripped
     * pass-through when the profile is absent. Targets in MANDATORY_PROFILE_TARGETS throw
     * a RuntimeException when no profile is provided (C3 — prevents unredacted PII export).
     *
     * @param array<int,array<string,mixed>> $objects Source objects retrieved from OR.
     * @param array<string,mixed>|null       $profile Loaded DataMappingProfile, or null for pass-through.
     * @param string                         $target  Data-exchange target slug (e.g. 'bron-rod').
     *
     * @return array<int,array<string,mixed>> Mapped (and PII-stripped) payload ready for OpenConnector.
     *
     * @throws \RuntimeException When the target requires a profile but none is configured.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function buildPayload(array $objects, ?array $profile, string $target=''): array
    {
        // C3: for targets that require a mapping profile, null profile is a hard fail.
        if ($profile === null && in_array($target, self::MANDATORY_PROFILE_TARGETS, strict: true) === true) {
            throw new \RuntimeException(
                "Data exchange target '{$target}' requires a DataMappingProfile but none is configured — "
                .'aborting to prevent unredacted PII export.'
            );
        }

        if ($profile === null || empty($profile['fieldMappings']) === true) {
            // No mapping: pass raw objects but strip PII fields (C3 — explicit unset).
            return array_map(
                function (array $obj) use ($target): array {
                    // Correlation stamp (duo-afkeurmelding-correction): every record carries
                    // the source object's own id BEFORE composition, so a rejection returned
                    // in a later job's result.validationReport can be resolved back to the
                    // Scholiq object that produced it. Stamped first so the leerplicht/swv
                    // composers below never strip it (they only add keys, never unset()).
                    $recordId = $obj['id'] ?? ($obj['uuid'] ?? '');
                    $obj['_scholiqRecordId'] = $recordId;

                    unset($obj['bsnEncrypted'], $obj['bsnHash'], $obj['email']);
                    if ($target === self::LEERPLICHT_TARGET) {
                        $obj = $this->composeLeerplichtDossier(record: $obj, flag: $obj);
                    }

                    if ($target === self::SWV_TARGET) {
                        $obj = $this->composeSwvDossier(record: $obj, supportRequest: $obj);
                    }

                    return $obj;
                },
                $objects
            );
        }//end if

        $fieldMappings = $profile['fieldMappings'];
        $payload       = [];

        foreach ($objects as $object) {
            // Correlation stamp (duo-afkeurmelding-correction): the source object's own id,
            // written before the fieldMappings loop so it is never a caller-mappable target
            // field and always survives dossier composition below.
            $record = ['_scholiqRecordId' => ($object['id'] ?? ($object['uuid'] ?? ''))];

            foreach ($fieldMappings as $mapping) {
                $scholiqField = $mapping['scholiqField'] ?? '';
                $targetField  = $mapping['targetField'] ?? '';
                $transform    = $mapping['transform'] ?? null;

                if ($scholiqField === '' || $targetField === '') {
                    continue;
                }

                $value = $object[$scholiqField] ?? null;

                $value = $this->applyTransform(
                    value: $value,
                    transform: $transform,
                    object: $object
                );

                $record[$targetField] = $value;
            }

            // C3: always strip PII fields from the mapped record even when profile is present.
            unset($record['bsnEncrypted'], $record['bsnHash'], $record['email']);

            // Re-assert the correlation stamp: guarantee it survives even if a
            // (misconfigured) fieldMappings entry names '_scholiqRecordId' as its
            // own targetField — this stamp must always equal the source object's id.
            $record['_scholiqRecordId'] = ($object['id'] ?? ($object['uuid'] ?? ''));

            // Verzuimloket dossier composer (target=leerplicht): assemble the report
            // from the originating AttendanceFlag's breachingRecordIds + interventions,
            // mirroring the "OSO dossier composer" pattern — not the bare flat mapping.
            if ($target === self::LEERPLICHT_TARGET) {
                $record = $this->composeLeerplichtDossier(record: $record, flag: $object);
            }

            // SWV zorgvraag dossier composer (target=swv): assemble the OSO-format
            // care-request dossier from the originating SupportRequest's linked
            // LearnerProfile + (optional) LearningPlan, same rationale as the
            // leerplicht composer above — fieldMappings cannot resolve a $ref into
            // a nested payload section.
            if ($target === self::SWV_TARGET) {
                $record = $this->composeSwvDossier(record: $record, supportRequest: $object);
            }

            $payload[] = $record;
        }//end foreach

        return $payload;

    }//end buildPayload()

    /**
     * Compose the verzuimloket dossier for a leerplicht-target report.
     *
     * Mirrors the "OSO dossier composer" pattern described in the data-exchange
     * spec's "What" section: assembles the dossier from the originating
     * AttendanceFlag's own data plus its linked AttendanceRecords, rather than
     * shipping only the flat scalar fields the DataMappingProfile.fieldMappings
     * mechanism can express (it has no facility to resolve a $ref array into a
     * nested payload section). `interventions` requires no extra resolution —
     * it is already a plain property on the AttendanceFlag object queried by
     * querySourceObjects, so it flows through untouched.
     *
     * Unlike the OSO/SWV dossiers, this composition does NOT gate on
     * pending-parent-review (see DataExchangeRunGuard — it only blocks
     * target=oso); the leerplicht report is a mandatory Leerplichtwet art. 21a
     * report, not a discretionary transfer.
     *
     * @param array<string,mixed> $record The flat field-mapped (or pass-through)
     *                                    record built so far.
     * @param array<string,mixed> $flag   The source AttendanceFlag object.
     *
     * @return array<string,mixed> $record with breachingRecords + interventions appended.
     *
     * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
     */
    private function composeLeerplichtDossier(array $record, array $flag): array
    {
        $breachingRecordIds = $flag['breachingRecordIds'] ?? [];
        if (is_array($breachingRecordIds) === false) {
            $breachingRecordIds = [];
        }

        $record['breachingRecords'] = $this->resolveAttendanceRecords(
            ids: $breachingRecordIds,
            tenantId: (string) ($flag['tenant_id'] ?? '')
        );

        $interventions = $flag['interventions'] ?? [];
        if (is_array($interventions) === false) {
            $interventions = [];
        }

        $record['interventions'] = $interventions;

        return $record;

    }//end composeLeerplichtDossier()

    /**
     * Compose the SWV zorgvraag care-request dossier for a swv-target job.
     *
     * Mirrors the "OSO dossier composer" pattern described in the
     * data-exchange spec's "What" section and composeLeerplichtDossier()
     * above: assembles the dossier from the originating SupportRequest's
     * linked LearnerProfile and (when set) LearningPlan, rather than shipping
     * only the flat scalar fields the DataMappingProfile.fieldMappings
     * mechanism can express (it has no facility to resolve a $ref into a
     * nested payload section).
     *
     * Minimal disclosure (openspec/changes/zorgvraag-swv-tlv-chain/design.md
     * "Minimal disclosure via DataMappingProfile whitelist, not object-level
     * ACLs"): both the `learner` and `learningPlanContext` sections below are
     * built from an EXPLICIT field whitelist, never a full-object dump —
     * `LearnerProfile.bsnEncrypted`/`bsnHash`/`email` are never read here, and
     * the LearningPlan section carries only the fields the SWV needs as
     * deliberation context (goals/support measures/kind/period), never
     * internal linkage fields like templateId/cohortId/courseId/coordinatorId.
     *
     * Fail-closed: absent `learningPlanId` on the SupportRequest yields no
     * `learningPlanContext` section at all (never a wider export); an
     * unresolvable LearnerProfile yields a null `learner` section rather than
     * inventing data.
     *
     * @param array<string,mixed> $record         The flat field-mapped record built so far.
     * @param array<string,mixed> $supportRequest The source SupportRequest object.
     *
     * @return array<string,mixed> $record with learner + learningPlanContext appended.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-minimal-disclosure-to-the-swv-via-a-field-whitelisting-datamappingprofile
     */
    private function composeSwvDossier(array $record, array $supportRequest): array
    {
        $learnerId = (string) ($supportRequest['learnerId'] ?? '');
        $tenantId  = (string) ($supportRequest['tenant_id'] ?? '');

        $record['learner'] = $this->resolveLearnerWhitelist(learnerId: $learnerId, tenantId: $tenantId);

        $learningPlanId = $supportRequest['learningPlanId'] ?? null;
        if (is_string($learningPlanId) === true && $learningPlanId !== '') {
            $record['learningPlanContext'] = $this->resolveLearningPlanWhitelist(
                learningPlanId: $learningPlanId,
                tenantId: $tenantId
            );
        }

        return $record;

    }//end composeSwvDossier()

    /**
     * Resolve a learner's LearnerProfile to the minimal-disclosure whitelist
     * of fields the OSO care-request dossier needs. NEVER includes
     * bsnEncrypted, bsnHash, or email (design.md "No BSN exposure").
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $tenantId  Tenant ID to enforce as a mandatory filter.
     *
     * @return array<string,mixed>|null Whitelisted learner fields, or null when unresolvable.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function resolveLearnerWhitelist(string $learnerId, string $tenantId): ?array
    {
        if ($learnerId === '') {
            return null;
        }

        $filters = ['ncUserId' => $learnerId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_PROFILE_SCHEMA,
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

        // Explicit whitelist — never bsnEncrypted/bsnHash/email.
        return [
            'eckId'      => $profile['eckId'] ?? null,
            'givenName'  => $profile['givenName'] ?? null,
            'familyName' => $profile['familyName'] ?? null,
            'birthDate'  => $profile['birthDate'] ?? null,
            'schoolId'   => $profile['schoolId'] ?? null,
        ];

    }//end resolveLearnerWhitelist()

    /**
     * Resolve a LearningPlan to the minimal-disclosure whitelist of context
     * fields the SWV needs for deliberation (goals/support measures/kind/
     * period) — never internal linkage fields (templateId/cohortId/courseId/
     * coordinatorId).
     *
     * @param string $learningPlanId UUID of the LearningPlan.
     * @param string $tenantId       Tenant ID to enforce as a mandatory filter.
     *
     * @return array<string,mixed>|null Whitelisted plan context, or null when unresolvable.
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
     */
    private function resolveLearningPlanWhitelist(string $learningPlanId, string $tenantId): ?array
    {
        $filters = ['id' => $learningPlanId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNING_PLAN_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $plan = $results[0];
        if (is_array($results[0]) === false) {
            $plan = $results[0]->jsonSerialize();
        }

        return [
            'kind'            => $plan['kind'] ?? null,
            'period'          => $plan['period'] ?? null,
            'goals'           => $plan['goals'] ?? [],
            'supportMeasures' => $plan['supportMeasures'] ?? [],
        ];

    }//end resolveLearningPlanWhitelist()

    /**
     * Resolve a flag's breachingRecordIds to their full AttendanceRecord data.
     *
     * @param array<int,mixed> $ids      UUIDs of AttendanceRecords to resolve.
     * @param string           $tenantId Tenant ID to enforce as a mandatory filter.
     *
     * @return array<int,array<string,mixed>> Resolved AttendanceRecord objects, PII-stripped.
     *
     * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
     */
    private function resolveAttendanceRecords(array $ids, string $tenantId): array
    {
        $records = [];

        foreach ($ids as $id) {
            if (is_string($id) === false || $id === '') {
                continue;
            }

            $filters = ['id' => $id];
            if ($tenantId !== '') {
                $filters['tenant_id'] = $tenantId;
            }

            $results = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::ATTENDANCE_RECORD_SCHEMA,
                    'filters'  => $filters,
                    'limit'    => 1,
                ]
            );

            if (empty($results) === true) {
                continue;
            }

            $recordData = $results[0];
            if (is_array($results[0]) === false) {
                $recordData = $results[0]->jsonSerialize();
            }

            unset($recordData['bsnEncrypted'], $recordData['bsnHash'], $recordData['email']);

            $records[] = $recordData;
        }//end foreach

        return $records;

    }//end resolveAttendanceRecords()

    /**
     * Apply a named transform to a field value.
     *
     * @param mixed               $value     The raw field value.
     * @param string|null         $transform Transform name: bsn-to-pseudonym, date-iso8601,
     *                                       cohort-to-brin, or null for passthrough.
     * @param array<string,mixed> $object    The full source object (for context lookups).
     *
     * @return mixed The transformed value.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function applyTransform(mixed $value, ?string $transform, array $object): mixed
    {
        if ($transform === null) {
            return $value;
        }

        switch ($transform) {
            case 'bsn-to-pseudonym':
                // BSN MUST NEVER leave Scholiq. Use the ECK iD pseudonym instead.
                // #206: if eckId is absent, fail the entire job (fail-closed) rather
                // than shipping a null pseudonym — a null value in the payload might
                // cause the receiving system to fall back to an unencrypted BSN field.
                $eckId = $object['eckId'] ?? null;
                if ($eckId === null || $eckId === '') {
                    $objectId = $object['id'] ?? ($object['uuid'] ?? 'unknown');
                    throw new \RuntimeException(
                        "bsn-to-pseudonym: object {$objectId} has no eckId — job aborted to prevent BSN leakage."
                    );
                }
                return $eckId;

            case 'date-iso8601':
                if ($value === null || $value === '') {
                    return null;
                }

                // Ensure the value is a valid ISO 8601 date string.
                $ts = strtotime((string) $value);
                if ($ts === false) {
                    return $value;
                }
                return date('Y-m-d', $ts);

            case 'cohort-to-brin':
                // Look up the Cohort's brinNumber for the given cohortId value.
                if ($value === null || $value === '') {
                    return null;
                }

                $cohorts = $this->objectService->findAll(
                    [
                        'register' => self::SCHOLIQ_REGISTER,
                        'schema'   => self::COHORT_SCHEMA,
                        'filters'  => ['id' => (string) $value],
                        'limit'    => 1,
                    ]
                );

                if (empty($cohorts) === true) {
                    return null;
                }

                $cohort = $cohorts[0];
                if (is_array($cohorts[0]) === false) {
                    $cohort = $cohorts[0]->jsonSerialize();
                }
                return $cohort['brinNumber'] ?? null;

            default:
                return $value;
        }//end switch

    }//end applyTransform()

    /**
     * Call the OpenConnector REST API to execute the named connection.
     *
     * Assumption (documented in design): OpenConnector exposes
     *   POST /index.php/apps/openconnector/api/sources/{name}/run
     * with body { payload: array } and returns:
     *   { runId, status, recordsProcessed, recordsAccepted,
     *     recordsRejected, validationReport, artefactRef }
     *
     * If the endpoint is unreachable or returns an error, returns null.
     * Scholiq implements NO wire protocols — all Edukoppeling/StUF/OSO-XML/
     * Digikoppeling/SAML logic lives in OpenConnector.
     *
     * @param string                         $target  Named OpenConnector connection (e.g. 'bron-rod').
     * @param array<int,array<string,mixed>> $payload The mapped payload to send.
     *
     * @return array<string,mixed>|null Response data, or null on failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-20
     */
    private function callOpenConnector(string $target, array $payload): ?array
    {
        $path = sprintf(self::OPENCONNECTOR_RUN_PATH, rawurlencode($target));
        $url  = $this->urlGenerator->getAbsoluteURL('/index.php'.$path);

        // #189: attach the configured API token so the OpenConnector endpoint
        // does not need to be @PublicPage (and is therefore not unauthenticated).
        $apiToken = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::OPENCONNECTOR_TOKEN_KEY,
            default: ''
        );

        $requestOptions = [
            'json'    => ['payload' => $payload],
            'timeout' => 120,
        ];

        if ($apiToken !== '') {
            $requestOptions['headers'] = [
                'Authorization' => 'Bearer '.$apiToken,
            ];
        } else {
            $this->logger->warning(
                '[DataExchangeRunHandler] No OpenConnector API token configured ('
                .'scholiq.openconnector_api_token); the call may fail with 401/403. '
                .'Set the token via the Scholiq admin settings.'
            );
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $url,
                $requestOptions
            );

            $body = json_decode($response->getBody(), true);
            if (is_array($body) === false) {
                $this->logger->error(
                    '[DataExchangeRunHandler] OpenConnector returned non-JSON for target {t}.',
                    ['t' => $target]
                );
                return null;
            }

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(
                "[DataExchangeRunHandler] OpenConnector call failed for target '{t}': {msg}",
                ['t' => $target, 'msg' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end callOpenConnector()

    /**
     * Persist updated fields on the DataExchangeJob without triggering a lifecycle event loop.
     *
     * @param string              $jobId  UUID of the DataExchangeJob.
     * @param array<string,mixed> $fields Fields to update.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function saveJobFields(string $jobId, array $fields): void
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::JOB_SCHEMA,
                'filters'  => ['id' => $jobId],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            $this->logger->warning('[DataExchangeRunHandler] Job {id} not found for field update.', ['id' => $jobId]);
            return;
        }

        $current = $existing[0];
        if (is_array($existing[0]) === false) {
            $current = $existing[0]->jsonSerialize();
        }

        $updated = array_merge($current, $fields);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::JOB_SCHEMA,
            object: $updated
        );

    }//end saveJobFields()
}//end class
