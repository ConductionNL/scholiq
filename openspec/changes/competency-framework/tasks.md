# Tasks: competency-framework

## 1. Schema — CompetencyFramework, Competency

- [ ] 1.1 Add `CompetencyFramework` schema to `lib/Settings/scholiq_register.json`: `name`,
      `sourceAuthority` (enum `sbb-kwalificatiedossier | slo-kerndoelen | slo-eindtermen | esco |
      school-defined | other`), `sourceRef` (nullable), `edition` (nullable), `level` (reuse the existing
      `po|vo|mbo|hbo|wo|corporate` enum), `description` (nullable), `proficiencyLevels` (array,
      `minItems: 1`, items `{levelId, label, order, minPercent}` mirroring `Rubric.criteria[].levels[]`'s
      nested shape), `tenant_id`, `x-openregister-lifecycle` (`draft → published → archived`, mirrors
      `Course`).
- [ ] 1.2 Add `Competency` schema: `frameworkId` (`$ref: CompetencyFramework`), `parentId` (nullable,
      `$ref: Competency` — recursive, mirrors `Course.parentCourseId`), `code`, `title`, `description`
      (nullable), `order` (nullable integer), `requiredForRoles` (array of `LearnerProfile.roles` enum
      values, default `[]`), `tenant_id`, `x-openregister-lifecycle` (`draft → published → archived`).
- [ ] 1.3 Add `Competency.childCount` (`x-openregister-aggregate-refs` count where `parentId ==
      @self.id`, mirroring `Course.lessonCount`) and `Competency.isLeaf` (`x-openregister-calculations`
      boolean, `childCount == 0`, mirroring `Course.isPublished`).
- [ ] 1.4 Register-validation test: both schemas validate against `npm run check:register`.

## 2. Schema — CompetencyAttainment + alignment fields on existing schemas

- [ ] 2.1 Add `CompetencyAttainment` schema, `readOnly: true`, no lifecycle (mirrors `FinalGrade`):
      `learnerId`, `learnerRef` (nullable `$ref: LearnerProfile`), `competencyId` (`$ref: Competency`),
      `frameworkId` (`$ref: CompetencyFramework`, denormalized), `proficiencyLevelId` (nullable string),
      `gradeEntryIds`/`assessmentResultIds`/`werkprocesAssessmentIds`/`submissionIds` (each array of
      `format: uuid` `$ref`-typed evidence references, default `[]`), `lastRecomputedAt` (nullable
      date-time), `tenant_id`. `x-openregister-triggers.calculatedChange` names
      `OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler`.
- [ ] 2.2 Add `x-property-rbac.read` on `CompetencyAttainment`: `anyOf` `admin`/`hr`/`manager` roles, or
      `learnerId == $userId` — mirrors `FinalGrade`'s shape, extended per `dashboard/spec.md:22`'s named
      audience.
- [ ] 2.3 Add `competencyIds` (array uuid `$ref: Competency`, default `[]`) to `Course` and `Lesson`;
      bump both schemas' own `version`.
- [ ] 2.4 Add `competencyIds` to `Assignment`; bump its `version`.
- [ ] 2.5 Add `competencyIds` to `Assessment` and `Item`; bump both schemas' own `version`.
- [ ] 2.6 Add `competencyIds` to `Credential`; bump its `version`.
- [ ] 2.7 Add `requiredCompetencyIds` (array uuid `$ref: Competency`, default `[]`) to `Programme`; bump
      its `version`.
- [ ] 2.8 Add `competencyId` (nullable, `$ref: Competency`) to `WerkprocesAssessment`; bump its `version`.
      Do NOT add `competencyId` to the praktijkopleider portal action's field whitelist in
      `lib/Portal/PortalContributionProvider.php` — it is server-resolved only (see design.md's Security
      Considerations).
- [ ] 2.9 Bump `lib/Settings/scholiq_register.json`'s `info.version` `0.7.0 → 0.8.0`.
- [ ] 2.10 Register-validation test: all new/touched schemas + the version bump validate against `npm run
       check:register`.

## 3. Backend — CompetencyAttainmentRollupHandler

- [ ] 3.1 Create `lib/Listener/CompetencyAttainmentRollupHandler.php` (`IEventListener`, SPDX header,
      `@spec` tags referencing the `competency` capability's roll-up requirement), constructor-injected
      `ObjectService` + `LoggerInterface`, mirroring `GradeRollupHandler.php`/`WerkprocesGradeEmitHandler.php`'s
      shape.
- [ ] 3.2 Implement the `GradeEntry` `publish`/`republish` branch: filter to `sourceKind:
      assignment-submission`, resolve `submissionId` → `Submission.assignmentId` →
      `Assignment.competencyIds`; for each competency, upsert `CompetencyAttainment(learnerId,
      competencyId)`, append `gradeEntryId`/`submissionId` (idempotent — skip if already present),
      recompute `proficiencyLevelId` via the percentage-threshold mapping (design.md).
- [ ] 3.3 Implement the `GradeEntry` `publish`/`republish` branch for `sourceKind: assessment-result`:
      resolve `assessmentResultId` → `AssessmentResult.assessmentId` → `Assessment.competencyIds`; same
      upsert shape, appending `gradeEntryId`/`assessmentResultId`.
- [ ] 3.4 Implement the `WerkprocesAssessment` `confirm` branch: read the assessment's own `competencyId`
      directly (no join); if set, upsert `CompetencyAttainment`, append `werkprocesAssessmentId`, map
      `beoordeling` onto the framework's highest/lowest `proficiencyLevels[]` entry (same mapping
      `WerkprocesGradeEmitHandler` already uses for `GradeEntry.value`).
- [ ] 3.5 No-op paths: `GradeEntry` sourceKind ∉ {assignment-submission, assessment-result}; a source with
      empty `competencyIds`; a `WerkprocesAssessment` with `competencyId: null` — all leave
      `CompetencyAttainment` untouched, per the spec's negative-path scenarios.
- [ ] 3.6 Add the `competencyId` server-side resolution to `WerkprocesAssessment` creation: match
      `werkprocesCode` against `Competency.code` scoped to a `CompetencyFramework` with
      `sourceAuthority: sbb-kwalificatiedossier`; a miss leaves `competencyId: null`, never blocking
      creation or the existing `confirm`/`GradeEntry` flow.
- [ ] 3.7 Register `CompetencyAttainmentRollupHandler` in `lib/AppInfo/Application.php` alongside the
      existing `GradeRollupHandler`/`WerkprocesGradeEmitHandler` listener registrations, filtering to its
      own schema/transition combinations per the existing multi-listener idiom.

## 4. Frontend

- [ ] 4.1 Add `src/manifest.json` read-only index/detail pages for `CompetencyFramework`, `Competency`,
      `CompetencyAttainment` (no create/edit actions rendered for `CompetencyAttainment` — system-derived).
- [ ] 4.2 Add `src/views/SkillsGapDashboard.vue`: computes the required set (`Programme.
      requiredCompetencyIds` for the learner's enrolled programme(s) ∪ `Competency.requiredForRoles`
      intersecting `LearnerProfile.roles`) against the learner's `CompetencyAttainment` rows, lists gaps;
      strings via `t()`; any `NcSelect` carries `inputLabel`. Add a manifest menu entry.
- [ ] 4.3 Manifest validation: `npm run check:manifest` passes.

## 5. Tests and docs

- [ ] 5.1 PHPUnit `tests/Unit/Listener/CompetencyAttainmentRollupHandlerTest.php`: assignment-submission
      path creates/updates attainment with correct evidence-id arrays; assessment-result path likewise;
      werkproces-assessment path maps `beoordeling` to the correct level; re-processing the same evidence
      id is idempotent (no duplicate array entries); unaligned evidence (`competencyIds: []` /
      `competencyId: null`) is a no-op in every branch; minimum 75% coverage on the new class per ADR-009.
- [ ] 5.2 Add `tests/e2e/spec-coverage/competency-framework.spec.ts` (Playwright): a learner/manager opens
      the Skills Gap dashboard and sees a Programme-required gap and a role-required gap, matching the two
      `@e2e` references in `specs/competency/spec.md`.
- [ ] 5.3 Add Dutch and English source strings for `SkillsGapDashboard.vue` and any new notification
      subjects (none are declared by this change — `CompetencyAttainment` has no
      `x-openregister-notifications` block).
- [ ] 5.4 Run `openspec validate competency-framework --strict` and resolve any reported issues before
      this change is considered ready to move to implementation.

## 6. Verify

- [ ] 6.1 `openspec validate competency-framework --strict` clean; PHPUnit green for
      `CompetencyAttainmentRollupHandler`; Playwright `competency-framework.spec.ts` green; no dangling
      `$ref`s in the register JSON after the three new schemas and eight additive properties land;
      `composer check:strict` clean on all new/touched PHP files.
