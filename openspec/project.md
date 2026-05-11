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

## The 14 Capability Specs

| # | Slug | Purpose |
|---|------|---------|
| 1 | course-management | Authoring of courses, modules, lessons; learning paths |
| 2 | enrolment | Enrol/un-enrol learners, cohorts, bulk operations, eligibility |
| 3 | assessment-engine | IMS QTI 3.0 test authoring, item banks, exam scheduling |
| 4 | proctoring | Online proctored exam orchestration with pluggable provider |
| 5 | certification | EDCI / Open Badges 3.0 issuing, expiry detection, renewal |
| 6 | compliance-audit | AVG/BIO refresher, attestation capture, audit pack export, NIS2 board proof |
| 7 | grading-pta | Grade entry, PTA weighting per kolom (NL VO), parent publication |
| 8 | opp-cycle | Ontwikkelingsperspectief — template, parent signing, quarterly evaluation |
| 9 | bron-rod-exchange | DUO BRON/ROD exchange, inline correction of afkeurmeldingen |
| 10 | oso-transfer | OSO transfer dossier PO → VO |
| 11 | absence-leerplicht | Sick reporting via DigiD, mentor view, 16-uur leerplicht threshold |
| 12 | identity-federation | SURFconext / Studielink / DigiD / user-saml; SchoolID + ECK iD |
| 13 | dashboard | Role-aware dashboards (teacher / student / parent / HR / compliance / inspector) |
| 14 | nextcloud-app | Nextcloud app shell — settings, OpenRegister dep check, Vue Router, NL Design |

## Architecture in One Paragraph

Scholiq is a thin client. It owns no database tables. All entities (Course, Module, Lesson, Enrolment, Cohort, Exam, ItemBank, Certificate, OPP, Grade, AbsenceRecord, BronExchange, OsoDossier, ProctoringSession) are persisted as JSON objects in **OpenRegister**, validated against schemas in `lib/Settings/scholiq_register.json`. All NL gatekeeper integrations (BRON/ROD, UWLR, OSO, Edukoppeling, Studielink, Digikoppeling) live in **OpenConnector** — Scholiq never makes outbound HTTP to DUO directly. The Vue 2.7 frontend uses `@conduction/nextcloud-vue` components and Pinia stores backed by OpenRegister REST + GraphQL. Dashboards and analytics are surfaced via **mydash** when richer charting is needed.

## Standards Posture

Scholiq is built around the NL gatekeeper stack (Edu-K, Edukoppeling, UWLR, OSO, ROD, ROSA, SchoolID/ECK iD) and the international learning stack (cmi5 + xAPI primary, SCORM shim, IMS QTI 3.0, LTI 1.3, EDCI/Europass, Open Badges 3.0). EU AI Act high-risk surfaces (adaptive learning, proctoring) are feature-flag gated and audit-logged. Pupil identifiers are pseudonymised — BSN never leaves OpenConnector.

## Status

All 14 specs are at status `idea`. ADRs ADR-001 … ADR-007 (pseudonymisation, content runtime, identity, assessment engine, AI Act gating, NL adapters, multi-tenancy) are pending — referenced as `TODO` from each spec's frontmatter until they land in `hydra/openspec/`.

## See Also

- `../docs/ARCHITECTURE.md` — entity definitions, standards research, data model decisions
- `../docs/FEATURES.md` — full 354-feature competitive analysis with tier classification
- `concurrentie-analyse/briefs/scholiq-context.md` — intelligence brief that seeded these specs
- `hydra/openspec/` — company-wide ADRs and shared specs
