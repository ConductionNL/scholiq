# Design: eportfolio

## Context

Scholiq has two grading pipelines that already prove the pattern this change needs: a source object
transitions to a terminal "graded/confirmed" state, a PHP listener bridges it into a `concept` `GradeEntry`,
and the existing `grading` roll-up (`GradeRollupHandler::handleGradeEntryPublished()` +
`GradeFormulaEvaluator`) takes it from there. It also has an ADR-046 portal (`portaliq`) with a settled,
incrementally-extended audience model. What it lacks is a *container* that lets a learner curate evidence —
files, submissions, workplace assessments, external training, credentials, reflections — into one
coherent, sometimes-graded, sometimes-shared portfolio. This document works out three things: the
reference-not-copy data model, how a course-bound portfolio reuses the existing grading seam without a
parallel mechanism, and how read-only sharing reuses NC Files sharing + the portal's audience model instead
of inventing a third access-control system.

## Goals / Non-Goals

**Goals**
- Model a portfolio as a container that *references* existing evidence objects (an NC file, a `Submission`,
  a `WerkprocesAssessment`, an `ExternalTrainingRecord`, a `Credential`) or a free-text reflection — never
  copies them.
- Support two lifecycles from one schema: `personal` (spans the whole study, ungraded) and `course-bound`
  (instantiated from a template, graded through the existing `GradeEntry` pipeline).
- Let a learner grant read-only access to a portfolio (or a selection of its entries) to a teacher, a BPV
  praktijkopleider, or an external assessor — reusing NC Files sharing for the NC-account case and the
  existing `PortalContributionProvider` audience mechanism for the two no-NC-account cases.
- Leave room for the `competency-framework` change (not yet built) to govern competency claims without this
  change creating a dangling reference today.

**Non-Goals**
- Building the `competency-framework` taxonomy itself (a sibling wave-2 concern this change does not own).
- Per-entry locking once a portfolio is graded (out of scope for size M — see proposal.md "Out of scope").
- A generic "share anything with anyone" primitive across the whole app — `PortfolioShare` is scoped to
  portfolios only, mirroring how `bpv-praktijkovereenkomst` added `praktijkopleider` as a scoped audience
  rather than generalising `PortalContributionProvider` into a universal ACL engine.
- Automating NC-Files share *revocation* end-to-end (flagged as a follow-up in proposal.md "Out of scope").

## Data Model

```
PortfolioTemplate (teacher-authored; draft → active → archived)
  sections[]: { sectionId, label, order, criteria[]: { criterionId, label, description,
                competencyCode?, competencyLabel? } }
  rubricId?  (→ Rubric, optional course-bound scoring)
        │
        │ templateId (nullable — personal portfolios may have none)
        ▼
Portfolio (learnerId + learnerRef; kind: personal | course-bound)
  kind=personal:      draft → active → archived            (never submitted/graded)
  kind=course-bound:  draft → submitted → graded → archived
        courseId, curriculumPlanId, curriculumPlanComponentId  (set only when kind=course-bound)
        gradeValue (teacher-entered, mirrors Submission.proposedGrade)
        gradeEntryId (nullable → GradeEntry; back-linked by PortfolioGradeEmitHandler on `graded`)
        │
        │ portfolioId
        ▼
PortfolioEntry (one evidence item; no lifecycle of its own)
  evidenceKind: file | submission | werkproces-assessment | external-training-record |
                credential | reflection
  attachmentRef? | submissionId? (→Submission) | werkprocesAssessmentId? (→WerkprocesAssessment) |
  externalTrainingRecordId? (→ExternalTrainingRecord) | credentialId? (→Credential)
  reflectionText?  (required when evidenceKind=reflection; optional alongside any other kind)
  sectionId?, criterionId?  (matches the governing PortfolioTemplate.sections[], when templated)
  competencyCode?, competencyLabel?  (free text — see "Competency alignment" below)

PortfolioShare (grant; draft → active → revoked; appendOnly)
  portfolioId (→Portfolio), entryIds? (a selection of PortfolioEntry ids; null = whole portfolio)
  sharedWithKind: teacher | praktijkopleider | external-assessor
  sharedWithTeacherId? (NC uid) | sharedWithPraktijkopleiderId? (→Praktijkopleider) |
  sharedWithExternalAssessorId? (→ExternalAssessor)
  sharedBy (NC uid), expiresAt?

ExternalAssessor (plain identity object, no NC account — structurally == Praktijkopleider)
  givenName, familyName, email, organisationName?, active
```

### `Portfolio.kind` — one schema, two flavours, one lifecycle

The brief asks for `PERSONAL` and `COURSE-BOUND` as two flavours of the same concept, not two schemas.
`LearningPlan.kind` (`opp | handelingsplan | iep | pdp | idp | generic`) already establishes the precedent
of one schema serving several profiles via an enum discriminator (`lib/Settings/scholiq_register.json`,
`LearningPlan` schema) — `Portfolio.kind` follows the same shape with two values instead of six.

The lifecycle union (`draft → active → archived` for `personal`; `draft → submitted → graded → archived`
for `course-bound`) is a single `x-openregister-lifecycle` state machine with five states
(`draft`, `active`, `submitted`, `graded`, `archived`) and transitions `activate` (`draft → active`),
`submit` (`draft|active → submitted`, guarded by `PortfolioSubmissionGuard`, `course-bound` only in
practice since a `personal` portfolio's UI never offers the action), `grade` (`submitted → graded`, the
transition `PortfolioGradeEmitHandler` listens for — mirrors `AssessmentResult`'s own `graded` target term,
`lib/Settings/scholiq_register.json`, `AssessmentResult.x-openregister-lifecycle`), `archive`
(`active|graded → archived`), `reactivate` (`archived → active`). A single state machine covering both
flavours is cheaper than two lifecycle definitions and matches how `WerkprocesAssessment` reuses
`CurriculumPlan.components[].kind: "assessment"` rather than adding a bespoke kind — reuse the existing
category, don't fork the machinery.

**Rejected alternative**: two separate schemas (`PersonalPortfolio`, `CoursePortfolio`). Rejected because
`PortfolioEntry`, `PortfolioShare`, and the manifest/UI would all need to branch on which parent schema they
point at, doubling the `$ref` surface for zero behavioural gain — the `kind` discriminator is the same
technique `LearningPlan` already uses successfully for a harder case (six profiles, not two).

### `PortfolioEntry` — reference-not-copy via the `GradeEntry.sourceKind` shape, not a polymorphic `$ref`

`evidenceKind` + one nullable typed `$ref` field per kind is a direct copy of `GradeEntry.sourceKind`'s
established shape (`submissionId` / `assessmentResultId` / `sessionId` / `exemptionCaseId` / `fraudCaseId` /
`ltiToolPlacementId`, one per `sourceKind` value). The `bpv-praktijkovereenkomst` design doc states the
reason explicitly and it applies identically here: the fleet's `hydra-gate-relation-dialect` gate bans a
`format: uuid` property whose `$ref` could resolve to more than one schema (a "bespoke/polymorphic relation
shape"). `PortfolioEntry` referencing five different possible evidence types is exactly the shape that gate
exists to catch if modelled as one polymorphic field — so it isn't.

**Rejected alternative**: `LearningPlan.goals[].evidenceRefs` — a bare `{type: string, format: uuid}` array
with **no** `$ref` at all, used for "supporting evidence" pointers the UI merely displays. This is lighter
weight and would technically dodge the relation-dialect gate (no `$ref` key means nothing to be polymorphic
about), but it also means the backend can never resolve *what kind* of object a given UUID names without an
out-of-band lookup across every possible schema — unusable for `PortfolioGradeEmitHandler`-style typed
joins and for the field-projected portal collections below, both of which need to know exactly which schema
to query. The per-kind-field shape costs five small JSON blocks; the untyped-array shape would have cost a
runtime type-sniffing routine this codebase has no precedent for. Chosen: per-kind fields.

For the NC file case specifically, `attachmentRef` is a bare string (no `$ref`), mirroring
`Submission.attachmentRefs`'s own documented shape: `"OpenRegister file attachment references (nc:files
paths or OR attachment IDs). The app does NOT store file bytes."` — an NC file path/attachment id is not an
OpenRegister object, so there is no schema for a `$ref` to resolve to; the existing precedent already
treats this as an untyped string, and `PortfolioEntry` does the same.

### Competency alignment — free text now, forward-compatible later

The brief flags `competency-framework` as a sibling wave-2 change that "adds the taxonomy" and says to
reference it as `depends_on`/optional. At HEAD in this worktree, `openspec/changes/` has **no**
`competency-framework` directory — it has not been created yet, so this change cannot `depends_on` it (a
non-existent slug) and cannot `$ref` a `Competency` schema that does not exist (a dangling reference that
would fail the register's own consistency checks and this change's own `openspec validate --strict`).

`PortfolioEntry.competencyCode`/`competencyLabel` are therefore plain, optional strings — a learner or
teacher can tag an entry "this evidences 2.3 Kwaliteitszorg" today without any taxonomy backing it.

**Rejected alternative**: add `PortfolioEntry.competencyId: $ref Competency` now, pointing at a schema
`competency-framework` will introduce later. Rejected — OpenSpec/OpenRegister has no forward-declaration
mechanism for a `$ref` target that doesn't exist yet; shipping it would either fail validation immediately
or ship a silently-broken reference until `competency-framework` lands in some specific, currently-unknown
shape this change cannot predict. The chosen free-text fields are honest about the current state and cost
nothing to migrate later: once `competency-framework` ships a `Competency` schema, a follow-up change can
add an additive, optional `competencyId` `$ref` field alongside the free-text ones (existing entries keep
their `competencyCode`/`competencyLabel` as a human-readable fallback), the same additive-remap pattern
`portal-identity` already used to add `learnerRef` alongside `learnerId` without breaking anything.

## The Grading Seam — mirrored exactly, not reinvented

`GradeRollupHandler::handleAssessmentResultGraded()` (`lib/Listener/GradeRollupHandler.php:414-478`) is the
literal template:

```
listen: ObjectTransitionedEvent, register=scholiq, schema=assessment-result, to=graded
  → skip if gradeEntryId already set (no duplicate)
  → build a concept GradeEntry: sourceKind, {kind}Id, value, gradeScaleId, grader, gradedAt, tenant_id
  → objectService->saveObject(grade-entry, ...)
  → back-link the source object's own gradeEntryId
```

`PortfolioGradeEmitHandler` follows this exactly:

```
listen: ObjectTransitionedEvent, register=scholiq, schema=portfolio, to=graded
  → skip if Portfolio.gradeEntryId already set
  → build a concept GradeEntry: sourceKind='portfolio', portfolioId=<this Portfolio>,
    value=Portfolio.gradeValue, curriculumPlanId=Portfolio.curriculumPlanId,
    componentId=Portfolio.curriculumPlanComponentId, gradeScaleId=<resolved from CurriculumPlan,
    same lookup WerkprocesGradeEmitHandler already performs>, grader=<teacher who drove `grade`>,
    gradedAt=now, tenant_id
  → objectService->saveObject(grade-entry, ...)
  → back-link Portfolio.gradeEntryId
```

This spec (like `assignments` and `bpv` before it) computes no final grade itself — the existing
`GradeRollupHandler::handleGradeEntryPublished()` → `GradeFormulaEvaluator` roll-up consumes the emitted
`concept` entry unchanged once a teacher publishes it via `GradebookView`, exactly as it already does for
`assignment-submission`/`assessment-result`/`manual`/`exemption`/`lti-ags` entries. `Portfolio.gradeValue`
is teacher-entered (mirrors `Submission.proposedGrade`) rather than auto-computed from `rubricScores`,
because a portfolio's evidence is heterogeneous (files, external records, reflections) — there is no single
scoring algorithm across evidence kinds the way there is for a `Rubric`'s uniform criteria; if a
`PortfolioTemplate.rubricId` is set, the teacher applies it manually in `PortfolioReviewView` and enters the
resulting `gradeValue`, the same way an HBO teacher applies a rubric to a `Submission` today.

**Rejected alternative**: computing `gradeValue` declaratively from `rubricScores` on the entries the way
`Submission.proposedGrade` sums `rubricScores[].points`. Rejected for this change because a portfolio's
entries are not uniformly rubric-scored — a `reflection` entry and a `credential` entry don't carry
`rubricScores` the way a `Submission` does — so a cross-entry sum would need a bespoke aggregation with no
clean JSON-logic expression (an ADR-031 PHP-exception territory of its own). Left as explicit teacher entry
for size M; a future change could add rubric-scored entries if a school's assessment model wants it.

## Sharing — NC Files sharing + the existing portal audience mechanism, no new system

Three recipient kinds, three different access realities:

1. **Teacher (has an NC account).** The grading teacher of a `course-bound` portfolio already has reach via
   Scholiq's existing NC-group-gated access (the same mechanism that lets a teacher read `Submission`/
   `AssessmentResult`/`GradeEntry` today despite none of those schemas listing a `teacher` role in
   `x-property-rbac` — confirmed by reading all 15 `x-property-rbac` blocks in the register: staff reach is
   an app-level NC-group gate, not a per-object clause, and this change does not change that). An *ad hoc*
   share to a teacher who is **not** the grading teacher of record (e.g. a mentor) is the genuine new case,
   and it is solved with **native Nextcloud Files sharing**: `PortfolioShareGrantHandler`, on
   `PortfolioShare.active` with `sharedWithKind: teacher`, resolves the NC file paths behind the shared
   `PortfolioEntry`(ies)' `attachmentRef`s and calls `OCP\Share\IManager::createShare()` for a read-only
   share targeting `sharedWithTeacherId`. This is literally "NC Files gives storage/sharing/versioning for
   free" — reused, not reimplemented.
2. **BPV praktijkopleider (no NC account, existing portal audience).** `PortalContributionProvider` already
   serves `praktijkopleider` as a direct-scope audience (`bpv-praktijkovereenkomst`,
   `getAudiences(): ['student', 'parent', 'praktijkopleider']`). This change adds **one new collection** to
   the existing `praktijkopleiderContribution()` method — no change to `getAudiences()`, no new `if` branch,
   no plumbing change — scoped by the same direct-match shape `poBpvPlacements` already uses
   (`PortfolioShare.sharedWithPraktijkopleiderId == subject.subjectRef`), field-projecting the shared
   `Portfolio`/`PortfolioEntry` rows.
3. **External assessor (no NC account, no existing audience).** This is the one genuinely new case — there
   is no existing audience an external, non-BPV assessor fits into. This change follows the *mechanism*
   `bpv-praktijkovereenkomst` used to add `praktijkopleider` as the third audience — not the `praktijkopleider`
   audience itself — to add a fourth, `external-assessor`: `getAudiences()` gains one value,
   `getContribution()` gains one `if` branch, a new `externalAssessorContribution()` method direct-matches
   `PortfolioShare.sharedWithExternalAssessorId == subject.subjectRef` for the grant collection, then
   resolves the matched `portfolioId`s and reads `Portfolio`/`PortfolioEntry` scoped to that set — the same
   reverse one-hop join shape the `parent` audience already uses to go from a guardian's `subjectRef`
   through `LearnerProfile.guardianRefs` to the children's own records
   (`openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/design.md`, "praktijkopleider as a third
   audience" section, describing the `parent`/`student` scoping shapes this change's `external-assessor`
   follows for the resolve-then-filter half).

`entryIds` ("a selection, not the whole portfolio") is enforced by field-projection at read time — the
collection reader filters `PortfolioEntry` rows to `portfolioId == <matched>` AND (`PortfolioShare.entryIds`
is null OR the entry's id is in it) — the same technique the `student` audience already uses to drop
staff-only columns from a read collection, applied to rows instead of columns. This change does not assume
or build a portaliq join primitive beyond the documented reverse-`scopeField` join the `parent` audience
already proved works; the entry-selection filter is Scholiq-side logic in the collection resolver, not a
new portaliq capability.

**Rejected alternative**: giving `praktijkopleider`/`external-assessor` blanket read access to every
portfolio tied to a `BpvPlacement`/course they're associated with, without an explicit `PortfolioShare`
grant. Rejected on least-privilege grounds — a praktijkopleider supervising a BPV placement should not
automatically see a learner's *personal* portfolio or unrelated course-bound ones; requiring an explicit,
revocable, optionally-scoped-to-a-selection grant is deliberately the same posture BPV's own
`Praktijkopleider` access already takes (scoped to `BpvPlacement`s that literally name them, never a
blanket learner-wide grant).

## Security / RBAC Posture

- `Portfolio`/`PortfolioEntry` default `x-property-rbac`: `admin` + self (`learnerId` match) — mirrors
  `Submission`/`AssessmentResult` exactly. No portal/share-derived access is expressed as a register-level
  `x-property-rbac` clause (this codebase's `x-property-rbac` `match` operator is `eq`-only against a field
  on the *same* object — confirmed by grepping every `operator` value used in the register — so a
  cross-object grant like `PortfolioShare` cannot be expressed there); portal reads are served by
  `PortalContributionProvider` (a separate, already-audited surface), and teacher ad hoc reads are served by
  NC Files' own share ACL on the underlying attachments, not by widening `Portfolio`'s own RBAC block.
- `PortfolioShare` creation is not self-service by the share recipient — only the portfolio's own learner
  (self-match) or `admin`/`teacher`-role staff may create a share (mirrors `x-openregister-authorization
  .create` restricting `BsaWarning`/`BsaDecision` away from the learner who could otherwise author their own
  evidence trail — here the restriction is symmetric: a recipient cannot grant themselves access).
- `PortfolioShare` is `appendOnly: true` (audit trail of who was granted access to a learner's evidence and
  when) — same posture as `AttendanceFlag`/`BsaProgressFlag`, which stay `appendOnly` while still exposing a
  `lifecycle` transition (`revoke`) that changes state without deleting the row.
- `PortalContributionProvider`'s field-projection discipline (an explicit `fields` whitelist per collection,
  documented in `portal-contribution/design.md`) is followed for both the new `praktijkopleider` collection
  and the new `external-assessor` audience — no internal/staff-only field (e.g. `gradeValue` before a
  portfolio is graded) is exposed to a portal audience.

## PERSONAL vs COURSE-BOUND Lifecycle Summary

| | `personal` | `course-bound` |
|---|---|---|
| Created by | learner, freely | a course task instantiates a `PortfolioTemplate` |
| `templateId` | optional | required in practice (not enforced by JSON Schema `required`, since a teacher could still assign an untemplated course task) |
| Transitions used | `draft → active → archived` | `draft → submitted → graded → archived` |
| Grading | never | `PortfolioGradeEmitHandler` on `graded` → `GradeEntry` |
| Sharing | learner-initiated `PortfolioShare` only | same, plus the grading teacher's existing implicit staff access |
