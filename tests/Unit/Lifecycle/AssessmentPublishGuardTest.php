<?php

/**
 * Scholiq AssessmentPublishGuard unit tests.
 *
 * Coverage for the Assessment `publish` transition guard: resolvable item
 * source (assessment-item-pools-and-analysis), the Hermiq DPO-enablement gate
 * for `ai-assisted` proctoring (ai-feature-delegate-to-hermiq), and the
 * sovereign-ai-guarantee locality-policy gate composed on top of it.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\AssessmentPublishGuard;
use OCA\Scholiq\Service\AiLocalityClassifier;
use OCA\Scholiq\Service\ItemPoolFilter;
use OCA\Scholiq\Service\SovereigntyPolicyService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AssessmentPublishGuard::check() — the Assessment
 * `draft -> published` transition.
 */
class AssessmentPublishGuardTest extends TestCase
{

    /**
     * Build a guard with explicit collaborators (all mocked unless overridden).
     *
     * @param ObjectService|null            $objectService  OR object service mock.
     * @param IAppManager|null              $appManager     App manager mock.
     * @param AiLocalityClassifier|null     $classifier     Locality classifier mock.
     * @param SovereigntyPolicyService|null $policyService  Policy service mock.
     *
     * @return AssessmentPublishGuard
     */
    private function buildGuard(
        ?ObjectService $objectService=null,
        ?IAppManager $appManager=null,
        ?AiLocalityClassifier $classifier=null,
        ?SovereigntyPolicyService $policyService=null
    ): AssessmentPublishGuard {
        $objectService ??= $this->createMock(ObjectService::class);
        $appManager    ??= $this->createMock(IAppManager::class);
        $classifier    ??= $this->createMock(AiLocalityClassifier::class);
        $policyService ??= $this->createMock(SovereigntyPolicyService::class);

        return new AssessmentPublishGuard(
            $objectService,
            new ItemPoolFilter(),
            $appManager,
            $classifier,
            $policyService,
            $this->createMock(LoggerInterface::class)
        );

    }//end buildGuard()

    /**
     * A fixed-selection Assessment with no itemRefs is refused before any
     * Hermiq/locality check ever runs.
     *
     * @return void
     */
    public function testNoItemRefsBlocksPublish(): void
    {
        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->never())->method('classifyActiveProvider');

        $guard   = $this->buildGuard(classifier: $classifier);
        $context = [
            'object'     => ['id' => 'assessment-1', 'itemRefs' => []],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testNoItemRefsBlocksPublish()

    /**
     * Manual proctoring (the default) publishes without consulting Hermiq or
     * the locality classifier/policy at all.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-manual-proctoring-is-unaffected
     */
    public function testManualProctoringSkipsDpoAndLocalityChecks(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->expects($this->never())->method('isInstalled');

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->never())->method('classifyActiveProvider');

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->expects($this->never())->method('isCompliant');

        $guard   = $this->buildGuard(appManager: $appManager, classifier: $classifier, policyService: $policyService);
        $context = [
            'object'     => [
                'id'         => 'assessment-2',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'manual'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testManualProctoringSkipsDpoAndLocalityChecks()

    /**
     * An unset flagReviewMode defaults to manual and is likewise unaffected.
     *
     * @return void
     */
    public function testUnsetFlagReviewModeDefaultsToManualAndSkipsChecks(): void
    {
        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->never())->method('classifyActiveProvider');

        $guard   = $this->buildGuard(classifier: $classifier);
        $context = [
            'object'     => ['id' => 'assessment-3', 'itemRefs' => ['item-1']],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testUnsetFlagReviewModeDefaultsToManualAndSkipsChecks()

    /**
     * `ai-assisted` proctoring with Hermiq not installed is refused — and the
     * locality classifier is never reached (fails closed on the DPO gate
     * first).
     *
     * @return void
     */
    public function testHermiqNotInstalledBlocksAiAssistedPublish(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(false);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->never())->method('classifyActiveProvider');

        $guard   = $this->buildGuard(appManager: $appManager, classifier: $classifier);
        $context = [
            'object'     => [
                'id'         => 'assessment-4',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'ai-assisted'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testHermiqNotInstalledBlocksAiAssistedPublish()

    /**
     * `ai-assisted` proctoring with Hermiq installed but the feature not
     * DPO-enabled is refused — and the locality classifier is never reached.
     *
     * @return void
     */
    public function testFeatureNotDpoEnabledBlocksAiAssistedPublish(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->never())->method('classifyActiveProvider');

        $guard   = $this->buildGuard(objectService: $objectService, appManager: $appManager, classifier: $classifier);
        $context = [
            'object'     => [
                'id'         => 'assessment-5',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'ai-assisted'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testFeatureNotDpoEnabledBlocksAiAssistedPublish()

    /**
     * DPO-enabled but locality violates SovereigntyPolicy — refused.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-publish-is-blocked-when-a-verified-third-country-provider-violates-an-on-premises-only-policy
     */
    public function testAiAssistedProctoringBlockedByLocalityPolicy(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['slug' => 'assessment-ai-proctor-review', 'lifecycle' => 'enabled']]);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->expects($this->once())->method('classifyActiveProvider')->willReturn(
            ['locality' => 'third-country', 'verified' => true, 'evidence' => 'openai, host-locked']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->expects($this->once())
            ->method('isCompliant')
            ->with('third-country', true)
            ->willReturn(false);

        $guard   = $this->buildGuard(objectService: $objectService, appManager: $appManager, classifier: $classifier, policyService: $policyService);
        $context = [
            'object'     => [
                'id'         => 'assessment-6',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'ai-assisted'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testAiAssistedProctoringBlockedByLocalityPolicy()

    /**
     * DPO-enabled but locality is unverified under a stricter-than-permissive
     * policy — refused.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-publish-is-blocked-when-locality-is-unverified-under-a-stricter-than-permissive-policy
     */
    public function testAiAssistedProctoringBlockedByUnverifiedLocality(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['slug' => 'assessment-ai-proctor-review', 'lifecycle' => 'enabled']]);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'unverified', 'verified' => false, 'evidence' => 'ollama, unverified']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('isCompliant')->with('unverified', false)->willReturn(false);

        $guard   = $this->buildGuard(objectService: $objectService, appManager: $appManager, classifier: $classifier, policyService: $policyService);
        $context = [
            'object'     => [
                'id'         => 'assessment-7',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'ai-assisted'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testAiAssistedProctoringBlockedByUnverifiedLocality()

    /**
     * DPO-enabled, locality unverified, but the school accepts
     * `third-country-allowed` — publish succeeds.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-publish-succeeds-when-the-school-accepts-the-permissive-tier
     */
    public function testAiAssistedProctoringAllowedUnderThirdCountryAllowedPolicy(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['slug' => 'assessment-ai-proctor-review', 'lifecycle' => 'enabled']]);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'unverified', 'verified' => false, 'evidence' => 'ollama, unverified']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('isCompliant')->with('unverified', false)->willReturn(true);

        $guard   = $this->buildGuard(objectService: $objectService, appManager: $appManager, classifier: $classifier, policyService: $policyService);
        $context = [
            'object'     => [
                'id'         => 'assessment-8',
                'itemRefs'   => ['item-1'],
                'proctoring' => ['flagReviewMode' => 'ai-assisted'],
            ],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testAiAssistedProctoringAllowedUnderThirdCountryAllowedPolicy()
}//end class
