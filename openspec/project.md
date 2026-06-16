# Scholiq — OpenSpec

This folder contains the OpenSpec change proposals and capability specifications for **Scholiq**, the open-source education platform for Nextcloud (LVS + LMS + training + certification + exam delivery).

## What is OpenSpec here?

OpenSpec is the spec-driven workflow used across all ConductionNL apps. Every meaningful change to Scholiq starts as a *proposal* under `openspec/changes/`, and every long-lived capability lives as a *spec* under `openspec/specs/{capability}/spec.md`. Implementation PRs trace back to a spec and an ADR — there is no code without a spec, and no spec without an evidence-backed insight from the intelligence DB.

## Folder Layout

```
openspec/
├── config.yaml          # App identity, depends_on, standards, design rules
├── project.md           # This file
├── changes/             # Active OpenSpec change proposals (one folder per change)
│   └── {change-id}/
│       ├── proposal.md
│       ├── tasks.md
│       └── context-brief.md   # Pure DB assembly from Specter (no LLM)
└── specs/               # Long-lived capability specs (one folder per capability)
    └── {capability-slug}/
        └── spec.md
```

## The 13 Capability Specs

**Phase 1 — the compliance-audit wedge (built):**

| # | Slug | Purpose |
|---|------|---------|
| 1 | nextcloud-app | NC app shell — CnAppRoot Tier-4 manifest, OpenRegister dep check, NL Design |
| 2 | course-management | Authoring of courses (recursive) + lessons; cmi5/xAPI runtime, SCORM shim |
| 3 | enrolment | Enrol/un-enrol learners, bulk enrol, mandatory flag, due_date, reminder cascade |
| 4 | certification | Open Badges 3.0 issuing, expiry detection, renewal (EDCI = Phase 3) |
| 5 | compliance-audit | Regulation tracking, coverage %, attestation capture, audit-pack export, NIS2 board proof |
| 6 | dashboard | Role-aware dashboards (compliance / hr / manager / learner) |

**Phase 2 — the generic educational-institution core (planned; restructured 2026-05-12 from 8 Dutch-specific stubs into 7 jurisdiction-neutral specs — Dutch requirements are *profiles*):**

| # | Slug | Purpose | Dutch profile |
|---|------|---------|---------------|
| 7 | school-structure | Programmes, CurriculumPlans (assessment components + weighting), Cohorts, Sessions, Materials | PTA / OER-studiegids / opleidingsplan |
| 8 | assignments | Assignments, Submissions, Rubrics, hand-in flow | opdracht / werkstuk / portfolio |
| 9 | assessment | Tests/exams, QTI 3.0 item banks, **pluggable proctoring (config on an Assessment)** | toets / tentamen / examen |
| 10 | grading | Grade entries, scales, final-grade roll-up per CurriculumPlan, soft-publish | PTA-kolom / SE-gemiddelde / eindcijfer |
| 11 | learning-plan | Individual learning plans — goals, support measures, evaluations, co-signatures | OPP / handelingsplan |
| 12 | attendance | Attendance records, excuse requests, threshold rules → flags | leerplicht 16-uur |
| 13 | data-exchange | Export/import jobs to external registries — **delegates the wire protocol to OpenConnector** | BRON/ROD · OSO · leerplichtmelding |

Federated authentication (DigiD / SURFconext / eduID) and the NL-gov wire protocols (Edukoppeling, StUF, OSO XML, OOAPI, SAML attribute release) are **OpenConnector adapters + Nextcloud auth providers**, not Scholiq specs (the former `identity-federation` spec was dropped). EU AI Act high-risk surfaces (adaptive learning, AI item generation, AI essay scoring, proctoring AI) are explicit `AiFeature` registrations behind the ADR-005 gate — not baked into any spec.

## Architecture in One Paragraph

Scholiq is a thin client. It owns no database tables. All entities are persisted as JSON objects in **OpenRegister**, validated against schemas in `lib/Settings/scholiq_register.json`, with business logic declared via `x-openregister-lifecycle / -calculations / -aggregations / -notifications / -widgets` (ADR-031) — PHP only for legitimate seams (lifecycle guards, cryptographic ops, document generation, external-system bridges). The frontend is `src/manifest.json` + `@conduction/nextcloud-vue` CnAppRoot (ADR-024 Tier-4) — manifest-declared pages, not bespoke CRUD components. All NL gatekeeper integrations (BRON/ROD, UWLR, OSO, Edukoppeling, Studielink, Digikoppeling, SURFconext) live in **OpenConnector** — Scholiq never makes outbound HTTP to DUO directly; the `data-exchange` spec is the thin Scholiq side that queues jobs and delegates. Richer dashboards/analytics surface via **launchpad** through runtime GraphQL.

## Standards Posture

Scholiq is built around the international learning stack (cmi5 + xAPI primary, SCORM shim, IMS QTI 3.0, LTI 1.3, EDCI/Europass, Open Badges 3.0, OOAPI 5.0) with the NL gatekeeper stack (Edu-K, Edukoppeling, UWLR, OSO, ROD, ROSA, SchoolID/ECK iD) reached through OpenConnector. EU AI Act high-risk surfaces are feature-flag gated (`AiFeature` + DPO acknowledgement) and audit-logged. Pupil identifiers are pseudonymised — BSN never leaves OpenConnector.

## Status

All 14 specs are at status `idea`. ADRs ADR-001 … ADR-007 (pseudonymisation, content runtime, identity, assessment engine, AI Act gating, NL adapters, multi-tenancy) are pending — referenced as `TODO` from each spec's frontmatter until they land in `hydra/openspec/`.

## See Also

- `../docs/ARCHITECTURE.md` — entity definitions, standards research, data model decisions
- `../docs/FEATURES.md` — full 354-feature competitive analysis with tier classification
- `concurrentie-analyse/briefs/scholiq-context.md` — intelligence brief that seeded these specs
- `hydra/openspec/` — company-wide ADRs and shared specs
