# eportfolio Specification

## Purpose
TBD - created by archiving change eportfolio. Update Purpose after archive.
## Requirements
### Requirement: Persist e-portfolio domain objects in OpenRegister

The system MUST persist `PortfolioTemplate`, `Portfolio`, `PortfolioEntry`, `ExternalAssessor`, and
`PortfolioShare` as OpenRegister objects. `PortfolioTemplate` MUST carry `x-openregister-lifecycle`
(`draft → active → archived`, mirroring `LearningPlanTemplate`). `Portfolio` MUST carry a single
`x-openregister-lifecycle` covering both flavours (`draft → active → archived` for `personal`;
`draft → submitted → graded → archived` for `course-bound`). `PortfolioShare` MUST be `appendOnly: true`
with its own `x-openregister-lifecycle` (`draft → active → revoked`). `PortfolioEntry` and
`ExternalAssessor` are plain objects with no lifecycle of their own (mirroring `Praktijkopleider`).

#### Scenario: E-portfolio objects persist in OpenRegister with the correct lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON (no scholiq DOM surface to drive registration itself), mirroring bsa-study-progress-guard's equivalent scenario. -->

- **GIVEN** the `eportfolio` schemas are registered in OpenRegister
- **WHEN** a `PortfolioTemplate`, `Portfolio`, `PortfolioEntry`, `ExternalAssessor`, or `PortfolioShare` is
  created
- **THEN** it is stored as an OpenRegister object with its declared lifecycle (or none, for the two plain
  objects)
- **AND** `PortfolioShare` is `appendOnly: true`

### Requirement: `Portfolio.kind` selects between a personal and a course-bound flavour on one schema

`Portfolio` MUST support exactly two `kind` values: `personal` (spans the whole study; created freely by
the learner; never transitions past `active`) and `course-bound` (instantiated from a `PortfolioTemplate`
as a graded course task; carries `courseId`, `curriculumPlanId`, and `curriculumPlanComponentId`). Both
flavours MUST share the same `x-openregister-lifecycle` state machine (`draft`, `active`, `submitted`,
`graded`, `archived`) rather than two separate schemas or two separate lifecycle definitions — a `personal`
portfolio simply never calls the `submit`/`grade` transitions.

#### Scenario: A learner creates a personal portfolio that is never submitted for grading

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a learner opens the portfolio builder
- **WHEN** they create a new `Portfolio` with `kind: personal`
- **THEN** the portfolio has no `courseId`/`curriculumPlanId` and stays available for `activate`/`archive`
  transitions only — `submit` and `grade` are not offered

#### Scenario: A course task instantiates a course-bound portfolio from a template

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a `PortfolioTemplate` in `active` state, assigned as a graded task on a `Course`
- **WHEN** a learner starts the task
- **THEN** a `Portfolio` is created with `kind: course-bound`, `templateId` set to the template,
  `courseId`/`curriculumPlanId`/`curriculumPlanComponentId` set from the course task

### Requirement: `PortfolioEntry` references existing evidence objects via per-kind fields, never a polymorphic `$ref`

`PortfolioEntry.evidenceKind` MUST be one of `file`, `submission`, `werkproces-assessment`,
`external-training-record`, `credential`, or `reflection`. Each kind MUST resolve through its own nullable,
typed field — `attachmentRef` (bare string, mirroring `Submission.attachmentRefs`'s untyped NC-file
reference shape), `submissionId` (`$ref: Submission`), `werkprocesAssessmentId`
(`$ref: WerkprocesAssessment`), `externalTrainingRecordId` (`$ref: ExternalTrainingRecord`), or
`credentialId` (`$ref: Credential`) — never a single `format: uuid` property whose `$ref` could resolve to
more than one schema (the `hydra-gate-relation-dialect` gate's ban on bespoke/polymorphic relation shapes).
`reflectionText` MUST be present when `evidenceKind: reflection` and MAY be present alongside any other
kind. `PortfolioEntry` MUST NOT copy the bytes or data of the referenced object — it stores only the
reference.

#### Scenario: A learner adds an existing Submission as portfolio evidence

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a learner has an existing, returned `Submission`
- **WHEN** they add it to a `Portfolio` via the portfolio builder's evidence picker
- **THEN** a `PortfolioEntry` is created with `evidenceKind: submission` and `submissionId` set to that
  `Submission`'s UUID
- **AND** no `Submission` field values are duplicated onto the `PortfolioEntry`

#### Scenario: A learner adds a free-text reflection with no external evidence

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a learner is composing a portfolio entry with no file or existing object to attach
- **WHEN** they choose "reflection" and write their reflection text
- **THEN** a `PortfolioEntry` is created with `evidenceKind: reflection` and `reflectionText` set
- **AND** every per-kind reference field (`attachmentRef`, `submissionId`, `werkprocesAssessmentId`,
  `externalTrainingRecordId`, `credentialId`) is null

#### Scenario: A BPV learner evidences a confirmed WerkprocesAssessment

<!-- @e2e exclude Cross-schema reference correctness (WerkprocesAssessment lookup + display) is covered by the same evidence-picker component tested in the Submission scenario above; a second full e2e pass over an identical UI flow with a different evidenceKind adds no new DOM behaviour to verify. -->

- **GIVEN** a learner has a `confirmed` `WerkprocesAssessment` from their BPV placement
- **WHEN** they add it to a `Portfolio` as evidence
- **THEN** a `PortfolioEntry` is created with `evidenceKind: werkproces-assessment` and
  `werkprocesAssessmentId` set

### Requirement: Portfolio submission is blocked until required template sections have evidence

The `Portfolio.submit` transition (`draft|active → submitted`) MUST require a `PortfolioSubmissionGuard`
PHP class (mirrors `SubmissionWindowGuard`'s `requires:` shape). When `Portfolio.templateId` is set, the
guard MUST verify that every `PortfolioTemplate.sections[]` entry has at least one linked `PortfolioEntry`
(matched by `sectionId`); if any required section has no entry, the transition MUST be refused. When
`templateId` is null, the guard MUST allow the transition unconditionally.

#### Scenario: Submission is refused when a required section has no evidence

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit PortfolioSubmissionGuardTest::testMissingSectionEvidenceRefused; no scholiq DOM surface for the guard itself. -->

- **GIVEN** a `course-bound` `Portfolio` whose `PortfolioTemplate` declares two sections, and the learner
  has only added a `PortfolioEntry` for one of them
- **WHEN** the learner attempts to transition the portfolio to `submitted`
- **THEN** the transition is refused

#### Scenario: Submission succeeds once every required section has evidence

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a `course-bound` `Portfolio` whose `PortfolioTemplate` declares two sections, each with at least
  one linked `PortfolioEntry`
- **WHEN** the learner transitions the portfolio to `submitted`
- **THEN** the transition succeeds

### Requirement: A graded course-bound portfolio flows through the existing GradeEntry pipeline, not a parallel one

Grading a `course-bound` `Portfolio` MUST NOT compute a final grade itself. The `Portfolio.grade` transition
(`submitted → graded`) MUST emit (or, if already emitted, leave untouched) a `concept` `GradeEntry` via the
existing `grading` capability's pipeline, consumed unchanged by `GradeRollupHandler`'s roll-up once a
teacher publishes it. See the `grading` capability's MODIFIED requirement for the emission mechanics.

#### Scenario: Transitioning a course-bound portfolio to graded emits a concept GradeEntry

<!-- @e2e exclude Cross-schema event-to-object-write bridge is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest, mirroring GradeRollupHandlerTest's equivalent AssessmentResult coverage; no scholiq DOM surface for the emission itself. -->

- **GIVEN** a `course-bound` `Portfolio` in `submitted` state with `gradeValue` set by the teacher
- **WHEN** the teacher transitions it to `graded`
- **THEN** a `GradeEntry` is created with `sourceKind: portfolio`, `lifecycle: concept`, and `portfolioId`
  set to the portfolio
- **AND** `Portfolio.gradeEntryId` is back-linked to the created entry

#### Scenario: Re-triggering the graded transition does not create a duplicate GradeEntry

<!-- @e2e exclude Idempotency guard is backend logic verified by PHPUnit PortfolioGradeEmitHandlerTest::testNoDuplicateWhenGradeEntryIdAlreadySet. -->

- **GIVEN** a `course-bound` `Portfolio` that already has `gradeEntryId` set from a previous `graded`
  transition
- **WHEN** the `graded` transition fires again (e.g. a re-processed event)
- **THEN** no second `GradeEntry` is created

### Requirement: A teacher can be granted a read-only share via native Nextcloud Files sharing

A `PortfolioShare` with `sharedWithKind: teacher` MUST, on its `grant` transition (`draft → active`),
cause `PortfolioShareGrantHandler` to create a read-only Nextcloud Files share (via `OCP\Share\IManager`)
of the NC files behind the shared portfolio's (or selected entries') `attachmentRef`s, targeting
`sharedWithTeacherId`. This reuses Nextcloud's own file-sharing mechanism; it MUST NOT duplicate file
bytes or build a parallel access-control layer for file evidence.

#### Scenario: Granting a teacher share creates a native NC Files share

<!-- @e2e exclude NC Files share-API integration is backend logic verified by PHPUnit PortfolioShareGrantHandlerTest (IManager mocked); no scholiq DOM surface for the OCP\Share call itself. -->

- **GIVEN** a `PortfolioShare` in `draft` with `sharedWithKind: teacher` and `sharedWithTeacherId` set to a
  mentor who is not the portfolio's grading teacher of record
- **WHEN** the share is transitioned to `active`
- **THEN** `PortfolioShareGrantHandler` creates a read-only NC Files share of the referenced attachments
  for that teacher

### Requirement: BPV praktijkopleider and external-assessor sharing reuse the ADR-046 portal audience mechanism

The system MUST serve read-only portfolio sharing with a BPV praktijkopleider or an external assessor
(neither has a Nextcloud account) through `lib/Portal/PortalContributionProvider.php`, not a new
access-control system. The existing `praktijkopleider` audience MUST gain exactly one new, direct-match-scoped collection
(`PortfolioShare.sharedWithPraktijkopleiderId == subject.subjectRef`) with no change to
`getAudiences()`/`getContribution()`. A new `external-assessor` audience MUST be added following the same
mechanism `bpv-praktijkovereenkomst` used to add `praktijkopleider` as the third audience: one more
`getAudiences()` value, one more `getContribution()` branch, and an `externalAssessorContribution()` method
that direct-matches `PortfolioShare.sharedWithExternalAssessorId == subject.subjectRef` and resolves the
matched `portfolioId`s into field-projected `Portfolio`/`PortfolioEntry` reads, honouring `entryIds` as a
row-level filter when set.

#### Scenario: A praktijkopleider reads a portfolio shared with them, field-projected

<!-- @e2e exclude Backend-only contract class rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php), mirroring the existing praktijkopleider/parent/student coverage in that test class. -->

- **GIVEN** an active `PortfolioShare` with `sharedWithKind: praktijkopleider` naming a specific
  praktijkopleider, and no `entryIds` set (whole portfolio)
- **WHEN** portaliq resolves the contribution for that praktijkopleider's subject
- **THEN** the shared `Portfolio` and all of its `PortfolioEntry` rows are returned, field-projected to
  drop any staff-only column

#### Scenario: An external assessor sees only the selected entries, not the whole portfolio

<!-- @e2e exclude Backend-only contract class; covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php). -->

- **GIVEN** an active `PortfolioShare` with `sharedWithKind: external-assessor` and `entryIds` set to two
  of the portfolio's five entries
- **WHEN** portaliq resolves the contribution for that external assessor's subject
- **THEN** only the two selected `PortfolioEntry` rows are returned, not the other three

#### Scenario: A revoked share stops resolving in either audience

<!-- @e2e exclude Backend-only contract class; covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php). -->

- **GIVEN** a `PortfolioShare` that has transitioned `active → revoked`
- **WHEN** portaliq resolves the contribution for the previously-shared subject
- **THEN** the collection scoped by that share returns no rows for either the `praktijkopleider` or
  `external-assessor` audience

### Requirement: Frontend is declarative with two named custom views

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `PortfolioTemplate`,
`Portfolio`, `PortfolioEntry`, `ExternalAssessor`, and `PortfolioShare`. Exactly two custom Vue components
are permitted: `PortfolioBuilder.vue` (the learner assembles a portfolio by referencing existing evidence
rather than typing raw UUIDs) and `PortfolioReviewView.vue` (the teacher/praktijkopleider/external-assessor
read-only review surface, plus the teacher's `gradeValue` entry and `grade` transition for course-bound
portfolios). No PHP CRUD controllers.

#### Scenario: A learner builds a portfolio using the evidence picker, not raw UUID entry

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a learner opens `PortfolioBuilder.vue` for a `course-bound` `Portfolio`
- **WHEN** they add an entry and choose "existing submission" from the evidence-kind picker
- **THEN** they select from a list of their own `Submission`s (no free-text UUID field) and the resulting
  `PortfolioEntry` is created with `submissionId` set

#### Scenario: A teacher reviews and grades a submitted course-bound portfolio

<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->

- **GIVEN** a `course-bound` `Portfolio` in `submitted` state
- **WHEN** the grading teacher opens `PortfolioReviewView.vue`, reviews the entries, enters a `gradeValue`,
  and transitions the portfolio to `graded`
- **THEN** the portfolio moves to `graded` and the emitted `GradeEntry` is visible in the existing
  `GradebookView` as a `concept` entry

