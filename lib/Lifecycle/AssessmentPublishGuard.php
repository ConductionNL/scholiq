<?php

/**
 * Scholiq Assessment Publish Guard
 *
 * Lifecycle guard for the Assessment schema's `publish` transition. Enforces that:
 * 1. The assessment has at least one item reference (itemRefs is non-empty).
 * 2. When proctoring.flagReviewMode is `ai-assisted`, the high-risk AI feature
 *    with slug `assessment-ai-proctor-review` is registered and DPO-enabled in
 *    the central Hermiq governance register (EU AI Act, ADR-005 DPO gate).
 *
 * Governance delegation (ai-feature-delegate-to-hermiq): Scholiq no longer owns
 * an AiFeature governance register — the EU AI Act high-risk inventory, its
 * DPO-acknowledgement lifecycle, and enablement live in the Hermiq app's
 * `agentaifeature` register. This guard therefore delegates its DPO-gate lookup
 * to Hermiq and degrades gracefully:
 *   - Hermiq not installed              → block, "install Hermiq to govern this AI feature".
 *   - Hermiq installed, feature absent  → block, "DPO has not enabled the feature".
 * It fails CLOSED for the high-risk AI path (you cannot publish an AI-proctored
 * assessment without the governance app) but never fatals the app: only the
 * `ai-assisted` proctoring path is gated; manual proctoring (the default) and
 * every other transition are untouched.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Assessment → Hermiq agentaifeature) and conditional logic.
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
 * @spec openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the Assessment `publish` transition.
 *
 * An Assessment may only be published when:
 * - It has at least one item reference (itemRefs is non-empty).
 * - If proctoring.flagReviewMode is `ai-assisted`, the AI feature with slug
 *   `assessment-ai-proctor-review` is `enabled` in Hermiq's central governance
 *   register (ADR-005). Governance is delegated to Hermiq.
 */
class AssessmentPublishGuard
{

    /**
     * App id of the central AI governance app (Hermiq).
     */
    private const HERMIQ_APP_ID = 'hermiq';

    /**
     * OR register slug that holds the delegated AI-feature governance inventory.
     */
    private const HERMIQ_REGISTER = 'hermiq';

    /**
     * OR schema slug of Hermiq's high-risk AI-feature inventory entry.
     */
    private const HERMIQ_AI_FEATURE_SCHEMA = 'agentaifeature';

    /**
     * AiFeature slug required for AI-assisted proctoring flag review.
     */
    private const AI_PROCTOR_SLUG = 'assessment-ai-proctor-review';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for the Hermiq AI-feature lookup.
     * @param IAppManager     $appManager    Used to tell "Hermiq absent" from "feature not enabled".
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppManager $appManager,
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
     * @spec openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
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

        if ($flagReviewMode !== 'ai-assisted') {
            return true;
        }

        // AI-assisted proctoring is a high-risk AI use — its DPO gate is governed
        // centrally by Hermiq (ADR-005). Fail closed when Hermiq is unavailable.
        if ($this->appManager->isInstalled(self::HERMIQ_APP_ID) === false) {
            $this->logger->info(
                '[AssessmentPublishGuard] flagReviewMode is ai-assisted but the Hermiq governance '
                .'app is not installed; blocking publish. Install and enable Hermiq, then have the '
                .'DPO enable the "{slug}" AI feature (ADR-005 DPO gate).',
                ['slug' => self::AI_PROCTOR_SLUG]
            );
            return false;
        }

        $aiFeatures = $this->objectService->findAll(
            [
                'register' => self::HERMIQ_REGISTER,
                'schema'   => self::HERMIQ_AI_FEATURE_SCHEMA,
                'filters'  => ['slug' => self::AI_PROCTOR_SLUG, 'lifecycle' => 'enabled'],
                'limit'    => 1,
            ]
        );

        if (empty($aiFeatures) === true) {
            $this->logger->info(
                '[AssessmentPublishGuard] flagReviewMode is ai-assisted but no enabled Hermiq AI '
                .'feature with slug "{slug}" was found; blocking publish (ADR-005 DPO gate). Register '
                .'and DPO-enable it in the Hermiq AI-feature register.',
                ['slug' => self::AI_PROCTOR_SLUG]
            );
            return false;
        }

        return true;
    }//end check()
}//end class
