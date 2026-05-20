# Design — Assignments & Submissions

## 1. Schemas

### 1.1 Rubric (slug `rubric`)

A reusable marking scheme an Assignment can point at.

| field | type | notes |
|---|---|---|
| name | string | required |
| description | string\|null | |
| criteria | array | `{ criterionId, label, weight (number), levels: [{ levelId, label, points (number) }] }` — required, ≥1 |
| maxPoints | number | author-declared; `computedMaxPoints` is the sum of each criterion's top level |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived (and active → archived) |

`x-openregister-calculations`: `criterionCount`, `computedMaxPoints`.

#### Seed data (Dutch examples)

```json
[
  {
    "uuid": "d1a2b3c4-0001-0000-0000-000000000001",
    "name": "Rubric Onderzoeksverslag Biologie",
    "description": "Beoordelingsschema voor het onderzoeksverslag van periode 3",
    "criteria": [
      {
        "criterionId": "c1",
        "label": "Probleemstelling en hypothese",
        "weight": 2,
        "levels": [
          { "levelId": "l1", "label": "Onvoldoende", "points": 0 },
          { "levelId": "l2", "label": "Voldoende",   "points": 3 },
          { "levelId": "l3", "label": "Goed",        "points": 5 }
        ]
      },
      {
        "criterionId": "c2",
        "label": "Methodologie",
        "weight": 3,
        "levels": [
          { "levelId": "l1", "label": "Onvoldoende", "points": 0 },
          { "levelId": "l2", "label": "Voldoende",   "points": 3 },
          { "levelId": "l3", "label": "Goed",        "points": 5 }
        ]
      }
    ],
    "maxPoints": 10,
    "tenant_id": "scholiq-demo",
    "lifecycle": "active"
  },
  {
    "uuid": "d1a2b3c4-0001-0000-0000-000000000002",
    "name": "Rubric Presentatie Nederlands",
    "description": "Mondelinge presentatie beoordeling voor 3e klas havo",
    "criteria": [
      {
        "criterionId": "c1",
        "label": "Inhoud en structuur",
        "weight": 3,
        "levels": [
          { "levelId": "l1", "label": "Onvoldoende", "points": 0 },
          { "levelId": "l2", "label": "Voldoende",   "points": 4 },
          { "levelId": "l3", "label": "Uitstekend",  "points": 6 }
        ]
      },
      {
        "criterionId": "c2",
        "label": "Taalgebruik",
        "weight": 2,
        "levels": [
          { "levelId": "l1", "label": "Onvoldoende", "points": 0 },
          { "levelId": "l2", "label": "Voldoende",   "points": 2 },
          { "levelId": "l3", "label": "Uitstekend",  "points": 4 }
        ]
      }
    ],
    "maxPoints": 10,
    "tenant_id": "scholiq-demo",
    "lifecycle": "active"
  },
  {
    "uuid": "d1a2b3c4-0001-0000-0000-000000000003",
    "name": "Rubric Portfolio-item HBO Verpleegkunde",
    "description": "Beoordelingsschema voor het reflectieverslag bij klinische stage",
    "criteria": [
      {
        "criterionId": "c1",
        "label": "Reflectiediepte",
        "weight": 4,
        "levels": [
          { "levelId": "l1", "label": "Beschrijvend",  "points": 3 },
          { "levelId": "l2", "label": "Analytisch",    "points": 6 },
          { "levelId": "l3", "label": "Kritisch-evaluatief", "points": 10 }
        ]
      },
      {
        "criterionId": "c2",
        "label": "Koppeling theorie-praktijk",
        "weight": 3,
        "levels": [
          { "levelId": "l1", "label": "Beperkt",  "points": 2 },
          { "levelId": "l2", "label": "Adequaat", "points": 5 },
          { "levelId": "l3", "label": "Sterk",    "points": 8 }
        ]
      }
    ],
    "maxPoints": 18,
    "tenant_id": "scholiq-demo",
    "lifecycle": "draft"
  }
]
```

### 1.2 Assignment (slug `assignment`)

| field | type | notes |
|---|---|---|
| title | string | required |
| instructions | string | rich text |
| courseId | uuid\|null | scope; publish requires courseId OR sessionId |
| sessionId | uuid\|null | scope |
| cohortId | uuid\|null | optional additional scope |
| curriculumPlanComponentId | string\|null | which `CurriculumPlan.components[].componentId` this scores |
| briefingMaterialIds | uuid[] | attached `Material`s |
| dueAt | datetime\|null | submission deadline |
| maxPoints | number | |
| allowLateSubmission | bool | default false |
| latePenaltyPercent | number | default 0 |
| rubricId | uuid\|null | linked Rubric |
| groupSubmission | bool | default false |
| visibleFrom | datetime\|null | learner-visibility window start |
| visibleUntil | datetime\|null | learner-visibility window end |
| plagiarismProvider | string\|null | resolves to `ProvidesPlagiarismCheck` — pluggable, no bundled provider |
| tenant_id | string | required |
| lifecycle | string | draft → published → closed \| archived |

`x-openregister-lifecycle.transitions.publish.requires`: `OCA\Scholiq\Lifecycle\AssignmentPublishGuard`.
`x-openregister-relations`: course, session, cohort, rubric, briefingMaterials.
`x-openregister-calculations`: `isOverdue` (dateDiff vs `dueAt`), `submissionCount`, `gradedCount`.

#### Seed data (Dutch examples)

```json
[
  {
    "uuid": "d1a2b3c4-0002-0000-0000-000000000001",
    "title": "Onderzoeksverslag Ecosystemen — Periode 3",
    "instructions": "<p>Schrijf een onderzoeksverslag van minimaal 1500 woorden over een Nederlands ecosysteem naar keuze. Gebruik de opgegeven rubric als leidraad voor de beoordeling.</p>",
    "courseId": "course-uuid-biologie-havo4",
    "sessionId": null,
    "cohortId": "cohort-uuid-havo4a",
    "curriculumPlanComponentId": "component-biologie-se-p3",
    "briefingMaterialIds": [],
    "dueAt": "2026-06-06T23:59:00+02:00",
    "maxPoints": 10,
    "allowLateSubmission": false,
    "latePenaltyPercent": 0,
    "rubricId": "d1a2b3c4-0001-0000-0000-000000000001",
    "groupSubmission": false,
    "visibleFrom": "2026-05-20T08:00:00+02:00",
    "visibleUntil": null,
    "plagiarismProvider": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "published"
  },
  {
    "uuid": "d1a2b3c4-0002-0000-0000-000000000002",
    "title": "Mondelinge Presentatie Nederlandse Literatuur",
    "instructions": "<p>Bereid een presentatie van 8-10 minuten voor over een roman uit de Nederlandse literatuur na 1945. Lever ook een geschreven samenvatting in.</p>",
    "courseId": "course-uuid-nederlands-havo3",
    "sessionId": null,
    "cohortId": "cohort-uuid-havo3b",
    "curriculumPlanComponentId": "component-nl-presentatie-p2",
    "briefingMaterialIds": [],
    "dueAt": "2026-05-30T17:00:00+02:00",
    "maxPoints": 10,
    "allowLateSubmission": true,
    "latePenaltyPercent": 10,
    "rubricId": "d1a2b3c4-0001-0000-0000-000000000002",
    "groupSubmission": false,
    "visibleFrom": "2026-05-10T08:00:00+02:00",
    "visibleUntil": "2026-05-30T17:00:00+02:00",
    "plagiarismProvider": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "published"
  },
  {
    "uuid": "d1a2b3c4-0002-0000-0000-000000000003",
    "title": "Groepsopdracht Maatschappijleer — Politiek Debat",
    "instructions": "<p>Vorm groepen van 3-4 studenten en voer een gestructureerd debat over een actueel politiek thema. Lever individuele reflectieverslagen in.</p>",
    "courseId": "course-uuid-maatschappijleer-vwo5",
    "sessionId": null,
    "cohortId": "cohort-uuid-vwo5c",
    "curriculumPlanComponentId": "component-ml-debat-p4",
    "briefingMaterialIds": [],
    "dueAt": "2026-06-20T23:59:00+02:00",
    "maxPoints": 10,
    "allowLateSubmission": false,
    "latePenaltyPercent": 0,
    "rubricId": null,
    "groupSubmission": true,
    "visibleFrom": "2026-06-01T08:00:00+02:00",
    "visibleUntil": null,
    "plagiarismProvider": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "draft"
  }
]
```

### 1.3 Submission (slug `submission`)

| field | type | notes |
|---|---|---|
| assignmentId | uuid | required |
| learnerIds | string[] | NC user IDs — one for solo, many for group |
| attachmentRefs | string[] | OpenRegister file-attachment references — **no bytes stored on this schema** |
| submittedAt | datetime\|null | set on `submit` |
| feedbackText | string\|null | teacher feedback |
| rubricScores | array | `{ criterionId, levelId, points }` — the teacher's per-criterion marking |
| proposedGrade | number\|null | sum of `rubricScores[].points` |
| gradeEntryId | uuid\|null | set once the `grading` spec's `GradeEntry` exists (forward-ref) |
| tenant_id | string | required |
| lifecycle | string | draft → submitted → late → returned |

`x-openregister-lifecycle.transitions.submit.requires`: `OCA\Scholiq\Lifecycle\SubmissionWindowGuard`.
`x-openregister-relations`: assignment, learners.
`x-openregister-calculations`: `isLate`, `effectiveGrade`.
Not `appendOnly` — a draft submission is editable; once `submitted`/`returned` it should not change, which the lifecycle (no transition back to `draft`) enforces.

#### Seed data (Dutch examples)

```json
[
  {
    "uuid": "d1a2b3c4-0003-0000-0000-000000000001",
    "assignmentId": "d1a2b3c4-0002-0000-0000-000000000001",
    "learnerIds": ["user-leerling-jan-devries"],
    "attachmentRefs": ["attachment-ref-jan-verslag-v2.pdf"],
    "submittedAt": "2026-06-05T21:34:00+02:00",
    "feedbackText": "Goed onderbouwde hypothese. De methodologiesectie kan nog scherper — zie mijn commentaar per criterium.",
    "rubricScores": [
      { "criterionId": "c1", "levelId": "l3", "points": 5 },
      { "criterionId": "c2", "levelId": "l2", "points": 3 }
    ],
    "proposedGrade": 8,
    "gradeEntryId": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "returned"
  },
  {
    "uuid": "d1a2b3c4-0003-0000-0000-000000000002",
    "assignmentId": "d1a2b3c4-0002-0000-0000-000000000001",
    "learnerIds": ["user-leerling-fatima-elamrani"],
    "attachmentRefs": ["attachment-ref-fatima-verslag-final.pdf"],
    "submittedAt": "2026-06-06T18:02:00+02:00",
    "feedbackText": null,
    "rubricScores": [],
    "proposedGrade": null,
    "gradeEntryId": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "submitted"
  },
  {
    "uuid": "d1a2b3c4-0003-0000-0000-000000000003",
    "assignmentId": "d1a2b3c4-0002-0000-0000-000000000002",
    "learnerIds": ["user-leerling-priya-ganpat"],
    "attachmentRefs": ["attachment-ref-priya-presentatie.pptx", "attachment-ref-priya-samenvatting.docx"],
    "submittedAt": "2026-06-01T10:15:00+02:00",
    "feedbackText": null,
    "rubricScores": [],
    "proposedGrade": null,
    "gradeEntryId": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "late"
  },
  {
    "uuid": "d1a2b3c4-0003-0000-0000-000000000004",
    "assignmentId": "d1a2b3c4-0002-0000-0000-000000000001",
    "learnerIds": ["user-leerling-sem-dejong"],
    "attachmentRefs": ["attachment-ref-sem-concept-v1.pdf"],
    "submittedAt": null,
    "feedbackText": null,
    "rubricScores": [],
    "proposedGrade": null,
    "gradeEntryId": null,
    "tenant_id": "scholiq-demo",
    "lifecycle": "draft"
  }
]
```

## 2. PHP — ADR-031 legitimate exceptions

### 2.1 AssignmentPublishGuard (`lib/Lifecycle/AssignmentPublishGuard.php`)

`check(array &$transitionContext): bool`. Returns false unless `$object['courseId'] !== null || $object['sessionId'] !== null`. No OR queries. Single responsibility.

**Justification (ADR-031 §"Exceptions")**: A lifecycle transition guard per the ADR-031 "PHP guards remain a legitimate seam" clause. The precondition (parent object presence) cannot be expressed in JSON-logic; the guard is short, focused, and single-method.

### 2.2 SubmissionWindowGuard (`lib/Lifecycle/SubmissionWindowGuard.php`)

`check(array &$transitionContext): bool`. Reads `$object['assignmentId']`, looks up the Assignment via `ObjectService::findAll(['register'=>'scholiq','schema'=>'assignment','filters'=>['uuid'=>$assignmentId],'limit'=>1])`. If now > `dueAt` and `allowLateSubmission === false` → return false (OR surfaces HTTP 422). If now > `dueAt` and late is allowed → set the transition's target state to `late` (per OR's lifecycle-guard contract — see `OCA\OpenRegister\Service\Lifecycle\TransitionEngine`; if the contract can't redirect, just allow and rely on the `isLate` calculation) and return true. Else return true. Single responsibility; no audit writes.

**Justification (ADR-031 §"Exceptions")**: A lifecycle transition guard. The time-comparison against a related object (`Assignment.dueAt`) and conditional target-state branching cannot be expressed in JSON-logic; guard is short, focused, and single-method.

### 2.3 ProvidesPlagiarismCheck (`lib/Plagiarism/ProvidesPlagiarismCheck.php`)

An interface (`startCheck($submissionId): string`, `fetchReport($checkId): array`). No concrete provider ships. Wired via `Assignment.plagiarismProvider` config. Analogous to `ProctoringProviderInterface` in the `assessment` spec.

**Justification (ADR-031 §"Exceptions")**: External API integration — the plagiarism adapter reaches outside the Nextcloud environment. The interface defines the seam; adapter implementations are third-party packages.

Guards are resolved by OR's lifecycle engine via DI using the FQCN declared in the schema's `requires:` — no `registerEventListener` in `Application.php` needed.

## 3. Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Submission lifecycle (draft → submitted → late → returned) | Declarative (`x-openregister-lifecycle`) | OR's lifecycle engine natively expresses state transitions |
| Late-window enforcement on `submit` | PHP guard (`SubmissionWindowGuard`) | Requires a cross-object lookup (Assignment.dueAt) + conditional branch of the target state — not expressible in JSON-logic |
| Publish guard on Assignment | PHP guard (`AssignmentPublishGuard`) | Simple precondition check (parent presence); JSON-logic has no reference to sibling field nullness |
| `isLate`, `effectiveGrade`, `isOverdue` | Declarative (`x-openregister-calculations`) | Pure derived fields from `@self` fields and time — the calculation engine handles dateDiff and arithmetic |
| `submissionCount`, `gradedCount` on Assignment | Declarative (`x-openregister-calculations`) | Cross-schema count aggregation supported by OR |
| Plagiarism check invocation | PHP interface (`ProvidesPlagiarismCheck`) | External API integration — explicitly listed in ADR-031 §"What apps SHOULD still write in PHP" |

## 4. Frontend

### 4.1 Manifest pages

| id | route | type | notes |
|---|---|---|---|
| Assignments | /assignments | index | schema=Assignment |
| AssignmentDetail | /assignments/:id | detail | schema=Assignment |
| Rubrics | /assignments/rubrics | index | schema=Rubric |
| RubricDetail | /assignments/rubrics/:id | detail | schema=Rubric |
| Submissions | /assignments/submissions | index | schema=Submission |
| SubmissionDetail | /assignments/submissions/:id | detail | schema=Submission |
| SubmitWorkModal | — | custom | component=SubmitWorkModal |
| MarkSubmissionView | /assignments/submissions/:id/mark | custom | component=MarkSubmissionView |

One nav menu entry: "Assignments", route=Assignments, order=40.

Validation rule per ADR-024: detail pages do **not** carry a string-array `config.tabs`; `CnObjectSidebar` renders detail tabs automatically.

### 4.2 SubmitWorkModal.vue

Three steps inside one modal:
1. **Pick / drag files** — file input backed by OR's attachment API (`POST /api/objects/{id}/attachments`).
2. **Review** — shows the assignment title, `instructions`, `dueAt`, and whether the learner is inside the submission window (`isOverdue` calculation surfaced in the response).
3. **Confirm + submit** — create/update the `Submission` via OR REST, attach files via OR's attachment API, then dispatch the `submit` transition (which runs `SubmissionWindowGuard`).

Options API; `createObjectStore`; no custom Pinia module. Registered in `src/main.js` via `customComponents` on `CnAppRoot`.

### 4.3 MarkSubmissionView.vue

- Shows the submission's `attachmentRefs` (download links via OR's attachment endpoint).
- Loads the linked `Rubric` (via `rubricId` on the Assignment) and renders its `criteria` — teacher picks a level per criterion.
- Points sum → `proposedGrade` computed client-side and written to the Submission.
- On save: write `rubricScores` + `proposedGrade` + `feedbackText` to the Submission and dispatch the `return` transition.
- Leaves a `// TODO(grading spec): emit/update a GradeEntry for the Assignment's curriculumPlanComponentId` — `GradeEntry` does not exist until the `grading` spec lands; this view does not fabricate one.

Options API; `createObjectStore`; no custom Pinia module.

## 5. Out of scope

- The structured-test / exam path (QTI items, scoring engine, proctoring) — the `assessment` spec.
- Peer review / peer grading — a follow-up.
- The plagiarism-detection algorithm — only the pluggable interface is here.
- Final-grade computation — the `grading` spec (`GradeEntry` → `FinalGrade`).
