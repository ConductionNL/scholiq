<?php

/**
 * Scholiq Learning Plan Signature Guard
 *
 * Lifecycle guard for the LearningPlan schema's `draft → active` transition.
 * Blocks activation unless every role declared in the template's
 * `requiredSignerRoles` has a Signature on THIS plan version with at least the
 * minimum required assurance level.
 *
 * Assurance minimum rules (configurable via guard constants):
 *   - `parent` role on `opp` plan: SUBSTANTIAL
 *   - all other roles: BASIC
 *
 * On successful activation this guard also transitions the prior version
 * (identified by `supersedesId`) to `superseded` via TransitionEngine, so the
 * version chain is atomically maintained.
 *
 * ADR-031 legitimate exception: multi-schema guard logic (LearningPlan →
 * LearningPlanTemplate + Signature + TransitionEngine) cannot be expressed as
 * schema metadata declarations.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use Psr\Log\LoggerInterface;

/**
 * Guards the LearningPlan `draft → active` lifecycle transition.
 *
 * Single responsibility: verify that all required signers have signed this
 * version with sufficient assurance, then supersede the prior version.
 */
class LearningPlanSignatureGuard
{

    /**
     * Scholiq register slug.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Minimum assurance level for most roles.
     *
     * Levels ordered ascending: none < basic < substantial < high
     */
    private const DEFAULT_MINIMUM_ASSURANCE = 'basic';

    /**
     * Minimum assurance for the `parent` role on an `opp` plan.
     */
    private const OPP_PARENT_MINIMUM_ASSURANCE = 'substantial';

    /**
     * Ordered assurance levels for comparison.
     *
     * @var string[]
     */
    private const ASSURANCE_ORDER = ['none', 'basic', 'substantial', 'high'];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object query service.
     * @param TransitionEngine $transitionEngine OR lifecycle transition engine.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert all required signers have signed this version; supersede prior on pass.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `draft → active` transition. Returns false (HTTP 422) when the
     * co-sign pre-condition is not yet satisfied.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the LearningPlan data array
     *                                               - 'transition' : 'activate'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'active'
     *
     * @return bool True when all required roles have signed with sufficient assurance.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    public function check(array &$transitionContext): bool
    {
        $plan         = $transitionContext['object'] ?? [];
        $planId       = $plan['id'] ?? ($plan['uuid'] ?? '');
        $templateId   = $plan['templateId'] ?? null;
        $version      = (int) ($plan['version'] ?? 1);
        $kind         = $plan['kind'] ?? '';
        $supersedesId = $plan['supersedesId'] ?? null;
        $learnerId    = $plan['learnerId'] ?? '';

        if ($planId === '') {
            $this->logger->warning('[LearningPlanSignatureGuard] Plan has no id; blocking activation.');
            return false;
        }

        // Fetch required signer roles from the template (if any).
        $requiredRoles = $this->fetchRequiredRoles(templateId: $templateId);

        if (empty($requiredRoles) === true) {
            // No template or template has no requiredSignerRoles → no co-sign needed.
            $this->logger->info(
                '[LearningPlanSignatureGuard] No required signer roles — activating plan {id} v{v}.',
                ['id' => $planId, 'v' => $version]
            );
            $this->supersedesPriorVersion(supersedesId: $supersedesId);
            return true;
        }

        // Fetch all Signatures for this plan + version.
        $signatures = $this->fetchSignatures(planId: $planId, version: $version);

        // #180: filter 'parent' signatures to those whose signerId is in LearnerProfile.parentIds
        // so self-claimed parent roles cannot satisfy the co-sign gate.
        $signatures = $this->filterVerifiedParentSignatures(signatures: $signatures, learnerId: $learnerId);

        // Index by signerRole (keep highest assurance per role).
        $sigsByRole = $this->indexByRole(signatures: $signatures);

        // Check every required role.
        foreach ($requiredRoles as $role) {
            $minimum = $this->minimumAssurance(role: $role, kind: $kind);
            $sig     = $sigsByRole[$role] ?? null;

            if ($sig === null) {
                $this->logger->info(
                    '[LearningPlanSignatureGuard] Missing signature for role {role} on plan {id} v{v}.',
                    ['role' => $role, 'id' => $planId, 'v' => $version]
                );
                return false;
            }

            $sigAssurance = $sig['assuranceLevel'] ?? 'none';
            if ($this->assuranceSatisfies(actual: $sigAssurance, minimum: $minimum) === false) {
                $this->logger->info(
                    '[LearningPlanSignatureGuard] Role {role}: assurance {actual} < minimum {min} on plan {id} v{v}.',
                    ['role' => $role, 'actual' => $sigAssurance, 'min' => $minimum, 'id' => $planId, 'v' => $version]
                );
                return false;
            }
        }//end foreach

        $this->logger->info(
            '[LearningPlanSignatureGuard] All {n} required roles satisfied — activating plan {id} v{v}.',
            ['n' => count($requiredRoles), 'id' => $planId, 'v' => $version]
        );

        // Supersede prior version now that this version is activating.
        $this->supersedesPriorVersion(supersedesId: $supersedesId);

        return true;

    }//end check()

    /**
     * Fetch the requiredSignerRoles from the LearningPlanTemplate.
     *
     * @param string|null $templateId Template UUID or null.
     *
     * @return string[] Empty array when no template or no roles defined.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    private function fetchRequiredRoles(?string $templateId): array
    {
        if ($templateId === null || $templateId === '') {
            return [];
        }

        $templates = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'learning-plan-template',
                'filters'  => ['uuid' => $templateId],
                'limit'    => 1,
            ]
        );

        if (empty($templates) === true) {
            return [];
        }

        $template = $templates[0];
        if (is_array($templates[0]) === false) {
            $template = $templates[0]->jsonSerialize();
        }

        return $template['requiredSignerRoles'] ?? [];

    }//end fetchRequiredRoles()

    /**
     * Fetch all Signature objects for the given plan and version.
     *
     * @param string $planId  LearningPlan UUID.
     * @param int    $version Plan version number.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    private function fetchSignatures(string $planId, int $version): array
    {
        $raw = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'signature',
                'filters'  => [
                    'subjectId'      => $planId,
                    'subjectVersion' => $version,
                ],
                'limit'    => 200,
            ]
        );

        $result = [];
        foreach ($raw as $item) {
            $row = $item;
            if (is_array($item) === false) {
                $row = $item->jsonSerialize();
            }

            $result[] = $row;
        }

        return $result;

    }//end fetchSignatures()

    /**
     * Verify that signers claiming the 'parent' role are registered parents of the learner.
     *
     * Filters the signature list so only signatures where the signerId appears in the
     * learner's LearnerProfile.parentIds array count as valid 'parent' co-signs. Fixes #180.
     *
     * Other roles (teacher, student, supervisor, etc.) are passed through unchanged.
     *
     * @param array<int,array<string,mixed>> $signatures Raw signature objects.
     * @param string                         $learnerId  The learner's ID from the LearningPlan.
     *
     * @return array<int,array<string,mixed>> Filtered signature objects.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    private function filterVerifiedParentSignatures(array $signatures, string $learnerId): array
    {
        if ($learnerId === '' || empty($signatures) === true) {
            return $signatures;
        }

        // Load the LearnerProfile to resolve the authoritative parentIds.
        $profiles = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'learner-profile',
                'filters'  => ['learnerId' => $learnerId],
                'limit'    => 1,
            ]
        );

        $authorisedParentIds = [];
        if (empty($profiles) === false) {
            $profile = $profiles[0];
            if (is_array($profiles[0]) === false) {
                $profile = $profiles[0]->jsonSerialize();
            }

            $authorisedParentIds = $profile['parentIds'] ?? [];
        }

        $filtered = [];
        foreach ($signatures as $sig) {
            if (($sig['signerRole'] ?? '') !== 'parent') {
                // Non-parent roles pass through — only 'parent' needs join-verification.
                $filtered[] = $sig;
                continue;
            }

            $signerId = $sig['signerId'] ?? ($sig['userId'] ?? '');
            if (in_array($signerId, $authorisedParentIds, strict: true) === true) {
                // Signer is a registered parent of this learner — accept.
                $filtered[] = $sig;
                continue;
            }

            $this->logger->warning(
                '[LearningPlanSignatureGuard] Parent co-sign from {signer} rejected — not in LearnerProfile.parentIds for learner {learner}.',
                ['signer' => $signerId, 'learner' => $learnerId]
            );
        }//end foreach

        return $filtered;

    }//end filterVerifiedParentSignatures()

    /**
     * Index signatures by signerRole, keeping the highest assurance per role.
     *
     * #198: when multiple signatures exist for the same role and the later one has a
     * higher assurance level than an existing one, we log a warning so the audit trail
     * preserves evidence of the potential forgery. The guard still uses the highest
     * assurance for the gate check (so legitimate upgrades also work), but the warning
     * makes the replacement visible in server logs for compliance review.
     *
     * @param array<int,array<string,mixed>> $signatures Raw signature objects.
     *
     * @return array<string,array<string,mixed>> Map of role → signature.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    private function indexByRole(array $signatures): array
    {
        $byRole = [];
        foreach ($signatures as $sig) {
            $role      = $sig['signerRole'] ?? 'other';
            $assurance = $sig['assuranceLevel'] ?? 'none';

            if (isset($byRole[$role]) === false) {
                $byRole[$role] = $sig;
                continue;
            }

            // Keep the signature with the higher assurance.
            $existing = $byRole[$role]['assuranceLevel'] ?? 'none';
            if ($this->assuranceRank(level: $assurance) > $this->assuranceRank(level: $existing)) {
                // #198: log when a higher-assurance signature supersedes an existing one.
                // This may indicate a legitimate upgrade (DigiD step-up) or a forged
                // over-ride; the compliance officer can review both signatures in OR's
                // audit trail.
                $this->logger->warning(
                    '[LearningPlanSignatureGuard] Higher-assurance signature for role {role} supersedes existing '
                    .'(old={old}, new={new}) — verify this is a legitimate assurance upgrade.',
                    [
                        'role' => $role,
                        'old'  => $existing,
                        'new'  => $assurance,
                    ]
                );
                $byRole[$role] = $sig;
            }
        }//end foreach

        return $byRole;

    }//end indexByRole()

    /**
     * Determine the minimum required assurance for a role.
     *
     * @param string $role The signer role.
     * @param string $kind The plan kind.
     *
     * @return string Assurance level string.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-16
     */
    private function minimumAssurance(string $role, string $kind): string
    {
        if ($role === 'parent' && $kind === 'opp') {
            return self::OPP_PARENT_MINIMUM_ASSURANCE;
        }

        return self::DEFAULT_MINIMUM_ASSURANCE;

    }//end minimumAssurance()

    /**
     * Check whether actual assurance level satisfies the minimum.
     *
     * @param string $actual  The actual assurance level on the signature.
     * @param string $minimum The minimum required assurance level.
     *
     * @return bool True when actual >= minimum.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-16
     */
    private function assuranceSatisfies(string $actual, string $minimum): bool
    {
        return $this->assuranceRank(level: $actual) >= $this->assuranceRank(level: $minimum);

    }//end assuranceSatisfies()

    /**
     * Return numeric rank for an assurance level (higher = stronger).
     *
     * @param string $level Assurance level string.
     *
     * @return int Rank (0–3). Unknown levels map to 0.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-16
     */
    private function assuranceRank(string $level): int
    {
        $rank = array_search($level, self::ASSURANCE_ORDER, strict: true);
        if ($rank === false) {
            return 0;
        }

        return (int) $rank;

    }//end assuranceRank()

    /**
     * Transition the superseded plan version to `superseded` lifecycle state.
     *
     * Best-effort: if the prior version is not found or the transition fails,
     * this guard still returns true (the activation is not blocked by prior-version
     * housekeeping). Errors are logged.
     *
     * @param string|null $supersedesId UUID of the LearningPlan version to supersede.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-15
     */
    private function supersedesPriorVersion(?string $supersedesId): void
    {
        if ($supersedesId === null || $supersedesId === '') {
            return;
        }

        try {
            $this->transitionEngine->transition(objectId: $supersedesId, action: 'supersede');
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[LearningPlanSignatureGuard] Could not supersede prior plan version {id}: {msg}',
                ['id' => $supersedesId, 'msg' => $e->getMessage()]
            );
        }

    }//end supersedesPriorVersion()
}//end class
