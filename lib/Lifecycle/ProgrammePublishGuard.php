<?php

/**
 * Scholiq Programme Publish Guard
 *
 * Lifecycle guard for the Programme schema's `publish` transition. Enforces that a
 * Programme has an assigned CurriculumPlan and that the plan is published with at
 * least one required course before the Programme itself may be published.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Programme schema's x-openregister-lifecycle.transitions.publish.requires
 * in scholiq_register.json.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Programme `publish` transition.
 *
 * A Programme may only be published when:
 * 1. It has an assigned CurriculumPlan (curriculumPlanId is set).
 * 2. That CurriculumPlan is in lifecycle state `published`.
 * 3. The CurriculumPlan has at least one required course (requiredCourseIds is non-empty).
 */
class ProgrammePublishGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for querying CurriculumPlans.
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
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `publish`
     * transition on a Programme object. Returns true only when the Programme has
     * an assigned published CurriculumPlan that lists at least one required course.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Programme data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : current lifecycle state
     *                                               - 'to'         : 'published'
     *
     * @return bool True if the Programme's CurriculumPlan is published and has ≥1 required
     *              course; false blocks the transition.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-13
     */
    public function check(array &$transitionContext): bool
    {
        $object           = $transitionContext['object'] ?? [];
        $curriculumPlanId = $object['curriculumPlanId'] ?? null;
        $tenantId         = $object['tenant_id'] ?? '';

        if ($curriculumPlanId === null) {
            $this->logger->info(
                '[ProgrammePublishGuard] Programme has no CurriculumPlan assigned; blocking publish.'
            );
            return false;
        }

        // H1: scope CurriculumPlan lookup to the same tenant.
        $planFilters = ['uuid' => $curriculumPlanId, 'lifecycle' => 'published'];
        if ($tenantId !== '') {
            $planFilters['tenant_id'] = $tenantId;
        }

        $plans = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'curriculum-plan',
                'filters'  => $planFilters,
                'limit'    => 1,
            ]
        );

        if (empty($plans) === true) {
            $this->logger->info(
                '[ProgrammePublishGuard] CurriculumPlan {id} is not published; blocking Programme publish.',
                ['id' => $curriculumPlanId]
            );
            return false;
        }

        $plan = $plans[0];
        $requiredCourseIds = $plan['requiredCourseIds'] ?? [];

        if (empty($requiredCourseIds) === true) {
            $this->logger->info(
                '[ProgrammePublishGuard] CurriculumPlan {id} has no required courses; blocking Programme publish.',
                ['id' => $curriculumPlanId]
            );
            return false;
        }

        return true;
    }//end check()
}//end class
