# Scholiq v0.1 — Wedge Plan

> **Goal**: ship the smallest credible Scholiq that a real buyer would pay for, without taking on the multi-quarter NL-gov-integration burden. Then iterate.

Document version: 1.0 · 2026-05-11

---

## The wedge: Compliance-Audit

Scholiq v0.1 is a **compliance training platform** for Dutch corporate L&D, MKB, and government employee training. It does **one job**: prove that every employee has completed the training they're legally required to complete, and export an audit-grade evidence pack on demand.

### Why this and not K-12 LVS first

The K-12 LVS opportunity is bigger (7,400+ NL schools, ParnasSys 65% / Magister 55% market share, deep buyer dissatisfaction) — but K-12 procurement is gatekept by four integration standards (BRON/ROD, UWLR, OSO, Edukoppeling) each representing months of work, plus SchoolID + ECK iD pseudonymisation, plus AVG-Onderwijs parental-consent flow, plus DigiD parent app. None of that is built. **K-12 is Phase 2.**

Compliance-audit:
- Targets adult employees / civil servants — no pseudonymisation requirement
- No NL-gov adapters required for v0.1 (HR-system integration is the only "external" need, and it's cleanly OpenConnector-shaped)
- No EU AI Act exposure (no adaptive learning, no proctoring AI in v0.1)
- Five of the 19 critical-priority user stories in the intelligence DB live here
- Direct buyer demand: NIS2 board-training proof (mandatory), BIO2 medewerker, AVG basis refresher, Cyberbeveiligingswet
- Market signal: €250M annual Dutch government training spend; 15%+ YoY corporate eLearning growth; SAP SuccessFactors / Cornerstone / Docebo are charging per-seat where an open-source self-host wins

### The buyer
Compliance officer at a Dutch MKB-to-mid-market organisation (50–2,000 employees), or DPO / CISO at a Dutch government agency. Their daily KPI is "what % of mandatory training is current" — and once a year they need to prove it to an auditor.

---

## Capabilities — Phase 1 (the wedge) vs Phase 2 (the generic LVS/LMS/training core)

Phase 1 ships the **compliance-audit wedge** (6 capabilities, all built). Phase 2 is the **generic educational-institution core** — restructured 2026-05-12 from 8 Dutch-specific stubs into 7 jurisdiction-neutral specs where the Dutch requirements are *profiles*, not the model (see the rationale in `## The Phase-2 restructuring` below).

### Phase 1 — the wedge (built)

| Capability | Spec | Status |
|---|---|---|
| Nextcloud app shell, CnAppRoot Tier-4 manifest, OpenRegister dep check | `nextcloud-app` | **built** |
| Course + Lesson, cmi5/xAPI runtime, SCORM shim | `course-management` | **built** (follow-up: collapse Course/Module → recursive Course; link CurriculumPlan) |
| Bulk-enrol learners, mandatory flag, due_date, reminder cascade | `enrolment` | **built** |
| Attestation capture, OB3 credential issuance, expiry detection | `certification` | **built** |
| Regulation tracking, coverage %, audit pack export, immutable evidence | `compliance-audit` | **built** (wedge core) |
| Compliance Officer dashboard, role-aware surfaces | `dashboard` | **built** |

### Phase 2 — the generic core (planned; build order top-to-bottom)

| Capability | Spec | Replaces (Dutch stubs) | Dutch profile |
|---|---|---|---|
| Programmes, CurriculumPlans, Cohorts, Sessions, Materials | `school-structure` | — | PTA / OER-studiegids / opleidingsplan |
| Assignments, Submissions, Rubrics, hand-in flow | `assignments` | (½ of grading-pta) | opdracht / werkstuk / portfolio |
| Tests/exams, QTI 3.0 item banks, pluggable proctoring | `assessment` | `assessment-engine` + `proctoring` | toets / tentamen / examen |
| Grade entries, scales, final-grade roll-up, soft-publish | `grading` | `grading-pta` | PTA-kolom / SE-gemiddelde / eindcijfer |
| Individual learning plans, goals, evaluations, signatures | `learning-plan` | `opp-cycle` | OPP / handelingsplan |
| Attendance, excuse requests, threshold rules + flags | `attendance` | `absence-leerplicht` | leerplicht 16-uur |
| Export/import jobs to external registries (delegates to OpenConnector) | `data-exchange` | thin slice of `bron-rod-exchange` + `oso-transfer` | BRON/ROD · OSO · leerplichtmelding |
| ~~Federated identity~~ | **dropped** — NC auth + OpenConnector adapters; identifiers already on `LearnerProfile` | `identity-federation` | DigiD / SURFconext / eduID |

The NL gatekeeper *integrations* (BRON/ROD, OSO, leerplicht-Digikoppeling, SURFconext attribute release, generic HR sync) and federated *authentication* (DigiD/SURFconext/eduID) are **OpenConnector** source/target configurations and Nextcloud auth providers — not Scholiq schemas. `data-exchange` is the thin Scholiq side: a `DataExchangeJob` queue + `DataMappingProfile`s that hand off to OpenConnector. Issues filed against `ConductionNL/openconnector`.

### What the wedge UI shows
- Compliance Officer dashboard with coverage % per regulation
- Mandatory training list (regulation → audience → due date)
- Bulk-enrol modal ("enrol all 137 employees in AVG 2026")
- Learner view of "your mandatory training, what's due"
- Attestation capture flow (watch content → click attest → digital signature)
- Audit-pack export button (PDF + CSV evidence per regulation)
- Immutable evidence log (append-only, audit-trail tab)

### What the wedge UI does NOT show
- Item banks, exam authoring, QTI editing
- Proctored exam runner
- OPP cycle, parent signing, DigiD
- Cohort/class rosters with PTA grading
- BRON/UWLR/OSO/Edukoppeling integration consoles
- Student/teacher views (those are K-12 specific)

---

## Foundation ADRs needed first

Per the workflow rule "ADRs before specs," three ADRs must land in `openspec/architecture/` and be marked `accepted` before the 6 wedge specs can move from `idea` → `planned`:

1. **ADR-002 Content runtime: cmi5 + xAPI primary, SCORM compatibility shim**
   Even simple "watch video + click attest" training emits cmi5 statements. Setting the runtime convention now means every future feature inherits it cleanly. SCORM 1.2/2004 stays supported via a shim.

2. **ADR-005 EU AI Act compliance gate: feature-flag + mandatory audit trail per AI decision**
   No AI features ship in v0.1, but the *pattern* — feature-flag + decision audit trail + human-override — needs to exist day one so future AI features (adaptive learning, AI item generation, AI essay scoring, spraakdetectie proctoring) inherit the architectural constraint automatically.

3. **ADR-008 Immutable audit trail as architectural foundation**
   Compliance-audit *is* an audit trail. Every state mutation in Scholiq writes an append-only audit entry into OpenRegister with actor + timestamp + before/after diff + reason. This underpins compliance-audit AND becomes the hygienic default for every other capability. AVG retention, AI Act decision traces, and NIS2 evidence packs all build on this.

The other four ADRs (pseudonymisation, identity federation, QTI engine, NL gov adapters, multi-tenancy) get drafted when their phase arrives.

---

## Critical path to first code commit

```
[ now ]
  1. Accept the wedge plan (this doc)
  2. Write ADR-002, ADR-005, ADR-008  (3 markdown files, ~600 lines total)
  3. Run /app-create scholiq          (creates ConductionNL/scholiq repo, applies template + identity)
  4. Push ADRs to main (per /app-pipeline Phase 7 ordering: identity + ADRs to main FIRST)
  5. Coverage classification pass     (mark which of 354 features are app vs nc vs openregister vs nextcloud-vue)
  6. Slim the 6 in-scope specs to wedge-only requirements
       (drop K-12-only stories from course-management, enrolment, dashboard;
        remove proctoring/QTI references from certification)
  7. /opsx-new for each of the 6 specs        (idea → planned, formal requirements)
  8. /opsx-ff for each of the 6 specs         (generate change artifacts)
  9. scripts/push_spec_pipeline.py scholiq    (push briefs to repo, open GH issues with ready-to-build)

[ hydra picks up ]
  10. Hydra supervisor sees ready-to-build issue → builder runs → quality checks
     → browser tests → code review → security review → merge to development
```

Estimated calendar time to step 9: **3-5 days** of focused work, mostly:
- ADR drafting (1 day — 3 ADRs × ~200 lines each)
- /app-create + identity application (half day)
- Coverage classification (half day — 354 features, mostly mechanical)
- Spec slimming + /opsx-new + /opsx-ff (1-2 days × 6 specs)

Then Hydra runs at ~7-9 min/spec, so the first round of 6 specs lands in development branch within an hour of step 9.

---

## Out of scope for v0.1 (explicit)

- K-12 LVS features (cohorts, OPP, BRON/UWLR/OSO, leerplicht, PTA grading, parent app)
- Higher education features (Studielink, OOAPI 5.0 catalog publication, SURFconext attribute mapping)
- Assessment authoring (QTI 3.0 editor, item banks, blueprint composer)
- Proctored exams (ProctoringProviderInterface, ProctorU/Honorlock/ExamSoft adapters)
- Adaptive learning / AI item generation / AI essay scoring (any feature in EU AI Act Annex III §3)
- Multi-tenancy / SIVON federation
- EDCI verifiable credentials issuance (Open Badges 3.0 is enough for v0.1 attestations; EDCI ELM lands in Phase 3)
- Mobile-first surfaces (responsive grid is fine; native mobile apps are not in scope)
- Federated catalog via OpenCatalogi
- Integration with DocuDesk (certificate templating works inline for v0.1)

---

## Success criteria for v0.1

Three measurable outcomes the wedge has to hit before declaring Phase 1 done:

1. **Compliance officer demo**: a Dutch compliance officer can install Scholiq + OpenRegister on their NC instance, enrol 50 employees in an AVG refresher course, capture attestations, and export an audit pack — without writing any code or asking ConductionNL for help.
2. **NIS2 board-training reference**: at least one Dutch government agency or NIS2-regulated organisation uses Scholiq for their annual board cyber-security training and exports an evidence pack that survives an external audit.
3. **Hydra round-trip**: all 6 wedge specs complete the Specter → Hydra round trip (issue → ready-to-build → builder → reviewers → merge to development) without manual intervention beyond initial spec authoring.

---

## The Phase-2 restructuring (2026-05-12)

The original Phase-2/3 specs were 8 Dutch-government-specific stubs (`grading-pta`, `opp-cycle`, `bron-rod-exchange`, `oso-transfer`, `absence-leerplicht`, `identity-federation`, `assessment-engine`, `proctoring`). They were rewritten into **7 jurisdiction-neutral capability specs** on the principle: *model how a school / university / training firm actually operates, then express the Dutch requirements as profiles of that model.* The mapping:

- A **module is just a Course used as a container** → `Course` becomes recursive; the rigid `Course → Module → Lesson` hierarchy collapses.
- A **PTA is a `CurriculumPlan`** (governing plan: required courses, assessment components with weights + periods, the roll-up formula) — so are an HE `OER/studiegids`, an MBO `opleidingsplan`, a corporate `training curriculum`.
- A **klas / werkgroep / training group is a `Cohort`**; a **les / hoorcollege / workshop is a `Session`** that carries `Material`s and `Assignment`s.
- **Hand-in work** (`Assignment` → `Submission` → `GradeEntry`) and **structured tests** (`Assessment` → `AssessmentResult` → `GradeEntry`, with **proctoring as config on an Assessment**, not a schema) are separate specs; **grades roll up** (`GradeEntry` → `FinalGrade`) via the `CurriculumPlan`'s declared formula — the Dutch SE-gemiddelde is one formula profile.
- An **OPP is a `LearningPlan`** (goals, support measures, evaluation cycle, co-signatures) — so are a `handelingsplan`, a US `IEP`, an HE `PDP`.
- **Leerplicht 16-uur is an `AttendanceThreshold`** rule; the report to the leerplichtambtenaar is a `DataExchangeJob` (a `data-exchange` target), not inline code.
- **BRON/ROD, OSO, leerplicht-Digikoppeling, SURFconext attributes, HR sync** are **OpenConnector adapters**; Scholiq only holds `DataExchangeJob` + `DataMappingProfile` and delegates the wire protocol. **Federated authentication (DigiD/SURFconext/eduID) is a Nextcloud auth-provider concern entirely** — Scholiq just stores the pseudonymous identifiers `LearnerProfile` already carries (`eckId`, `schoolId`, `bsnEncrypted`). The `identity-federation` spec was therefore **dropped**.

This keeps the Dutch-gov-integration burden in OpenConnector (where it belongs and is reusable) while making the core model serve any institution — and it removes the EU AI Act exposure from the core (proctoring AI and adaptive learning are explicit `AiFeature` registrations behind the ADR-005 gate, not baked in).

---

## What this plan deliberately leaves out

This plan does **not** decide:
- Which Dutch organisation gets the first install (sales question)
- Whether Conduction hosts a managed SaaS Scholiq or only ships self-host (commercial question)
- Pricing (commercial question)
- Whether the K-12 wedge or the HE wedge ships second after Phase 1 (open — depends on which buyer surfaces first)

These belong in a separate go-to-market doc, not the architecture plan.
