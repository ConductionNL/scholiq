<?php

/**
 * Scholiq Peer Review Controller
 *
 * One action: `allocate`. Delegates to `PeerReviewAllocationService`, the
 * genuine batch-matching business logic OpenRegister's generic object API
 * cannot perform (ADR-022). Authorized by an explicit per-object check — the
 * caller must be `admin` OR a listed teacher on the Assignment's own Cohort
 * (resolved directly via `Assignment.cohortId`, or via
 * `Assignment.sessionId` -> `Session.cohortId` when the Assignment is only
 * attached at Session level) — never a bare `#[NoAdminRequired]`
 * authenticated-user gate, per the architecture rule on new controller
 * methods (design.md "Reviewer Allocation").
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\PeerReview\PeerReviewAllocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Reviewer-allocation endpoint for the peer-and-self-assessment feature.
 *
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */
class PeerReviewController extends Controller
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const ASSIGNMENT_SCHEMA = 'assignment';
    private const SESSION_SCHEMA    = 'session';
    private const COHORT_SCHEMA     = 'cohort';

    /**
     * Constructor.
     *
     * @param IRequest                    $request           HTTP request.
     * @param IUserSession                $userSession       Current user session.
     * @param IGroupManager               $groupManager      Group manager (admin check).
     * @param ObjectService               $objectService     OR object query/persistence.
     * @param PeerReviewAllocationService $allocationService Reviewer allocation logic.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ObjectService $objectService,
        private readonly PeerReviewAllocationService $allocationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Allocate PeerReview rows for an Assignment's Submissions.
     *
     * @param string $assignmentId UUID of the Assignment.
     *
     * @return JSONResponse The allocation summary, or an error/denial response.
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
     */
    #[NoAdminRequired]
    public function allocate(string $assignmentId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if ($assignmentId === '') {
            return new JSONResponse(data: ['error' => 'assignmentId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $assignment = $this->fetchObject(id: $assignmentId, schema: self::ASSIGNMENT_SCHEMA);
        if ($assignment === null) {
            return new JSONResponse(data: ['error' => 'Assignment not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        if ($this->canAllocate(user: $user, assignment: $assignment) === false) {
            return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
        }

        $result = $this->allocationService->allocate(assignmentId: $assignmentId);

        return new JSONResponse(data: ['result' => $result]);
    }//end allocate()

    /**
     * True when the caller is admin, or a listed teacher on the Assignment's own
     * Cohort (direct `cohortId`, or via `sessionId` -> `Session.cohortId`).
     *
     * @param IUser               $user       The authenticated caller.
     * @param array<string,mixed> $assignment The Assignment data array.
     *
     * @return bool
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
     */
    private function canAllocate(IUser $user, array $assignment): bool
    {
        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return true;
        }

        $cohortId = $assignment['cohortId'] ?? null;
        if (is_string($cohortId) === false || $cohortId === '') {
            $cohortId = $this->resolveCohortIdViaSession(assignment: $assignment);
        }

        if ($cohortId === null || $cohortId === '') {
            return false;
        }

        $cohort = $this->fetchObject(id: $cohortId, schema: self::COHORT_SCHEMA);
        if ($cohort === null) {
            return false;
        }

        $teacherIds = $cohort['teacherIds'] ?? [];
        if (is_array($teacherIds) === false) {
            return false;
        }

        return in_array($user->getUID(), $teacherIds, true) === true;
    }//end canAllocate()

    /**
     * Resolve a Cohort id via the Assignment's `sessionId` -> `Session.cohortId`,
     * used when the Assignment has no direct `cohortId`.
     *
     * @param array<string,mixed> $assignment The Assignment data array.
     *
     * @return string|null
     */
    private function resolveCohortIdViaSession(array $assignment): ?string
    {
        $sessionId = $assignment['sessionId'] ?? null;
        if (is_string($sessionId) === false || $sessionId === '') {
            return null;
        }

        $session = $this->fetchObject(id: $sessionId, schema: self::SESSION_SCHEMA);
        if ($session === null) {
            return null;
        }

        $cohortId = $session['cohortId'] ?? null;
        if (is_string($cohortId) === false || $cohortId === '') {
            return null;
        }

        return $cohortId;
    }//end resolveCohortIdViaSession()

    /**
     * Fetch an object by id/schema, normalising to an array whether OR returns an
     * array or an object exposing jsonSerialize().
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        $obj = $this->objectService->find(
            id: $id,
            register: self::SCHOLIQ_REGISTER,
            schema: $schema
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $serialized = $obj->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end fetchObject()
}//end class
