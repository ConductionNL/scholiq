<?php

/**
 * Scholiq Assessment Publish Guard
 *
 * Lifecycle guard for the Assessment schema's `publish` transition. Enforces that:
 * 1. The assessment has at least one item reference (itemRefs is non-empty).
 * 2. When proctoring.flagReviewMode is `ai-assisted`, an AiFeature with slug
 *    `assessment-ai-proctor-review` exists in the `enabled` state (ADR-005 DPO gate).
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Assessment → AiFeature) and conditional logic.
 * Referenced from the Assessment schema's x-openregister-lifecycle.transitions.publish.requires
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Assessment `publish` transition.
 *
 * An Assessment may only be published when:
 * - It has at least one item reference (itemRefs is non-empty).
 * - If proctoring.flagReviewMode is `ai-assisted`, an AiFeature with slug
 *   `assessment-ai-proctor-review` exists in the `enabled` state (ADR-005).
 */
class AssessmentPublishGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * AiFeature slug required for AI-assisted proctoring flag review.
     */
    private const AI_PROCTOR_SLUG = 'assessment-ai-proctor-review';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for AiFeature lookup.
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
     * transition on an Assessment object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Assessment data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'published'
     *
     * @return bool True if the Assessment may be published; false blocks the transition (HTTP 422).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $tenantId = $object['tenant_id'] ?? '';
        $itemRefs = $object['itemRefs'] ?? [];

        if (empty($itemRefs) === true) {
            $this->logger->info(
                '[AssessmentPublishGuard] Assessment has no itemRefs; blocking publish.'
            );
            return false;
        }

        $proctoring     = $object['proctoring'] ?? null;
        $flagReviewMode = null;
        if (is_array($proctoring) === true) {
            $flagReviewMode = $proctoring['flagReviewMode'] ?? 'manual';
        }

        if ($flagReviewMode === 'ai-assisted') {
            // H1: scope AiFeature lookup to the same tenant.
            $aiFilters = ['slug' => self::AI_PROCTOR_SLUG, 'lifecycle' => 'enabled'];
            if ($tenantId !== '') {
                $aiFilters['tenant_id'] = $tenantId;
            }

            $aiFeatures = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => 'AiFeature',
                    'filters'  => $aiFilters,
                    'limit'    => 1,
                ]
            );

            if (empty($aiFeatures) === true) {
                $this->logger->info(
                    '[AssessmentPublishGuard] flagReviewMode is ai-assisted but no enabled AiFeature '
                    .'with slug "{slug}" found; blocking publish (ADR-005 DPO gate).',
                    ['slug' => self::AI_PROCTOR_SLUG]
                );
                return false;
            }
        }//end if

        return true;
    }//end check()
}//end class
