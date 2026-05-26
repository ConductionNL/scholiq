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
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Handles DataExchangeJob lifecycle → running.
 *
 * Loads the mapping profile, builds the payload, delegates to OpenConnector,
 * and records the result. Implements no wire protocols.
 *
 * @implements IEventListener<Event>
 */
class DataExchangeRunHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const JOB_SCHEMA       = 'data-exchange-job';
    private const MAPPING_PROFILE_SCHEMA = 'data-mapping-profile';
    private const COHORT_SCHEMA          = 'cohort';

    /**
     * The OpenConnector REST endpoint for triggering a source run.
     * Assumption documented in design: POST /apps/openconnector/api/sources/{name}/run
     * returns { runId, status, recordsProcessed, recordsAccepted, recordsRejected,
     *           validationReport, artefactRef }.
     * If this endpoint path changes in OpenConnector, update the constant.
     */
    private const OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param IClientService  $clientService NC HTTP client factory.
     * @param IURLGenerator   $urlGenerator  NC URL generator for internal requests.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
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
     * Execute the data exchange job.
     *
     * @param ObjectTransitionedEvent $event The running-state transition event.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
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

        // Record startedAt.
        $this->saveJobFields(jobId: $jobId, fields: ['startedAt' => date('c')]);

        // 1. Load the DataMappingProfile.
        $profile = null;
        if ($mappingProfileId !== null && $mappingProfileId !== '') {
            $profile = $this->loadMappingProfile(profileId: $mappingProfileId);
        }

        // 2. Query Scholiq source objects per scope.
        $sourceObjects = $this->querySourceObjects(scope: $scope);

        // 3. Build payload by applying fieldMappings.
        $payload = $this->buildPayload(objects: $sourceObjects, profile: $profile);

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
            $this->saveJobFields(
                jobId: $jobId,
                fields: [
                    'finishedAt'   => date('c'),
                    'errorMessage' => $errorMsg,
                    'lifecycle'    => 'failed',
                ],
            );
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
        $nextState = 'succeeded';
        if ($rejected > 0 && $accepted > 0) {
            $nextState = 'partial';
        }

        if ($rejected > 0 && $accepted === 0 && $processed > 0) {
            $nextState = 'failed';
        }

        $this->saveJobFields(
            jobId: $jobId,
            fields: [
                'finishedAt'     => date('c'),
                'result'         => $resultData,
                'connectorRunId' => $connectorRunId,
                'lifecycle'      => $nextState,
            ],
        );

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
     * @param array<string,mixed> $scope The job scope (schema, filters, cohortId, period).
     *
     * @return array<int,array<string,mixed>> Source objects.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
     */
    private function querySourceObjects(array $scope): array
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

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => $filters,
                'limit'    => 10000,
            ]
        );

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
    private function buildPayload(array $objects, ?array $profile): array
    {
        if ($profile === null || empty($profile['fieldMappings']) === true) {
            // No mapping: pass raw objects as-is (OpenConnector handles formatting).
            return $objects;
        }

        $fieldMappings = $profile['fieldMappings'];
        $payload       = [];

        foreach ($objects as $object) {
            $record = [];
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

            $payload[] = $record;
        }//end foreach

        return $payload;

    }//end buildPayload()

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
                // BSN MUST NEVER leave OpenConnector. Use the ECK iD pseudonym.
                // If eckId is set on the object, prefer it. Fall back to null.
                return $object['eckId'] ?? null;

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

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $url,
                [
                    'json'    => ['payload' => $payload],
                    'timeout' => 120,
                ]
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
