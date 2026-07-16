# competency Specification

## Purpose
TBD - created by archiving change competency-framework. Update Purpose after archive.
## Requirements
### Requirement: CompetencyFramework carries a named source authority and its own proficiency scale

The system MUST persist `CompetencyFramework` as an OpenRegister object with `sourceAuthority` (required
enum `sbb-kwalificatiedossier | slo-kerndoelen | slo-eindtermen | esco | school-defined | other`), an
optional `sourceRef` (the external dossier code, kerndoelenset id, or ESCO taxonomy URI) and `edition`
(the framework's own version/jaarversie, distinct from the schema's own OpenRegister `version`), `level`
(reusing the `po|vo|mbo|hbo|wo|corporate` enum already used by `Course.level`/`Programme.level`), and a
required, non-empty `proficiencyLevels[]` array (`{levelId, label, order, minPercent}`, mirroring
`Rubric.criteria[].levels[]`'s nested-array shape) declaring the ordered scale every `Competency` under
this framework is measured against. `CompetencyFramework` MUST carry `x-openregister-lifecycle`
(`draft → published → archived`, mirroring `Course`).

#### Scenario: A school defines a two-level proficiency scale for a kwalificatiedossier framework

<!-- @e2e exclude Pure OpenRegister schema shape; no scholiq DOM surface beyond the declarative manifest pages already covered by other specs' UI conventions — covered by the register-validation test referenced in tasks.md. -->

- **GIVEN** a curriculum designer authoring a new `CompetencyFramework`
- **WHEN** they set `sourceAuthority: sbb-kwalificatiedossier`, `sourceRef` to the dossier code, and
  `proficiencyLevels` to `[{levelId: "nog-niet-competent", label: "...", order: 0}, {levelId: "competent",
  label: "...", order: 1}]`
- **THEN** the framework persists with its scale, and any `Competency` created under it can resolve that
  scale via `frameworkId`

#### Scenario: A framework cannot be created without at least one proficiency level

<!-- @e2e exclude Required-array validation is a pure JSON Schema constraint; covered by the register-validation test referenced in tasks.md. -->

- **GIVEN** a `CompetencyFramework` payload with `proficiencyLevels: []`
- **WHEN** it is submitted
- **THEN** OpenRegister rejects it as failing the `minItems: 1` constraint

### Requirement: Competency is a recursive taxonomy node, and a leaf Competency is the learning outcome

The system MUST persist `Competency` as an OpenRegister object with `frameworkId` (required, `$ref:
CompetencyFramework`), `parentId` (nullable, `$ref: Competency` — recursive, mirroring `Course.
parentCourseId`), `code`, `title`, `description`, `order`, and `requiredForRoles[]` (array of the same role
strings as `LearnerProfile.roles`, default `[]`). `Competency` MUST NOT introduce a separate
`LearningOutcome` schema: a `Competency` with no children (`childCount == 0`, materialized via
`isLeaf`, an `x-openregister-calculations` boolean over an `x-openregister-aggregate-refs` count of child
`Competency` rows where `parentId == @self.id` — the same `lessonCount`/`isPublished` shape `Course`
already uses) **is** the learning outcome — the atomic, directly-alignable, directly-assessable node in
the tree, exactly as a leaf `Course` (no sub-`Course`s) is a directly-teachable unit under
`Course.parentCourseId`. `Competency` MUST carry `x-openregister-lifecycle` (`draft → published →
archived`, mirroring `Course`/`CompetencyFramework`).

#### Scenario: A kerntaak/werkproces hierarchy is a two-level Competency tree under one framework

<!-- @e2e exclude Pure OpenRegister schema shape and calculation; no scholiq DOM surface — covered by the register-validation test and a PHPUnit assertion on the isLeaf/childCount calculation shape referenced in tasks.md. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: sbb-kwalificatiedossier`
- **WHEN** a curriculum designer creates a kerntaak `Competency` (`parentId: null`) and three werkproces
  `Competency` rows beneath it (`parentId` set to the kerntaak's id)
- **THEN** the kerntaak's `childCount` calculates to 3 and `isLeaf` is `false`
- **AND** each werkproces row's `childCount` is 0 and `isLeaf` is `true` — each werkproces is a learning
  outcome in its own right, with no separate `LearningOutcome` object required

#### Scenario: A leerlijn/kerndoel hierarchy follows the same recursive shape

<!-- @e2e exclude Same calculation mechanism as the kerntaak/werkproces scenario; no separate DOM surface. -->

- **GIVEN** a `CompetencyFramework` with `sourceAuthority: slo-kerndoelen`
- **WHEN** a curriculum designer creates a leerlijn `Competency` and several kerndoel `Competency` rows
  beneath it
- **THEN** the same recursive `parentId`/`isLeaf` shape applies without any framework-specific schema
  branching

### Requirement: CompetencyAttainment is a declared, event-driven per-learner roll-up, never a TimedJob

The system MUST persist `CompetencyAttainment` as a read-only (`x-openregister.readOnly: true`), non-
lifecycled OpenRegister object — one row per `(learnerId, competencyId)` — mirroring `FinalGrade`'s exact
shape: `learnerId` (NC uid, required), `learnerRef` (nullable `$ref: LearnerProfile`, ADR-046 A4),
`competencyId` (required, `$ref: Competency`), `frameworkId` (required, `$ref: CompetencyFramework`,
denormalized for query convenience exactly as `FinalGrade.curriculumPlanId` is), `proficiencyLevelId`
(nullable string, the computed current level — matches a `CompetencyFramework.proficiencyLevels[].
levelId`), `gradeEntryIds`/`assessmentResultIds`/`werkprocesAssessmentIds`/`submissionIds` (each an array
of `format: uuid` `$ref`-typed evidence references, default `[]` — the relation-dialect-compliant array
form of `GradeEntry`'s single-value `sourceKind`-selected `$ref` fan-out, since one roll-up row
accumulates evidence from more than one event over time), and `lastRecomputedAt`. Recomputation MUST be
driven by `x-openregister-triggers.calculatedChange` naming a new
`OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler` — reacting to `GradeEntry`'s `publish`/`republish`
transitions (resolving `sourceKind: assignment-submission` via `Submission.assignmentId.competencyIds`
and `sourceKind: assessment-result` via `AssessmentResult.assessmentId.competencyIds`) and to
`WerkprocesAssessment`'s `confirm` transition directly (via its own generalized `competencyId`) —
registered in `lib/AppInfo/Application.php` alongside the existing `GradeRollupHandler`/
`WerkprocesGradeEmitHandler` listeners on the same `ObjectTransitionedEvent` class, NOT a PHP `TimedJob`
(ADR-022).

Note: `AssessmentResult.assessmentId` and `Submission.assignmentId` grade a whole `Assignment`/`Assessment`,
not an individual `Item`; per-`Item` attainment granularity is explicitly out of scope for this change (see
design.md) — `Item.competencyIds` is authoring/analytics metadata here, not yet consumed by the roll-up.

#### Scenario: A published GradeEntry from a competency-aligned Assignment creates or updates a CompetencyAttainment

<!-- @e2e exclude Backend event-driven roll-up logic; no scholiq DOM surface for a declared trigger firing — covered by PHPUnit CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** an `Assignment` with `competencyIds` containing one `Competency` UUID
- **AND** a learner's marked `Submission` for that `Assignment` whose `GradeEntry` transitions to
  `published`
- **WHEN** `CompetencyAttainmentRollupHandler` reacts to that transition
- **THEN** a `CompetencyAttainment` row for `(learnerId, competencyId)` is created (or updated if one
  already exists), the `GradeEntry`'s id is appended to `gradeEntryIds`, the `Submission`'s id is appended
  to `submissionIds`, and `proficiencyLevelId` is recomputed against the competency's framework scale

#### Scenario: A confirmed WerkprocesAssessment updates its generalized Competency's attainment directly

<!-- @e2e exclude Backend event-driven roll-up logic on the bpv object; no scholiq DOM surface — covered by PHPUnit CompetencyAttainmentRollupHandlerTest referenced in tasks.md. -->

- **GIVEN** a `WerkprocesAssessment` whose generalized `competencyId` resolves to a werkproces `Competency`
- **WHEN** the `WerkprocesAssessment` transitions `submitted → confirmed`
- **THEN** `CompetencyAttainmentRollupHandler` (registered alongside `WerkprocesGradeEmitHandler` on the
  same transition) creates or updates the `(learnerId, competencyId)` `CompetencyAttainment` row and
  appends the `WerkprocesAssessment`'s id to `werkprocesAssessmentIds`
- **AND** `proficiencyLevelId` maps directly from `beoordeling` to the matching `levelId` on the
  kwalificatiedossier framework's `proficiencyLevels` (`competent` → the highest level, `nog-niet-competent`
  → the lowest), the same binary-scale precedent `WerkprocesGradeEmitHandler` already uses when mapping
  `beoordeling` onto `GradeEntry.value`

#### Scenario: Evidence with no declared competency alignment leaves attainment untouched

<!-- @e2e exclude Negative-path backend behaviour; no DOM surface — covered by PHPUnit CompetencyAttainmentRollupHandlerTest::testNoAlignmentIsNoOp referenced in tasks.md. -->

- **GIVEN** a `GradeEntry` whose source `Assignment`/`Assessment` has `competencyIds: []`
- **WHEN** that `GradeEntry` publishes
- **THEN** `CompetencyAttainmentRollupHandler` performs no write — no `CompetencyAttainment` row is
  created or updated

### Requirement: CompetencyAttainment read access includes the roles the dashboard spec already promises

`CompetencyAttainment`'s `x-property-rbac` read rule MUST grant `admin`, `hr`, and `manager` roles
unrestricted read (grounded in `openspec/specs/dashboard/spec.md:22`, which already names `HR/manager
(team learning progress, time-to-competence)` as a dashboard audience with no backing data until this
change), plus the matching learner (`learnerId == $userId`) reading their own rows — the same
self-plus-privileged-roles shape `FinalGrade`/`GradeEntry` already use, extended with the two roles this
object's stated audience specifically needs.

#### Scenario: A manager reads team attainment; a learner reads only their own

<!-- @e2e exclude RBAC read-scoping is a backend authorization rule with no distinct DOM surface beyond the declarative pages other specs already cover — covered by PHPUnit register/RBAC assertions referenced in tasks.md. -->

- **GIVEN** `CompetencyAttainment` rows for several learners
- **WHEN** a `manager` or `hr` role reads the collection
- **THEN** every row is visible
- **AND** WHEN a `learner` role reads the collection THEN only rows where `learnerId` equals their own
  user id are visible

### Requirement: Skills-gap view compares required competencies (by Programme and by role) against attained ones

The frontend MUST be declarative: `src/manifest.json` read-only index/detail pages for
`CompetencyFramework`, `Competency`, and `CompetencyAttainment` (no create/edit actions rendered for
`CompetencyAttainment` — it is system-derived). The only custom Vue component MUST be
`SkillsGapDashboard.vue`, which computes the union of a learner's required competencies —
`Programme.requiredCompetencyIds` for their enrolled programme(s) plus any `Competency` whose
`requiredForRoles` intersects their `LearnerProfile.roles` — against their `CompetencyAttainment` rows,
and lists any required competency with no attainment row, or an attained `proficiencyLevelId` below the
framework's declared pass/target level, as a gap. No PHP CRUD controller.

#### Scenario: A learner sees an unmet Programme-required competency as a gap

<!-- @e2e tests/e2e/spec-coverage/competency-framework.spec.ts -->

- **GIVEN** a `Programme` whose `requiredCompetencyIds` includes a `Competency` the learner has no
  `CompetencyAttainment` row for
- **WHEN** the learner (or their manager) opens the Skills Gap dashboard
- **THEN** that competency is listed as a gap, distinct from competencies with an attainment row at or
  above the framework's target level

#### Scenario: A role-required competency surfaces even without a Programme link

<!-- @e2e tests/e2e/spec-coverage/competency-framework.spec.ts -->

- **GIVEN** a `Competency` with `requiredForRoles` containing `"instructor"`
- **AND** a learner whose `LearnerProfile.roles` includes `"instructor"`, with no `Programme` linking them
  to that competency
- **WHEN** the Skills Gap dashboard loads for that learner
- **THEN** the role-required competency is included in the required set, independent of any Programme
  enrolment

