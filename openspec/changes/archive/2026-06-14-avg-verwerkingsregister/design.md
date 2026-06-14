## Context

Reworked per the 2026-06-11 abstraction decision: procest, docudesk, and scholiq authored near-identical AVG processing-register changes the same day, so the verwerkingsregister is a platform abstraction owned by OpenRegister (`processing-activity-register`, OR-PA-1..9 — its design.md maps scholiq S1–S6 requirement-by-requirement). Scholiq keeps only what the platform cannot know: its catalogue content, its UI surfacing, and its audit-pack inclusion.

## Division of labour

| Concern | Owner |
|---|---|
| ProcessingActivity entity, Art. 30(1) validation, lifecycle, versioning, owner/review notifications | OpenRegister (OR-PA-1) — scholiq sets the fields |
| Seed-as-draft, upsert-by-code, never-overwrite-officer-edits | OpenRegister (OR-PA-2) — scholiq supplies the content |
| Art. 30(4) export engine (CSV/JSON/PDF) | OpenRegister (OR-PA-7) — scholiq fetches and includes |
| RBAC, privacy-officer delegation, register-slice scoping | OpenRegister (OR-PA-8) |
| Catalogue content (~7 activities), Compliance manifest pages, audit-pack artefact step | **scholiq (this change)** |

## D1 — Catalogue references, never copies

Seed entries reference `bsnEncrypted`/`eckId`/`schoolId`/`actorIp` descriptively as categories of personal data; no value is ever copied into the register. LearnerProfile and all existing schemas are untouched.

## D2 — The school is the controller

Seeds arrive as drafts; the school's privacy officer reviews, amends, and activates in the platform lifecycle. Scholiq pre-fills Kennisnet-Aanpak-IBP-quality content but never auto-activates.

## D3 — Audit pack stays an aggregator

The compliance audit-pack ZIP already aggregates artefacts; the verwerkingsregister becomes one more fetched artefact (OR-PA-7 export, scholiq slice). Missing platform capability degrades loudly (warning in the pack manifest), never silently.
