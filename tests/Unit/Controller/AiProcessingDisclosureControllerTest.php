<?php

/**
 * Scholiq AiProcessingDisclosureController unit tests.
 *
 * Coverage for sovereign-ai-guarantee tasks.md#3.2: the disclosure endpoint
 * composes Hermiq's agentaifeature register, the scholiq-ai-features AVG
 * carrier, and the locality/policy verdict — and degrades gracefully (never
 * errors) when Hermiq is absent.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\AiProcessingDisclosureController;
use OCA\Scholiq\Service\AiLocalityClassifier;
use OCA\Scholiq\Service\SovereigntyPolicyService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AiProcessingDisclosureController::index().
 */
class AiProcessingDisclosureControllerTest extends TestCase
{

    /**
     * An unauthenticated caller is rejected before any composition happens.
     *
     * @return void
     */
    public function testUnauthenticatedCallerIsRejected(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $controller = $this->buildController(userSession: $userSession, objectService: $objectService);
        $response   = $controller->index();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedCallerIsRejected()

    /**
     * When Hermiq is installed with a registered feature, the response
     * composes the feature's DPO/lifecycle state, the AVG processing-activity
     * fields, and the locality verdict.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-the-disclosure-page-lists-every-hermiq-governed-feature-with-its-locality-verdict
     */
    public function testComposesHermiqFeatureAvgCarrierAndLocalityVerdict(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if (($config['schema'] ?? null) === 'agentaifeature') {
                    return [
                        [
                            'slug'         => 'assessment-ai-proctor-review',
                            'name'         => 'Assessment AI Proctor Review',
                            'riskCategory' => 'high',
                            'lifecycle'    => 'enabled',
                        ],
                    ];
                }

                // SovereigntyPolicy lookup — none set.
                return [];
            }
        );

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'third-country', 'verified' => true, 'evidence' => 'openai, host-locked']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('currentPolicy')->willReturn('eu-hosted-allowed');
        $policyService->method('isCompliant')->with('third-country', true)->willReturn(false);

        $controller = $this->buildController(
            appManager: $appManager,
            objectService: $objectService,
            classifier: $classifier,
            policyService: $policyService
        );

        $response = $controller->index();
        $data     = $response->getData();

        self::assertTrue($data['hermiqInstalled']);
        self::assertCount(1, $data['features']);
        self::assertSame('assessment-ai-proctor-review', $data['features'][0]['slug']);
        self::assertSame('enabled', $data['features'][0]['lifecycle']);
        self::assertSame('third-country', $data['features'][0]['locality']);
        self::assertTrue($data['features'][0]['verified']);
        self::assertFalse($data['features'][0]['policyCompliant']);
        self::assertArrayHasKey('aiProcessingActivity', $data['features'][0]);
        self::assertSame('scholiq-ai-features', $data['features'][0]['aiProcessingActivity']['code']);

    }//end testComposesHermiqFeatureAvgCarrierAndLocalityVerdict()

    /**
     * An unverified locality is surfaced as `verified: false` — never masked
     * as compliant regardless of the school's policy tier — so the frontend
     * badge logic has the raw signal it needs to never render green.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-an-unverified-locality-never-renders-as-compliant
     */
    public function testUnverifiedLocalitySurfacedAsUnverifiedEvenUnderPermissivePolicy(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                if (($config['schema'] ?? null) === 'agentaifeature') {
                    return [['slug' => 'assessment-ai-proctor-review', 'name' => 'x', 'lifecycle' => 'enabled']];
                }

                return [];
            }
        );

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'unverified', 'verified' => false, 'evidence' => 'ollama']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('currentPolicy')->willReturn('third-country-allowed');
        // The feature IS allowed to run under this permissive tier...
        $policyService->method('isCompliant')->with('unverified', false)->willReturn(true);

        $controller = $this->buildController(
            appManager: $appManager,
            objectService: $objectService,
            classifier: $classifier,
            policyService: $policyService
        );

        $data = $controller->index()->getData();

        // ...but the locality itself is still reported as unverified, not
        // upgraded to a compliant-looking value.
        self::assertSame('unverified', $data['features'][0]['locality']);
        self::assertFalse($data['features'][0]['verified']);

    }//end testUnverifiedLocalitySurfacedAsUnverifiedEvenUnderPermissivePolicy()

    /**
     * Hermiq not installed degrades to an empty feature list — never an
     * error — while still returning the school's own SovereigntyPolicy.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-hermiq-absent-degrades-gracefully
     */
    public function testHermiqAbsentReturnsEmptyFeatureListNotError(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(false);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'unverified', 'verified' => false, 'evidence' => 'Hermiq is not installed.']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('currentPolicy')->willReturn('eu-hosted-allowed');
        $policyService->method('isCompliant')->willReturn(false);

        $controller = $this->buildController(
            appManager: $appManager,
            objectService: $objectService,
            classifier: $classifier,
            policyService: $policyService
        );

        $response = $controller->index();
        $data     = $response->getData();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertFalse($data['hermiqInstalled']);
        self::assertSame([], $data['features']);
        self::assertSame('eu-hosted-allowed', $data['sovereigntyPolicy']['policy']);

    }//end testHermiqAbsentReturnsEmptyFeatureListNotError()

    /**
     * A Hermiq read failure degrades to an empty feature list rather than
     * propagating the exception.
     *
     * @return void
     */
    public function testHermiqReadFailureDegradesToEmptyFeatureList(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(true);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willThrowException(new \RuntimeException('cross-app read failed'));

        $classifier = $this->createMock(AiLocalityClassifier::class);
        $classifier->method('classifyActiveProvider')->willReturn(
            ['locality' => 'unverified', 'verified' => false, 'evidence' => 'x']
        );

        $policyService = $this->createMock(SovereigntyPolicyService::class);
        $policyService->method('currentPolicy')->willReturn('eu-hosted-allowed');
        $policyService->method('isCompliant')->willReturn(false);

        $controller = $this->buildController(
            appManager: $appManager,
            objectService: $objectService,
            classifier: $classifier,
            policyService: $policyService
        );

        $response = $controller->index();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame([], $response->getData()['features']);

    }//end testHermiqReadFailureDegradesToEmptyFeatureList()

    /**
     * Build a controller with the given (or default-mocked) collaborators.
     *
     * @param IUserSession|null             $userSession   User session mock.
     * @param IAppManager|null              $appManager    App manager mock.
     * @param ObjectService|null            $objectService OR object service mock.
     * @param AiLocalityClassifier|null     $classifier    Locality classifier mock.
     * @param SovereigntyPolicyService|null $policyService Policy service mock.
     *
     * @return AiProcessingDisclosureController
     */
    private function buildController(
        ?IUserSession $userSession=null,
        ?IAppManager $appManager=null,
        ?ObjectService $objectService=null,
        ?AiLocalityClassifier $classifier=null,
        ?SovereigntyPolicyService $policyService=null
    ): AiProcessingDisclosureController {
        if ($userSession === null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('compliance-officer-1');

            $userSession = $this->createMock(IUserSession::class);
            $userSession->method('getUser')->willReturn($user);
        }

        $appManager    ??= $this->createMock(IAppManager::class);
        $objectService ??= $this->createMock(ObjectService::class);
        $classifier    ??= $this->createMock(AiLocalityClassifier::class);
        $policyService ??= $this->createMock(SovereigntyPolicyService::class);

        return new AiProcessingDisclosureController(
            $this->createMock(IRequest::class),
            $userSession,
            $appManager,
            $objectService,
            $classifier,
            $policyService,
            $this->createMock(LoggerInterface::class)
        );

    }//end buildController()
}//end class
