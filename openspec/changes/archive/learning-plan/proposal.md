## Why

Learners with extra educational needs require an individualised plan: a structured document with goals, support measures, a review cycle, and co-signatures. In the Netherlands, the **Wet Passend Onderwijs** mandates the **Ontwikkelingsperspectief (OPP)** for every pupil with extra needs; `handelingsplannen` sit beneath it. ParnasSys (~65% PO market share) is the incumbent, but its OPP UX is widely criticised. Scholiq generalises this: `LearningPlan` is the abstract document; **OPP** is one `kind` profile (with sector-template structure and DigiD parent co-signing), **IEP** (US), **PDP** (higher-ed), and **IDP** (corporate) are others. Each material revision of a plan produces a new versioned document requiring fresh signatures before activation.

## What Changes

### New Schemas (4) — `lib/Settings/scholiq_register.json` (25 → 29)

- **LearningPlanTemplate** (slug `learning-plan-template`) — pre-structures a LearningPlan. Fields: `name`, `kind` (enum: opp|handelingsplan|iep|pdp|idp|generic), `description`, `sections` (`{sectionId,label,order,helpText}[]`), `goalDomains` (string[]), `requiredSignerRoles` (string[]), `defaultReviewCadenceMonths` (int, default 3), `tenant_id`. Lifecycle: draft → active → archived (bidirectional reactivate).
- **LearningPlan** (slug `learning-plan`) — per-learner plan. Fields: `learnerId`, `kind`, `templateId` (uuid|null), `cohortId` (uuid|null), `courseId` (uuid|null), `coordinatorId`, `goals` (`{goalId,description,domain,baseline,target,targetDate,status:(open|met|adjusted|dropped),evidenceRefs:uuid[]}[]`), `supportMeasures` (`{measureId,description,responsibleId,startDate,endDate}[]`), `period`, `reviewCadenceMonths` (int, default 3), `nextReviewAt` (date|null), `version` (int, default 1), `supersedesId` (uuid|null), `tenant_id`. Lifecycle: draft → active → under-evaluation → closed | superseded. `activate` transition requires `LearningPlanSignatureGuard`. `x-openregister-relations`: learner, template, cohort, course, supersedes (self-ref). `x-openregister-calculations`: `goalsMetCount`, `goalsTotalCount`, `nextReviewDue` (nextReviewAt ≤ today), `isFullySigned`. `x-openregister-notifications`: `quarterlyReviewReminder` (trigger: `calculatedChange` on `nextReviewDue` becoming true — NOT a TimedJob; recipient: coordinatorId; idempotencyKey: `${@self.id}-${@self.nextReviewAt}`), `signatureRequested` (trigger: `lifecycle.enter.draft`; recipient: each required signer; idempotencyKey: `${@self.id}-v${@self.version}-${role}`).
- **LearningPlanEvaluation** (slug `learning-plan-evaluation`, `appendOnly: true`) — dated review record. Fields: `learningPlanId` (uuid), `evaluatedAt` (date), `evaluatedBy` (string), `goalOutcomes` (`{goalId,outcome:(met|adjusted|dropped|continued),note}[]`), `narrative` (string|null), `attendeeIds` (string[]), `nextReviewAt` (date|null), `tenant_id`. Lifecycle: draft → recorded. `x-openregister-relations`: learningPlan.
- **Signature** (slug `signature`, `appendOnly: true`) — co-sign record on a plan version. Fields: `subjectKind` (enum: learning-plan, default `learning-plan`), `subjectId` (uuid), `subjectVersion` (int), `signerId` (string), `signerRole` (enum: learner|parent|coordinator|teacher|other), `signedAt` (datetime), `assuranceLevel` (enum: none|basic|substantial|high), `method` (string), `evidenceRef` (string|null), `tenant_id`. No lifecycle. `x-openregister-relations`: subject (LearningPlan). **DigiD/eIDAS handshake is OUT OF SCOPE** — only the Signature record is stored.

### New PHP (2, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/LearningPlanSignatureGuard.php` — single `check(array &$transitionContext): bool`. Fetches template's `requiredSignerRoles`; fetches Signatures for this plan + version; checks each required role has a signature with assurance ≥ minimum (`substantial` for `parent` on `opp`, `basic` for all others). On pass, calls `TransitionEngine::transition($supersedesId, 'supersede')` to atomically supersede the prior version. Referenced via schema `requires:` — no `Application.php` registration needed.
- `lib/Listener/LearningPlanEvaluationHandler.php` — `IEventListener` for `ObjectTransitionedEvent`; filters to `learning-plan-evaluation` → `recorded`. Updates parent `LearningPlan.goals[].status` per `goalOutcomes` (met→met, adjusted→adjusted, dropped→dropped, continued→leave open) and sets `LearningPlan.nextReviewAt`. Persists via `ObjectService::saveObject`. Registered in `Application.php`.

### New Frontend

- Manifest pages: `LearningPlanTemplates` / `LearningPlanTemplateDetail`, `LearningPlans` / `LearningPlanDetail`, `LearningPlanEvaluations` / `LearningPlanEvaluationDetail` (readOnly — appendOnly), `Signatures` / `SignatureDetail` (readOnly — appendOnly), `SignPlanModal` (custom, component `SignPlanModal`), `LearningPlanEditor` (custom, component `LearningPlanEditor`). One nav `menu` entry: "Learning Plans" (order 49).
- `src/views/SignPlanModal.vue` — loads the plan; shows version, kind, coordinator; signer selects role and method (`click-to-confirm` → assurance `basic`; `digid` → placeholder + assurance `substantial`); POSTs a Signature object on confirm. Options API, no custom store.
- `src/views/LearningPlanEditor.vue` — renders goals grouped by template's `goalDomains`/sections + supportMeasures; add/edit/remove; save back via PUT. Read-only once `active` — "Create new version" clones plan with `version+1` + `supersedesId` in lifecycle `draft`. Options API, no custom store.

### i18n

- `l10n/en.json` + `l10n/nl.json` — new keys for all new pages and the two custom views (plain-English keys, both languages).

## Capabilities

### New Capabilities

- `learning-plan`: LearningPlanTemplate, LearningPlan, LearningPlanEvaluation, Signature schemas with declarative lifecycle / notifications / calculations; LearningPlanSignatureGuard PHP exception; LearningPlanEvaluationHandler PHP exception; manifest pages + two custom Vue views; l10n en+nl.

### Out of Scope

- DigiD / eIDAS authentication handshake (openconnector / NC auth concern — see `data-exchange`).
- Sector-wide OPP analytics (mydash).
- Auto-generation of goals from assessment results (AiFeature registration).
- Samenwerkingsverband (collaboration-network) funding flow.
