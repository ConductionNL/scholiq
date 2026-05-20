# Design — Grading: GradeScale, GradeEntry, FinalGrade, Soft-Publish

## 1. NotificationPreference — using OR's existing mechanism

**Finding**: `openregister/lib/Controller/UserController.php` exposes `GET /api/users/me/notification-preferences` + `PUT /api/users/me/notification-preferences`; `UserService::getNotificationPreferences()` / `setNotificationPreferences()` manage per-user prefs stored in NC preferences table; `BatchNotificationJob` dispatches digest queues. The `mode` (instant / daily-digest), `digestAtHour`, and subject-kind filtering map directly to the fields this spec needs.

**Decision**: Do NOT add a `NotificationPreference` schema. Schema count: **22 → 24** (GradeScale + GradeEntry + FinalGrade). The `gradePublished` notification's recipient resolution delegates to OR's existing mechanism: the declarative `x-openregister-notifications` on GradeEntry targets the `learnerId`, and for parent fan-out, the `GradeRollupHandler` resolves `LearnerProfile.parentIds` and fires the notification for each parent.

## 2. Schemas

### 2.1 GradeScale (slug `grade-scale`)

| field | type | notes |
|---|---|---|
| name | string | required |
| kind | string enum | `numeric` \| `letter` \| `ects` \| `pass-fail` \| `percentage` \| `band` |
| bands | array | `{ bandId, label, minValue (number), maxValue (number), pass (bool) }[]` — for letter/ects/band kinds |
| min | number\|null | for numeric/percentage kinds |
| max | number\|null | for numeric/percentage kinds |
| passThreshold | number\|null | for numeric/percentage kinds |
| roundingRule | string enum | `none` \| `half-up-1dp` \| `half-up-int` \| `nearest-half` |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived |

`x-openregister-calculations`: `bandCount` (count of bands array).

### 2.2 GradeEntry (slug `grade-entry`)

| field | type | notes |
|---|---|---|
| learnerId | string | required — NC user ID |
| curriculumPlanId | uuid | required |
| componentId | string | required — `CurriculumPlan.components[].componentId` |
| courseId | uuid\|null | |
| cohortId | uuid\|null | |
| sourceKind | string enum | `assignment-submission` \| `assessment-result` \| `participation` \| `manual` |
| submissionId | uuid\|null | set when sourceKind=assignment-submission |
| assessmentResultId | uuid\|null | set when sourceKind=assessment-result |
| sessionId | uuid\|null | set when sourceKind=participation |
| value | number | required — the raw mark on this component's GradeScale |
| gradeScaleId | uuid | required |
| weight | number\|null | per-entry override; null → use `CurriculumPlan.components[].weight` |
| period | string | e.g. "periode-1", "semester-2" |
| grader | string | NC user ID of the grading teacher |
| gradedAt | datetime | |
| comment | string\|null | |
| tenant_id | string | required |
| lifecycle | string | concept → published → revised |

`x-openregister-lifecycle`:
- Transitions: `concept` → (publish) → `published`; `published` → (revise) → `revised`; `revised` → (publish) → `published`. NO back-transition to `concept`.
- `publish` transition: triggers `gradePublished` notification.

`x-openregister-notifications`:
- `gradePublished`: event `lifecycle.published`, recipient `learnerId`, idempotencyKey `"${@self.id}-${@self.lifecycle}"`.

`x-openregister-relations`: learner (learnerId → LearnerProfile), curriculumPlan, course, cohort, submission, assessmentResult, session.

`x-openregister-calculations`: `effectiveWeight` (weight if not null, else CurriculumPlan.components[componentId].weight), `pointsContributed` (value × effectiveWeight).

Not appendOnly — concept entries are editable; lifecycle enforces immutability once published (no back-transition).

### 2.3 FinalGrade (slug `final-grade`)

| field | type | notes |
|---|---|---|
| learnerId | string | required |
| courseId | uuid\|null | |
| programmeId | uuid\|null | |
| curriculumPlanId | uuid | required |
| gradeScaleId | uuid | required |
| tenant_id | string | required |

No lifecycle (derived). Read-only from the frontend.

`x-openregister-aggregations`: cross-schema aggregation pulling the learner's published GradeEntries matching `learnerId` + `curriculumPlanId` + `lifecycle=published` from the `grade-entry` schema.

`x-openregister-calculations`:
- `value`: apply `CurriculumPlan.formula` via `GradeFormulaEvaluator`. The four formulas:
  - `weighted-average`: Σ(entry.value × entry.effectiveWeight) / Σ(entry.effectiveWeight) across all published entries.
  - `last-attempt`: for each componentId, take the most-recent (by gradedAt) published entry; then weighted-average of those.
  - `best-of-n`: for each componentId, take the highest-value published entry; then weighted-average of those.
  - `all-must-pass`: weighted-average as above, BUT `passed` is false unless every component's best value ≥ its `passRules` threshold.
- `breakdown`: per-period averages + per-component contributions (JSON object).
- `passed`: per formula + `passRules` + `GradeScale.passThreshold`.
- `lastRecomputedAt`: timestamp of last recompute.

`x-openregister-triggers`: `calculatedChange` — recompute when any published GradeEntry for this (learnerId, curriculumPlanId) changes. Implemented via `GradeRollupHandler` (see §3.2).

## 3. PHP — ADR-031 legitimate exceptions

### 3.1 GradeFormulaEvaluator (lib/Grading/GradeFormulaEvaluator.php)

Single public method: `evaluate(string $curriculumPlanId, string $learnerId): array`

Returns `['value' => float, 'passed' => bool, 'breakdown' => array]`.

Algorithm:
1. Fetch the CurriculumPlan via `ObjectService::find(id: $curriculumPlanId, register: 'scholiq', schema: 'curriculum-plan')`.
2. Fetch the learner's published GradeEntries via `ObjectService::findAll(['register'=>'scholiq','schema'=>'grade-entry','filters'=>['learnerId'=>$learnerId,'curriculumPlanId'=>$curriculumPlanId,'lifecycle'=>'published']])`.
3. Build a component-weight map from `CurriculumPlan.components`.
4. Apply the formula (`formula` field on the plan).
5. Apply `GradeScale.passThreshold` + `passRules` for the `passed` verdict.
6. Return value, passed, breakdown.

Legitimate per ADR-031 "calculation engine above schema metadata". Single responsibility. No state. No audit writes.

### 3.2 GradeRollupHandler (lib/Listener/GradeRollupHandler.php)

Listens for `ObjectTransitionedEvent`. Filters: register=scholiq, schema=grade-entry, to=published.

On match (GradeEntry published):
1. Read `learnerId` and `curriculumPlanId` from the transitioned GradeEntry.
2. Fetch or create the FinalGrade for `(learnerId, curriculumPlanId)` via `ObjectService::findAll()` / `ObjectService::saveObject()`.
3. Call `GradeFormulaEvaluator::evaluate()`.
4. Persist updated `value`, `breakdown`, `passed`, `lastRecomputedAt` via `ObjectService::saveObject()`.
5. Resolve `LearnerProfile.parentIds` for this learnerId and fire the `gradePublished` notification for each parent (fan-out to parents not covered by the declarative learner-only notification).

On match (AssessmentResult graded):
1. Create a `concept` GradeEntry with `sourceKind: assessment-result`, `value: totalScore`, `componentId` from `AssessmentResult.gradeEntryComponentId`.
2. Set `AssessmentResult.gradeEntryId` via `ObjectService::saveObject()`.

Registered in `Application.php` via `registerEventListener(ObjectTransitionedEvent::class, GradeRollupHandler::class)`.

Legitimate per ADR-031 "lifecycle handler — event-to-object-write bridge". Single responsibility: translate a GradeEntry publish event into a FinalGrade recompute + parent notification fan-out.

### 3.3 MarkSubmissionView.vue — TODO fulfilled

The `// TODO(grading spec)` in `saveAndReturn()` is replaced with a POST to create a `concept` GradeEntry with `sourceKind: assignment-submission`, `value: proposedGrade`, `componentId: assignment.curriculumPlanComponentId`, then a PUT to set `Submission.gradeEntryId`. The TODO comment is removed.

## 4. Frontend

### 4.1 Manifest pages

| id | route | type | notes |
|---|---|---|---|
| GradeScales | /grades/scales | index | schema=GradeScale |
| GradeScaleDetail | /grades/scales/:id | detail | schema=GradeScale |
| GradeEntries | /grades/entries | index | schema=GradeEntry |
| GradeEntryDetail | /grades/entries/:id | detail | schema=GradeEntry |
| FinalGrades | /grades/final | index | schema=FinalGrade, readOnly |
| FinalGradeDetail | /grades/final/:id | detail | schema=FinalGrade, readOnly |
| GradebookView | /grades/cohort/:cohortId/plan/:planId | custom | component=GradebookView |
| GradeImpactDetail | /grades/entries/:id/impact | custom | component=GradeImpactDetail |

One nav menu entry: "Grades", route=GradeEntries, order=46.

### 4.2 GradebookView.vue

For a given Cohort + CurriculumPlan:
- Fetches the Cohort (learner list), the CurriculumPlan (components), and the learners' GradeEntries (all states).
- Renders a learner × component grid. Each cell shows the entry value (or blank for missing). Teacher can click a cell to enter/edit a value — creates/updates a `concept` GradeEntry.
- Distribution preview: simple histogram (bucket count × value band) shown below the grid.
- "Publish all" button: transitions all `concept` entries for this cohort + plan to `published` (each triggers `gradePublished` notification once per recipient per their OR notification preference).

Options API + `createObjectStore`. No Pinia module.

### 4.3 GradeImpactDetail.vue

For one published GradeEntry:
- Shows `value`, `effectiveWeight`, `pointsContributed`.
- Shows the resulting period average (fetches other published GradeEntries for the same period).
- Shows the delta to the learner's FinalGrade (fetches the FinalGrade for this learnerId + curriculumPlanId).

Read-only.

## 5. Notification soft-publish flow

1. Teacher enters grades in the gradebook → each GradeEntry saved as `concept`. No notification fires.
2. Teacher optionally previews the distribution (histogram in GradebookView).
3. Teacher clicks "Publish all" → each `concept` entry transitions to `published`.
4. OR's `gradePublished` notification fires for the learner (per the declarative idempotencyKey — no duplicate even if publish is retried).
5. `GradeRollupHandler` fires `gradePublished` for each parent listed in `LearnerProfile.parentIds`.
6. Recipients with `mode=instant` get the notification immediately. Recipients with `mode=daily-digest` get it in the next digest flush (OR's `BatchNotificationJob`). Recipients with `mode=off` get nothing.
7. 18+ learners who set their own preference (OR's `managedByOwnerSelf` equivalent stored via UserService) are not overridden by a parent setting.

## 6. Declarative-vs-imperative decisions (ADR-031)

| Behaviour | Decision | Justification |
|---|---|---|
| GradeEntry lifecycle (concept → published → revised) | **Declarative** — `x-openregister-lifecycle` in register | Matches the lifecycle extension exactly; no guard needed |
| `gradePublished` notification to learner | **Declarative** — `x-openregister-notifications` with idempotencyKey | OR's notification extension handles recipient dispatch and duplicate suppression |
| Parent notification fan-out | **Imperative** — `GradeRollupHandler` resolves `LearnerProfile.parentIds` | OR's declarative notification cannot resolve dynamic parent IDs from a cross-schema relation at declaration time |
| FinalGrade roll-up trigger | **Imperative** — `GradeRollupHandler` listens for `ObjectTransitionedEvent` | `x-openregister-triggers.calculatedChange` is declared but the four formulas (weighted-average, last-attempt, best-of-n, all-must-pass) require a PHP evaluator; this is the ADR-031 "calculation engine above schema metadata" exception |
| `effectiveWeight` + `pointsContributed` per GradeEntry | **Declarative** — `x-openregister-calculations` | Simple arithmetic expressions expressible in JSON-logic |
| GradeScale `bandCount` | **Declarative** — `x-openregister-calculations` | Array-length expression |

## 7. Reuse Analysis (ADR-022)

| OR Abstraction | How consumed |
|---|---|
| `ObjectService::saveObject` / `findAll` / `find` | All data reads/writes in GradeFormulaEvaluator and GradeRollupHandler |
| `x-openregister-lifecycle` | GradeEntry lifecycle transitions (concept → published → revised) |
| `x-openregister-notifications` | `gradePublished` learner notification with idempotencyKey |
| `x-openregister-calculations` | `effectiveWeight`, `pointsContributed` (GradeEntry); `bandCount` (GradeScale); `value`, `breakdown`, `passed`, `lastRecomputedAt` (FinalGrade) |
| `x-openregister-aggregations` | Cross-schema pull of published GradeEntries into FinalGrade |
| `x-openregister-relations` | GradeEntry → LearnerProfile, CurriculumPlan, Course, Cohort, Submission, AssessmentResult, Session |
| `UserService::getNotificationPreferences` / `setNotificationPreferences` | Per-parent / per-18+-learner notification mode (instant vs daily-digest); NOT duplicated as a new schema |
| `BatchNotificationJob` | Digest delivery for parents/learners with `mode=daily-digest` |
| `CnAppRoot` + `src/manifest.json` | All pages declared declaratively; standard CRUD pages use schema-driven CnIndexPage/CnDetailPage via manifest renderer (ADR-024) |
| `createObjectStore` | GradebookView.vue store for GradeEntry CRUD + lifecycle transitions |
| `CnIndexPage` / `CnDetailPage` | Standard index+detail for GradeScale, GradeEntry, FinalGrade (no custom PHP CRUD controllers) |

No parallel implementations. `GradeFormulaEvaluator` and `GradeRollupHandler` are the only PHP additions; both are documented ADR-031 exceptions.

## 8. Seed Data

Seed objects are included in `lib/Settings/scholiq_register.json` under `components.objects[]` using the `@self` envelope. Re-importing is idempotent (matched by slug).

### 8.1 GradeScale seed objects

```json
{
  "@self": { "register": "scholiq", "schema": "grade-scale", "slug": "grade-scale-nl-numeriek" },
  "name": "Nederlandse cijferschaal 1–10",
  "kind": "numeric",
  "min": 1.0,
  "max": 10.0,
  "passThreshold": 5.5,
  "roundingRule": "half-up-1dp",
  "bands": [],
  "tenant_id": "scholiq-demo",
  "lifecycle": "active"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-scale", "slug": "grade-scale-ects" },
  "name": "ECTS beoordelingsschaal A–F",
  "kind": "ects",
  "min": null,
  "max": null,
  "passThreshold": null,
  "roundingRule": "none",
  "bands": [
    { "bandId": "A", "label": "A — Uitstekend", "minValue": 90, "maxValue": 100, "pass": true },
    { "bandId": "B", "label": "B — Zeer goed", "minValue": 80, "maxValue": 89, "pass": true },
    { "bandId": "C", "label": "C — Goed", "minValue": 70, "maxValue": 79, "pass": true },
    { "bandId": "D", "label": "D — Voldoende", "minValue": 60, "maxValue": 69, "pass": true },
    { "bandId": "E", "label": "E — Matig", "minValue": 50, "maxValue": 59, "pass": true },
    { "bandId": "F", "label": "F — Onvoldoende", "minValue": 0, "maxValue": 49, "pass": false }
  ],
  "tenant_id": "scholiq-demo",
  "lifecycle": "active"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-scale", "slug": "grade-scale-geslaagd-gezakt" },
  "name": "Geslaagd / Gezakt",
  "kind": "pass-fail",
  "min": null,
  "max": null,
  "passThreshold": null,
  "roundingRule": "none",
  "bands": [
    { "bandId": "geslaagd", "label": "Geslaagd", "minValue": 1, "maxValue": 1, "pass": true },
    { "bandId": "gezakt", "label": "Gezakt", "minValue": 0, "maxValue": 0, "pass": false }
  ],
  "tenant_id": "scholiq-demo",
  "lifecycle": "active"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-scale", "slug": "grade-scale-percentage" },
  "name": "Procentschaal 0–100%",
  "kind": "percentage",
  "min": 0,
  "max": 100,
  "passThreshold": 55,
  "roundingRule": "half-up-int",
  "bands": [],
  "tenant_id": "scholiq-demo",
  "lifecycle": "active"
}
```

### 8.2 GradeEntry seed objects

```json
{
  "@self": { "register": "scholiq", "schema": "grade-entry", "slug": "grade-entry-wiskunde-jandevries-p1" },
  "learnerId": "jan.devries",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "componentId": "comp-wiskunde-toets-1",
  "courseId": "00000000-0000-0000-0000-000000000201",
  "cohortId": "00000000-0000-0000-0000-000000000301",
  "sourceKind": "assessment-result",
  "submissionId": null,
  "assessmentResultId": "00000000-0000-0000-0000-000000000401",
  "sessionId": null,
  "value": 7.5,
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "weight": null,
  "period": "periode-1",
  "grader": "j.bakker",
  "gradedAt": "2026-03-15T10:30:00+01:00",
  "comment": "Goede prestatie op het algebraonderdeel.",
  "tenant_id": "scholiq-demo",
  "lifecycle": "published"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-entry", "slug": "grade-entry-engels-fatima-p1" },
  "learnerId": "fatima.elamrani",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "componentId": "comp-engels-werkstuk-1",
  "courseId": "00000000-0000-0000-0000-000000000202",
  "cohortId": "00000000-0000-0000-0000-000000000301",
  "sourceKind": "assignment-submission",
  "submissionId": "00000000-0000-0000-0000-000000000601",
  "assessmentResultId": null,
  "sessionId": null,
  "value": 8.0,
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "weight": null,
  "period": "periode-1",
  "grader": "m.visser",
  "gradedAt": "2026-03-18T14:00:00+01:00",
  "comment": "Uitstekende argumentatiestructuur.",
  "tenant_id": "scholiq-demo",
  "lifecycle": "published"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-entry", "slug": "grade-entry-scheikunde-pieter-p2" },
  "learnerId": "pieter.vandenberg",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "componentId": "comp-scheikunde-practicum-2",
  "courseId": "00000000-0000-0000-0000-000000000203",
  "cohortId": "00000000-0000-0000-0000-000000000301",
  "sourceKind": "participation",
  "submissionId": null,
  "assessmentResultId": null,
  "sessionId": "00000000-0000-0000-0000-000000000701",
  "value": 6.0,
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "weight": 2,
  "period": "periode-2",
  "grader": "a.dekker",
  "gradedAt": "2026-04-22T09:15:00+02:00",
  "comment": null,
  "tenant_id": "scholiq-demo",
  "lifecycle": "published"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "grade-entry", "slug": "grade-entry-nederlands-lisa-p1-concept" },
  "learnerId": "lisa.bakker",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "componentId": "comp-nederlands-toets-1",
  "courseId": "00000000-0000-0000-0000-000000000204",
  "cohortId": "00000000-0000-0000-0000-000000000301",
  "sourceKind": "manual",
  "submissionId": null,
  "assessmentResultId": null,
  "sessionId": null,
  "value": 5.0,
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "weight": null,
  "period": "periode-1",
  "grader": "m.visser",
  "gradedAt": "2026-03-20T11:00:00+01:00",
  "comment": "Herkansing gepland voor volgende week.",
  "tenant_id": "scholiq-demo",
  "lifecycle": "concept"
}
```

### 8.3 FinalGrade seed objects

```json
{
  "@self": { "register": "scholiq", "schema": "final-grade", "slug": "final-grade-jandevries-plan-101" },
  "learnerId": "jan.devries",
  "courseId": "00000000-0000-0000-0000-000000000201",
  "programmeId": "00000000-0000-0000-0000-000000000801",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "tenant_id": "scholiq-demo"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "final-grade", "slug": "final-grade-fatima-plan-101" },
  "learnerId": "fatima.elamrani",
  "courseId": "00000000-0000-0000-0000-000000000202",
  "programmeId": "00000000-0000-0000-0000-000000000801",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "tenant_id": "scholiq-demo"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "final-grade", "slug": "final-grade-pieter-plan-101" },
  "learnerId": "pieter.vandenberg",
  "courseId": null,
  "programmeId": "00000000-0000-0000-0000-000000000801",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "tenant_id": "scholiq-demo"
}
```

```json
{
  "@self": { "register": "scholiq", "schema": "final-grade", "slug": "final-grade-lisa-plan-101" },
  "learnerId": "lisa.bakker",
  "courseId": null,
  "programmeId": "00000000-0000-0000-0000-000000000801",
  "curriculumPlanId": "00000000-0000-0000-0000-000000000101",
  "gradeScaleId": "00000000-0000-0000-0000-000000000501",
  "tenant_id": "scholiq-demo"
}
```

## 9. Out of scope

- CE result import + CE+SE→eindcijfer combination (DUO/data-exchange concern).
- Cross-school cohort analytics (mydash via runtime GraphQL).
- Transcript / diploma-supplement generation (certification spec + DocuDesk).
- AI-assisted grading (AiFeature registration, not in scope).
