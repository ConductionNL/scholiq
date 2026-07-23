<?php

/**
 * Scholiq AiLocalityClassifier unit tests.
 *
 * Coverage for the sovereign-ai-guarantee evidence chain: verified
 * `third-country` for the three catalogued, host-locked, broker-mediated SaaS
 * chat providers; `unverified` for everything else (ollama, nextcloud,
 * inject-only credentials, Hermiq absent/unconfigured, a denied/failed
 * credential read). No branch may ever emit `verified: true` for
 * `on-premises`/`eu-hosted`.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\AiLocalityClassifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AiLocalityClassifier::classify()/classifyActiveProvider().
 */
class AiLocalityClassifierTest extends TestCase
{

    /**
     * Build a classifier with explicit collaborators (all mocked unless overridden).
     *
     * @param ObjectService|null $objectService OR object service mock.
     * @param IAppConfig|null    $appConfig     App config mock.
     * @param IAppManager|null   $appManager    App manager mock (defaults: hermiq installed).
     *
     * @return AiLocalityClassifier
     */
    private function buildClassifier(
        ?ObjectService $objectService=null,
        ?IAppConfig $appConfig=null,
        ?IAppManager $appManager=null
    ): AiLocalityClassifier {
        $objectService ??= $this->createMock(ObjectService::class);
        $appConfig     ??= $this->createMock(IAppConfig::class);

        if ($appManager === null) {
            $appManager = $this->createMock(IAppManager::class);
            $appManager->method('isInstalled')->with('hermiq')->willReturn(true);
        }

        return new AiLocalityClassifier($objectService, $appConfig, $appManager, $this->createMock(LoggerInterface::class));

    }//end buildClassifier()

    /**
     * A catalogued third-country SaaS provider (openai) classifies as
     * verified third-country.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-a-catalogued-third-country-saas-provider-classifies-as-verified-third-country
     */
    public function testOpenAiCredentialClassifiesAsVerifiedThirdCountry(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-1', 'provider' => 'openai']);

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('openai', 'cred-1');

        self::assertSame('third-country', $result['locality']);
        self::assertTrue($result['verified']);
        self::assertNotEmpty($result['evidence']);

    }//end testOpenAiCredentialClassifiesAsVerifiedThirdCountry()

    /**
     * Fireworks — same broker path, same verdict.
     *
     * @return void
     */
    public function testFireworksCredentialClassifiesAsVerifiedThirdCountry(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-2', 'provider' => 'fireworks']);

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('fireworks', 'cred-2');

        self::assertSame('third-country', $result['locality']);
        self::assertTrue($result['verified']);

    }//end testFireworksCredentialClassifiesAsVerifiedThirdCountry()

    /**
     * Anthropic (API key) — same broker path, same verdict.
     *
     * @return void
     */
    public function testAnthropicCredentialClassifiesAsVerifiedThirdCountry(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-3', 'provider' => 'anthropic']);

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('anthropic', 'cred-3');

        self::assertSame('third-country', $result['locality']);
        self::assertTrue($result['verified']);

    }//end testAnthropicCredentialClassifiesAsVerifiedThirdCountry()

    /**
     * Anthropic (Claude Max OAuth) — the catalogue's second host-locked
     * anthropic entry — also verifies.
     *
     * @return void
     */
    public function testAnthropicOAuthCredentialClassifiesAsVerifiedThirdCountry(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-4', 'provider' => 'anthropic-oauth']);

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('anthropic', 'cred-4');

        self::assertSame('third-country', $result['locality']);
        self::assertTrue($result['verified']);

    }//end testAnthropicOAuthCredentialClassifiesAsVerifiedThirdCountry()

    /**
     * A self-hosted Ollama configuration classifies as unverified, never as
     * on-premises, regardless of what the configured URL looks like.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-a-self-hosted-ollama-configuration-classifies-as-unverified-never-as-on-premises
     */
    public function testOllamaAlwaysClassifiesUnverified(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('find');

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('ollama', null);

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testOllamaAlwaysClassifiesUnverified()

    /**
     * `nextcloud` (opaque TaskProcessing backend) classifies as unverified.
     *
     * @return void
     */
    public function testNextcloudTaskProcessingClassifiesUnverified(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('find');

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('nextcloud', null);

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testNextcloudTaskProcessingClassifiesUnverified()

    /**
     * A broker-mediated provider whose credential is an inject-only
     * `generic-*` type (not host-locked) classifies as unverified.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-an-inject-only-broker-credential-classifies-as-unverified
     */
    public function testInjectOnlyCredentialClassifiesUnverified(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-5', 'provider' => 'generic-apikey']);

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('openai', 'cred-5');

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testInjectOnlyCredentialClassifiesUnverified()

    /**
     * A broker-mediated provider whose credential cannot be resolved (RBAC
     * denial, deleted, or a thrown exception) degrades to unverified — never
     * throws, never assumes compliant.
     *
     * @return void
     */
    public function testUnresolvableCredentialDegradesToUnverified(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willThrowException(new \RuntimeException('RBAC denied'));

        $classifier = $this->buildClassifier(objectService: $objectService);
        $result     = $classifier->classify('openai', 'cred-6');

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testUnresolvableCredentialDegradesToUnverified()

    /**
     * A broker-mediated provider with no credentialId at all classifies as
     * unverified.
     *
     * @return void
     */
    public function testBrokerMediatedProviderWithNoCredentialIdClassifiesUnverified(): void
    {
        $classifier = $this->buildClassifier();
        $result     = $classifier->classify('openai', null);

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testBrokerMediatedProviderWithNoCredentialIdClassifiesUnverified()

    /**
     * Hermiq not installed classifies unverified via classifyActiveProvider().
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
     */
    public function testHermiqAbsentOrUnconfiguredClassifiesUnverified(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->with('hermiq')->willReturn(false);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->expects($this->never())->method('getValueString');

        $classifier = $this->buildClassifier(appConfig: $appConfig, appManager: $appManager);
        $result     = $classifier->classifyActiveProvider();

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testHermiqAbsentOrUnconfiguredClassifiesUnverified()

    /**
     * Hermiq installed but `hermiq.llm` unset/empty classifies unverified.
     *
     * @return void
     */
    public function testHermiqInstalledButUnconfiguredClassifiesUnverified(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->with('hermiq', 'llm', '{}')->willReturn('{}');

        $classifier = $this->buildClassifier(appConfig: $appConfig);
        $result     = $classifier->classifyActiveProvider();

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testHermiqInstalledButUnconfiguredClassifiesUnverified()

    /**
     * classifyActiveProvider() extracts chatProvider + the matching
     * `<provider>Config.credentialId` from the decoded hermiq.llm blob and
     * reaches a verified verdict end-to-end.
     *
     * @return void
     */
    public function testClassifyActiveProviderExtractsCredentialIdFromLlmConfig(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->with('hermiq', 'llm', '{}')->willReturn(
            json_encode(['chatProvider' => 'openai', 'openaiConfig' => ['credentialId' => 'cred-7']])
        );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(['id' => 'cred-7', 'provider' => 'openai']);

        $classifier = $this->buildClassifier(objectService: $objectService, appConfig: $appConfig);
        $result     = $classifier->classifyActiveProvider();

        self::assertSame('third-country', $result['locality']);
        self::assertTrue($result['verified']);

    }//end testClassifyActiveProviderExtractsCredentialIdFromLlmConfig()

    /**
     * Malformed JSON in hermiq.llm degrades to unverified rather than
     * throwing.
     *
     * @return void
     */
    public function testMalformedLlmConfigJsonDegradesToUnverified(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->with('hermiq', 'llm', '{}')->willReturn('{not valid json');

        $classifier = $this->buildClassifier(appConfig: $appConfig);
        $result     = $classifier->classifyActiveProvider();

        self::assertSame('unverified', $result['locality']);
        self::assertFalse($result['verified']);

    }//end testMalformedLlmConfigJsonDegradesToUnverified()
}//end class
