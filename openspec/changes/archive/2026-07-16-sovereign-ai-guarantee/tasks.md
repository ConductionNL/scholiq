# Tasks: sovereign-ai-guarantee

## 1. Schema — SovereigntyPolicy

- [x] 1.1 Add `SovereigntyPolicy` to `lib/Settings/scholiq_register.json`: `policy` (enum
  `on-premises-only`|`eu-hosted-allowed`|`third-country-allowed`, default `eu-hosted-allowed`, English
  title/description documenting the ordering strictest-to-most-permissive, design.md Decision 2),
  `rationale` (string, optional), `setBy` (string, derived), `setAt` (date-time, derived). No
  `x-openregister-lifecycle` (flat singleton, mirrors Hermiq's `TenantControl`/`ModelPolicy`). English
  `title`/`description` on every property (gate-28).
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy`
  - **acceptance_criteria**:
    - Schema validates against this register's OpenAPI 3.0.0 conventions
    - `policy` enum has exactly the 3 values, default `eu-hosted-allowed`
- [x] 1.2 Add `SovereigntyPolicy.x-openregister-authorization`: `create`/`update` restricted to
  `["admin", "compliance-officer"]` (mirrors `AccessibilityLimitation`).
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy`

## 2. Backend — classifier + policy service

- [x] 2.1 Implement `OCA\Scholiq\Service\AiLocalityClassifier::classify(string $chatProvider, ?string
  $credentialId): array{locality, verified, evidence}` per design.md's evidence-chain table: reads
  `hermiq.llm` cross-app (`IAppConfig::getValueString('hermiq', 'llm', '{}')`), and for
  `openai`/`fireworks`/`anthropic` cross-app-reads the referenced `brokeredcredential` object
  (`register: credential-broker`, `schema: brokeredcredential`) to confirm its catalogue `provider` is
  host-locked (not `generic-*`). Degrades to `unverified` (never errors, never assumes compliant) when the
  credential read is denied by RBAC or the object cannot be resolved — pin the exact lookup shape
  (direct-by-id vs. filtered `findAll`) against `ai-companion-tools`'s `findCourse()` precedent during this
  task.
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-must-derive-an-ai-features-processing-locality-from-real-code-enforced-configuration-never-a-hand-typed-field`
  - **acceptance_criteria**:
    - `AiLocalityClassifierTest::testOpenAiCredentialClassifiesAsVerifiedThirdCountry` (and fireworks/anthropic
      equivalents) pass
    - `AiLocalityClassifierTest::testOllamaAlwaysClassifiesUnverified` passes
    - `AiLocalityClassifierTest::testNextcloudTaskProcessingClassifiesUnverified` passes
    - `AiLocalityClassifierTest::testInjectOnlyCredentialClassifiesUnverified` passes
    - `AiLocalityClassifierTest::testHermiqAbsentOrUnconfiguredClassifiesUnverified` passes
    - No branch of this method ever returns `verified: true` for `locality: on-premises` or `locality:
      eu-hosted`
- [x] 2.2 Implement `OCA\Scholiq\Service\SovereigntyPolicyService::currentPolicy(): string` (reads the
  `SovereigntyPolicy` singleton, defaults `eu-hosted-allowed` when none exists) and
  `isCompliant(string $locality, bool $verified): bool` per design.md's compliance-rule table.
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy`,
    `specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-schools-policy`
  - **acceptance_criteria**:
    - `SovereigntyPolicyServiceTest::testDefaultsToEuHostedAllowedWhenUnset` passes
    - `SovereigntyPolicyServiceTest::testUnverifiedNeverSatisfiesOnPremisesOrEuHostedTiers` passes
    - `SovereigntyPolicyServiceTest::testUnverifiedSatisfiesThirdCountryAllowedTier` passes

## 3. Backend — guard + disclosure controller

- [x] 3.1 Modify `OCA\Scholiq\Lifecycle\AssessmentPublishGuard::check()`: inject `AiLocalityClassifier` and
  `SovereigntyPolicyService`; after the existing DPO-enablement block (lines 157-184, unchanged), call
  `classify()`/`isCompliant()` for `ai-assisted` `flagReviewMode` and refuse the transition with a distinct
  log message on a `false` result. Composed as an additional call inside the existing method, not a second
  `x-openregister-lifecycle.requires` entry (design.md, `ReportPeriodLockGuard` precedent).
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-schools-policy`
  - **acceptance_criteria**:
    - `AssessmentPublishGuardTest::testAiAssistedProctoringBlockedByLocalityPolicy` passes
    - `AssessmentPublishGuardTest::testAiAssistedProctoringBlockedByUnverifiedLocality` passes
    - `AssessmentPublishGuardTest::testAiAssistedProctoringAllowedUnderThirdCountryAllowedPolicy` passes
    - Existing `AssessmentPublishGuardTest` DPO-enablement and manual-proctoring coverage remains green
      unmodified
- [x] 3.2 Implement `OCA\Scholiq\Controller\AiProcessingDisclosureController::index()` composing Hermiq's
  `agentaifeature` register (cross-app, graceful `IAppManager::isInstalled('hermiq')` degradation), Scholiq's
  `scholiq-ai-features` AVG carrier fields, and the classifier/policy verdict into one payload. Register `GET
  /api/ai-processing-disclosure` in `appinfo/routes.php`.
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo`
  - **acceptance_criteria**:
    - `AiProcessingDisclosureControllerTest::testComposesHermiqFeatureAvgCarrierAndLocalityVerdict` passes
    - `AiProcessingDisclosureControllerTest::testHermiqAbsentReturnsEmptyFeatureListNotError` passes
    - Route reachable only to `admin`/`compliance-officer`

## 4. Frontend — disclosure page

- [x] 4.1 Add `ScholiqAiProcessingDisclosure.vue` (`src/views/`): renders the `SovereigntyPolicy` tier with an
  inline editor (writes via OR's existing generic object-create/update endpoint, no bespoke write
  controller — mirrors `CourseTemplate`'s frontend-orchestration precedent), and the disclosure list from
  task 3.2 with three distinct compliant/violates-policy/unverified badge states — `unverified` MUST render
  regardless of policy tier. Register in `src/registry.js` and declare the singleton `src/manifest.json`
  custom page (no `:id` route, mirrors `ScholiqAccessibilityStatement`'s entry shape).
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo`,
    `specs/ai-locality-guarantee/spec.md#requirement-the-ai-processing-disclosure-page-shall-be-registered-and-reachable`
  - **acceptance_criteria**:
    - Page shows policy tier, every disclosed feature's DPO/lifecycle state, and its verdict badge
    - No badge renders green without `verified: true` in the underlying payload
- [x] 4.2 Add a navigation entry gated to `admin`/`compliance-officer` (`visibleIf`, mirrors the `Compliance`
  nav entry's role gate) linking to the new page.
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-ai-processing-disclosure-page-shall-be-registered-and-reachable`

## 5. Tests — e2e

- [ ] 5.1 Add `tests/e2e/spec-coverage/sovereign-ai-guarantee.spec.ts` covering: setting the policy (task
  1.1/4.1), the disclosure page listing a Hermiq feature with its verdict badge (task 3.2/4.1), the
  unverified-never-green rule under a permissive policy (task 4.1), and navigation reachability/gating (task
  4.2).
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy`,
    `specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo`,
    `specs/ai-locality-guarantee/spec.md#requirement-the-ai-processing-disclosure-page-shall-be-registered-and-reachable`
  - **acceptance_criteria**: all scenarios pass against a seeded instance with Hermiq installed and one
    `assessment-ai-proctor-review` feature registered

## 6. Docs

- [x] 6.1 Amend `ADR-005-eu-ai-act-gating.md` with a short addendum (mirrors its existing 2026-07-06
  amendment style) noting that Scholiq's own `AssessmentPublishGuard` now additionally gates on AI-processing
  locality via `SovereigntyPolicy`, and pointing to this change for the evidence chain — no re-litigation of
  the existing delegation-to-Hermiq amendment.
  - **spec_ref**: `specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-schools-policy`
