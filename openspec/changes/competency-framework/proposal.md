---
kind: code
depends_on: []
---

# Proposal: competency-framework

## Why

**This is the highest-demand gap in the whole Specter dataset.** The intelligence
brief's own MVP shortlist (`docs/Features/features.md:120-121`) lists both
canonical features at the same figures cited in this change's brief: `Competency
management | MVP | 153 | 43 | 12 | Skills/competency framework` and `Skills
management | MVP | 153 | 43 | 12 | Linked to credentials` — demand 153, 43
tenders, 12 profiled competitors, the joint-highest demand score in the entire
table alongside `Certification management`/`Credential management` (same row
block, same figures). `docs/Features/features.md:303` independently lists
"Skills + Competency + Certification + Credential management (linked entities)"
as MVP shortlist item #6, pulled from `canonical_features where priority='must'`
(`features.md:296`) — so this is not a peripheral nice-to-have, it is
top-of-list alongside the two capabilities (`certification`, `grading`) Scholiq
already ships. The competitor set named in this change's brief (Canvas
Outcomes+Mastery, Moodle Competency Framework, Brightspace, Cornerstone skills
graph, SAP SuccessFactors, 360Learning, eFront, Opigno, ILIAS, Chamilo, It's
Learning, Coursera, Credly, ExamSoft) is the Specter competitive-intelligence
payload for this change and is not independently re-derivable from this
worktree (no raw Specter DB or gap-report file lives in this repo — grepped
`gap-report`/`wave-2` repo-wide, zero hits); it is reported here as given
context, not re-verified.

**Verified at HEAD — the taxonomy genuinely does not exist, and the two
`WerkprocesAssessment` fields the brief names are exactly what they're claimed
to be:**
- Grepping `competenc|skill|leerlijn|kerndoel|eindterm|proficiency|mastery|esco\b`
  case-insensitively across every `openspec/specs/*/spec.md` and the whole
  register JSON returns exactly three hits, all in `dashboard/spec.md` (a
  "skill-area heat map" *dashboard-tile prose*, not a schema — lines 22, 26, 34)
  and the ones inside `openspec/specs/bpv/spec.md`/the register's
  `WerkprocesAssessment` object. There is no `Competency`, `CompetencyFramework`,
  `LearningOutcome`, or `CompetencyAttainment` schema, and no capability spec
  owns this domain.
- `WerkprocesAssessment` (`lib/Settings/scholiq_register.json:10798-10927`,
  spec `openspec/specs/bpv/spec.md:49-53`) already carries
  `kwalificatiedossierCode`, `kerntaakCode`, `werkprocesCode`, `werkprocesLabel`
  as **plain sibling string properties** (lines 10842-10861) — four flat
  strings with no shared taxonomy object behind them, no recursion, no
  reusable proficiency scale, and no way for any other capability (assignments,
  assessment, certification) to point at "the same werkproces" this
  `WerkprocesAssessment` row is about. Its own description
  (line 10803) explicitly documents that a confirmed row "emits or updates a
  GradeEntry … this schema computes no final grade itself" — i.e. it already
  participates in the grading roll-up, it just has no competency taxonomy to
  generalize into.
- `Lesson.learningObjectives` (`lib/Settings/scholiq_register.json:1149-1156`)
  is a bare `array` of `string` — "Learning objectives for this lesson", no
  structure, no linkage to anything gradable.
- `Item.subjectTags` (`lib/Settings/scholiq_register.json:4536-4544`) is
  likewise a bare `array` of `string` — "Subject or topic tags for cross-bank
  searching", free text with no taxonomy behind it.
- `Course.programmeIds`/`Programme.courseIds`
  (`lib/Settings/scholiq_register.json:937-946`, `2799-2808`) and
  `CurriculumPlan.components[]` (`2914-2972`, `kind: assignment|assessment|
  participation`) already give every course/programme a governing plan and a
  component list, but nothing in that plan says *what a learner should be able
  to do* — only what graded columns exist and how they weight.
- `COMPLIANCE-ENGINE-GOAL.md:86` (a separate kickoff brief for a
  compliance-rule engine, not yet built) independently lists `Competency`/
  `LearningOutcome` among "likely scholiq object types the checks map to" —
  external confirmation that this gap is visible from more than one angle, not
  something invented for this change.
- `openspec/specs/dashboard/spec.md:22,26,34` already promises managers "a
  heat-map by skill area" and cites `HR/manager (team learning progress,
  time-to-competence)` as a target dashboard audience — a UI promise with no
  backing data model. This change is also what makes that promise buildable
  (see the `manager`/`hr` RBAC grounding in design.md).

**Reusable patterns already proven at HEAD (this change generalizes and reuses
them, it does not reinvent):**
- **Recursive same-schema hierarchy.** `Course.parentCourseId`
  (`lib/Settings/scholiq_register.json:921-928`, nullable `$ref: Course`) makes
  a "module" nothing more than a `Course` used as a container — the exact
  precedent this change follows to decide `LearningOutcome` is a leaf
  `Competency`, not a second object type (see design.md).
- **Nested-levels array.** `Rubric.criteria[].levels[]`
  (`lib/Settings/scholiq_register.json:3848-3877`, `{levelId, label, points}`)
  and `GradeScale.bands[]` (`5309-5350`, `{bandId, label, minValue, maxValue,
  pass}`) are both precedent for a small ordered array of named levels living
  on the owning object rather than a separate schema — reused for
  `CompetencyFramework.proficiencyLevels[]`.
- **`sourceKind` + per-kind nullable `$ref` fan-out.** `GradeEntry`
  (`lib/Settings/scholiq_register.json:5479-5545`) already resolves one mark to
  exactly one of `submissionId`/`assessmentResultId`/`sessionId`/
  `exemptionCaseId`/`fraudCaseId`/`ltiToolPlacementId` by `sourceKind` — the
  relation-dialect-compliant way this register expresses "one row, several
  possible evidence origins," reused (as *arrays* of the same per-kind
  `$ref` shape, since one competency roll-up accumulates evidence over time)
  for `CompetencyAttainment`'s `gradeEntryIds`/`assessmentResultIds`/
  `werkprocesAssessmentIds`/`submissionIds`.
- **Declared, event-driven roll-up, never a TimedJob.** `FinalGrade`
  (`lib/Settings/scholiq_register.json:5720-5868`) is `readOnly: true`, has no
  lifecycle, and recomputes via `x-openregister-triggers.calculatedChange`
  naming `OCA\Scholiq\Listener\GradeRollupHandler` — real, shipped code
  (`lib/Listener/GradeRollupHandler.php`). `WerkprocesGradeEmitHandler`
  (`lib/Listener/WerkprocesGradeEmitHandler.php`, also real, shipped) is the
  same shape one hop earlier (a confirmed `WerkprocesAssessment` emits a
  `GradeEntry`). `CompetencyAttainment` reuses `FinalGrade`'s exact shape
  (`readOnly: true`, `x-openregister-triggers.calculatedChange`) and adds a
  second listener, `CompetencyAttainmentRollupHandler`, sitting alongside
  `WerkprocesGradeEmitHandler` and `GradeRollupHandler` on the same
  `ObjectTransitionedEvent` — verified at HEAD
  (`lib/AppInfo/Application.php:180-380`) that this app already registers more
  than a dozen independent listeners on that one event class, each filtering
  internally to its own schema/transition, so a second listener on
  `werkproces-assessment`'s `confirm` transition is the established idiom, not
  a new one.
- **Additive-only schema evolution, `version` bumped per touched schema.**
  Every prior wave (`ectsCredits` on `Course` in `bsa-study-progress-guard`,
  the ADR-046 `learnerRef`/`learnerRefs` remap in `portal-identity`, the
  `sourceKind: lti-ags` addition in `lti-tool-placement`) adds nullable,
  non-required fields and bumps only the touched schema's own `version` plus
  the register's `info.version` — no existing row is invalidated. This change
  follows the same discipline for `Course`, `Lesson`, `Assignment`,
  `Assessment`, `Item`, `Credential`, `Programme`, and `WerkprocesAssessment`.

## What Changes

- **New capability `competency`** (`openspec/specs/competency/spec.md`, added
  by this change): `CompetencyFramework` (a named taxonomy: `sourceAuthority`
  enum `sbb-kwalificatiedossier | slo-kerndoelen | slo-eindtermen | esco |
  school-defined | other`, `sourceRef`, `edition`, `level`, and its own
  `proficiencyLevels[]` scale), `Competency` (recursive via `parentId`, exactly
  like `Course.parentCourseId` — `frameworkId`, `code`, `title`, `description`,
  `order`, `requiredForRoles[]`, calculated `childCount`/`isLeaf`), and
  `CompetencyAttainment` (a `FinalGrade`-shaped, read-only, per-`(learnerId,
  competencyId)` roll-up row carrying `proficiencyLevelId` plus four
  evidence-ref arrays, recomputed by a new `CompetencyAttainmentRollupHandler`
  listener — never a `TimedJob`).
- **`LearningOutcome` is not a separate schema.** A leaf `Competency`
  (`isLeaf: true`, i.e. `childCount == 0`) *is* the learning outcome — the same
  "a module is just a Course used as a container" reasoning `Course.
  parentCourseId` already establishes at HEAD. Full rationale and the rejected
  two-schema alternative are in design.md.
- **Alignment fields added to six existing schemas** (all additive, all
  nullable/array-default-`[]`, one new `ADDED Requirement` per owning
  capability spec, mirroring how `bsa-study-progress-guard` added `Course.
  ectsCredits` as an `ADDED Requirement` on `course-management` rather than
  folding it into `study-progress`):
  - `Course.competencyIds` + `Lesson.competencyIds` (`course-management` delta).
  - `Assignment.competencyIds` (`assignments` delta).
  - `Assessment.competencyIds` + `Item.competencyIds` (`assessment` delta).
  - `Credential.competencyIds` (`certification` delta).
  - `Programme.requiredCompetencyIds` (`school-structure` delta) — the
    "required by Programme" half of the skills-gap view.
  - `Lesson.learningObjectives` and `Item.subjectTags` are left in place
    (existing rows keep working, no breaking migration) but their
    descriptions gain a documented supersession note pointing authors at
    `competencyIds` for anything that needs to roll up or be queried
    structurally; free text remains for anything competencyIds can't yet
    express. See design.md for why this is additive coexistence, not a forced
    migration.
- **`WerkprocesAssessment` is generalized, not duplicated** — a **MODIFIED
  Requirement** on `specs/bpv/spec.md`: it gains a nullable `competencyId`
  (`$ref: Competency`) resolved from the shared kwalificatiedossier
  `CompetencyFramework`/`Competency` tree at creation time, while
  `kwalificatiedossierCode`/`kerntaakCode`/`werkprocesCode`/`werkprocesLabel`
  remain as denormalized display fields (no breaking change to the
  `praktijkopleider` portal action's field whitelist,
  `lib/Settings/scholiq_register.json:10842-10861` shape unchanged). Its
  `confirm` transition additionally fires the new
  `CompetencyAttainmentRollupHandler` alongside the existing
  `WerkprocesGradeEmitHandler`.
- **Skills-gap view**: one named custom view, `SkillsGapDashboard.vue`
  (mirrors the `BsaRiskDashboard.vue` precedent from `bsa-study-progress-guard`
  — the only custom Vue component this change introduces), comparing a
  learner's `CompetencyAttainment` rows against the competencies required by
  their enrolled `Programme.requiredCompetencyIds` and by their
  `LearnerProfile.roles` via `Competency.requiredForRoles`.
- **No wire protocol, no PHP CRUD controller** — everything is declarative
  OpenRegister config plus the one narrowly-scoped ADR-031 PHP exception
  (`CompetencyAttainmentRollupHandler`) the `FinalGrade`/`GradeRollupHandler`
  and `WerkprocesAssessment`/`WerkprocesGradeEmitHandler` precedents already
  establish as necessary for a cross-schema roll-up join.

## Impact

- **`lib/Settings/scholiq_register.json`** — three new schemas
  (`CompetencyFramework`, `Competency`, `CompetencyAttainment`); additive
  properties on eight existing schemas (`Course`, `Lesson`, `Assignment`,
  `Assessment`, `Item`, `Credential`, `Programme`, `WerkprocesAssessment`) —
  each touched schema's own `version` bumped; register `info.version`
  0.7.0 → 0.8.0.
- **New PHP** — `OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler`
  (the one ADR-031 cross-schema-join exception this change needs), registered
  in `lib/AppInfo/Application.php` alongside the existing listener list.
- **`src/manifest.json`** — index/detail pages for `CompetencyFramework`,
  `Competency`, `CompetencyAttainment` (read-only, no create/edit actions
  rendered — `CompetencyAttainment` is system-derived); one new custom view
  `SkillsGapDashboard.vue`.
- **Affected specs**: new `competency` capability; `course-management`,
  `assignments`, `assessment`, `certification`, `school-structure` each gain
  one `ADDED Requirement` (additive schema field only, no behavioural change to
  their existing requirements); `bpv` gains one `MODIFIED Requirement`
  (`WerkprocesAssessment` generalization).
- **Out of scope (documented, not silently dropped)**: per-`Item` attainment
  granularity (an `AssessmentResult` produces one `GradeEntry` per attempt, not
  per item, so the roll-up operates at `Assignment`/`Assessment`/
  `WerkprocesAssessment` grain — `Item.competencyIds` is authoring/analytics
  metadata for this change, not yet consumed by the roll-up; see design.md);
  a manager/HR-scoped (not just self+admin) read view of `CompetencyAttainment`
  — `dashboard/spec.md:22` already names this audience, and this change's RBAC
  block grants `hr`/`manager` read access on that grounding, but the
  manager-facing dashboard *tab* itself is `dashboard`'s follow-up, not built
  here; an ESCO/SLO/SBB **import** connector (populating `CompetencyFramework`/
  `Competency` from an external taxonomy source) — this change ships the
  target schema, not an importer; that is explicit `openconnector`/
  `data-exchange` follow-up work, matching the `bpv` change's SBB-adapter
  scope cut.
