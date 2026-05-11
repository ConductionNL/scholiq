---
adr_id: ADR-005
title: EU AI Act compliance — feature-flag + mandatory audit trail per AI decision
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
---

# ADR-005 — EU AI Act compliance gate

## Status
**accepted** (2026-05-11) — binding for every PR that introduces AI/ML behaviour, even if no AI ships in v0.1. The `AiFeatureRegistry` skeleton + `ai_decisions` schema MUST land before the first AI-bearing capability (assessment-engine proctoring or course-management adaptive paths). DPO sign-off required to flip any high-risk feature flag from off to on.

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

## Decision

Every AI / ML feature in Scholiq MUST conform to a single architectural pattern, enforced by code review and by automated tests, even when no AI features are currently active.

### The pattern

1. **Per-tenant feature flag, default off.**
   Every AI feature is registered in a central `ai_features` registry (`Scholiq\Service\AiFeatureRegistry`) with: `feature_slug`, `display_name`, `ai_act_category` (Annex III §3 (a)/(b)/(c)/(d)/none), `risk_level` (high-risk / limited / minimal), `model_card_ref`. Admin UI shows the toggle per tenant; default = off; flipping to on requires:
   - admin explicitly acknowledges a CE / Declaration of Conformity confirmation
   - an audit-trail entry records who flipped the flag, when, with what acknowledgement text

2. **Decision audit trail (depends on ADR-008).**
   Every individual AI decision (one classification, one essay score, one item generated, one anomaly flagged) writes an audit entry into the `ai_decisions` schema in OpenRegister with these mandatory fields:
   | Field | Type | Purpose |
   |---|---|---|
   | `feature_slug` | string | which AI feature |
   | `tenant_id` | UUID | tenant separation |
   | `model_id` | string | which model/provider |
   | `model_version` | string | exact version hash |
   | `input_hash` | string | SHA-256 of input (not the input itself, to respect minimisation) |
   | `output_decision` | json | the model's output |
   | `confidence` | float | 0..1 |
   | `human_override_link` | string \| null | route to override UI |
   | `human_override_at` | timestamp \| null | when overridden |
   | `human_override_by` | UUID \| null | who overrode |
   | `affected_subject` | UUID | learner / cohort / submission |
   | `created_at` | timestamp | xAPI-shaped |

   Append-only. Retention ≥ 10 years for high-risk features (per AI Act Article 12 + 14).

3. **Human override is non-optional for high-risk features.**
   Every high-risk decision MUST have a UI surface where a qualified human (instructor / DPO / compliance officer) can override the AI output before it has effect on the learner. "Effect" means: gate access, set a grade, fail an exam, recommend a remedial path. The override link must be present in the audit trail entry.

4. **Transparency notice in the UI.**
   Anywhere a high-risk AI decision is presented to a learner, the UI MUST render a transparency notice naming the model and stating that the decision is AI-generated and reviewable. NL Design System banner pattern. Non-dismissible.

5. **Technical documentation pack auto-generated.**
   For each AI feature registered, the registry exposes a `GET /api/ai-features/{slug}/dossier` endpoint that returns the technical-documentation pack required by AI Act Article 11 + Annex IV: intended purpose, training data summary (data card), accuracy + robustness metrics, known limitations, human-oversight mechanism, post-market monitoring plan, version history. Auto-generated from registry metadata + model card.

6. **Post-market monitoring.**
   Decisions of confidence < admin-configurable threshold (default 0.7) and decisions overridden by human reviewer surface in a "post-market monitoring" dashboard view per AI Act Article 72. Compliance officer can export a quarterly monitoring report.

7. **Bans (Article 5).**
   Scholiq does NOT implement any AI feature that falls in AI Act Article 5 prohibited list — specifically no emotion-recognition inference in education contexts, no social scoring of learners.

### Concretely for v0.1

No AI features are registered in v0.1. The registry exists; the schema exists; the controller exists; the UI scaffolding for the feature-flag admin panel exists (wireframe in DESIGN-REFERENCES.md §4.10). When Phase 3 adds `assessment-engine` proctoring with AI integrity flags, ADR-005 is the binding contract — that PR must register the feature, fill the dossier, wire the audit trail, expose the override UI, and add the transparency notice, or it fails review.

## Consequences

### Positive
- v1+ AI features inherit a uniform compliance posture; no per-feature scrambling against the August 2026 deadline.
- Competitive positioning: Scholiq can claim AI Act compliance as a baseline, where commercial competitors (Docebo, Cornerstone, SAP SuccessFactors) still play catch-up.
- Compliance officers (Scholiq's primary v0.1 buyer) recognise the pattern immediately — same audit trail discipline as their existing GRC tools.

### Negative / risks
- Engineering tax on every AI feature PR (registry entry, audit-trail wiring, override UI, transparency banner, dossier text). Mitigation: a code-generator skill (`/scholiq:ai-feature-add`) that scaffolds all five layers from a single command.
- Decision storage grows fast at scale (hundreds of thousands of per-decision audit rows). Mitigation: 10y retention is non-negotiable but partitioning by year + columnar indexing on actor + feature_slug + month keeps queries cheap.
- Over-restricts low-risk AI features that don't legally need the full audit pack. Mitigation: `risk_level` field tiers the obligations — limited-risk AI (e.g. recommend-a-course) gets only transparency + opt-out, not full audit + dossier.

## Alternatives considered

- **Defer AI Act compliance until first AI feature lands.** Rejected: retrofit cost grows quickly once code is shipped; same trap most legacy LMSes are in right now (insight #1 of the brief).
- **Adopt an external AI governance platform** (e.g. Credo AI, Holistic AI). Rejected: adds a vendor lock-in for what is essentially a structured-data + audit-trail problem that OpenRegister already solves.
- **Hand-wave with a "responsible AI policy" doc, no code enforcement.** Rejected: not credible to compliance buyers; not enforceable; not auditable.

## Implementation notes

- `Scholiq\Service\AiFeatureRegistry` is a singleton service registered in `OCA\Scholiq\AppInfo\Application::register()`.
- The `ai_features` registry is bootstrap data, not runtime data — checked into `lib/Bootstrap/AiFeatures.php`.
- OpenRegister schemas: `scholiq-ai-feature`, `scholiq-ai-decision`. Append-only on decision.
- The transparency banner is `<CnAiTransparencyBanner>` — a new component proposed for `@conduction/nextcloud-vue`, since this pattern is reusable across decidesk + procest as their AI features land.
- The dossier endpoint output format MUST match the European Commission's draft "Standardised AI Documentation Template" once published; until then, use Article 11 + Annex IV section structure.

## Verification

A code change that touches AI-feature behaviour is compliant if:
- The feature is registered in `Scholiq\Service\AiFeatureRegistry` with full metadata.
- Decisions write to `ai_decisions` with all 11 mandatory fields.
- The corresponding UI surface renders `<CnAiTransparencyBanner>`.
- For high-risk features: a human-override path exists and is reachable in ≤ 2 clicks from the decision surface.
- `GET /api/ai-features/{slug}/dossier` returns a non-empty Article-11-shaped document.
- Feature flag defaults to off; flipping on writes an audit-trail entry.

Automated checks:
- PHPStan custom rule fails the build if a class implementing `AiDecisionMaker` does not call `AiAuditTrail::record()` in its decision path.
- Unit test asserts every entry in `AiFeatureRegistry::all()` has a non-empty dossier.

## References

- Regulation (EU) 2024/1689 — EU AI Act: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- Annex III §3 — High-risk education AI list
- Article 9 (risk management) · Article 10 (data governance) · Article 11 (technical documentation) · Article 12 (record-keeping) · Article 13 (transparency) · Article 14 (human oversight) · Article 15 (accuracy & robustness) · Article 72 (post-market monitoring)
- Brief insight: "EU AI Act classifies LMS adaptive learning + proctoring as high-risk" (legal-requirement, critical)
- Companion ADR: ADR-008 (audit trail foundation) — required dependency.
