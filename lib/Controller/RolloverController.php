<?php

/**
 * Scholiq Rollover Controller
 *
 * Endpoints for the school-year rollover wizard: propose a default mapping,
 * compute a side-effect-free preview (storing the dryRunReport and advancing the
 * plan to `previewed`). Plan create/execute are plain OpenRegister object writes
 * + lifecycle transitions per ADR-022; this controller only owns the
 * proposal-and-preview computation, which the generic object API cannot express.
 *
 * Authorized via the ADR-023 action matrix (`rollover.plan`, admin / configured
 * coordinator group) — NOT a plain `@NoAdminRequired` pass-through.
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
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\RolloverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Default-mapping proposal + side-effect-free preview for the rollover wizard.
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */
class RolloverController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request         HTTP request.
     * @param IUserSession      $userSession     Current user session.
     * @param ActionAuthService $actionAuth      ADR-023 action authorization.
     * @param RolloverService   $rolloverService Rollover logic.
     * @param ObjectService     $objectService   OR object query/persistence.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly RolloverService $rolloverService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Propose a default per-cohort mapping for a from-year.
     *
     * Loads the from-year cohorts for the tenant and returns the leerjaar-increment
     * default mapping; unparseable cohort names map to a null action the wizard must
     * resolve.
     *
     * @param string $fromAcademicYear The year being rolled out of.
     *
     * @return JSONResponse The proposed mappings.
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    #[NoAdminRequired]
    public function proposeMapping(string $fromAcademicYear=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'rollover.plan');

        if ($fromAcademicYear === '') {
            return new JSONResponse(data: ['error' => 'fromAcademicYear is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $cohorts = $this->objectService->findAll(
            [
                'register' => 'scholiq',
                'schema'   => 'cohort',
                'filters'  => ['academicYear' => $fromAcademicYear],
            ]
        );

        $fromCohorts = [];
        foreach ($cohorts as $row) {
            if (is_array($row) === true) {
                $fromCohorts[] = $row;
            } else {
                $fromCohorts[] = (array) $row->jsonSerialize();
            }
        }

        return new JSONResponse(data: ['mappings' => $this->rolloverService->proposeDefaultMapping(fromCohorts: $fromCohorts)]);
    }//end proposeMapping()

    /**
     * Compute the preview for a plan and advance it to `previewed`.
     *
     * The preview is side-effect-free except for storing the resulting
     * `dryRunReport` on the plan and transitioning `draft → previewed`. A blocked
     * preview (unresolved null mapping action) is returned but does NOT advance the
     * plan, keeping the dry-run gate structural.
     *
     * @param string $planId UUID of the draft RolloverPlan.
     *
     * @return JSONResponse The dry-run report (+ blocked flag).
     *
     * @spec openspec/changes/school-year-rollover/tasks.md
     */
    #[NoAdminRequired]
    public function preview(string $planId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'rollover.plan');

        if ($planId === '') {
            return new JSONResponse(data: ['error' => 'planId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $planObj = $this->objectService->find(id: $planId, register: 'scholiq', schema: 'rollover-plan');
        if ($planObj === null) {
            return new JSONResponse(data: ['error' => 'Plan not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $plan   = $planObj->jsonSerialize();
        $report = $this->rolloverService->preview(plan: $plan);

        $plan['dryRunReport'] = $report;

        // Only advance to `previewed` when the preview is not blocked; a blocked
        // preview keeps the plan in draft so it cannot be executed.
        if (($report['blocked'] ?? false) === false) {
            $plan['lifecycle'] = 'previewed';
        }

        $this->objectService->saveObject(register: 'scholiq', schema: 'rollover-plan', object: $plan);

        return new JSONResponse(data: ['report' => $report, 'blocked' => ($report['blocked'] ?? false)]);
    }//end preview()
}//end class
