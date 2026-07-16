# Design: competency-framework

## Architecture Overview

```
CompetencyFramework (sourceAuthority: sbb-kwalificatiedossier | slo-kerndoelen | slo-eindtermen | esco | school-defined | other)
  proficiencyLevels[] = [{levelId, label, order, minPercent}, ...]   (the scale — declared once per framework)
       │
       │ frameworkId
       ▼
  Competency (recursive via parentId, mirrors Course.parentCourseId)
       kerntaak ──parentId── werkproces, werkproces           (isLeaf: true  ⇒ IS the learning outcome)
       leerlijn ──parentId── kerndoel,   kerndoel              (isLeaf: true  ⇒ IS the learning outcome)
       requiredForRoles[]  (skills-gap "required by role")
       │        ▲
       │        │ requiredCompetencyIds[]  (skills-gap "required by Programme")
       │        │
       │   Programme
       │
       │ competencyId (nullable, generalized from kwalificatiedossierCode/kerntaakCode/werkprocesCode)
       │        ▲
       │        │
       │   WerkprocesAssessment (bpv) ──confirm──┬──> WerkprocesGradeEmitHandler ──> GradeEntry (unchanged)
       │                                          └──> CompetencyAttainmentRollupHandler ─┐
       │                                                                                   │
       │ competencyIds[]                                                                   │
       │        ▲                                                                          │
       │        │                                                                          │
       │   Course / Lesson (course-management)                                             │
       │   Assignment (assignments) ──Submission──> GradeEntry.publish ──────┐              │
       │   Assessment / Item (assessment) ──AssessmentResult──> GradeEntry.publish ─┤        │
       │   Credential (certification, attests-only, no roll-up trigger)     │        │        │
       │                                                                    ▼        ▼        ▼
       │                                                          CompetencyAttainmentRollupHandler
       │                                                          (new listener, ObjectTransitionedEvent,
       │                                                           registered alongside GradeRollupHandler /
       │                                                           WerkprocesGradeEmitHandler)
       │                                                                    │
       │                                                                    ▼
       └──competencyId──────────────────────────────────>  CompetencyAttainment
                                                             (readOnly, per learnerId×competencyId,
                                                              mirrors FinalGrade's shape exactly)
                                                             gradeEntryIds[] / assessmentResultIds[] /
                                                             werkprocesAssessmentIds[] / submissionIds[]
                                                             proficiencyLevelId

SkillsGapDashboard.vue (the one custom view)
  required = Programme.requiredCompetencyIds ∪ {c: c.requiredForRoles ∩ LearnerProfile.roles ≠ ∅}
  attained = CompetencyAttainment rows for the learner
  gap = required − {c ∈ attained : proficiencyLevelId ≥ framework's target level}
```

## LearningOutcome: a leaf Competency, not a second schema

**Decision**: `LearningOutcome` is not modelled as its own OpenRegister schema. A `Competency` with no
children (`childCount == 0`, materialized as `isLeaf`) *is* the learning outcome — the atomic node other
objects align to and evidence rolls up against.

**Grounded precedent this follows.** `Course.parentCourseId`
(`lib/Settings/scholiq_register.json:921-928`) already solves the identical modelling problem one
capability over: "a module is just a Course used as a container." `school-structure`'s own spec
(`openspec/specs/school-structure/spec.md`, "Course (extends the existing schema)") states this explicitly
— the fleet already decided, for this exact shape of problem, that recursion via a nullable self-`$ref`
beats a second schema whose only distinction from the first is "has no children." `Course.lessonCount`
(`lib/Settings/scholiq_register.json:981-997`, an `x-openregister-aggregate-refs` count materialized via
`x-openregister-calculations`) is the parallel precedent for `Competency.childCount`/`isLeaf`.

**Rejected alternative 1: a separate `LearningOutcome` schema, `Competency` stops one level above it.**
This was the brief's literal phrasing ("kerntaak → werkproces, or leerlijn → kerndoel") read as "the last
level is a different *kind* of thing." Rejected because: (a) it forces every consumer (`Course.
competencyIds`, `Assignment.competencyIds`, `Programme.requiredCompetencyIds`, `CompetencyAttainment.
competencyId`) to choose between two `$ref` targets depending on tree depth, which is exactly the kind of
per-property dual-target relation the fleet's relation-dialect gate (cited in `bpv`'s own design.md,
"a `$ref` must resolve to exactly one schema") exists to prevent; (b) some frameworks are two levels
(kerntaak→werkproces), others could plausibly be three or four (a leerlijn with sub-leerlijnen before
kerndoel) — a fixed "outcome is always exactly one level below Competency" rule doesn't generalize the way
`Course.parentCourseId`'s open-ended recursion already does; (c) `COMPLIANCE-ENGINE-GOAL.md:86` lists
`Competency`/`LearningOutcome` together as "likely scholiq object types," not as two objects with a
declared relationship, which reads as the same open question this design resolves, not a mandate for two
schemas.

**Rejected alternative 2: a `kind` discriminator on `Competency` (`domain | kerntaak | werkproces | ...`).**
Rejected because a leaf/non-leaf distinction is structural (does this node have children?), not semantic
(what is this node *called* in its framework's own vocabulary) — `code`/`title` already carry the
framework's own naming (a `werkproces` code looks like a werkproces code; a `kerndoel` code looks like a
kerndoel code), and forcing every framework's own hierarchy vocabulary into one shared enum (`kerntaak` vs
`leerlijn` vs `domain` vs an ESCO skill-group level) would need frequent enum growth for frameworks this
change doesn't anticipate. `isLeaf` (structural, calculated, framework-agnostic) is sufficient for every
consumer that needs to know "is this directly assessable" without inventing that vocabulary.

## Data Model

All in OpenRegister (ADR-001). New schemas:

- **`CompetencyFramework`**: `name`, `sourceAuthority` (enum), `sourceRef` (nullable), `edition` (nullable
  — the framework's own version/jaarversie, distinct from the schema's OpenRegister `version`), `level`
  (reused `po|vo|mbo|hbo|wo|corporate` enum), `description` (nullable), `proficiencyLevels[]` (required,
  `minItems: 1`, `{levelId, label, order, minPercent}` — mirrors `Rubric.criteria[].levels[]`'s shape),
  `lifecycle` (`draft → published → archived`, mirrors `Course`), `tenant_id`.
- **`Competency`**: `frameworkId` (`$ref: CompetencyFramework`), `parentId` (nullable, `$ref: Competency`
  — recursive, mirrors `Course.parentCourseId`), `code`, `title`, `description` (nullable), `order`
  (nullable integer, mirrors `Lesson.order`), `requiredForRoles[]` (array of the same strings as
  `LearnerProfile.roles`, default `[]`), `lifecycle` (`draft → published → archived`), `tenant_id`.
  Calculated: `childCount` (via `x-openregister-aggregate-refs` count where `parentId == @self.id`,
  mirrors `Course.lessonCount`), `isLeaf` (`childCount == 0`, mirrors `Course.isPublished`'s boolean
  `x-openregister-calculations` shape).
- **`CompetencyAttainment`**: `readOnly: true`, no lifecycle — mirrors `FinalGrade` exactly. `learnerId`
  (NC uid), `learnerRef` (nullable `$ref: LearnerProfile`, ADR-046 A4), `competencyId` (`$ref:
  Competency`), `frameworkId` (`$ref: CompetencyFramework`, denormalized like `FinalGrade.
  curriculumPlanId`), `proficiencyLevelId` (nullable string), `gradeEntryIds`/`assessmentResultIds`/
  `werkprocesAssessmentIds`/`submissionIds` (each array of `format: uuid` `$ref`-typed evidence
  references, default `[]`), `lastRecomputedAt` (nullable date-time), `tenant_id`.
  `x-openregister-triggers.calculatedChange` names `OCA\Scholiq\Listener\
  CompetencyAttainmentRollupHandler`. `x-property-rbac.read`: `admin`, `hr`, `manager` (grounded in
  `dashboard/spec.md:22`'s named audience) unrestricted, plus `learnerId == $userId` for self.

Additive properties on existing schemas (each touched schema's own `version` bumped one minor):

| Schema | New property | Shape |
|---|---|---|
| `Course` | `competencyIds` | array uuid `$ref: Competency`, default `[]` |
| `Lesson` | `competencyIds` | array uuid `$ref: Competency`, default `[]` |
| `Assignment` | `competencyIds` | array uuid `$ref: Competency`, default `[]` |
| `Assessment` | `competencyIds` | array uuid `$ref: Competency`, default `[]` |
| `Item` | `competencyIds` | array uuid `$ref: Competency`, default `[]` (authoring/analytics only — see below) |
| `Credential` | `competencyIds` | array uuid `$ref: Competency`, default `[]` (attests-only, no trigger) |
| `Programme` | `requiredCompetencyIds` | array uuid `$ref: Competency`, default `[]` |
| `WerkprocesAssessment` | `competencyId` | nullable uuid `$ref: Competency`, server-resolved |

Register `info.version` bumps 0.7.0 → 0.8.0.

## WerkprocesAssessment generalization mechanics

`WerkprocesAssessment.competencyId` is resolved **server-side, at creation time**, never accepted as
client input — including from the praktijkopleider portal's `createWerkprocesAssessment` action
(`lib/Settings/scholiq_register.json:10842-10861`'s field whitelist stays exactly what it is today:
`bpvPlacementId`, `curriculumPlanId`, `componentId`, `kwalificatiedossierCode`, `kerntaakCode`,
`werkprocesCode`, `werkprocesLabel`, `beoordeling`, `toelichting` — `competencyId` is deliberately **not**
added to that whitelist). This is a security decision, not an oversight: a praktijkopleider is an
external, no-NC-account portal identity (ADR-046); if `competencyId` were client-settable, a
praktijkopleider could claim their assessment concerns a different competency than the codes actually
describe, corrupting a learner's `CompetencyAttainment` roll-up with a mismatched taxonomy node. Resolution
matches `werkprocesCode` against `Competency.code` scoped to `CompetencyFramework.sourceAuthority:
sbb-kwalificatiedossier`; a miss leaves `competencyId: null` and blocks nothing — `GradeEntry` emission via
`WerkprocesGradeEmitHandler` is completely unaffected (same as today), and the assessment simply doesn't
feed `CompetencyAttainment` until a matching `Competency` exists. This mirrors `bpv`'s own precedent for
"the app must not require a follow-up system to function" (`ProvidesLeerbedrijfVerification` similarly
leaves `BpvPlacement` merely unable to confirm, not broken, when unconfigured).

## Mastery roll-up mechanics

`CompetencyAttainmentRollupHandler` is a new `IEventListener` (SPDX headers, `@spec` tags, registered in
`lib/AppInfo/Application.php` alongside the existing dozen-plus listeners on `ObjectTransitionedEvent` —
verified at HEAD that this is the established multi-listener idiom, not a new one) filtering to three
transitions:

1. **`GradeEntry` → `published`/`republished`, `sourceKind: assignment-submission`.** Resolves
   `submissionId` → `Submission.assignmentId` → `Assignment.competencyIds`. For each competency: upsert
   `CompetencyAttainment(learnerId, competencyId)`, append the `GradeEntry` id to `gradeEntryIds` and the
   `Submission` id to `submissionIds` (both idempotent — `in_array` guard before append, so a re-fired
   `republish` doesn't duplicate), recompute `proficiencyLevelId`.
2. **`GradeEntry` → `published`/`republished`, `sourceKind: assessment-result`.** Resolves
   `assessmentResultId` → `AssessmentResult.assessmentId` → `Assessment.competencyIds`. Same upsert shape,
   appending to `gradeEntryIds`/`assessmentResultIds`.
3. **`WerkprocesAssessment` → `confirmed`.** Uses the assessment's own generalized `competencyId` directly
   (no join needed — see the previous section). Same upsert shape, appending to `werkprocesAssessmentIds`.

**`proficiencyLevelId` resolution** (the one genuinely cross-schema, non-JSON-logic step, hence a PHP
class — the same class of ADR-031 exception `GradeFormulaEvaluator`/`BsaProgressEvaluator` already are):

- **WerkprocesAssessment path**: direct label mapping, exactly mirroring `WerkprocesGradeEmitHandler`'s
  existing `beoordeling` → `GradeEntry.value` mapping (`competent` → 1.0, `nog-niet-competent` → 0.0) —
  `competent` resolves to the framework's highest-`order` `proficiencyLevels[]` entry, `nog-niet-competent`
  to the lowest.
- **GradeEntry path** (from a graded `Submission`/`AssessmentResult`): the evidence's percentage
  (`GradeEntry.value` relative to the source's `maxPoints`/`maxScore`) is compared against each
  `proficiencyLevels[].minPercent`, taking the highest level whose threshold is met — the same "ordered
  numeric thresholds resolve to a named level" shape `GradeScale.bands[].minValue`/`maxValue` already uses
  for grade bands, applied to percentages instead of raw values. A framework whose levels omit
  `minPercent` entirely (e.g. a framework with no percentage-graded evidence, only WerkprocesAssessment
  evidence) simply never resolves a level via this path — `proficiencyLevelId` stays whatever the
  WerkprocesAssessment path last set, or `null`.
- If neither path can resolve a level (no `minPercent` on the framework AND the evidence isn't a
  WerkprocesAssessment), `proficiencyLevelId` remains `null` while the evidence ids still accumulate —
  the row exists and is queryable (e.g. "evidence exists, level not yet interpretable"), it just can't
  answer "has this learner met the bar" until the framework declares thresholds.

**Why one row per `(learnerId, competencyId)`, not one row per evidence event.** `FinalGrade` is the
precedent: a single, continuously-recomputed roll-up row, not an append-only log of every contributing
`GradeEntry`. `CompetencyAttainment` follows the same shape rather than `GradeEntry`'s per-event log,
because the brief's own phrasing — "rolled up declaratively where possible" — describes the *destination*
of a roll-up, and the evidence-id arrays already preserve full traceability back to every contributing
`GradeEntry`/`AssessmentResult`/`WerkprocesAssessment`/`Submission` without needing a second, append-only
"CompetencyEvidence" object in between. **Rejected alternative**: a two-object split
(`CompetencyEvidence`, append-only, one row per event, feeding a `CompetencyAttainment` aggregation) —
this is structurally closer to `GradeEntry`/`FinalGrade`'s two-tier split, but was rejected as
unnecessary duplication here: `GradeEntry` (and `AssessmentResult`/`WerkprocesAssessment`) already *are*
the append-only evidence log for grading purposes; inventing a second evidence-logging schema whose sole
job is "the same event, tagged by competency instead of by curriculum component" duplicates data OR would
require every `GradeEntry` to also learn about competencies (which is out of scope — see the "no
GradeEntry schema change" note in proposal.md's Impact section). Storing the evidence UUIDs directly on
the one roll-up row keeps the traceability without the duplication.

## Skills-gap view

`SkillsGapDashboard.vue` (the one named custom view, mirroring `BsaRiskDashboard.vue`'s precedent from
`bsa-study-progress-guard`) computes, client-side against the OpenRegister object API (no new PHP
endpoint, per ADR-022):

1. **Required set** = `Programme.requiredCompetencyIds` for the learner's enrolled programme(s) (resolved
   via existing `Enrolment`→`Course`→`Course.programmeIds`→`Programme` joins, all existing relations) ∪
   every `Competency` whose `requiredForRoles` intersects the learner's `LearnerProfile.roles`.
2. **Attained set** = the learner's `CompetencyAttainment` rows, each carrying `proficiencyLevelId`.
3. **Gap** = any required competency with no attainment row, or an attained `proficiencyLevelId` whose
   `order` is below the framework's declared target (a target level is out of this change's schema surface
   — v1 treats "any non-null `proficiencyLevelId`" as attained, and a per-framework configurable target
   level is a documented follow-up, not silently dropped: see proposal.md's Impact/Out-of-scope section
   discussion of what this change does and doesn't compute).

## Security Considerations

- **No client-settable taxonomy link on WerkprocesAssessment** — see the generalization-mechanics section
  above; `competencyId` is resolved server-side only, never portal-writable.
- **`CompetencyAttainment` is `readOnly: true`** — no create/update action exists for the frontend or the
  portal to call; the only writer is the new listener, matching `FinalGrade`'s precedent exactly (no new
  write-IDOR surface is introduced).
- **RBAC** (`x-property-rbac`): `admin`/`hr`/`manager` read all, learner reads own — grounded in
  `dashboard/spec.md:22`'s already-declared audience, not invented. No new role is added beyond what that
  spec already names.
- **EU AI Act**: none of this change involves AI — proficiency resolution is a deterministic mapping
  (label match or percentage threshold), not a model inference. No `AiFeature` registration needed.
- No secrets, tokens, or new endpoints in this change.

## File Structure

```
lib/Listener/CompetencyAttainmentRollupHandler.php     (new)
lib/AppInfo/Application.php                            (modified — + listener registration)
lib/Settings/scholiq_register.json                     (+3 schemas; +8 additive properties;
                                                          info.version 0.7.0 → 0.8.0)
src/manifest.json                                       (+ read-only index/detail pages for
                                                          CompetencyFramework/Competency/
                                                          CompetencyAttainment, + SkillsGapDashboard.vue)
src/views/SkillsGapDashboard.vue                        (new — the one custom view)
tests/Unit/Listener/CompetencyAttainmentRollupHandlerTest.php  (new)
openspec/
  changes/competency-framework/                         (this change)
  specs/competency/spec.md                               (capability status stub, proposed)
```

## Trade-offs

- **`LearningOutcome` as a leaf `Competency` vs a separate schema** — see "LearningOutcome" section above;
  the recursive `Course.parentCourseId` precedent is directly on point and a second schema would force a
  dual-target relation dialect violation.
- **One roll-up row per `(learnerId, competencyId)` vs an append-only per-event evidence log** — see
  "Mastery roll-up mechanics" above; the evidence-id arrays on the single roll-up row already give full
  traceability without duplicating `GradeEntry`/`AssessmentResult`/`WerkprocesAssessment` as a second log.
- **No `GradeEntry` schema change** — `GradeEntry` gains no new field in this change (unlike
  `lti-tool-placement`'s `sourceKind: lti-ags` addition). The roll-up handler reaches competency alignment
  by joining through `Submission.assignmentId`/`AssessmentResult.assessmentId` instead, keeping `grading`
  untouched by this change entirely — a deliberate minimal-footprint choice given `GradeEntry` is already
  the most heavily cross-referenced schema in the register (six nullable per-`sourceKind` `$ref` fields
  already).
- **`Item.competencyIds` is authoring metadata only, not roll-up input** — an `AssessmentResult` grades a
  whole `Assessment` per attempt, not per `Item`; wiring per-item attainment would need
  `AssessmentResult.responses[].autoScore`/`manualScore` (already per-item) to feed a per-item roll-up,
  which is a real, larger follow-up (documented in proposal.md's Impact section), not silently assumed
  away.
- **No configurable per-framework "target level" for the skills-gap view in v1** — "any attainment" is
  treated as met; a target-level threshold is a natural v2 addition to `CompetencyFramework` once real
  usage shows what schools actually need (pass/fail vs a specific mastery tier), left as a documented gap
  rather than guessed at.
- **No ESCO/SLO/SBB import connector** — this change ships the target schema an importer would populate,
  not the importer itself; explicit `openconnector`/cross-repo follow-up, matching `bpv`'s own SBB-adapter
  scope cut.
