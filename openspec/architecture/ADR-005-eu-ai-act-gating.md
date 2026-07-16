---
adr_id: ADR-005
title: EU AI Act compliance — schema-declarative feature flags + per-decision audit via OR
status: accepted
category: legal-architecture
date: 2026-05-11
accepted_at: 2026-05-11
deciders:
  - architecture-team
  - dpo
supersedes: []
depends_on:
  - ADR-008
applies_to:
  - assessment-engine
  - proctoring
  - certification
  - dashboard
  - course-management
  - compliance-audit
references:
  - hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md
  - hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md
---

# ADR-005 — EU AI Act compliance gate

## Status
**accepted** (2026-05-11) — binding for every PR that introduces AI/ML behaviour, even if no AI ships in v0.1. The `AiFeature` schema MUST land in `lib/Settings/scholiq_register.json` (with an empty seed array) before the first AI-bearing capability (assessment-engine proctoring or course-management adaptive paths). DPO sign-off (encoded as the `AiFeatureDpoAckGuard` lifecycle precondition) is required to flip any high-risk feature from `disabled` to `enabled`.

## Amendment (2026-07-06) — governance delegated to the Hermiq app

Change `ai-feature-delegate-to-hermiq`. The **compliance pattern below is unchanged**, but its **home moves**: Scholiq no longer owns an AI-feature governance register. The EU AI Act high-risk feature inventory, the DPO-acknowledgement lifecycle (`AiFeatureDpoAckGuard`), the `/ai-features` management pages, the dossier export, the transparency-banner registration, and the post-market-monitoring aggregations are all **delegated to the fleet-wide Hermiq app** (`hermiq`'s `agentaifeature` register). Hermiq is the single, fleet-wide home for AI-feature governance and AI routing — the same feature that was scholiq-local is now registered and DPO-acknowledged there.

**What remains in Scholiq:**
- A **minimal `AiFeature` schema** in `lib/Settings/scholiq_register.json`, retained *only* as the AVG Art. 30 processing-activity carrier (`scholiq-ai-features`) — slug/name/description + `x-openregister-processing`, with **no** `x-openregister-lifecycle`/`-notifications` governance. This keeps Scholiq's verwerkingsregister at seven activities (`ProcessingActivityCatalogueTest`).
- The **`AssessmentPublishGuard`** still enforces the Art. 14 / ADR-005 DPO gate for AI-assisted proctoring, but now **sources the `enabled` approval from Hermiq's central register** (`register=hermiq`, `schema=agentaifeature`, `slug=assessment-ai-proctor-review`). It **fails closed** for that high-risk path and degrades gracefully: Hermiq absent → block with an "install Hermiq" log; Hermiq present but feature not enabled → block with a "DPO-enable it in Hermiq" log. Manual proctoring (the default) and every other transition are untouched.
- The Admin Settings **"AI Features"** section links to Hermiq's register when installed, else shows an "install and enable Hermiq" notice (no hard dependency; Scholiq never fatals when Hermiq is absent).

**What moves to Hermiq:** every step of "The pattern" and "Concretely for v0.1" below now describes **Hermiq's** responsibility. Any new Scholiq high-risk AI feature is registered in Hermiq's `agentaifeature` register (not `scholiq_register.json`), acknowledged by the DPO there, and — where Scholiq must gate on it — looked up cross-app as `AssessmentPublishGuard` does. The sections below are retained for historical context and as the contract Hermiq inherits.

## Amendment (2026-07-16) — locality gate added on top of the DPO gate

Change `sovereign-ai-guarantee`. **The DPO gate above is unchanged** — this amendment adds a second, independent check to the same `AssessmentPublishGuard::check()` method, not a new register or a new lifecycle. Where the 2026-07-06 amendment answers "is this AI feature governed," this one answers "is this AI feature's processing happening somewhere this school has agreed to accept."

- A new **`SovereigntyPolicy`** OR object (`lib/Settings/scholiq_register.json`, flat, un-lifecycled singleton) lets a school declare its accepted locality tier (`on-premises-only` / `eu-hosted-allowed` / `third-country-allowed`, default `eu-hosted-allowed`).
- A new **`OCA\Scholiq\Service\AiLocalityClassifier`** derives a `{locality, verified, evidence}` verdict from Hermiq's real `hermiq.llm` chat-provider configuration and, for the three OpenRegister-catalogued host-locked broker SaaS providers (`openai`/`fireworks`/`anthropic`), the referenced credential's catalogue `provider` field — never from a hand-typed field. It classifies `third-country` with `verified: true` only for those three catalogued providers; every other configuration (`ollama`, `nextcloud`, an inject-only credential, Hermiq absent) classifies `unverified`. No code path proves `on-premises`/`eu-hosted` true today, so the classifier never emits `verified: true` for either — this is a deliberate asymmetry (prove violations, never fabricate compliance), not an oversight.
- **`AssessmentPublishGuard`** composes this classifier plus `OCA\Scholiq\Service\SovereigntyPolicyService::isCompliant()` after its existing DPO-enablement check: an `ai-assisted`-proctored `Assessment` cannot publish if the active provider's locality verdict violates the school's `SovereigntyPolicy`. `unverified` never satisfies the two stricter tiers.
- A new read-only `AiProcessingDisclosureController` + `ScholiqAiProcessingDisclosure.vue` compose Hermiq's `agentaifeature` register, Scholiq's existing `scholiq-ai-features` AVG Art. 30 carrier, and this verdict into one DPO-facing disclosure page. No verdict renders "compliant" (green) unless `verified: true` — an unverifiable claim shows as `unverified`, never as compliant.

See `openspec/changes/sovereign-ai-guarantee/design.md` for the full evidence chain (which providers are verifiable and why) and the compliance-rule matrix. No hermiq-repo file is touched by this amendment; a per-provider `jurisdiction` catalogue field and a per-feature provider binding are named there as cross-repo follow-up, not built here.

## Context

The EU AI Act (Regulation 2024/1689) entered into force August 2024; high-risk obligations apply from **August 2026**. Annex III §3 explicitly classifies as **high-risk** any AI system used:

> "(a) to determine access or admission or to assign natural persons to educational and vocational training institutions at all levels;
> (b) to evaluate learning outcomes, including when those outcomes are used to steer the learning process of natural persons at all levels;
> (c) to assess the appropriate level of education that an individual will receive or will be able to access, in the context of or within educational and vocational training institutions at all levels;
> (d) to monitor and detect prohibited behaviour of students during tests in the context of or within educational and vocational training institutions at all levels."

Translated to Scholiq features, the following land in high-risk:
- Adaptive learning paths that gate access to subsequent content based on prior performance
- AI-generated assessment items (item-bank auto-population, distractor generation)
- AI essay scoring / short-answer auto-grading
- Spraakdetectie + gezichtsherkenning + look-away detection in proctored exams
- AI-based recommendation of remedial or accelerated courses
- AI-predicted dropout risk surfaced to instructors

Brief insight (critical, legal-requirement):
> *"EU AI Act classifies LMS adaptive learning + proctoring as high-risk. Scholiq must treat any AI feature that determines learner access, evaluates pupils, monitors exams, or steers personalised learning paths as high-risk per Annex III §3, with all the associated obligations: risk management, data governance, technical documentation, transparency, human oversight, accuracy + robustness, post-market monitoring."*

**Phase 1 (compliance-audit wedge) ships ZERO AI features.** The wedge is watch-content + click-attest — no AI in the loop. But the architectural pattern that ANY future AI feature must conform to has to be established day one, because once code is shipped it is much more expensive to retrofit feature-flag + audit-trail discipline.

### Why this ADR was rewritten (2026-05-11)

The first version of this ADR specified an `AiFeatureRegistry` PHP singleton plus an `ai_decisions` schema written via `Scholiq\Service\AuditTrail::record()`. Both patterns are **forbidden** on net-new code by the company-wide ADRs:

- **ADR-022** (apps consume OR abstractions) — Scholiq does not get its own audit-trail substrate; it consumes OR's audit trail.
- **ADR-031** (schema-declarative business logic over service classes) — feature flags + decision-recording fit `x-openregister-lifecycle` and `x-openregister-notifications` exactly. The PHP singleton is the canonical anti-pattern ADR-031 prohibits ("Custom state-machine service for an object whose schema could declare `x-openregister-lifecycle`").

The rewritten pattern below produces the **same legal compliance** (Article 11 dossier, Article 12 audit log, Article 14 human override) with **zero state-machine / notification PHP**.

## Decision

Every AI / ML feature in Scholiq MUST conform to a single architectural pattern, enforced by code review and by automated tests, even when no AI features are currently active.

### The pattern

1. **Feature flags as schema-declared lifecycle, default off.**
   AI features live as `AiFeature` objects in `lib/Settings/scholiq_register.json`. The schema declares:
   - Fields: `slug`, `displayName`, `aiActCategory` (Annex III §3 (a)/(b)/(c)/(d)/none), `riskLevel` (high-risk / limited / minimal), `modelCardRef`.
   - `x-openregister-lifecycle` with `disabled → enabled → disabled`, default `disabled`. The `enable` transition declares `requires: OCA\\Scholiq\\Lifecycle\\AiFeatureDpoAckGuard` — a thin PHP guard (legitimate per ADR-031 §"PHP guards remain a legitimate seam") that asserts the admin acknowledged the CE / Declaration of Conformity confirmation text. The guard is the only PHP in the flag flow.
   - `x-openregister-notifications` dispatches a `security.config.changed` audit-trail entry on every `enable` / `disable` transition — recorded automatically via OR's audit-trail abstraction (ADR-022), no app-side `AuditTrail::record()` call.

   In v0.1 the schema exists with an empty seed array. Zero AI features ship.

2. **Decision audit via OR's audit-trail abstraction (depends on ADR-008).**
   Every individual AI decision (one classification, one essay score, one item generated, one anomaly flagged) writes an **audit-trail entry on the affected OR object** (the Enrolment, the AssessmentSubmission, the ProctoringSession). The audit-trail abstraction is OR's — Scholiq does not maintain a parallel `ai_decisions` store.

   The event_type is `ai.decision.recorded`. The payload carries:

   | Field | Type | Purpose |
   |---|---|---|
   | `feature_slug` | string | which AI feature (links to `AiFeature` object) |
   | `model_id` | string | which model/provider |
   | `model_version` | string | exact version hash |
   | `input_hash` | string | SHA-256 of input (not the input itself, to respect minimisation) |
   | `output_decision` | json | the model's output |
   | `confidence` | float | 0..1 |
   | `human_override_link` | string \| null | route to override UI |
   | `human_override_at` | timestamp \| null | when overridden |
   | `human_override_by` | UUID \| null | who overrode |

   Retention is OR-managed: high-risk features get the 10-year class (AI Act Article 12 + 14); limited / minimal get 7 years.

3. **Human override is non-optional for high-risk features.**
   Every high-risk decision MUST have a UI surface — declared as a `customComponents` page in `src/manifest.json` per ADR-024 — where a qualified human (instructor / DPO / compliance officer) can override the AI output before it has effect on the learner. "Effect" means: gate access, set a grade, fail an exam, recommend a remedial path. The override action writes a follow-up audit-trail entry that references the original decision's audit id.

4. **Transparency notice in the UI.**
   Anywhere a high-risk AI decision is presented to a learner, the UI MUST render a transparency banner naming the model and stating that the decision is AI-generated and reviewable. NL Design System banner pattern. Non-dismissible. The banner is a reusable `CnAiTransparencyBanner` component proposed for `@conduction/nextcloud-vue` (since this pattern is reusable across decidesk + procest as their AI features land).

5. **Technical documentation dossier auto-generated from schema metadata.**
   For each enabled `AiFeature` object, an `AuditPackExportController` endpoint (`GET /api/ai-features/{slug}/dossier`, legitimate PHP per ADR-031 — document generation) returns the technical-documentation pack required by AI Act Article 11 + Annex IV: intended purpose, training data summary (data card), accuracy + robustness metrics, known limitations, human-oversight mechanism, post-market monitoring plan, version history. **All inputs are read from the `AiFeature` schema fields + linked model card; no app-local registry.**

6. **Post-market monitoring.**
   Decisions of confidence < admin-configurable threshold (default 0.7) and decisions overridden by human reviewer surface in the compliance dashboard via `x-openregister-aggregations` declared on the `AiFeature` schema — exposing `lowConfidenceDecisionCount` and `humanOverrideCount` as widget data points consumed by `CnDashboardPage`. No `PostMarketMonitoringService`. Compliance officer exports a quarterly monitoring report through the same audit-pack export endpoint, filtered by `event_type=ai.decision.recorded`.

7. **Bans (Article 5).**
   Scholiq does NOT implement any AI feature that falls in AI Act Article 5 prohibited list — specifically no emotion-recognition inference in education contexts, no social scoring of learners.

### Concretely for v0.1

No AI features are registered in v0.1. The `AiFeature` schema exists; the lifecycle guard exists; the transparency banner component exists; the dossier export endpoint exists (returns 404 for any slug because the seed array is empty). When Phase 3 adds `assessment-engine` proctoring with AI integrity flags, ADR-005 is the binding contract — that PR must:

1. Patch `lib/Settings/scholiq_register.json` with a new `AiFeature` seed for the proctoring integrity classifier.
2. Wire the `customComponents` page in `src/manifest.json` that renders the override UI.
3. Add the schema metadata that feeds the dossier endpoint.
4. Add the `CnAiTransparencyBanner` to the relevant learner-facing page.

No PHP service code is required for any of those steps.

## Consequences

### Positive
- v1+ AI features inherit a uniform compliance posture; no per-feature scrambling against the August 2026 deadline.
- Compliance officers (Scholiq's primary v0.1 buyer) recognise the pattern immediately — same audit-trail discipline as their existing GRC tools.
- Zero PHP state-machine code; every flag flip + every decision flows through OR's audit-trail abstraction, inheriting hash-chain integrity, retention, replayability, and MCP discovery for free.

### Negative / risks
- The dossier export endpoint needs to be flexible enough to render an Article-11-shaped document from arbitrary schema metadata. Mitigation: the schema declares an explicit `dossier_template` field per AiFeature; the controller is a simple template engine.
- Decision storage grows fast at scale (hundreds of thousands of per-decision audit rows). Mitigation: handled by OR's audit-trail partitioning + retention class — already part of the abstraction Scholiq consumes.
- Over-restricts low-risk AI features that don't legally need the full audit pack. Mitigation: `riskLevel` field tiers the obligations; limited-risk AI (e.g. recommend-a-course) gets only transparency + opt-out, not full audit + dossier.

## Alternatives considered

- **Defer AI Act compliance until first AI feature lands.** Rejected: retrofit cost grows quickly once code is shipped.
- **Adopt an external AI governance platform** (e.g. Credo AI, Holistic AI). Rejected: adds a vendor lock-in for what is essentially a structured-data + audit-trail problem that OpenRegister already solves.
- **App-local PHP `AiFeatureRegistry` singleton + `ai_decisions` schema with `Scholiq\Service\AuditTrail::record()`** (the original ADR-005 v1 pattern). Rejected per ADR-022 + ADR-031 — the singleton is the canonical anti-pattern (custom state-machine + custom audit substrate where OR already provides both).

## Implementation notes

- `AiFeature` schema lives in `lib/Settings/scholiq_register.json` alongside every other Scholiq entity.
- `AiFeatureDpoAckGuard` is a single-method PHP class under `lib/Lifecycle/`. It receives the transition context and returns true/false. No state; no dependencies beyond `OCP\IConfig` for reading the DPO acknowledgement value.
- The dossier endpoint is `Scholiq\Controllers\AuditPackExportController::dossier($slug)` — same controller that serves the regulation audit-pack export (per ADR-008). Single legitimate PHP entry point.
- `<CnAiTransparencyBanner>` lives in `@conduction/nextcloud-vue`; Scholiq registers it via `customComponents` on `CnAppRoot` per ADR-024.
- AI decision recording is done by whatever service performs the inference (an external API call adapter via OpenConnector, or a future internal classifier). The adapter calls OR's audit-trail abstraction directly — no Scholiq-side wrapper.

## Verification

A code change that touches AI-feature behaviour is compliant if:
- The new feature appears as an `AiFeature` schema seed in `lib/Settings/scholiq_register.json`.
- The schema declares `x-openregister-lifecycle` with the `AiFeatureDpoAckGuard` precondition on the `enable` transition.
- The schema declares `x-openregister-notifications` so that lifecycle transitions emit `security.config.changed` audit entries via OR's audit trail.
- Decisions are recorded as audit-trail entries on the affected object (Enrolment / AssessmentSubmission / ProctoringSession) with `event_type=ai.decision.recorded` and the 9 mandatory fields.
- The corresponding UI surface renders `<CnAiTransparencyBanner>` and is declared in `src/manifest.json`.
- For high-risk features: a human-override page exists in the manifest and is reachable in ≤ 2 clicks from the decision surface.
- `GET /api/ai-features/{slug}/dossier` returns a non-empty Article-11-shaped document.

Automated checks:
- Hydra reviewer flags any new `lib/Service/*` class whose name matches `*AiDecision*`, `*AiFeature*Service`, or `*AiRegistry*` — those are ADR-031 anti-patterns on net-new code.
- Unit test asserts every enabled `AiFeature` seed has a non-empty `modelCardRef` and `dossier_template`.

## References

- Regulation (EU) 2024/1689 — EU AI Act: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- Annex III §3 — High-risk education AI list
- Article 9 (risk management) · Article 10 (data governance) · Article 11 (technical documentation) · Article 12 (record-keeping) · Article 13 (transparency) · Article 14 (human oversight) · Article 15 (accuracy & robustness) · Article 72 (post-market monitoring)
- Brief insight: "EU AI Act classifies LMS adaptive learning + proctoring as high-risk" (legal-requirement, critical)
- Hydra ADR-022 — apps consume OR abstractions (audit trail is the first row of the abstractions table).
- Hydra ADR-031 — schema-declarative business logic over service classes (the canonical "no AiFeatureRegistry singleton" rule).
- Companion ADR: ADR-008 (audit trail consumed from OR) — required dependency.
