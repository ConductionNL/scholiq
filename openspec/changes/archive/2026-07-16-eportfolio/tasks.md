# Tasks: eportfolio

## 1. Schema — eportfolio capability

- [x] 1.1 Add `PortfolioTemplate` to `lib/Settings/scholiq_register.json`: `name`, `kind`
  (`personal | course-bound`), `description` (nullable), `sections[]` (`sectionId`, `label`, `order`,
  `helpText` nullable, `criteria[]`: `criterionId`, `label`, `description`, `competencyCode` nullable,
  `competencyLabel` nullable — mirrors `LearningPlanTemplate.sections` + `Rubric.criteria` combined),
  `rubricId` (nullable `$ref: Rubric`), `tenant_id`, `lifecycle` (`draft → active → archived`, mirrors
  `LearningPlanTemplate`).
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-persist-e-portfolio-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Schema validates against the OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `sections[].criteria[]` shape matches `Rubric.criteria[]`'s field names for consistency
- [x] 1.2 Add `Portfolio`: `learnerId` (NC uid) + `learnerRef` (nullable `$ref: LearnerProfile`, additive,
  `portal-identity` convention), `kind` (`personal | course-bound`), `templateId` (nullable
  `$ref: PortfolioTemplate`), `courseId`/`curriculumPlanId` (nullable `$ref`, set only when
  `kind: course-bound`), `curriculumPlanComponentId` (nullable string, mirrors
  `Assignment.curriculumPlanComponentId`), `title`, `description` (nullable), `dueAt` (nullable
  date-time), `gradeValue` (nullable number, teacher-entered, mirrors `Submission.proposedGrade`),
  `gradeEntryId` (nullable `$ref: GradeEntry`, back-linked by `PortfolioGradeEmitHandler`), `tenant_id`,
  single `lifecycle` covering both flavours (`draft`, `active`, `submitted`, `graded`, `archived`;
  transitions: `activate` `draft→active`, `submit` `draft|active→submitted` requires
  `PortfolioSubmissionGuard`, `grade` `submitted→graded`, `archive` `active|graded→archived`,
  `reactivate` `archived→active`).
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-portfoliokind-selects-between-a-personal-and-a-course-bound-flavour-on-one-schema`
  - **acceptance_criteria**:
    - `kind` enum has exactly `personal`/`course-bound`; one `x-openregister-lifecycle` block serves both
    - `x-property-rbac` mirrors `Submission`'s block (`admin` + self `learnerId` match)
- [x] 1.3 Add `PortfolioEntry`: `portfolioId` (`$ref: Portfolio`, required), `title`, `evidenceKind`
  (`file | submission | werkproces-assessment | external-training-record | credential | reflection`,
  required), `attachmentRef` (nullable string, mirrors `Submission.attachmentRefs`'s untyped NC-file
  shape), `submissionId` (nullable `$ref: Submission`), `werkprocesAssessmentId` (nullable
  `$ref: WerkprocesAssessment`), `externalTrainingRecordId` (nullable `$ref: ExternalTrainingRecord`),
  `credentialId` (nullable `$ref: Credential`), `reflectionText` (nullable string), `sectionId`/
  `criterionId` (nullable strings, match the governing `PortfolioTemplate.sections[]`), `competencyCode`/
  `competencyLabel` (nullable free-text strings — see design.md "Competency alignment"), `tenant_id`. No
  `x-openregister-lifecycle`.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-portfolioentry-references-existing-evidence-objects-via-per-kind-fields-never-a-polymorphic-ref`
  - **acceptance_criteria**:
    - Exactly one per-kind reference field is a `$ref`-typed `format: uuid` property per `evidenceKind`
      value that needs one; none of them is a single polymorphic `$ref`
    - `attachmentRef` carries no `$ref` (mirrors `Submission.attachmentRefs`)
- [x] 1.4 Add `ExternalAssessor`: `givenName`, `familyName`, `email`, `organisationName` (nullable),
  `active` (bool, default true), `tenant_id`. No `x-openregister-lifecycle` — structurally identical to
  `Praktijkopleider`.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-persist-e-portfolio-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Field shape matches `Praktijkopleider`'s (minus the leerbedrijf-specific fields)
- [x] 1.5 Add `PortfolioShare` (`appendOnly: true`): `portfolioId` (`$ref: Portfolio`, required),
  `entryIds` (nullable array of `format: uuid` strings, no `$ref` — a selection of the same portfolio's
  own `PortfolioEntry` ids), `sharedWithKind` (`teacher | praktijkopleider | external-assessor`,
  required), `sharedWithTeacherId` (nullable string, NC uid), `sharedWithPraktijkopleiderId` (nullable
  `$ref: Praktijkopleider`), `sharedWithExternalAssessorId` (nullable `$ref: ExternalAssessor`),
  `sharedBy` (string, NC uid, required), `expiresAt` (nullable date-time), `tenant_id`, `lifecycle`
  (`draft → active → revoked`; transition `grant` `draft→active` requires no guard, `revoke`
  `active→revoked`), `x-openregister-authorization.create` restricted to the portfolio's own learner
  (self-match, enforced by a guard — see task 3.3) plus `admin`/`teacher` roles.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing`
  - **acceptance_criteria**:
    - `appendOnly: true`; exactly one per-`sharedWithKind` reference field is set at a time
    - A share recipient cannot appear as `sharedBy` for their own grant (enforced by task 3.3's guard)

## 2. Schema — grading delta

- [x] 2.1 Add `portfolio` to `GradeEntry.sourceKind`'s enum (alongside the existing six values) and add
  `portfolioId` (nullable `$ref: Portfolio`) to `GradeEntry`, matching the shape `ltiToolPlacementId`/
  `exemptionCaseId` already established. Purely additive; existing rows leave it null.
  - **spec_ref**: `specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Existing `GradeEntry` rows validate unchanged (`portfolioId` absent/null)
    - `sourceKind: portfolio` entries can set `portfolioId`

## 3. Backend — guards, listeners

- [x] 3.1 Add `OCA\Scholiq\Lifecycle\PortfolioSubmissionGuard` (SPDX; `@spec` tag): on `Portfolio.submit`,
  when `templateId` is set, verifies every `PortfolioTemplate.sections[].sectionId` has at least one
  linked `PortfolioEntry` (matched by `sectionId`); blocks the transition if any required section has no
  evidence. Allows unconditionally when `templateId` is null. Mirrors `SubmissionWindowGuard`'s
  `requires:` shape.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence`
  - **acceptance_criteria**:
    - Unit tests cover: missing-section-evidence blocked; every-section-covered allowed; no-template
      allowed unconditionally
- [x] 3.2 Add `OCA\Scholiq\Listener\PortfolioGradeEmitHandler` (SPDX; mirrors
  `GradeRollupHandler::handleAssessmentResultGraded()` exactly): `IEventListener` on
  `ObjectTransitionedEvent`, `register=scholiq`, `schema=portfolio`, `to=graded`. Skips if
  `Portfolio.gradeEntryId` already set. Otherwise resolves the governing `CurriculumPlan`'s
  `gradeScaleId` (same lookup shape `WerkprocesGradeEmitHandler` performs), builds a `concept`
  `GradeEntry` (`sourceKind: portfolio`, `portfolioId`, `curriculumPlanId`, `componentId` from
  `curriculumPlanComponentId`, `value` from `Portfolio.gradeValue`, `grader`, `gradedAt`, `tenant_id`),
  saves it, and back-links `Portfolio.gradeEntryId`. Registered in `lib/AppInfo/Application.php`.
  - **spec_ref**: `specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Unit tests cover: concept GradeEntry created with correct sourceKind/portfolioId/value on first
      `graded` transition; no duplicate created when `gradeEntryId` is already set
      (`testNoDuplicateWhenGradeEntryIdAlreadySet`)
- [x] 3.3 Add `OCA\Scholiq\Lifecycle\PortfolioShareGrantHandler` (SPDX; mirrors
  `WerkprocesGradeEmitHandler`'s event-listener shape): `IEventListener` on `ObjectTransitionedEvent`,
  `register=scholiq`, `schema=portfolio-share`, `to=active`. Also enforces (as a `requires:` guard on the
  same `grant` transition, since `x-property-rbac`/`x-openregister-authorization` cannot express a
  cross-object "recipient cannot self-grant" check) that `sharedBy` is not equal to the resolved recipient
  identity for the share's `sharedWithKind`. For `sharedWithKind: teacher`, resolves the NC file paths
  behind the shared `attachmentRef`s (via the portfolio's `PortfolioEntry`s, filtered by `entryIds` when
  set) and calls `OCP\Share\IManager::createShare()` for a read-only share targeting
  `sharedWithTeacherId`. For `praktijkopleider`/`external-assessor`, performs no NC Files call (portal
  projection handles those, task 4).
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-a-teacher-can-be-granted-a-read-only-share-via-native-nextcloud-files-sharing`
  - **acceptance_criteria**:
    - Unit tests cover: `sharedWithKind: teacher` calls `IManager::createShare()` with read-only
      permissions and the correct recipient (mocked `IManager`); `praktijkopleider`/`external-assessor`
      kinds make no `IManager` call; self-grant (`sharedBy == sharedWithTeacherId`) blocked

## 4. Backend — portal contribution extension

- [x] 4.1 Extend `lib/Portal/PortalContributionProvider.php`'s existing `praktijkopleiderContribution()`
  with one new collection (`poSharedPortfolios`), direct-matched
  (`scopeField: 'sharedWithPraktijkopleiderId'`, `scopeClaim: 'praktijkopleiderId'`, mirroring
  `poBpvPlacements`'s existing shape) over `portfolio-share`, resolving to field-projected
  `Portfolio`/`PortfolioEntry` reads (honouring `entryIds` as a row-level filter). No change to
  `getAudiences()`/`getContribution()` for this audience.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism`
  - **acceptance_criteria**:
    - Unit tests cover: a praktijkopleider with an active, unscoped `PortfolioShare` reads the whole
      portfolio field-projected; a revoked share resolves no rows
- [x] 4.2 Add `external-assessor` as a fourth `PortalContributionProvider` audience: one more
  `getAudiences()` value, one more `getContribution()` branch, a new `externalAssessorContribution()`
  method direct-matching `PortfolioShare.sharedWithExternalAssessorId == subject.subjectRef` for the grant
  collection, then resolving matched `portfolioId`s into field-projected `Portfolio`/`PortfolioEntry`
  reads honouring `entryIds` — mirrors the exact mechanism `bpv-praktijkovereenkomst` used to add
  `praktijkopleider` as the third audience. Zero create-actions (read-only per the brief).
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism`
  - **acceptance_criteria**:
    - Unit tests cover: `getAudiences()` includes `external-assessor`; `getContribution()` returns null
      for any audience Scholiq does not serve (fail-closed, unchanged for other audiences); an external
      assessor with `entryIds` set on their share sees only the selected entries; a revoked share resolves
      no rows

## 5. Frontend

- [x] 5.1 Add `src/manifest.json` index/detail pages for `PortfolioTemplate`, `Portfolio`,
  `PortfolioEntry`, `ExternalAssessor`, `PortfolioShare` (list/create/edit/detail per the standard
  declarative pattern used by `bpv`/`grading`).
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-frontend-is-declarative-with-two-named-custom-views`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added
- [x] 5.2 Add `src/views/PortfolioBuilder.vue`: the learner assembles a `Portfolio`'s entries by picking
  from their own existing `Submission`s/`WerkprocesAssessment`s/`ExternalTrainingRecord`s/`Credential`s
  (dropdown/search pickers, no free-text UUID entry) or an NC file (Files-app file picker), or writes a
  free-text reflection. Drives the `submit` transition once required sections have evidence. Strings via
  `t()`, data via the OpenRegister object API; any `NcSelect` carries `inputLabel`. Add a manifest menu
  entry.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-a-learner-builds-a-portfolio-using-the-evidence-picker-not-raw-uuid-entry`
  - **acceptance_criteria**:
    - Renders a seeded personal and course-bound portfolio; adding an entry via each `evidenceKind` picker
      creates the correctly-shaped `PortfolioEntry`
    - `submit` is disabled/blocked in the UI when a required section has no evidence, and the underlying
      `PortfolioSubmissionGuard` refusal is surfaced to the learner
- [x] 5.3 Add `src/views/PortfolioReviewView.vue`: read-only rendering of a shared or owned portfolio's
  entries (resolving and displaying the referenced `Submission`/`WerkprocesAssessment`/
  `ExternalTrainingRecord`/`Credential`/NC file per entry), plus — for the grading teacher of a
  `course-bound` portfolio in `submitted` state only — a `gradeValue` entry field and the `grade`
  transition action. Strings via `t()`; no PHP CRUD controller.
  - **spec_ref**: `specs/eportfolio/spec.md#requirement-a-teacher-reviews-and-grades-a-submitted-course-bound-portfolio`
  - **acceptance_criteria**:
    - Renders a seeded submitted portfolio's entries read-only for a non-grading viewer
    - The grading teacher can enter `gradeValue` and drive `grade`; the resulting `concept` `GradeEntry`
      is visible in the existing `GradebookView`

## 6. Tests and docs

- [x] 6.1 PHPUnit for `PortfolioSubmissionGuard`, `PortfolioGradeEmitHandler`, `PortfolioShareGrantHandler`,
  and the `PortalContributionProvider` extensions, per the acceptance criteria in tasks 3.1–4.2 (minimum
  75% coverage for new code per ADR-008).
  - **spec_ref**: all `eportfolio` requirements; `specs/grading/spec.md`
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [x] 6.2 Add `tests/e2e/spec-coverage/eportfolio.spec.ts` (Playwright): a learner creates a personal
  portfolio and a course-bound portfolio from a template, adds entries via the evidence picker (at least
  one `submission` and one `reflection` entry), submits the course-bound portfolio, and the grading
  teacher reviews and grades it in `PortfolioReviewView.vue`.
  - **spec_ref**: every scenario in `specs/eportfolio/spec.md` tagged
    `<!-- @e2e tests/e2e/spec-coverage/eportfolio.spec.ts -->`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches every `@e2e` reference in the spec scenarios
- [x] 6.3 Add Dutch and English translations for all new i18n keys (ADR-005/ADR-025).
  - **spec_ref**: all `eportfolio` requirements
  - **acceptance_criteria**:
    - No hardcoded strings in `PortfolioBuilder.vue`/`PortfolioReviewView.vue`; `nl`/`en` both populated

## 7. Verify

- [x] 7.1 `openspec validate eportfolio --strict` clean; PHPUnit green for all new PHP classes; Playwright
  `eportfolio.spec.ts` green; no dangling `$ref`s in the register JSON (all five new schemas plus
  `GradeEntry.portfolioId` resolve within the same register); `PortfolioSubmissionGuard` and
  `PortfolioShareGrantHandler`'s guard behaviours re-verified against seeded fixtures (missing-section
  submit refused; self-grant refused).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; both hard-guard invariants re-verified end-to-end
