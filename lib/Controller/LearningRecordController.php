<?php

/**
 * Scholiq Learning Record Controller
 *
 * One read endpoint: `me`. Exists because
 * `LearningRecordAggregationService::compose()` composes across nine
 * schemas whose own RBAC blocks only ever permit a self-match read of ONE
 * schema at a time (never a cross-schema composed read) — the same
 * RBAC-gap class `LeaderboardController`/`RolloverController`/
 * `PeerReviewController` already document. This controller resolves the
 * CALLER'S OWN `learnerRef` only; no other `learnerRef` is resolvable from
 * this endpoint without an `hr`/`manager`/`admin` role.
 *
 * NOT a pass-through CRUD wrapper (ADR-031 / hydra-gate-redundant-
 * controller): the three new schemas' own plain CRUD/list/filter is served
 * directly by OpenRegister's generic object API to the frontend, unchanged.
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\LearningRecordAggregationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the calling user's own composed learning-record trajectory.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-2-2
 */
class LearningRecordController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                         $request            HTTP request.
     * @param IUserSession                     $userSession        Current user session.
     * @param LearningRecordAggregationService $aggregationService Cross-schema read composition.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly LearningRecordAggregationService $aggregationService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the calling user's own composed learning-record trajectory.
     *
     * @return JSONResponse `{learnerRef, ...composition}` or an error response.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#scenario-a-learner-opens-their-aggregate-record-and-sees-composed-read-only-data
     */
    #[NoAdminRequired]
    public function mine(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $learnerRef = $this->aggregationService->resolveLearnerRefForUser(ncUserId: $user->getUID());
        if ($learnerRef === null) {
            return new JSONResponse(
                data: ['error' => 'No LearnerProfile is bound to this account.'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $composition = $this->aggregationService->compose(learnerRef: $learnerRef);

        return new JSONResponse(data: array_merge(['learnerRef' => $learnerRef], $composition));
    }//end mine()
}//end class
