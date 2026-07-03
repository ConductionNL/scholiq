<?php

/**
 * Scholiq External Training Controller
 *
 * User-invokable actions for externally-completed training records:
 *   - bulkRecord: record one classroom session for many learners at once
 *     (one record per learner sharing a batchId), and
 *   - issueCredential: optionally issue a linked manual Credential on a
 *     verified record so the certification expiry machinery covers external
 *     certificates too.
 *
 * Both endpoints are authorized via the ADR-023 action matrix
 * (ActionAuthService::requireAction) — they are NOT plain `@NoAdminRequired`
 * pass-throughs. CRUD on the record itself goes directly through OpenRegister's
 * object API per ADR-022; this controller only owns the two multi-object
 * operations that the generic object API cannot express.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\ExternalTrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Multi-object operations for external-training records.
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request         HTTP request.
     * @param IUserSession            $userSession     Current user session.
     * @param ActionAuthService       $actionAuth      ADR-023 action authorization.
     * @param ExternalTrainingService $trainingService External-training business logic.
     * @param ObjectService           $objectService   OR object query/persistence.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly ExternalTrainingService $trainingService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Bulk-record one external training for many learners.
     *
     * Authorized via the `external-training.bulk-record` action (officer / HR /
     * admin). Returns the shared batchId and the number of records created.
     *
     * @param array<string>       $learnerIds The learners attending the session.
     * @param array<string,mixed> $training   Shared training fields (title,
     *                                        provider, kind, completedAt,
     *                                        regulationSlug?, validUntil?,
     *                                        evidenceNote?, tenant_id).
     *
     * @return JSONResponse The created batchId + count, or an error.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    #[NoAdminRequired]
    public function bulkRecord(array $learnerIds=[], array $training=[]): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        // ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
        $this->actionAuth->requireAction(user: $user, action: 'external-training.bulk-record');

        if (empty($learnerIds) === true) {
            return new JSONResponse(data: ['error' => 'learnerIds is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        // The submitter is always the authenticated actor — never client-supplied.
        $training['submittedBy'] = $user->getUID();

        $batchId = $this->trainingService->bulkRecord(learnerIds: $learnerIds, shared: $training);

        if ($batchId === '') {
            return new JSONResponse(
                data: ['error' => 'Missing required training fields (title, provider, completedAt, tenant_id)'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return new JSONResponse(
            data: ['batchId' => $batchId, 'count' => count(array_unique($learnerIds))],
            statusCode: Http::STATUS_CREATED
        );
    }//end bulkRecord()

    /**
     * Issue a linked manual Credential for a verified external-training record.
     *
     * Authorized via the `external-training.issue-credential` action. The record
     * MUST be `verified`; the credential is created via OR's existing
     * `source: manual` path with `expiresAt = validUntil`, and its UUID is
     * written back onto the record as `credentialId`.
     *
     * @param string $recordId UUID of the verified external-training record.
     *
     * @return JSONResponse The new credentialId, or an error.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    #[NoAdminRequired]
    public function issueCredential(string $recordId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'external-training.issue-credential');

        if ($recordId === '') {
            return new JSONResponse(data: ['error' => 'recordId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $recordObj = $this->objectService->find(
            id: $recordId,
            register: 'scholiq',
            schema: 'external-training-record'
        );

        if ($recordObj === null) {
            return new JSONResponse(data: ['error' => 'Record not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $record = $recordObj->jsonSerialize();

        if (($record['lifecycle'] ?? '') !== 'verified') {
            return new JSONResponse(
                data: ['error' => 'Credential can only be issued for a verified record'],
                statusCode: Http::STATUS_CONFLICT
            );
        }

        if (($record['credentialId'] ?? '') !== '') {
            // Idempotent: a credential already exists for this record.
            return new JSONResponse(data: ['credentialId' => $record['credentialId']], statusCode: Http::STATUS_OK);
        }

        $payload = $this->trainingService->buildManualCredentialPayload(record: $record, issuedBy: $user->getUID());

        // Do NOT set lifecycle — OR fires the `issue` transition (and its signing
        // guard) from the initial state, mirroring CredentialIssuanceHandler.
        $saved = $this->objectService->saveObject(
            register: 'scholiq',
            schema: 'credential',
            object: $payload
        );

        $credentialId = '';
        if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
            $savedArr     = (array) $saved->jsonSerialize();
            $credentialId = (string) ($savedArr['id'] ?? ($savedArr['uuid'] ?? ''));
        } else if (is_array($saved) === true) {
            $credentialId = (string) ($saved['id'] ?? ($saved['uuid'] ?? ''));
        }

        // Write the credentialId back onto the record so the link is queryable.
        if ($credentialId !== '') {
            $record['credentialId'] = $credentialId;
            $this->objectService->saveObject(
                register: 'scholiq',
                schema: 'external-training-record',
                object: $record
            );
        }

        return new JSONResponse(data: ['credentialId' => $credentialId], statusCode: Http::STATUS_CREATED);
    }//end issueCredential()

    /**
     * Report whether a learner is covered for a regulation, and by which class.
     *
     * Powers the coverage view's per-learner evidence-class column: coverage
     * holds when the learner has a signed Attestation, a valid Credential, or a
     * verified unexpired ExternalTrainingRecord for the regulation. Authorized
     * via the same officer/HR/admin action as bulk-record; a learner querying
     * their own coverage is allowed because the action matrix admits their
     * group, and the read itself is scoped to the (learnerId, regulationSlug)
     * pair supplied — no arbitrary-object exposure beyond a boolean + class.
     *
     * @param string $learnerId      LearnerProfile UUID.
     * @param string $regulationSlug Regulation slug.
     *
     * @return JSONResponse { covered: bool, evidenceClass: string|null }.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    #[NoAdminRequired]
    public function learnerCoverage(string $learnerId='', string $regulationSlug=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'external-training.bulk-record');

        if ($learnerId === '' || $regulationSlug === '') {
            return new JSONResponse(
                data: ['error' => 'learnerId and regulationSlug are required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $evidenceClass = $this->trainingService->coveringEvidenceClass(
            learnerId: $learnerId,
            regulationSlug: $regulationSlug
        );

        return new JSONResponse(
            data: [
                'covered'       => $this->trainingService->isLearnerCovered(learnerId: $learnerId, regulationSlug: $regulationSlug),
                'evidenceClass' => $evidenceClass,
            ]
        );
    }//end learnerCoverage()
}//end class
