---
kind: code
depends_on: []
---

## Why

Scholiq has no e-portfolio capability at all, and no schema in the register comes close to it:

- **Zero hits.** A full case-insensitive grep of `lib/Settings/scholiq_register.json` for `Portfolio`
  returns nothing; the register's 59 top-level schemas (`AiFeature` … `XapiStatement`, enumerated in full
  via `python3 -c "import json; ... schemas.keys()"`) contain no `Portfolio`, `PortfolioTemplate`, or
  `PortfolioEntry` object. The string `portfolio` appears exactly twice in the whole spec corpus, both as a
  passing example, never as a modelled concept: `assignments/spec.md:9`'s `profiles: [opdracht-vo,
  opdracht-he, werkstuk, portfolio-item]` (a `Submission` *profile label*, not a portfolio object) and
  `certification/spec.md:211`'s Standards line — `"EDCI (Europass), Open Badges 3.0, **E-Portfolio NL**,
  Bologna Diploma Supplement, Schema.org EducationalOccupationalCredential"` — which names E-Portfolio NL as
  a standard Scholiq should interoperate with and then never implements a single field of it anywhere in
  `certification`'s Requirements section.
- **Portfolio-based qualification is core, not peripheral, to two of Scholiq's own domains.** MBO's BPV
  (beroepspraktijkvorming) already produces exactly the evidence a portfolio exists to organise —
  `WerkprocesAssessment` (`lib/Settings/scholiq_register.json`, `slug: werkproces-assessment`) records "one
  workplace assessment against one werkproces of the kwalificatiedossier" — but there is nowhere for a
  learner to assemble their werkproces assessments, reflections, and supporting evidence into the single
  qualification dossier the kwalificatiedossier structure implies. HBO/WO's own `assignments` spec already
  names `portfolio-item` as an assignment profile (`assignments/spec.md:9`) without ever building the
  multi-artefact, cross-course container a portfolio actually is — a `Submission` is one hand-in for one
  `Assignment`; nothing lets a learner curate evidence *across* assignments, courses, and terms.
- **NC Files solves storage/sharing/versioning for free; nothing links an artefact to the things that give
  it academic meaning.** `Submission.attachmentRefs` already establishes the reference-not-copy idiom
  scholiq uses for file evidence: `"OpenRegister file attachment references (nc:files paths or OR
  attachment IDs). The app does NOT store file bytes."` (`lib/Settings/scholiq_register.json`, `Submission`
  schema). What's missing is the *portfolio* layer above that: a container that can point at an NC file,
  *or* an existing `Submission`, *or* a `WerkprocesAssessment`, *or* an `ExternalTrainingRecord`, *or* a
  `Credential`, and roll a course-bound instance of that container into the existing grading pipeline the
  way every other gradable object already does.
- **Competitor products all ship this as a named, first-class feature.** OpenOLAT, It's Learning, ILIAS,
  and Claroline (the shortlist named in this brief) each ship a portfolio/e-portfolio module distinct from
  their assignment/submission flow — the durable, cross-course evidence container is table stakes for any
  MBO/HBO LMS, not an edge case.
- **The grading seam to reuse already exists and has a stable, repeatable shape.** `GradeRollupHandler`
  (`lib/Listener/GradeRollupHandler.php:414-478`, `handleAssessmentResultGraded()`) listens for
  `ObjectTransitionedEvent` (`register=scholiq`, `schema=assessment-result`, `to=graded`), creates a
  `concept` `GradeEntry` tagged with a dedicated `sourceKind` and a back-reference id field, then
  back-links the source object's own `gradeEntryId`. `WerkprocesGradeEmitHandler`
  (`lib/Listener/WerkprocesGradeEmitHandler.php`) does the equivalent for `WerkprocesAssessment.confirmed`.
  `GradeEntry.sourceKind` has already been extended twice this way — `exemption`
  (`exam-board-case-handling`, adding `exemptionCaseId`) and `lti-ags`
  (`lti-tool-placement`, adding `ltiToolPlacementId`/`ltiAgsResultId`,
  `openspec/changes/archive/2026-07-13-lti-tool-placement/specs/grading/spec.md`) — so adding a third
  `sourceKind: portfolio` + `portfolioId` is a direct, low-risk repeat of an established pattern, not a new
  mechanism.
- **The relation dialect for "one field, several possible target types" is already settled — and settled
  against a single polymorphic `$ref`.** `GradeEntry` itself models this with one nullable, typed `$ref`
  field per `sourceKind` (`submissionId`, `assessmentResultId`, `exemptionCaseId`, `fraudCaseId`,
  `ltiToolPlacementId` — `lib/Settings/scholiq_register.json:5418-5560`), and the
  `bpv-praktijkovereenkomst` design doc states the reason explicitly: *"the fleet's
  `hydra-gate-relation-dialect` gate explicitly bans bespoke/polymorphic relation shapes on a `format: uuid`
  property — a `$ref` must resolve to exactly one schema in the same register"*
  (`openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/design.md`). A `PortfolioEntry` that can
  evidence a `Submission` *or* a `WerkprocesAssessment` *or* an `ExternalTrainingRecord` *or* a `Credential`
  must follow the same per-kind-field shape, not a single polymorphic reference.
- **The sharing audiences to reuse already exist, and were built incrementally by exactly one precedent
  change.** `lib/Portal/PortalContributionProvider.php` (real, merged code — `git log --oneline -- lib/
  Portal/` shows commits `3acf642`, `ea7b19f`, `480259a`) currently serves three ADR-046 portaliq audiences
  — `getAudiences(): ['student', 'parent', 'praktijkopleider']` (line 79-88) — where `praktijkopleider` was
  added as the *third* audience by `bpv-praktijkovereenkomst`
  (`openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/design.md:116-207`), using a **direct
  scope-match** shape (`record.praktijkopleiderId == subject.subjectRef`) because `Praktijkopleider` is "a
  person with no Nextcloud account by definition" (`Praktijkopleider` schema description). This is the
  literal mechanism the brief asks to be reused for BPV praktijkopleider portfolio sharing (no schema/PHP
  change needed to the existing `praktijkopleider` audience's *plumbing*, only a new scoped collection); the
  same audience-extension mechanism (not a new sharing system) is what this change uses to add a fourth
  `external-assessor` audience for non-BPV external assessors, who have no existing audience to reuse
  because they are not praktijkopleiders and have no NC account either.
- **Teacher sharing needs no portal mechanism at all.** A teacher is already an NC user; Scholiq's existing
  per-object `x-property-rbac` posture (e.g. `Submission`, `AssessmentResult`, `GradeEntry` — all
  `admin`/self-match only, staff/teacher reach coming from the app's NC-group gate, not a per-object clause
  — confirmed by grepping all 15 `x-property-rbac` blocks in the register) already governs a course-bound
  portfolio's grading teacher the same way it governs every other gradable object. An *ad hoc* teacher share
  (a mentor who isn't the grading teacher of record) is a genuine gap this change closes using **native
  Nextcloud Files sharing** on the referenced attachments — reusing NC's own sharing/versioning machinery
  rather than inventing a parallel one, per the brief.
- **`competency-framework` does not exist in this worktree.** `ls openspec/changes/` (development branch,
  HEAD) has no `competency-framework` directory and no capability spec references a `Competency` schema
  anywhere in the register. This change therefore treats competency alignment as free-text
  (`competencyCode`/`competencyLabel` on `PortfolioEntry`) rather than a `$ref` to a schema that does not
  exist — a hard `$ref` to a non-existent schema would be a dangling reference and fail `openspec validate`/
  the register's own consistency checks. See design.md "Rejected alternatives" for the forward-compatibility
  plan once `competency-framework` lands.

## What Changes

- **New `eportfolio` capability**, five new OpenRegister schemas:
  - **`PortfolioTemplate`** — a teacher-authored structure (`sections[]`, each with `criteria[]` the
    learner must evidence, mirroring `LearningPlanTemplate.sections`'s ordered-section shape combined with
    `Rubric.criteria`'s criterion shape) plus an optional `rubricId` for course-bound scoring. Lifecycle
    `draft → active → archived` (mirrors `LearningPlanTemplate`).
  - **`Portfolio`** — the container itself, owned by the learner (`learnerId` + additive `learnerRef` per
    the `portal-identity` convention). Two flavours via `kind`: `personal` (spans the whole study, never
    submitted for grading) and `course-bound` (instantiated from a `PortfolioTemplate` as a graded course
    task, `courseId`/`curriculumPlanId`/`curriculumPlanComponentId` set). One `x-openregister-lifecycle`
    serves both flavours: `draft → active → archived` (personal) and `draft → submitted → graded →
    archived` (course-bound) share the same state machine, since a `personal` portfolio simply never calls
    `submit`/`grade`.
  - **`PortfolioEntry`** — one evidence item. `evidenceKind` (`file | submission | werkproces-assessment |
    external-training-record | credential | reflection`) plus one nullable, typed `$ref` field per kind
    (`attachmentRef`, `submissionId`, `werkprocesAssessmentId`, `externalTrainingRecordId`,
    `credentialId`) — the `GradeEntry.sourceKind` per-kind-field shape, not a polymorphic `$ref`. A
    `reflectionText` field carries the learner's own reflection, required when `evidenceKind: reflection`
    and optional alongside any other kind. Optional `sectionId`/`criterionId` (matching the governing
    `PortfolioTemplate.sections[]`) and optional free-text `competencyCode`/`competencyLabel`.
  - **`ExternalAssessor`** — a plain reference/identity object with no NC account, structurally identical to
    `Praktijkopleider` (`givenName`, `familyName`, `email`, `organisationName`, `active`) for the one
    audience that has no existing portal identity to reuse.
  - **`PortfolioShare`** — the single grant object for all three read-only sharing cases (`sharedWithKind`:
    `teacher | praktijkopleider | external-assessor`, one nullable typed field per kind — same per-kind
    shape as `PortfolioEntry`), `portfolioId`, optional `entryIds` (a selection, not a copy), `expiresAt`,
    `appendOnly: true`, lifecycle `draft → active → revoked`.
- **MODIFIED `grading` capability**: `GradeEntry.sourceKind` gains `portfolio`; new nullable `portfolioId`
  (`$ref: Portfolio`) field — the same additive shape `lti-ags`/`exemption` already used. New
  `PortfolioGradeEmitHandler` listener (mirrors `GradeRollupHandler::handleAssessmentResultGraded()`
  exactly): on `Portfolio.graded`, creates a `concept` `GradeEntry` from the teacher-entered
  `Portfolio.gradeValue`, then back-links `Portfolio.gradeEntryId` — this spec does not compute a final
  grade itself, the existing roll-up consumes the emitted entry unchanged.
- **New `PortfolioSubmissionGuard`** (ADR-031 PHP exception, mirrors `SubmissionWindowGuard`): blocks a
  course-bound portfolio's `submit` transition when its `PortfolioTemplate`'s required sections have no
  linked `PortfolioEntry`.
- **New `PortfolioShareGrantHandler`** (mirrors `WerkprocesGradeEmitHandler`'s event-listener shape): on
  `PortfolioShare.active`, for `sharedWithKind: teacher` calls Nextcloud's native `OCP\Share\IManager` to
  create a read-only share of the referenced portfolio's evidence attachments — reusing NC Files sharing,
  not a parallel mechanism. For `praktijkopleider`/`external-assessor`, no PHP action is needed: visibility
  is served declaratively by the `PortalContributionProvider` extension below.
- **Extend `lib/Portal/PortalContributionProvider.php`**: reuse the existing `praktijkopleider` audience
  unchanged (add one new scoped collection, no plumbing change), and add a fourth audience,
  `external-assessor`, following the exact mechanism `bpv-praktijkovereenkomst` used to add
  `praktijkopleider` as the third — `getAudiences()` gains one value, `getContribution()` gains one `if`
  branch, a new `externalAssessorContribution()` method with direct-match scoping
  (`PortfolioShare.sharedWithExternalAssessorId == subject.subjectRef`) plus the reverse-join shape the
  `parent` audience already uses to resolve `Portfolio`/`PortfolioEntry` rows from the matched shares.
- **Frontend**: declarative `src/manifest.json` index/detail pages for all five new objects, plus two named
  custom views — `PortfolioBuilder.vue` (the learner assembles a portfolio by *referencing* existing
  Submissions/WerkprocesAssessments/ExternalTrainingRecords/Credentials/NC files rather than typing raw
  UUIDs — the genuine reference-not-copy UX) and `PortfolioReviewView.vue` (the teacher/praktijkopleider/
  external-assessor read-only review surface, plus the teacher's `gradeValue`-entry and `grade` transition
  for course-bound portfolios).

## Impact

- **`lib/Settings/scholiq_register.json`** — five new schemas (`PortfolioTemplate`, `Portfolio`,
  `PortfolioEntry`, `ExternalAssessor`, `PortfolioShare`); `GradeEntry` gains `sourceKind: portfolio` +
  `portfolioId` (additive, mirrors the `lti-ags`/`exemption` precedent — no existing row invalidated).
- **New PHP** — `OCA\Scholiq\Listener\PortfolioGradeEmitHandler`, `OCA\Scholiq\Lifecycle\
  PortfolioSubmissionGuard`, `OCA\Scholiq\Listener\PortfolioShareGrantHandler`. `lib/Portal/
  PortalContributionProvider.php` gains one collection on the existing `praktijkopleider` audience and a
  new `external-assessor` audience. No new controller, no new route (consumes OR's object API + portaliq's
  existing discovery contract).
- **`src/manifest.json`** — index/detail pages for the five new objects; two new custom views
  (`PortfolioBuilder.vue`, `PortfolioReviewView.vue`).
- **Affected specs**: new `eportfolio` capability spec; `grading` (MODIFIED-by-addition:
  `sourceKind: portfolio`, `portfolioId`). `bpv`, `assignments`, `certification`, `portal-identity`, and
  `portal-contribution` are read-only precedents, not modified.
- **Out of scope**: the `competency-framework` taxonomy itself (this change only reserves free-text
  `competencyCode`/`competencyLabel` fields, forward-compatible — see design.md); per-entry locking once a
  portfolio is graded (entries stay editable post-grade, mirroring the fact that `GradeEntry` itself, not
  its sources, is the immutability boundary — a future change could add a freeze guard); automated NC-Files
  share revocation cascading from `PortfolioShare.revoked` (the `revoke` transition updates the grant
  record; actually unsharing the underlying NC file share is a `PortfolioShareGrantHandler` follow-up this
  change does not implement — flagged, not silently dropped).
