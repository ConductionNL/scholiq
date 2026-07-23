<?php

/**
 * Scholiq AI Locality Classifier
 *
 * Derives a {locality, verified, evidence} verdict for an AI-assisted feature's
 * processing destination from real, code-enforced configuration — never from a
 * hand-typed field an admin could get wrong or lie in (sovereign-ai-guarantee).
 *
 * The asymmetry this class exists to encode (design.md "What can actually be
 * verified — the evidence chain"): a `third-country` verdict can be PROVEN with
 * code-level certainty for exactly the three OpenRegister-catalogued,
 * host-locked, broker-mediated SaaS chat providers (`openai`, `fireworks`,
 * `anthropic`) — Hermiq's `hermiq/lib/Service/Llm/BrokerHttpClient.php` hands
 * OpenRegister's `CredentialBrokerService` only a `credentialId` and a path;
 * `CredentialBrokerService::request()`'s "Guard 4" code-enforces that the
 * resolved network destination equals the immutable, code-shipped
 * `lib/Settings/credential-providers.json` catalogue entry for that
 * credential's `provider` field. Every other configuration — `ollama` (a bare,
 * unverified config URL), `nextcloud` (an opaque TaskProcessing backend), an
 * inject-only/self-hosted `generic-*` credential, or Hermiq being
 * absent/unconfigured — classifies `unverified`. This class MUST NOT emit
 * `verified: true` for `on-premises` or `eu-hosted`: no code path available to
 * Scholiq or Hermiq currently proves either positively true (design.md
 * Decision 1 — prove violations, never prove compliance, and say so).
 *
 * Legitimate PHP per ADR-031: derives a classification from cross-app
 * configuration reads plus conditional logic against an immutable catalogue —
 * not expressible as a single declarative OR query or schema calculation.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stateless classifier: {locality, verified, evidence} for the currently
 * active Hermiq chat provider, or for an explicit (chatProvider, credentialId)
 * pair.
 *
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
 */
class AiLocalityClassifier
{

    /**
     * App id of the central AI governance/inference app (Hermiq).
     */
    private const HERMIQ_APP_ID = 'hermiq';

    /**
     * IAppConfig key holding Hermiq's LLM configuration JSON blob.
     */
    private const HERMIQ_LLM_CONFIG_KEY = 'llm';

    /**
     * `hermiq.llm.chatProvider` values that are broker-mediated and MUST be
     * checked against a `brokeredcredential`'s catalogue `provider` before any
     * `verified: true` verdict can be emitted. `ollama` and `nextcloud` are
     * deliberately absent — neither is ever broker-mediated
     * (`ProviderFactory.php:1142-1164`/`:1411-1419`), so neither can ever
     * reach a verified verdict via this path.
     *
     * @var string[]
     */
    private const BROKER_MEDIATED_CHAT_PROVIDERS = ['openai', 'fireworks', 'anthropic'];

    /**
     * `brokeredcredential.provider` catalogue identifiers that
     * `CredentialBrokerService`'s Guard 4 host-locks to a fixed, US-domiciled
     * `baseUrl` (`credential-providers.json`: `openai` -> api.openai.com,
     * `fireworks` -> api.fireworks.ai, `anthropic`/`anthropic-oauth` (API key
     * and Claude Max OAuth) -> api.anthropic.com). Any other catalogue
     * `provider` value (the `generic-*` inject-only family) is explicitly NOT
     * host-locked — Guard 4 does not apply to it.
     *
     * @var string[]
     */
    private const HOST_LOCKED_THIRD_COUNTRY_PROVIDERS = ['openai', 'fireworks', 'anthropic', 'anthropic-oauth'];

    /**
     * OR register slug for the credential-broker's metadata register.
     */
    private const CREDENTIAL_BROKER_REGISTER = 'credential-broker';

    /**
     * OR schema slug for a brokered credential's owner-/organisation-scoped
     * metadata (never the secret itself).
     */
    private const BROKERED_CREDENTIAL_SCHEMA = 'brokeredcredential';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for the cross-app
     *                                       `brokeredcredential` lookup.
     * @param IAppConfig      $appConfig     NC cross-app config reader for
     *                                       Hermiq's `hermiq.llm` blob.
     * @param IAppManager     $appManager    Tells "Hermiq absent" from
     *                                       "Hermiq installed but unconfigured".
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Classify the locality of Hermiq's CURRENTLY ACTIVE chat provider.
     *
     * Resolves `hermiq.llm` cross-app (`IAppConfig::getValueString('hermiq',
     * 'llm', ...)`), extracts `chatProvider` and — for the three
     * broker-mediated providers — the matching `<provider>Config.credentialId`,
     * then delegates to {@see classify()}. Degrades to `unverified` (never
     * throws) when Hermiq is not installed, unconfigured, or the blob is
     * malformed.
     *
     * @return array{locality: string, verified: bool, evidence: string}
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
     */
    public function classifyActiveProvider(): array
    {
        if ($this->appManager->isInstalled(self::HERMIQ_APP_ID) === false) {
            return $this->unverified(evidence: 'Hermiq is not installed — no AI chat provider is active, so no locality can be classified.');
        }

        $raw = $this->appConfig->getValueString(
            app: self::HERMIQ_APP_ID,
            key: self::HERMIQ_LLM_CONFIG_KEY,
            default: '{}'
        );

        $llmConfig = json_decode($raw, true);
        if (is_array($llmConfig) === false) {
            $this->logger->info('[AiLocalityClassifier] hermiq.llm config is not valid JSON; classifying unverified.');
            return $this->unverified(evidence: 'Hermiq\'s LLM configuration could not be read or parsed.');
        }

        $chatProvider = (string) ($llmConfig['chatProvider'] ?? '');
        $credentialId = null;

        if (in_array($chatProvider, self::BROKER_MEDIATED_CHAT_PROVIDERS, true) === true) {
            $providerConfig = $llmConfig[$chatProvider.'Config'] ?? [];
            if (is_array($providerConfig) === true) {
                $rawCredentialId = $providerConfig['credentialId'] ?? null;
                if (is_string($rawCredentialId) === true && $rawCredentialId !== '') {
                    $credentialId = $rawCredentialId;
                }
            }
        }

        return $this->classify(chatProvider: $chatProvider, credentialId: $credentialId);

    }//end classifyActiveProvider()

    /**
     * Classify the locality of a given (chatProvider, credentialId) pair.
     *
     * Returns `third-country`/`verified: true` ONLY when `$chatProvider` is one
     * of the three broker-mediated SaaS providers AND the referenced
     * `brokeredcredential`'s catalogue `provider` field confirms a host-locked
     * (never `generic-*`) path. Every other input — including a broker-mediated
     * provider whose credential cannot be resolved (RBAC-denied, deleted, or
     * absent) — classifies `unverified`. Never throws.
     *
     * @param string      $chatProvider Hermiq's `hermiq.llm.chatProvider` value.
     * @param string|null $credentialId The broker credential UUID referenced by
     *                                  `hermiq.llm.<chatProvider>Config.credentialId`,
     *                                  or null when the provider carries none
     *                                  (e.g. `ollama`, `nextcloud`).
     *
     * @return array{locality: string, verified: bool, evidence: string}
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-feature-s-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field
     */
    public function classify(string $chatProvider, ?string $credentialId): array
    {
        $chatProvider = trim($chatProvider);

        if ($chatProvider === '') {
            return $this->unverified(evidence: 'No Hermiq chat provider is configured.');
        }

        if (in_array($chatProvider, self::BROKER_MEDIATED_CHAT_PROVIDERS, true) === false) {
            // Ollama (bare, unverified config URL), Nextcloud (opaque
            // TaskProcessing backend), or any provider this classifier does
            // not recognise — none of these carry a host-lock guarantee.
            return $this->unverified(
                evidence: sprintf(
                    'Chat provider "%s" is never broker-mediated (ProviderFactory.php), so its network '
                    .'destination is not code-enforced — it could be anywhere, including genuinely '
                    .'on-premises or EU-hosted, but nothing proves it.',
                    $chatProvider
                )
            );
        }

        if ($credentialId === null || $credentialId === '') {
            return $this->unverified(
                evidence: sprintf('Chat provider "%s" is broker-mediated but no credential is configured for it.', $chatProvider)
            );
        }

        $credential = $this->findBrokeredCredential(credentialId: $credentialId);
        if ($credential === null) {
            return $this->unverified(
                evidence: sprintf(
                    'The brokered credential "%s" referenced by chat provider "%s" could not be read '
                    .'(not found, or not readable under this calling identity\'s RBAC) — degrading to '
                    .'unverified rather than assuming compliant.',
                    $credentialId,
                    $chatProvider
                )
            );
        }

        $catalogueProvider = (string) ($credential['provider'] ?? '');

        if (in_array($catalogueProvider, self::HOST_LOCKED_THIRD_COUNTRY_PROVIDERS, true) === false) {
            return $this->unverified(
                evidence: sprintf(
                    'Credential "%s" targets catalogue provider "%s" — an inject-only, non-host-locked '
                    .'path (CredentialBrokerService Guard 4 does not apply to it). The actual destination '
                    .'could be anywhere, including genuinely on-premises or EU-hosted, but nothing code-'
                    .'enforces it.',
                    $credentialId,
                    $catalogueProvider
                )
            );
        }

        return [
            'locality' => 'third-country',
            'verified' => true,
            'evidence' => sprintf(
                'Hermiq\'s active chat provider "%s" is broker-mediated via OpenRegister credential "%s", '
                .'whose catalogue provider "%s" is host-locked by CredentialBrokerService\'s Guard 4 to a '
                .'fixed, US-domiciled baseUrl (credential-providers.json) — the resolved network '
                .'destination cannot be redirected elsewhere.',
                $chatProvider,
                $credentialId,
                $catalogueProvider
            ),
        ];

    }//end classify()

    /**
     * Build the `unverified` verdict shape.
     *
     * @param string $evidence Human-readable, cited explanation.
     *
     * @return array{locality: string, verified: bool, evidence: string}
     */
    private function unverified(string $evidence): array
    {
        return [
            'locality' => 'unverified',
            'verified' => false,
            'evidence' => $evidence,
        ];

    }//end unverified()

    /**
     * Cross-app read of a `brokeredcredential` object by id. Degrades to null
     * — NEVER throws, NEVER assumes compliant — when the object cannot be
     * found or the read is denied by OpenRegister's RBAC for this calling
     * identity (design.md "Two things are explicitly not yet confirmed").
     *
     * @param string $credentialId UUID of the `brokeredcredential` object.
     *
     * @return array<string,mixed>|null
     */
    private function findBrokeredCredential(string $credentialId): ?array
    {
        try {
            $result = $this->objectService->find(
                id: $credentialId,
                register: self::CREDENTIAL_BROKER_REGISTER,
                schema: self::BROKERED_CREDENTIAL_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->info(
                '[AiLocalityClassifier] brokeredcredential "{id}" read denied or failed ({message}); '
                .'degrading to unverified.',
                ['id' => $credentialId, 'message' => $e->getMessage()]
            );
            return null;
        }

        if ($result === null) {
            return null;
        }

        if (is_array($result) === true) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;

    }//end findBrokeredCredential()
}//end class
