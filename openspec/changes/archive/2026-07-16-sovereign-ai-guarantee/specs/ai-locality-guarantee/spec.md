## ADDED Requirements

### Requirement: The system SHALL let a school declare an AI-processing locality policy

The system SHALL provide a `SovereigntyPolicy` OpenRegister object (`lib/Settings/scholiq_register.json`,
flat, no `x-openregister-lifecycle`, mirroring Hermiq's `TenantControl`/`ModelPolicy`/`GuardrailPolicy`
un-lifecycled-policy-record precedent) declaring a `policy` field with exactly three values —
`on-premises-only`, `eu-hosted-allowed`, `third-country-allowed` — ordered from strictest to most
permissive, defaulting to `eu-hosted-allowed`. Creation and update SHALL be restricted via
`x-openregister-authorization.create`/`update` to `admin`/`compliance-officer` roles, mirroring
`AccessibilityLimitation`'s identical restriction. The record SHALL be treated as a school-wide singleton —
one Scholiq instance is one school (verified: no `organisation`/`tenantId` field exists anywhere in
`scholiq_register.json`, unlike Hermiq's multi-tenant policy records) — with no delete/archive flow.

#### Scenario: A school sets its locality policy

<!-- @e2e tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts -->

- **GIVEN** an admin or compliance-officer on the AI processing disclosure page
- **WHEN** they set the policy to `on-premises-only` and save
- **THEN** a `SovereigntyPolicy` object persists with `policy: on-premises-only`

#### Scenario: A non-privileged user cannot change the policy

<!-- @e2e exclude Write-path RBAC is enforced by OpenRegister's x-openregister-authorization (OR-PA-8 style); asserted by OpenRegister's own suite, not a scholiq UI surface. Scholiq's client-side gating is defence-in-depth only, mirroring avg-verwerkingsregister's documented posture. -->

- **GIVEN** an authenticated user without the `admin` or `compliance-officer` role
- **WHEN** they attempt to update the `SovereigntyPolicy` object via the platform API
- **THEN** the request is rejected by OpenRegister's RBAC; Scholiq enforces nothing itself

#### Scenario: No policy set yet defaults to the documented default

<!-- @e2e exclude Pure schema-default/service-default behaviour verified by PHPUnit SovereigntyPolicyServiceTest::testDefaultsToEuHostedAllowedWhenUnset; no DOM surface for "object does not exist yet." -->

- **GIVEN** no `SovereigntyPolicy` object has ever been created on this instance
- **WHEN** `SovereigntyPolicyService::currentPolicy()` is called
- **THEN** it returns `eu-hosted-allowed`

### Requirement: The system MUST derive an AI feature's processing locality from real, code-enforced configuration, never a hand-typed field

The system MUST provide `OCA\Scholiq\Service\AiLocalityClassifier::classify()`, which resolves Hermiq's
active `hermiq.llm` chat-provider configuration (cross-app `IAppConfig::getValueString('hermiq', 'llm',
...)`) and returns exactly one of `on-premises`, `eu-hosted`, `third-country`, or `unverified`, each paired
with a `verified` boolean and a human-readable `evidence` string. The classifier MUST return `third-country`
with `verified: true` ONLY when the active provider is one of the three OpenRegister-catalogued,
host-locked, broker-mediated SaaS providers (`openai`, `fireworks`, `anthropic`/`anthropic-oauth` —
`credential-providers.json`) AND the referenced credential's catalogue `provider` field (cross-app
`ObjectService` read, `register: credential-broker`, `schema: brokeredcredential`) confirms that
host-locked path, not an inject-only `generic-*` credential. Every other configuration — `ollama` (a bare,
unverified config URL), `nextcloud` (an opaque `TaskProcessing` backend), an inject-only/self-hosted
credential, or Hermiq being absent/unconfigured — MUST classify as `unverified`. The classifier MUST NOT
emit `verified: true` for `on-premises` or `eu-hosted` at this revision: no code path available to Scholiq
or Hermiq currently proves either positively true, and the classifier MUST NOT approximate one.

#### Scenario: A catalogued third-country SaaS provider classifies as verified third-country

<!-- @e2e exclude Backend classification logic verified by PHPUnit AiLocalityClassifierTest::testOpenAiCredentialClassifiesAsVerifiedThirdCountry (and equivalent tests for fireworks/anthropic); no DOM surface for the classification computation itself. -->

- **GIVEN** Hermiq's `hermiq.llm.chatProvider` is `openai`
- **AND** the referenced credential's `brokeredcredential.provider` field is `openai`
- **WHEN** `AiLocalityClassifier::classify()` runs
- **THEN** it returns `{locality: 'third-country', verified: true, evidence: <cites the host-lock>}`

#### Scenario: A self-hosted Ollama configuration classifies as unverified, never as on-premises

<!-- @e2e exclude PHPUnit AiLocalityClassifierTest::testOllamaAlwaysClassifiesUnverified; no DOM surface. -->

- **GIVEN** Hermiq's `hermiq.llm.chatProvider` is `ollama` with a configured URL
- **WHEN** `AiLocalityClassifier::classify()` runs
- **THEN** it returns `{locality: 'unverified', verified: false, ...}` regardless of what the configured URL looks like

#### Scenario: An inject-only broker credential classifies as unverified

<!-- @e2e exclude PHPUnit AiLocalityClassifierTest::testInjectOnlyCredentialClassifiesUnverified; no DOM surface. -->

- **GIVEN** Hermiq's active provider is broker-mediated
- **AND** the referenced credential's catalogue `provider` is one of `generic-apikey`/`generic-bearer`/`generic-basic`/`generic-oauth2`/`generic-jwt`
- **WHEN** `AiLocalityClassifier::classify()` runs
- **THEN** it returns `{locality: 'unverified', verified: false, ...}`

### Requirement: The system MUST refuse to let an AI-assisted feature take effect when its verified or unverified locality violates the school's policy

The system MUST extend `AssessmentPublishGuard`'s existing `ai-assisted` proctoring check
(`AssessmentPublishGuard.php:137-187`) so that, after confirming Hermiq's `assessment-ai-proctor-review`
feature is DPO-`enabled`, it additionally calls
`AiLocalityClassifier::classify()` and `SovereigntyPolicyService::isCompliant()`. The transition MUST be
refused when the result is not compliant. Compliance MUST follow this rule exactly: `unverified` MUST NOT
satisfy `on-premises-only` or `eu-hosted-allowed` — it satisfies ONLY the `third-country-allowed` tier;
`on-premises-only` MUST require `locality: on-premises` AND `verified: true`; `eu-hosted-allowed` MUST
require `locality` in `{on-premises, eu-hosted}` AND `verified: true`. This check MUST be composed inside
the existing guard's `check()` method as an additional call, NOT as a second
`x-openregister-lifecycle.requires` entry (verified: `LifecycleAnnotationValidator`/`LifecycleGuardRegistry`
resolve `requires` as a single DI-tag string, never an array, in this register). Manual proctoring and every
other Assessment transition MUST remain unaffected.

#### Scenario: Publish is blocked when a verified third-country provider violates an on-premises-only policy

<!-- @e2e exclude Lifecycle-guard backend logic verified by PHPUnit AssessmentPublishGuardTest::testAiAssistedProctoringBlockedByLocalityPolicy; no scholiq DOM surface for the guard itself, mirrors AccessibilityStatementPublishGuard's own precedent. -->

- **GIVEN** the `SovereigntyPolicy` is `on-premises-only`
- **AND** Hermiq's `assessment-ai-proctor-review` feature is DPO-`enabled`
- **AND** Hermiq's active provider classifies as `{locality: 'third-country', verified: true}`
- **WHEN** an `ai-assisted`-proctored `Assessment` attempts `draft → published`
- **THEN** the transition is refused
- **AND** the block is logged distinctly from a DPO-not-enabled block

#### Scenario: Publish is blocked when locality is unverified under a stricter-than-permissive policy

<!-- @e2e exclude PHPUnit AssessmentPublishGuardTest::testAiAssistedProctoringBlockedByUnverifiedLocality; no DOM surface. -->

- **GIVEN** the `SovereigntyPolicy` is `eu-hosted-allowed`
- **AND** Hermiq's active provider classifies as `unverified`
- **WHEN** an `ai-assisted`-proctored `Assessment` attempts `draft → published`
- **THEN** the transition is refused

#### Scenario: Publish succeeds when the school accepts the permissive tier

<!-- @e2e exclude PHPUnit AssessmentPublishGuardTest::testAiAssistedProctoringAllowedUnderThirdCountryAllowedPolicy; no DOM surface. -->

- **GIVEN** the `SovereigntyPolicy` is `third-country-allowed`
- **AND** Hermiq's `assessment-ai-proctor-review` feature is DPO-`enabled`
- **AND** Hermiq's active provider classifies as `unverified`
- **WHEN** an `ai-assisted`-proctored `Assessment` attempts `draft → published`
- **THEN** the transition succeeds

#### Scenario: Manual proctoring is unaffected

<!-- @e2e exclude PHPUnit AssessmentPublishGuardTest existing coverage; no locality check runs for non-ai-assisted proctoring, no DOM surface. -->

- **GIVEN** an Assessment with `proctoring.flagReviewMode` unset or `manual`
- **WHEN** it attempts `draft → published`
- **THEN** neither the DPO-enablement check nor the locality check runs

### Requirement: The system SHALL compose an AI-processing disclosure a school can hand to its DPO

The system SHALL provide `AiProcessingDisclosureController::index()` (`#[NoAdminRequired]`, gated to
`admin`/`compliance-officer`) composing, for every `AiFeature` registered in Hermiq's `agentaifeature`
register (cross-app read, when Hermiq is installed): the feature's `slug`/`name`/`riskCategory`/`lifecycle`,
Scholiq's own `scholiq-ai-features` AVG Art. 30 processing-activity fields (doelbinding, data categories —
`avg-verwerkingsregister`'s existing carrier, not a new activity), and the `AiLocalityClassifier`/
`SovereigntyPolicyService` verdict for the currently active provider. The system SHALL render this as a
singleton `ScholiqAiProcessingDisclosure.vue` page (no `:id` route, mirroring
`ScholiqAccessibilityStatement.vue`'s shape) with three visually distinct verdict states — compliant
(green), violates policy (red), unverified (amber) — where the amber `unverified` state MUST render
regardless of the currently configured policy tier, even under `third-country-allowed` where the feature is
nonetheless allowed to run. No verdict SHALL render as compliant/green unless `verified: true`.

#### Scenario: The disclosure page lists every Hermiq-governed feature with its locality verdict

<!-- @e2e tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts -->

- **GIVEN** Hermiq is installed with the `assessment-ai-proctor-review` feature registered
- **WHEN** an admin/compliance-officer opens the AI processing disclosure page
- **THEN** the feature is listed with its DPO/lifecycle state, its AVG processing-activity fields, and its locality verdict badge

#### Scenario: An unverified locality never renders as compliant

<!-- @e2e tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts -->

- **GIVEN** the active Hermiq provider classifies as `unverified`
- **AND** the school's `SovereigntyPolicy` is `third-country-allowed` (the feature is therefore allowed to run)
- **WHEN** the disclosure page renders that feature's row
- **THEN** the badge shows `unverified` (amber), never `compliant` (green)

#### Scenario: Hermiq absent degrades gracefully

<!-- @e2e exclude Graceful-degradation backend behaviour verified by PHPUnit AiProcessingDisclosureControllerTest::testHermiqAbsentReturnsEmptyFeatureListNotError; mirrors ScholiqSettings.vue's existing hermiqInstalled note-card pattern for the one DOM-visible aspect, already covered by that existing surface. -->

- **GIVEN** Hermiq is not installed
- **WHEN** the disclosure page loads
- **THEN** it shows the school's own `SovereigntyPolicy` and an empty/absent Hermiq-features section with an "install Hermiq" notice, not an error

### Requirement: The AI processing disclosure page SHALL be registered and reachable

The system SHALL register `ScholiqAiProcessingDisclosure.vue` in `src/registry.js`'s `customComponents` map
and declare its corresponding `src/manifest.json` custom page (per ADR-024). A navigation entry gated to
`admin`/`compliance-officer` (`visibleIf`, mirroring the `Compliance` nav entry's role gate) SHALL link to
it.

#### Scenario: The page is reachable from navigation

<!-- @e2e tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts -->

- **GIVEN** an admin or compliance-officer signed in
- **WHEN** they scan the navigation menu
- **THEN** an entry reaches the AI processing disclosure page

#### Scenario: A non-privileged user does not see the navigation entry

<!-- @e2e tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts -->

- **GIVEN** an authenticated user without the `admin` or `compliance-officer` role
- **WHEN** they scan the navigation menu
- **THEN** no AI processing disclosure entry is present
