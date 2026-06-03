# Design — Learning Plan: Template, Plan, Evaluation, Signature + Version Chain

## 1. Schema decisions

### 1.1 OPP as a LearningPlan kind profile

The Dutch OPP (Ontwikkelingsperspectief) is modelled as `LearningPlan.kind = 'opp'` rather than a separate schema. This keeps a single write path for all plan types (OPP, handelingsplan, IEP, PDP, IDP) and avoids per-country schema proliferation. The template system (`LearningPlanTemplate.kind`) provides the sector-specific structure (sections, goalDomains, requiredSignerRoles).

### 1.2 Version chain

Each material revision of a LearningPlan is a **new OpenRegister object** (not a field update on the existing one) with:
- `version = prior.version + 1`
- `supersedesId = prior plan UUID`
- `lifecycle = 'draft'`

The prior version transitions to `superseded` atomically inside `LearningPlanSignatureGuard` when the new version activates. `Signature` and `LearningPlanEvaluation` objects reference `subjectVersion` / are linked to the plan UUID — they are immutable (both `appendOnly: true`) so the audit trail is always complete.

### 1.3 LearningPlanTemplate sections vs goalDomains

`sections` define the structural document outline (ordered, with help text). `goalDomains` define the allowed goal taxonomies (e.g. `leren-en-ontwikkeling`, `werkhouding`, `sociaal-emotioneel`, `fysiek-medisch` for OPP-VO). Both are arrays — templates may define both, one, or neither.

## 2. Schemas

### 2.1 LearningPlanTemplate (slug `learning-plan-template`)

| field | type | notes |
|---|---|---|
| name | string | required |
| kind | enum | opp\|handelingsplan\|iep\|pdp\|idp\|generic |
| description | string\|null | optional |
| sections | array | `{sectionId, label, order, helpText?}[]` — ordered document structure |
| goalDomains | string[] | allowed goal taxonomy domains |
| requiredSignerRoles | string[] | roles that must co-sign each version before activation |
| defaultReviewCadenceMonths | int | default 3 |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived (bidirectional reactivate) |

### 2.2 LearningPlan (slug `learning-plan`)

| field | type | notes |
|---|---|---|
| learnerId | string | required — NC user ID |
| kind | enum | opp\|handelingsplan\|iep\|pdp\|idp\|generic |
| templateId | uuid\|null | links to LearningPlanTemplate |
| cohortId | uuid\|null | optional Cohort context |
| courseId | uuid\|null | optional Course context |
| coordinatorId | string | required — NC user ID |
| goals | array | `{goalId, description, domain?, baseline?, target?, targetDate?, status:(open\|met\|adjusted\|dropped), evidenceRefs:uuid[]}[]` |
| supportMeasures | array | `{measureId, description, responsibleId?, startDate?, endDate?}[]` |
| period | string\|null | e.g. '2025-2026' |
| reviewCadenceMonths | int | default 3 |
| nextReviewAt | date\|null | set post-activation; drives `nextReviewDue` |
| version | int | default 1; increments per revision |
| supersedesId | uuid\|null | UUID of prior plan version |
| tenant_id | string | required |
| lifecycle | string | draft → active → under-evaluation → closed \| superseded |

`x-openregister-lifecycle.transitions.activate.requires`: `OCA\Scholiq\Lifecycle\LearningPlanSignatureGuard`

`x-openregister-calculations`: `goalsMetCount`, `goalsTotalCount`, `nextReviewDue` (nextReviewAt ≤ today), `isFullySigned` (computed by guard)

`x-openregister-notifications`:
- `quarterlyReviewReminder`: trigger `calculatedChange` on `nextReviewDue` becoming true; recipient `coordinatorId`; idempotencyKey `${@self.id}-${@self.nextReviewAt}`. NOT a TimedJob.
- `signatureRequested`: trigger `lifecycle.enter.draft`; recipient each required signer (from template); idempotencyKey `${@self.id}-v${@self.version}-${role}`.

### 2.3 LearningPlanEvaluation (slug `learning-plan-evaluation`, appendOnly)

| field | type | notes |
|---|---|---|
| learningPlanId | uuid | required |
| evaluatedAt | date | required |
| evaluatedBy | string | required — NC user ID |
| goalOutcomes | array | `{goalId, outcome:(met\|adjusted\|dropped\|continued), note?}[]` |
| narrative | string\|null | free-text meeting notes |
| attendeeIds | string[] | NC user IDs |
| nextReviewAt | date\|null | next review date; written back to LearningPlan by handler |
| tenant_id | string | required |
| lifecycle | string | draft → recorded |

Lifecycle transition `record` triggers `LearningPlanEvaluationHandler`.

### 2.4 Signature (slug `signature`, appendOnly)

| field | type | notes |
|---|---|---|
| subjectKind | enum | learning-plan (only, for now) |
| subjectId | uuid | required — LearningPlan UUID |
| subjectVersion | int | required — plan version being signed |
| signerId | string | required — NC user ID |
| signerRole | enum | learner\|parent\|coordinator\|teacher\|other |
| signedAt | datetime | required — ISO 8601 |
| assuranceLevel | enum | none\|basic\|substantial\|high |
| method | string | required — e.g. 'digid', 'click-to-confirm', 'wet-handtekening-scan' |
| evidenceRef | string\|null | e.g. redirect log URL, scan URL |
| tenant_id | string | required |

No lifecycle. `x-openregister-relations.subject` → LearningPlan.

## 3. PHP — ADR-031 legitimate exceptions

### 3.1 LearningPlanSignatureGuard (lib/Lifecycle/LearningPlanSignatureGuard.php)

Referenced from `LearningPlan.x-openregister-lifecycle.transitions.activate.requires`.

Algorithm:
1. Read `plan.templateId`, `plan.version`, `plan.kind`, `plan.supersedesId`, `plan.id`.
2. Fetch `LearningPlanTemplate.requiredSignerRoles` via `ObjectService::findAll(['register'=>'scholiq','schema'=>'learning-plan-template','filters'=>['uuid'=>$templateId],'limit'=>1])`. If no template / no roles → allow immediately.
3. Fetch `Signature[]` for `(subjectId=planId, subjectVersion=version)` via `ObjectService::findAll(...)`.
4. Index signatures by `signerRole` (keep highest assurance per role).
5. For each required role: minimum assurance = `substantial` for `parent` on `opp`, `basic` otherwise. If actual < minimum → return false (HTTP 422).
6. On pass: call `TransitionEngine::transition($supersedesId, 'supersede')` (best-effort; logged on failure). Return true.

Not registered in `Application.php` — OR's lifecycle engine resolves guards by class name from the schema `requires:` string.

### 3.2 LearningPlanEvaluationHandler (lib/Listener/LearningPlanEvaluationHandler.php)

Registered in `Application.php` for `ObjectTransitionedEvent`.

Filters: register=scholiq, schema=learning-plan-evaluation, to=recorded.

On match:
1. Read `evaluation.learningPlanId`, `evaluation.goalOutcomes`, `evaluation.nextReviewAt`.
2. Fetch the parent LearningPlan via `ObjectService::findAll(filters=['uuid'=>$planId], limit=1)`.
3. For each `goalOutcome`: map `met→met`, `adjusted→adjusted`, `dropped→dropped`; `continued` leaves `goal.status` unchanged (open).
4. Set `plan.nextReviewAt` from `evaluation.nextReviewAt` if non-null.
5. Persist via `ObjectService::saveObject(register='scholiq', schema='learning-plan', object=$plan)`.

Legitimate per ADR-031: cross-object write bridge that cannot be expressed declaratively.

## 4. Frontend

### 4.1 Manifest pages

| id | route | type | notes |
|---|---|---|---|
| LearningPlanTemplates | /learning-plans/templates | index | schema=LearningPlanTemplate |
| LearningPlanTemplateDetail | /learning-plans/templates/:id | detail | schema=LearningPlanTemplate |
| LearningPlans | /learning-plans | index | schema=LearningPlan |
| LearningPlanDetail | /learning-plans/:id | detail | schema=LearningPlan |
| LearningPlanEvaluations | /learning-plans/:planId/evaluations | index | schema=LearningPlanEvaluation, readOnly |
| LearningPlanEvaluationDetail | /learning-plans/:planId/evaluations/:id | detail | schema=LearningPlanEvaluation, readOnly |
| Signatures | /learning-plans/:planId/signatures | index | schema=Signature, readOnly |
| SignatureDetail | /learning-plans/:planId/signatures/:id | detail | schema=Signature, readOnly |
| SignPlanModal | /learning-plans/:planId/sign | custom | component=SignPlanModal |
| LearningPlanEditor | /learning-plans/:planId/edit | custom | component=LearningPlanEditor |

One nav menu entry: "Learning Plans", route=LearningPlans, order=49.

### 4.2 SignPlanModal.vue

- Loads the LearningPlan (`:planId`).
- Displays plan version, kind, learnerId, coordinatorId.
- Form: `signerRole` select (learner/parent/coordinator/teacher/other), `signingMethod` select (`click-to-confirm` → `basic` / `digid` → `substantial`).
- DigiD selected → informational placeholder banner (redirect is out of scope).
- On confirm: POST `Signature` (`subjectKind`, `subjectId`, `subjectVersion`, `signerId=currentUser`, `signerRole`, `signedAt=now()`, `assuranceLevel`, `method`).
- Options API, no Pinia module.

### 4.3 LearningPlanEditor.vue

- Loads the LearningPlan (`:planId`) and its template (for `goalDomains`).
- Renders `goals[]` grouped by template `goalDomains`; each goal shows description, baseline, target, targetDate, status.
- Add / edit / remove goals within each domain.
- Renders `supportMeasures[]` with description, responsibleId, startDate, endDate.
- Save via PUT to LearningPlan.
- Read-only when lifecycle is `active`, `closed`, or `superseded` — shows "Create new version" button that clones the plan (`version+1`, `supersedesId=current`, `lifecycle=draft`) via POST.
- Options API, no Pinia module.

## 5. Quarterly review reminder — NOT a TimedJob

The `quarterlyReviewReminder` notification is driven by OR's `calculatedChange` trigger on the `nextReviewDue` calculation (`nextReviewAt <= today()`). When the date arrives, OR's calculation engine flips `nextReviewDue` to true on the next evaluation cycle, and the notification fires. The `idempotencyKey` (`${@self.id}-${@self.nextReviewAt}`) prevents duplicate fires if the value is re-evaluated multiple times on the same day.

## 6. Out of scope

- DigiD / eIDAS authentication handshake (openconnector concern).
- Sector-wide OPP analytics (launchpad).
- Auto-generation of goals from assessment results (AiFeature).
- Samenwerkingsverband funding flow.
