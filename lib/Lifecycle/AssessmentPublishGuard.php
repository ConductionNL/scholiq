<?php

/**
 * Scholiq Assessment Publish Guard
 *
 * Lifecycle guard for the Assessment schema's `publish` transition. Enforces that:
 * 1. The assessment has a resolvable item source: either itemRefs is non-empty
 *    (itemSelectionMode `fixed`), or itemPoolConfig.itemBankId resolves to an
 *    ItemBank with at least drawCount `published` Items across at least
 *    drawCount DISTINCT variantGroupId groups, after applying the configured
 *    subjectTags/difficulty filters (itemSelectionMode `random-draw`,
 *    assessment-item-pools-and-analysis).
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
 * Locality gate (sovereign-ai-guarantee): AFTER the DPO-enablement check above
 * passes, this guard additionally calls {@see AiLocalityClassifier} and
 * {@see \OCA\Scholiq\Service\SovereigntyPolicyService} — a school-verifiable
 * locality guarantee composed on top of the existing DPO gate, not a second
 * `x-openregister-lifecycle.requires` entry (verified at HEAD:
 * `LifecycleAnnotationValidator`/`LifecycleGuardRegistry` resolve `requires` as
 * a single DI-tag string, never an array, in this register — the
 * `ReportPeriodLockGuard` precedent for "two checks, one guard class"). A
 * `false` result from `SovereigntyPolicyService::isCompliant()` blocks the
 * transition with a log message distinct from the DPO-enablement block, so an
 * admin can tell the two failure reasons apart.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (Assessment → Hermiq agentaifeature, and cross-app locality
 * classification) and conditional logic. Referenced from the Assessment schema's
 * x-openregister-lifecycle.transitions.publish.requires in scholiq_register.json.
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
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\AiLocalityClassifier;
use OCA\Scholiq\Service\ItemPoolFilter;
use OCA\Scholiq\Service\SovereigntyPolicyService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the Assessment `publish` transition.
 *
 * An Assessment may only be published when:
 * - It has a resolvable item source: itemRefs is non-empty (fixed), or
 *   itemPoolConfig resolves to enough distinct-variant-group Items
 *   (random-draw).
 * - If proctoring.flagReviewMode is `ai-assisted`, the AI feature with slug
 *   `assessment-ai-proctor-review` is `enabled` in Hermiq's central governance
 *   register (ADR-005). Governance is delegated to Hermiq.
 * - If proctoring.flagReviewMode is `ai-assisted` AND the feature is
 *   DPO-enabled, the active AI provider's classified locality
 *   ({@see AiLocalityClassifier}) MUST also comply with the school's
 *   {@see SovereigntyPolicyService} `SovereigntyPolicy` (sovereign-ai-guarantee).
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-publishing-an-assessment-requires-a-resolvable-item-source
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
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
     * OR schema slug for Item objects (assessment-item-pools-and-analysis).
     */
    private const ITEM_SCHEMA = 'item';

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService            OR object service for the Hermiq AI-feature
     *                                                           lookup and, since
     *                                                           assessment-item-pools-and-analysis, the
     *                                                           random-draw item-pool resolvability check.
     * @param ItemPoolFilter           $poolFilter               Pool filter/variant-grouping collaborator (assessment-item-pools-and-analysis).
     * @param IAppManager              $appManager               Used to tell "Hermiq absent" from "feature not enabled".
     * @param AiLocalityClassifier     $localityClassifier       Derives the active AI provider's locality verdict (sovereign-ai-guarantee).
     * @param SovereigntyPolicyService $sovereigntyPolicyService Evaluates the locality verdict against the
     *                                                           school's SovereigntyPolicy (sovereign-ai-guarantee).
     * @param LoggerInterface          $logger                   PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ItemPoolFilter $poolFilter,
        private readonly IAppManager $appManager,
        private readonly AiLocalityClassifier $localityClassifier,
        private readonly SovereigntyPolicyService $sovereigntyPolicyService,
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
        $object = $transitionContext['object'] ?? [];

        if ($this->hasResolvableItemSource(assessment: $object) === false) {
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

        // DPO-enablement passed. Sovereign-ai-guarantee: the AI feature is
        // governed, but is it running somewhere this school has agreed to
        // accept? A distinct log message from the DPO-gate block above so an
        // admin can tell the two failure reasons apart.
        $verdict = $this->localityClassifier->classifyActiveProvider();
        if ($this->sovereigntyPolicyService->isCompliant(locality: $verdict['locality'], verified: $verdict['verified']) === false) {
            $verifiedLabel = 'false';
            if ($verdict['verified'] === true) {
                $verifiedLabel = 'true';
            }

            $this->logger->info(
                '[AssessmentPublishGuard] DPO-enabled but locality violates SovereigntyPolicy — '
                .'blocking publish. Verdict: locality={locality}, verified={verified}. {evidence}',
                ['locality' => $verdict['locality'], 'verified' => $verifiedLabel, 'evidence' => $verdict['evidence']]
            );
            return false;
        }

        return true;
    }//end check()

    /**
     * Whether the Assessment has a resolvable item source for publish.
     *
     * `itemSelectionMode: fixed` (the default) keeps the existing rule:
     * itemRefs must be non-empty. `itemSelectionMode: random-draw` requires
     * itemPoolConfig.itemBankId to resolve to at least drawCount `published`
     * Items across at least drawCount DISTINCT variantGroupId groups, after
     * applying the configured subjectTags/difficulty filters — mirroring the
     * exact exclusivity rule AssessmentDrawResolver enforces at attempt time,
     * so a "sufficient at publish" bank can never immediately produce a
     * fail-closed empty draw.
     *
     * @param array<string,mixed> $assessment Assessment data.
     *
     * @return bool
     *
     * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-publishing-an-assessment-requires-a-resolvable-item-source
     */
    private function hasResolvableItemSource(array $assessment): bool
    {
        $itemSelectionMode = $assessment['itemSelectionMode'] ?? 'fixed';

        if ($itemSelectionMode !== 'random-draw') {
            $itemRefs = $assessment['itemRefs'] ?? [];
            if (empty($itemRefs) === true) {
                $this->logger->info(
                    '[AssessmentPublishGuard] Assessment has no itemRefs; blocking publish.'
                );
                return false;
            }

            return true;
        }

        $poolConfig = $assessment['itemPoolConfig'] ?? null;
        if (is_array($poolConfig) === false) {
            $this->logger->info(
                '[AssessmentPublishGuard] itemSelectionMode is random-draw but itemPoolConfig is not '
                .'set; blocking publish.'
            );
            return false;
        }

        $itemBankId = $poolConfig['itemBankId'] ?? null;
        $drawCount  = (int) ($poolConfig['drawCount'] ?? 0);
        if ($itemBankId === null || $drawCount < 1) {
            $this->logger->info(
                '[AssessmentPublishGuard] itemPoolConfig is missing itemBankId or a valid drawCount; '
                .'blocking publish.'
            );
            return false;
        }

        $distinctGroupCount = $this->countDistinctVariantGroups(poolConfig: $poolConfig, itemBankId: $itemBankId);

        if ($distinctGroupCount < $drawCount) {
            $this->logger->info(
                '[AssessmentPublishGuard] ItemBank {bank} has only {found} distinct variant group(s) '
                .'matching the configured filters, fewer than drawCount {drawCount}; blocking publish.',
                ['bank' => $itemBankId, 'found' => $distinctGroupCount, 'drawCount' => $drawCount]
            );
            return false;
        }

        return true;

    }//end hasResolvableItemSource()

    /**
     * Count the number of DISTINCT variantGroupId groups among `published`
     * Items in the given bank matching the pool's subjectTags/difficulty
     * filters. An Item with no variantGroupId counts as its own singleton
     * group — delegates the filter/group logic to ItemPoolFilter, the SAME
     * collaborator AssessmentDrawResolver draws from, so "sufficient at
     * publish" and "resolvable at attempt time" can never disagree.
     *
     * @param array<string,mixed> $poolConfig itemPoolConfig (subjectTags, difficultyMin/Max).
     * @param string              $itemBankId UUID of the ItemBank.
     *
     * @return int
     */
    private function countDistinctVariantGroups(array $poolConfig, string $itemBankId): int
    {
        $items = $this->objectService->findAll(
            [
                'register' => 'scholiq',
                'schema'   => self::ITEM_SCHEMA,
                'filters'  => ['itemBankId' => $itemBankId, 'lifecycle' => 'published'],
            ]
        );

        $groups = $this->poolFilter->filterAndGroupByVariant(items: $items, poolConfig: $poolConfig);

        return count($groups);

    }//end countDistinctVariantGroups()
}//end class
