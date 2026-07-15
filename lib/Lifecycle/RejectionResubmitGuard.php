<?php

/**
 * Scholiq Rejection Resubmit Guard
 *
 * Lifecycle guard for the ExchangeRejection schema's `resubmit` transition
 * (`corrected` → `resubmitted`). Mirrors MunicipalityFeedbackGuard's
 * role-check + server-side-stamp shape, with one addition: on success it also
 * creates a single new DataExchangeJob scoped to exactly this rejection's
 * source object (`scope.filters.id = sourceObjectId`), reusing the existing
 * generic filter mechanism — never a batched multi-record resubmission (see
 * design.md "Per-rejection resubmission, not a multi-select batch action").
 *
 * ADR-031 legitimate exception: this register has no declarative mechanism
 * to express "on this transition, create a new sibling object scoped to a
 * $ref field on the transitioning object" — a PHP guard is the only proven
 * mechanism (same rationale as MunicipalityFeedbackGuard's field-scoped
 * write-authorization gap).
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExchangeRejection `corrected → resubmitted` transition.
 *
 * The transition proceeds only when the acting user is in one of the
 * authorised groups (`admin`, `coordinator`). On success it creates exactly
 * one new DataExchangeJob (target/mappingProfileId copied from the
 * originating job, scope narrowed to this rejection's source object) and
 * stamps `resubmittedJobId` into the transition payload — always
 * server-side, never a caller-supplied value.
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
 */
class RejectionResubmitGuard
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const JOB_SCHEMA       = 'data-exchange-job';

    /**
     * Groups whose members may resubmit a corrected rejection.
     *
     * @var string[]
     */
    private const AUTHORISED_GROUPS = [
        'admin',
        'coordinator',
    ];

    /**
     * Maps ExchangeRejection.sourceKind to the typed $ref id field carrying
     * the source object's id. Mirrors RejectionMappingHandler's own map.
     *
     * @var array<string,string>
     */
    private const SOURCE_KIND_FIELD_MAP = [
        'learner-profile' => 'learnerProfileId',
        'enrolment'       => 'enrolmentId',
        'final-grade'     => 'finalGradeId',
        'attendance-flag' => 'attendanceFlagId',
        'support-request' => 'supportRequestId',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param IGroupManager   $groupManager  NC group manager to resolve the acting user's role groups.
     * @param IUserManager    $userManager   User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface $logger        PSR logger for guard rejections.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the resubmission preconditions, create the scoped DataExchangeJob,
     * and stamp resubmittedJobId.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `corrected → resubmitted` resubmit transition. Returns true to allow the
     * transition (and writes `resubmittedJobId` into the payload), false to
     * block it.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'  : the
     *                                               ExchangeRejection data array
     *                                               - 'actor'   : NC user ID of
     *                                               the requester
     *                                               - 'payload' : mutable array;
     *                                               resubmittedJobId is written
     *                                               here
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
     */
    public function check(array &$transitionContext): bool
    {
        $rejection   = $transitionContext['object'] ?? [];
        $rejectionId = $rejection['id'] ?? ($rejection['uuid'] ?? '?');
        $actor       = (string) ($transitionContext['actor'] ?? '');

        if ($actor === '') {
            $this->logger->warning(
                '[RejectionResubmitGuard] No actor in transitionContext — denying resubmit of {id}.',
                ['id' => $rejectionId]
            );
            return false;
        }

        if ($this->actorIsAuthorised(actor: $actor) === false) {
            $this->logger->info(
                '[RejectionResubmitGuard] Actor {a} is not in an authorised group — denying resubmit of {id}.',
                ['a' => $actor, 'id' => $rejectionId]
            );
            return false;
        }

        $sourceKind  = (string) ($rejection['sourceKind'] ?? '');
        $sourceField = self::SOURCE_KIND_FIELD_MAP[$sourceKind] ?? null;

        if ($sourceField === null) {
            $this->logger->warning(
                '[RejectionResubmitGuard] ExchangeRejection {id} has unsupported sourceKind "{kind}" — denying resubmit.',
                ['id' => $rejectionId, 'kind' => $sourceKind]
            );
            return false;
        }

        $sourceObjectId = (string) ($rejection[$sourceField] ?? '');
        $originalJobId  = (string) ($rejection['dataExchangeJobId'] ?? '');

        if ($sourceObjectId === '' || $originalJobId === '') {
            $this->logger->warning(
                '[RejectionResubmitGuard] ExchangeRejection {id} is missing {field} or dataExchangeJobId — denying resubmit.',
                ['id' => $rejectionId, 'field' => $sourceField]
            );
            return false;
        }

        $tenantId    = (string) ($rejection['tenant_id'] ?? '');
        $originalJob = $this->loadOriginalJob(jobId: $originalJobId, tenantId: $tenantId);

        if ($originalJob === null) {
            $this->logger->warning(
                '[RejectionResubmitGuard] Originating DataExchangeJob {job} for rejection {id} could not be '
                .'resolved — denying resubmit.',
                ['job' => $originalJobId, 'id' => $rejectionId]
            );
            return false;
        }

        $newJobId = $this->createResubmissionJob(
            originalJob: $originalJob,
            sourceKind: $sourceKind,
            sourceObjectId: $sourceObjectId,
            actor: $actor,
            tenantId: $tenantId
        );

        if ($newJobId === null) {
            $this->logger->error(
                '[RejectionResubmitGuard] Failed to create the resubmission DataExchangeJob for rejection {id} — '
                .'denying resubmit.',
                ['id' => $rejectionId]
            );
            return false;
        }

        // Server-side only — never trust a caller-supplied resubmittedJobId for
        // this compliance-sensitive link (mirrors MunicipalityFeedbackGuard's
        // recordedBy stamping).
        $payload = $transitionContext['payload'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        $payload['resubmittedJobId']  = $newJobId;
        $transitionContext['payload'] = $payload;

        return true;

    }//end check()

    /**
     * Load the originating DataExchangeJob referenced by an ExchangeRejection.
     *
     * @param string $jobId    UUID of the originating DataExchangeJob.
     * @param string $tenantId Tenant ID to enforce as a mandatory filter.
     *
     * @return array<string,mixed>|null The job data, or null if not found.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
     */
    private function loadOriginalJob(string $jobId, string $tenantId): ?array
    {
        $filters = ['id' => $jobId];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::JOB_SCHEMA,
                'filters'  => $filters,
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

    }//end loadOriginalJob()

    /**
     * Create the single-record DataExchangeJob scoped to this rejection's
     * source object.
     *
     * @param array<string,mixed> $originalJob    The originating DataExchangeJob data.
     * @param string              $sourceKind     Resolved sourceKind (== the scope.schema slug to reuse).
     * @param string              $sourceObjectId UUID of the rejection's source object.
     * @param string              $actor          NC user ID of the requester (requestedBy).
     * @param string              $tenantId       Tenant ID.
     *
     * @return string|null UUID of the newly-created job, or null on failure.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
     */
    private function createResubmissionJob(
        array $originalJob,
        string $sourceKind,
        string $sourceObjectId,
        string $actor,
        string $tenantId,
    ): ?string {
        $newJob = [
            'direction'        => 'export',
            'target'           => $originalJob['target'] ?? '',
            'mappingProfileId' => $originalJob['mappingProfileId'] ?? null,
            'scope'            => [
                'schema'   => $sourceKind,
                'filters'  => ['id' => $sourceObjectId],
                'cohortId' => null,
                'period'   => null,
            ],
            'requestedBy'      => $actor,
            'requestedAt'      => date('c'),
            'lifecycle'        => 'queued',
            'tenant_id'        => $tenantId,
        ];

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::JOB_SCHEMA,
            object: $newJob
        );

        if ($saved === null) {
            return null;
        }

        $savedJob = $saved;
        if (is_array($saved) === false) {
            $savedJob = $saved->jsonSerialize();
        }

        $newJobId = $savedJob['id'] ?? ($savedJob['uuid'] ?? null);

        if (is_string($newJobId) === false || $newJobId === '') {
            return null;
        }

        return $newJobId;

    }//end createResubmissionJob()

    /**
     * Whether the acting user is in one of the authorised groups.
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in admin / coordinator.
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
     */
    private function actorIsAuthorised(string $actor): bool
    {
        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::AUTHORISED_GROUPS)) > 0;

    }//end actorIsAuthorised()
}//end class
