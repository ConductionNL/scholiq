---
kind: code
depends_on: []
---

# Proposal: bpv-praktijkovereenkomst

## Why

MBO beroepspraktijkvorming (BPV) — the mandatory work-placement component of every
Dutch MBO qualification — has **zero coverage in Scholiq today**, and it is a hard
legal requirement, not a nice-to-have: WEB art. 7.2.8/7.2.9 requires a signed
**praktijkovereenkomst (POK)** for every BPV placement, and the placement's
employer must be a leerbedrijf **erkend by SBB** (Samenwerkingsorganisatie
Beroepsonderwijs Bedrijfsleven). Evidence: insight 1147 (legal-requirement,
high); stories `leerbedrijf-erkenning-check` (10064, critical),
`pok-genereren-ondertekenen` (10065), `werkproces-beoordeling-online` (10066),
`bpv-bezoekverslag-dossier` (10067); journeys 1742 (critical), 1743; source 6590
(MORA procesketen BPV); MBO Raad servicedocument POK. Incumbents handle this with
spreadsheets and paper three-party signing — direct, evidence-backed competitive
gap for the MBO wedge.

**Verified at HEAD — nothing exists:**
- Grepping `BPV|praktijk|POK|leerbedrijf|SBB` across `openspec/specs/*/spec.md`
  returns only false positives (`apphost-adoption`, `preferences-api` substring
  hits) — confirmed by re-running the sweep during this change.
- No `POK`/`praktijk`/`leerbedrijf`/`SBB` fields exist in
  `lib/Settings/scholiq_register.json` (grepped; zero real hits).
- No BPV pages exist in `src/manifest.json` (99 pages, none BPV-related).

**Reusable patterns already proven at HEAD (this change reuses them, not
reinvents them):**
- **Pluggable-provider pattern for external verification/scoring hooks.**
  `lib/Proctoring/ProvidesProctoring.php:61` (`interface ProvidesProctoring`) and
  `lib/Plagiarism/ProvidesPlagiarismCheck.php:41` (`interface
  ProvidesPlagiarismCheck`) are real, shipped PHP interfaces: Scholiq ships the
  seam, never a concrete adapter, and the config field
  (`Assessment.proctoring.provider`,
  `lib/Settings/scholiq_register.json:4583-4586`; `Assignment.plagiarismProvider`,
  `lib/Settings/scholiq_register.json:3852-3857`) names the adapter to resolve.
  This change adds a third interface, `ProvidesLeerbedrijfVerification`, for the
  SBB erkend-leerbedrijf check — so Scholiq works standalone (no openconnector
  required) and an SBB-register adapter can be built as a separate,
  `ConductionNL/openconnector` cross-repo follow-up without blocking this change.
- **Signature pattern, verified working at HEAD.** The `Signature` schema
  (`lib/Settings/scholiq_register.json:6263-6353`) is `appendOnly: true` and
  records `subjectKind` (enum, currently **only** `"learning-plan"`, default
  `"learning-plan"` — lines 6288-6296), `subjectId` (a UUID **hard-`$ref`'d to
  `LearningPlan`** — lines 6297-6303, not a polymorphic reference), `signerId`,
  `signerRole` (enum `learner|parent|coordinator|teacher|other` — lines
  6314-6324), `signedAt`, `assuranceLevel` (eIDAS: `none|basic|substantial|high`),
  `method`, `evidenceRef`. Because `subjectId`'s `$ref` is fixed to one schema
  (and the fleet's `hydra-gate-relation-dialect` bans bespoke/polymorphic
  relation shapes on a `format: uuid` property), this change does **not**
  widen `Signature` itself — it ships a parallel `PokSignature` schema with the
  **identical field shape and append-only semantics**, `$ref`'d to
  `Praktijkovereenkomst` instead. That is the literal, grounded meaning of
  "reuse the Signature pattern": same shape, same append-only guard idiom
  (`LearningPlanSignatureGuard` → `PokActivationGuard`), different subject.
- **External-portal access for people without a Nextcloud account is a solved,
  shipped problem — ADR-046 / portaliq.**
  `lib/Portal/PortalContributionProvider.php` is real, merged code (commits
  `3acf642`, `480259a`, `ea7b19f` — `git log --oneline -- lib/Portal/`), not a
  draft. It declares `getAudiences(): ['student', 'parent']` (line 89) and
  branches `getContribution()` per audience (lines 123-138). The `student`
  audience scopes every collection by a **direct** match —
  `record.learnerRef === subject.subjectRef` (lines 165-166,
  `scopeField`/`scopeClaim` both `learnerRef`) — and ships create-actions
  (`createSubmission`, `createExcuseRequest`, lines 292-323) because a direct
  scope match is safe to write against. The `parent` audience instead needs a
  **reverse one-hop join** (`guardianRefs` → child `learnerRef`, lines 365-458)
  and explicitly ships **no** create-action (lines 444-455) because portaliq's
  writer cannot yet verify a client-supplied cross-reference against that
  reverse join — a documented write-IDOR avoidance, not an oversight. A
  **praktijkopleider** (the workplace supervisor conducting BPV) is, by
  definition, a person without a Nextcloud account (ADR-046 premise) — exactly
  portaliq's target population — and their scope key (`praktijkopleiderId`) is a
  **direct** match on `BpvPlacement`/`WerkprocesAssessment`/`PokSignature`
  records (the placement/assessment/signature IS theirs, no reverse join
  needed), so this change follows the `student` shape (safe to ship
  create-actions), not the `parent` shape. `openspec/specs/portal-identity/spec.md`
  and `openspec/specs/portal-contribution/spec.md` (both `in-progress`, backed
  by the same merged code) confirm this is the house pattern, not a one-off.
- **CurriculumPlan already models the MBO opleidingsplan profile.**
  `lib/Settings/scholiq_register.json:2694-2706` — `CurriculumPlan.kind` enum
  already includes `"opleidingsplan"`; `components[].kind`
  (lines 2771-2781) is `assignment|assessment|participation`. A werkproces
  assessment is graded work against a CurriculumPlan component exactly like an
  `assignments`/`assessment` result is — `kind: "assessment"` fits without
  widening that enum, so `WerkprocesAssessment` references an existing
  `curriculumPlanId` + `componentId` pair and carries the kwalificatiedossier
  taxonomy (`kwalificatiedossierCode`, `kerntaakCode`, `werkprocesCode`,
  `werkprocesLabel`) as its own fields, rather than modifying `school-structure`.
- **Notification dialect has no external (email) channel at HEAD.** Grepping
  every `x-openregister-notifications.*.channels` value in
  `lib/Settings/scholiq_register.json` returns only `"nc-notification"` and
  `"activity"` — both Nextcloud-internal. A declared reminder cannot reach a
  praktijkopleider who has no NC account. This change scopes declared
  notifications to the internal parties only (student, school coordinator) and
  leaves the external party's visibility to the portal's own pull-based read
  surface — documented as a real gap in design.md, not silently ignored.

## What Changes

- **New capability `bpv`** (`openspec/specs/bpv/spec.md`, added by this change):
  `Praktijkopleider` (the workplace-supervisor identity anchor — no NC account
  by premise), `BpvPlacement` (learner ↔ leerbedrijf match, gated confirmation),
  `ProvidesLeerbedrijfVerification` (pluggable SBB-check interface, no bundled
  provider), `Praktijkovereenkomst` + `PokSignature` (three-party signing:
  student, school, praktijkopleider), `WerkprocesAssessment` (per-werkproces
  workplace assessment aligned to the kwalificatiedossier, emits a `GradeEntry`),
  `BpvVisitReport` (visit / three-way-conversation reports linked to the
  learner's `LearnerProfile` dossier).
- **`PortalContributionProvider`** (`lib/Portal/PortalContributionProvider.php`)
  gains a third audience, `praktijkopleider`, scoped by direct
  `praktijkopleiderId == subject.subjectRef` match: read collections for the
  supervisor's own `BpvPlacement`s (field-projected — school-internal columns
  dropped), and two whitelisted create-actions —
  `createWerkprocesAssessment` and `signPraktijkovereenkomst` — both
  `minTrust: substantial` (an official assessment/signature feeding a diploma
  record).
- **`ProvidesLeerbedrijfVerification`** (new PHP interface, `lib/Bpv/`): the
  single seam for an SBB erkend-leerbedrijf check. Scholiq ships **zero**
  concrete providers. **The SBB register adapter itself (an OpenConnector
  source configuration hitting SBB's leerbedrijvenregister) is explicit
  cross-repo follow-up work on `ConductionNL/openconnector` — not built in this
  change.** Scholiq works standalone without it (the interface simply has no
  configured provider, and `BpvPlacement` cannot confirm until one exists and
  returns `verified`).
- **`SignPokModal`** — a `type: "custom"` manifest page (mirrors the existing
  `SignPlanModal` pattern) mounting `CnSignatureCapture` from
  `@conduction/nextcloud-vue`, for the **internal** (student + school) legs of
  POK signing inside Scholiq's own UI. The praktijkopleider's signature is
  captured through the portal (portaliq), not this page.
- Declarative `src/manifest.json` index/detail pages for `BpvPlacement`,
  `Praktijkopleider`, `Praktijkovereenkomst`, `WerkprocesAssessment`,
  `BpvVisitReport`. No PHP CRUD controllers (ADR-022).

## Impact

- **`lib/Settings/scholiq_register.json`** — six new schemas
  (`Praktijkopleider`, `BpvPlacement`, `Praktijkovereenkomst`, `PokSignature`,
  `WerkprocesAssessment`, `BpvVisitReport`); register `info.version`
  0.3.1 → 0.4.0.
- **`lib/Bpv/ProvidesLeerbedrijfVerification.php`** (new) — pluggable interface,
  mirrors `ProvidesProctoring`/`ProvidesPlagiarismCheck`.
- **`lib/Lifecycle/`** — two new ADR-031 PHP-exception guards:
  `BpvConfirmationGuard` (blocks `BpvPlacement` confirm until leerbedrijf
  verification is `verified`) and `PokActivationGuard` (blocks
  `Praktijkovereenkomst` activation until all three `PokSignature`s exist),
  mirroring `LearningPlanSignatureGuard` / `AssessmentPublishGuard`.
- **`lib/Portal/PortalContributionProvider.php`** — add the `praktijkopleider`
  audience (new private method + `getAudiences()` return-array edit).
- **`src/manifest.json`** — new index/detail pages + `SignPokModal`.
- **No change to `openspec/specs/learning-plan/spec.md` or
  `openspec/specs/school-structure/spec.md`** — both surveyed patterns
  (`Signature`, `CurriculumPlan.components[].kind`) are reused **as-is**; see
  the Why section for the grounded reasons neither needed widening.
- **Out of scope (documented, not silently dropped):** the SBB OpenConnector
  adapter (cross-repo follow-up, see above); PDF/print rendering of the signed
  POK (a `docudesk` leaf note — the POK's governing state is the OpenRegister
  object + its three `PokSignature`s, not a rendered document; a docudesk
  template is a follow-up, not required for the POK to be legally complete);
  leerbedrijf re-verification / erkenning-expiry monitoring (this change only
  gates the initial confirmation; a renewal cycle is a follow-up once the SBB
  adapter exists); an email/external-recipient notification channel (documented
  gap in design.md — the declared `signatureRequested`/`visitDueReminder`
  notifications reach only NC-account holders).
