---
kind: code
depends_on: []
---

## Why

**No competitor ships this, structurally.** Blackboard's AI Design Assistant, D2L Lumi, and Microsoft
Reading Progress are all cloud add-ons bolted onto a US-hosted platform — the AI *is* the third-country
dependency, so none of them can ever offer a locality guarantee about their own AI without contradicting
their own product. A Specter competitor-feature query for "AI processing locality guarantee" returns zero
rows for the same reason a query for "on-premise Copilot" would: it is not a feature these products can
build without abandoning what they are.

**The regulatory pattern is real and specific, not generic privacy anxiety.** Hessen banned Office 365 in
schools in 2019; Baden-Württemberg banned MS365 in schools in 2022 — notably *after* a hardened-configuration
pilot still failed the DPA's review, meaning config-level mitigations were rejected and only an
architecture-level answer was accepted. Denmark's Datatilsynet forced all 53 Google-Workspace-for-Education
municipalities into remediation. Follow the Money's reporting established that Magister (the incumbent Dutch
SIS) routes pupil data through US infrastructure. These are the same category of institution — school-level
DPAs and their overseers — that AVG Art. 30 (already implemented by `avg-verwerkingsregister`) and the EU AI
Act Annex III §3 (already the binding posture of `assessment` and `ADR-005`) exist to serve.

**But the sharpest constraint is the counter-insight, not the pattern.** A Specter positioning insight
(impact=high) states it precisely: *"Sovereignty pressure keeps getting relieved — DPIA regimes let
Google/Microsoft remediate back to acceptability."* A sovereignty *claim* is cheap and gets neutralised by
the same remediation cycle every time. What survives that cycle is not a claim but a guarantee the school can
independently verify and hand to its own DPA — enforced by the app, evidenced in the record, never merely
asserted in marketing. That reframes the feature from "assert we are sovereign" to "refuse to let an AI
feature run somewhere we cannot prove, and say so honestly when we cannot prove it either way."

**What already exists, verified at HEAD, that this composes rather than rebuilds:**

- **The `AiFeature`/`AiFeature` gate exists but is now a thin AVG carrier, not a governance register.**
  `ai-feature-delegate-to-hermiq` (merged, unarchived at `openspec/changes/ai-feature-delegate-to-hermiq/`,
  all tasks `[x]`) stripped Scholiq's own `AiFeature` schema
  (`lib/Settings/scholiq_register.json:765-818`) down to `slug`/`name`/`description` plus its
  `x-openregister-processing` AVG Art. 30 annotation (`scholiq-ai-features`, lines 766-787) — it carries
  **no** lifecycle, riskCategory, or provider field any more. EU AI Act high-risk governance (the
  DPO-acknowledgement lifecycle, the `agentaifeature` register) now lives entirely in the Hermiq app
  (`hermiq/lib/Settings/hermiq_register.json:477-644`, `ADR-005`'s 2026-07-06 amendment).
- **The one live cross-app enforcement point is `AssessmentPublishGuard`.**
  `scholiq/lib/Lifecycle/AssessmentPublishGuard.php:137-187` already reads Hermiq's register cross-app
  (`ObjectService::findAll(['register' => 'hermiq', 'schema' => 'agentaifeature', 'filters' => ['slug' =>
  'assessment-ai-proctor-review', 'lifecycle' => 'enabled']])`, lines 167-174) and fails closed when Hermiq
  is absent or the feature is not DPO-enabled (lines 157-184). This is the exact, tested, live pattern this
  change composes with — not a new mechanism.
- **`hermiq/openspec/changes/archive/2026-07-16-ai-course-recommendations/design.md`** establishes the
  fleet's EU AI Act posture for education AI (deterministic ranking, LLM restricted to phrasing only,
  fail-closed feature gate, Annex III §3 framing) and explicitly defers "Scholiq registering its own
  delegation note for this feature" as scholiq-repo follow-up work (design.md, "Follow-up" section) — this
  change is part of that follow-up, generalised beyond one feature.
- **`avg-verwerkingsregister`** (`openspec/specs/avg-verwerkingsregister/spec.md`) already declares AI
  features as an Art. 30 processing activity (`scholiq-ai-features`) that a privacy officer activates. An AI
  feature that processes pupil data is one processing activity among the seven the register already tracks
  — this change composes a locality view on top of that catalogue, it does not add an eighth activity.
- **`assessment`'s EU AI Act posture is the load-bearing precedent for "never fabricate an AI capability we
  don't have."** `openspec/specs/assessment/spec.md:121-122` states native proctoring performs no biometric
  inference "precisely because it performs no such inference" — the spec earns its EU AI Act Annex III §3
  exemption by being honest about what the system does NOT do, not by asserting compliance. This change
  applies the identical discipline to locality: the system will show `unverified`, never a fabricated
  `on-premises`/`eu-hosted` claim, wherever it genuinely cannot prove one.
- **`accessibility-conformance-statement`** (`openspec/changes/archive/2026-07-16-accessibility-conformance-statement/`)
  is the exact shape to mirror: a published, evidence-backed, guard-enforced statement that structurally
  cannot overclaim (`AccessibilityStatementPublishGuard` refuses `fully-compliant` while an `open` limitation
  exists — design.md Decision 2). This change is that same shape applied to AI-processing sovereignty instead
  of accessibility: a policy record, a guard that fails closed, and a disclosure surface that a DPO can trust
  because it cannot lie by omission.

**The evidence chain that makes this buildable, not just aspirational (verified at HEAD, not hermiq's
own code — this change does not modify hermiq):**

- `hermiq/lib/Service/Llm/ProviderFactory.php:243-299` resolves one instance-wide chat provider
  (`openai`/`ollama`/`fireworks`/`anthropic`/`nextcloud`) from the single `hermiq.llm` `IAppConfig` blob
  (`LlmSettingsHandler.php:83`, key `hermiq`/`llm`) — every Hermiq `AiFeature`, including a future
  `course-recommendations`-style feature, shares this one active provider today; there is no per-feature
  provider binding yet.
- For the three broker-mediated providers, `hermiq/lib/Service/Llm/BrokerHttpClient.php:22-23` is explicit:
  *"the request URI is reduced to a PATH. The host is the broker's host-lock ... a client that can name the
  host can name a different one."* Hermiq never sees the resolved host — it hands OpenRegister's
  `CredentialBrokerService` a `credentialId` and a path only.
  `openregister/lib/Service/Credential/CredentialBrokerService.php:532-556` ("Guard 4 — build the resolved
  URL and verify its host equals the provider host") enforces that the actual network destination is the
  **immutable, code-shipped** `lib/Settings/credential-providers.json` catalogue entry for that credential's
  `provider` field — verified: `openai` → `https://api.openai.com`, `anthropic`/`anthropic-oauth` →
  `https://api.anthropic.com`, `fireworks` → `https://api.fireworks.ai` (all three US-domiciled vendors, all
  three catalogued at fixed hosts that cannot be redirected). The `generic-apikey`/`generic-bearer`/
  `generic-basic`/`generic-oauth2`/`generic-jwt` catalogue entries are explicitly the inject-only,
  non-host-locked path for arbitrary/self-hosted targets — Guard 4 does not apply to them.
  `openregister/lib/Settings/credential_broker_register.json:30-55` confirms the credential's `provider`
  field is non-secret metadata, "safe to list, export, audit, and query."
- `ollama` and `nextcloud` never go through the broker at all
  (`ProviderFactory.php:1142-1164`, `:1411-1419`) — `ollama`'s URL is a bare self-reported config string with
  no independent verification; `nextcloud` delegates to whatever `TaskProcessing` provider is installed, an
  opaque backend from Hermiq's own vantage point.

This is the whole feature in one sentence: for exactly the three broker-mediated SaaS providers, Scholiq can
**prove** a third-country destination from code-enforced facts (the catalogue, Guard 4); for every other
configuration it can only say **unverified** — and the guarantee is that it will never say anything stronger
than the evidence supports.

## What Changes

- **New `SovereigntyPolicy` schema** (`lib/Settings/scholiq_register.json`) — a school-wide singleton
  declaring the locality tier the school will accept for AI processing (`on-premises-only` |
  `eu-hosted-allowed` | `third-country-allowed`), admin/compliance-officer authored, flat (no
  `x-openregister-lifecycle`, mirroring Hermiq's own `TenantControl`/`ModelPolicy`/`GuardrailPolicy`
  precedent for a policy record that needs no state machine).
- **New `OCA\Scholiq\Service\AiLocalityClassifier`** — derives a `{locality, verified, evidence}` triple from
  Hermiq's real `hermiq.llm` configuration (cross-app `IAppConfig::getValueString('hermiq', 'llm', ...)`)
  and, for broker-mediated providers, the referenced `brokeredcredential` object's catalogue `provider` field
  (cross-app `ObjectService`, `register: credential-broker`) — never from a hand-typed field. Classifies
  `third-country` (verified) only for the three catalogued SaaS providers; every other configuration
  (`ollama`, `nextcloud`, an inject-only/self-hosted credential, Hermiq absent) classifies as `unverified`.
  `on-premises`/`eu-hosted` are schema values with **no current code path that can verify them true** — this
  is stated plainly, not hidden.
- **New `OCA\Scholiq\Service\SovereigntyPolicyService`** — reads the `SovereigntyPolicy` singleton and
  implements the compliance rule: `unverified` never satisfies `on-premises-only` or `eu-hosted-allowed`; it
  only ever passes under the explicitly permissive `third-country-allowed` tier.
- **`AssessmentPublishGuard.php` gains a locality check**, composed internally (not a second
  `x-openregister-lifecycle.requires` entry — verified at HEAD this register never expresses more than one
  `requires` guard per transition, `scholiq_register.json`'s own v0.11.0 changelog note) after its existing
  Hermiq DPO-enablement check: an `ai-assisted` proctored Assessment cannot publish if the classifier's
  verdict for the active provider violates the school's `SovereigntyPolicy`. Fails closed; manual proctoring
  and every other transition are untouched.
- **New read-only `AiProcessingDisclosureController::index()`** — composes Scholiq's own `AiFeature` AVG
  carrier, Hermiq's `agentaifeature` register (cross-app, when installed), and the classifier/policy verdict
  for the currently-active provider into one disclosure payload. Legitimate PHP per ADR-031 (cross-app query
  + config resolution + conditional classification, none of which is a single declarative OR query) —
  identical justification `AssessmentPublishGuard`'s own docblock already gives for its Hermiq read.
- **New `ScholiqAiProcessingDisclosure.vue`** — a singleton disclosure page (mirrors
  `ScholiqAccessibilityStatement.vue`'s role and its no-`:id`-route shape), gated to admin/compliance-officer,
  rendering the school's policy (editable inline via OR's existing generic object-create/update endpoint, no
  bespoke write controller), every known AI feature's DPO/lifecycle state, its locality verdict, and whether
  it is currently policy-compliant — never rendering `unverified` as if it were `compliant`.
- **No hermiq files are touched.** The cross-repo follow-up seam (a `jurisdiction` field on Hermiq's
  provider/credential surface, or a per-feature provider binding so a locality verdict is not instance-wide)
  is defined precisely in `design.md` and left as prose, per this change's brief.

## Impact

- **`lib/Settings/scholiq_register.json`** — one new schema, `SovereigntyPolicy`.
- **New PHP** — `OCA\Scholiq\Service\AiLocalityClassifier`, `OCA\Scholiq\Service\SovereigntyPolicyService`,
  `OCA\Scholiq\Controller\AiProcessingDisclosureController`. Modified:
  `OCA\Scholiq\Lifecycle\AssessmentPublishGuard`.
- **`appinfo/routes.php`** — one new `GET` route for the disclosure endpoint.
- **`src/manifest.json` / `src/registry.js`** — one new singleton custom page
  (`AiProcessingDisclosure`), one new gated navigation entry.
- **Affected specs**: new `ai-locality-guarantee` capability spec. `avg-verwerkingsregister`, `assessment`,
  and `ai-surface` are read-only precedents (composed with, not modified).
- **Out of scope**: any hermiq-repo change; a positively-verified `on-premises`/`eu-hosted` classification
  (no code path proves either today — named as the cross-repo follow-up in design.md); per-feature provider
  binding (today one instance-wide Hermiq provider serves every `AiFeature`, disclosed honestly rather than
  implying finer-grained control); publishing this disclosure to any external register (unlike
  `accessibility-conformance-statement`, no equivalent public sovereignty register exists — this is a
  document for the school's own DPO).
