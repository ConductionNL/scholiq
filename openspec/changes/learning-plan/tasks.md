# Tasks — Individual Learning Plan

> Scope: four schema additions (`LearningPlanTemplate`, `LearningPlan`, `LearningPlanEvaluation`, `Signature`) on `lib/Settings/scholiq_register.json`, two PHP lifecycle guard files, a manifest extension with four new pages, and one custom Vue component (`SignPlanModal.vue`).

---

## Phase 0: Deduplication Check

- [ ] Scan `openspec/specs/` and `openregister/lib/Service/` for any existing `LearningPlan`, `OPP`, `Signature`, or `LearningPlanTemplate` schemas. Document findings in a comment on this task. Expected finding: no overlap — these are net-new schemas unique to the scholiq-learning domain.
- [ ] Confirm `Signature` schema slug `'learning-plan-signature'` does not conflict with any existing OR core schema. If overlap is found, open a deduplication issue before proceeding.

---

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add **`LearningPlanTemplate`** schema per design §1.1 — lifecycle (`draft → published → archived`), properties (`slug`, `name`, `kind`, `sectorCode`, `goalDomains`, `sections`, `requiredSignerRoles`, `requiredAssuranceLevel`, `active`). Reference: decidesk's `Meeting` schema for lifecycle pattern.
- [ ] Add **`LearningPlan`** schema per design §1.2 — lifecycle (`draft → active → under-evaluation → closed | superseded`), calculations (`goalsMetCount`, `nextReviewDue`, `isFullySigned`), notifications (`quarterlyReviewReminder` with scheduledOffset + idempotencyKey, `signatureRequested` with lifecycleTransition trigger + idempotencyKey), relations (learner, template, cohort, coordinator), widgets (`planStatusSummary`). **Do NOT** write a PHP service class for any of these behaviours.
- [ ] Add **`LearningPlanEvaluation`** schema per design §1.3 — lifecycle (`draft → finalised`), relations (plan, evaluator).
- [ ] Add **`Signature`** schema per design §1.4 — `appendOnly: true`, lifecycle (`pending → signed | rejected`), relations (plan, signer). **Do NOT** add a parallel link table; use `x-openregister-relations`.
- [ ] Write a JSON-schema validation test that asserts all four schemas parse against OR's schema-extension contract (lifecycle, calculations, notifications, relations, widgets blocks). Assert slug uniqueness across the register.
- [ ] **Do NOT** add a `scholiq-audit-event` schema, a `LearningPlanService`, a `NotificationService`, or any PHP TimedJob for review reminders (ADR-031 + ADR-022 anti-patterns).

---

## Phase 2: Seed data in `lib/Settings/scholiq_register.json`

- [ ] Add seed objects under `components.objects[]` using the `@self` envelope per ADR-001 §"Seed data":
  - 2 `LearningPlanTemplate` objects (`opp-po-v1`, `opp-vo-v1`) per design §6.
  - 3 `LearningPlan` objects (Emma de Vries OPP, Yusuf El-Amin OPP v2 draft, Sophie van den Berg PDP) per design §6.
  - 2 `LearningPlanEvaluation` objects (eval-emma-q1-2026, eval-sophie-mid-2026) per design §6.
  - 3 `Signature` objects (Emma's coordinator, parent, learner signatures) per design §6.
- [ ] Verify idempotency: re-running `ConfigurationService::importFromApp` with `force:false` MUST skip objects matched by slug. Write a test asserting no duplicates on second import.
- [ ] Confirm all `evidenceRefs` arrays are empty in seed data (evidence linking via `AssessmentResult` / `GradeEntry` / `AttendanceRecord` requires those specs to have landed first).

---

## Phase 3: PHP — ADR-031 legitimate exceptions only

- [ ] Create **`lib/Lifecycle/LearningPlanVersionGuard.php`**: single `check($transitionContext)` method that:
  - Reads `requiredSignerRoles` from the linked `LearningPlanTemplate` (via OR's ObjectService).
  - Queries OR for `Signature` objects with `planId=@planId`, `planVersion=@planVersion`, `lifecycle=signed`.
  - Returns `Reject('All required signers must sign before the plan can become active')` if any required role has no signed Signature.
  - Returns `Accept()` otherwise.
  - No state; no dependencies beyond `OCP\OpenRegister\ObjectService`.
  - Unit tests: (a) all roles signed → `Accept()`; (b) missing parent Signature → `Reject()`; (c) Signature in lifecycle='pending' not counted.
- [ ] Create **`lib/Lifecycle/SignatureAssuranceGuard.php`**: single `check($transitionContext)` method that:
  - Reads `requiredAssuranceLevel` from the linked `LearningPlanTemplate`.
  - Compares the `assuranceLevel` on the transition payload against the minimum.
  - Level hierarchy: `none < low < substantial < high`.
  - Returns `Reject('Signature assurance level is insufficient')` when below minimum; `Accept()` otherwise.
  - Unit tests: (a) same level → `Accept()`; (b) higher level → `Accept()`; (c) lower level → `Reject()`; (d) template has `requiredAssuranceLevel='none'` → any level accepted.
- [ ] Register both guards in `lib/AppInfo/Application.php` (DI container) so OR's lifecycle engine can resolve them by FQCN.
- [ ] Register routes in `appinfo/routes.php` — only if any custom PHP controller is added (none expected; OR handles all CRUD via its REST API).
- [ ] **Do NOT** create `lib/Service/LearningPlanService.php`, `lib/Service/SignatureService.php`, `lib/Service/NotificationService.php`, or `lib/BackgroundJob/ReviewReminderJob.php`.

---

## Phase 4: Frontend — manifest extension and SignPlanModal

- [ ] Extend **`src/manifest.json`** with the four new pages per design §3.1:
  - `LearningPlanIndex` (`/learning-plans`, type: `index`)
  - `LearningPlanDetail` (`/learning-plans/:id`, type: `detail`, tabs: `[details, evaluations, versionHistory, signatures, auditTrail]`)
  - `LearningPlanEvaluationIndex` (`/learning-plans/:planId/evaluations`, type: `index`)
  - `LearningPlanEvaluationDetail` (`/learning-plans/:planId/evaluations/:id`, type: `detail`)
  - `SignPlan` (`/learning-plans/:id/sign`, type: `custom`, component: `SignPlanModal`)
  - Re-run `npm run check:manifest` after each addition; must pass.
- [ ] Create **`src/views/SignPlanModal.vue`** per design §3.2:
  - Summary section: displays plan kind, learner name, version, period.
  - Co-signer checklist: lists each `requiredSignerRole` with current Signature status (signed / pending / absent).
  - "Ondertekenen" button: (1) `POST /api/openregister/scholiq/Signature` with `{ planId, planVersion, signerRole, signerId, assuranceLevel }`; (2) `PATCH .../transition/sign`; (3) surfaces 422 guard rejection message inline.
  - DigiD branch: when `assuranceLevel >= 'substantial'`, renders external-link button pointing to openconnector DigiD redirect; picks up `externalRefId` from query param on return.
  - Register via `customComponents` on `CnAppRoot` in `src/main.js`.
  - Playwright test: load SignPlanModal with a draft plan → click Ondertekenen → assert Signature with lifecycle='signed' exists in OR → assert plan shows updated co-signer checklist.
- [ ] **Do NOT** create `src/views/LearningPlanListView.vue`, `LearningPlanDetailView.vue`, `EvaluationListView.vue`, `EvaluationDetailView.vue`, or `src/stores/learningPlanStore.js`. `CnAppRoot` index/detail renderers and `createObjectStore('LearningPlan')` cover these.
- [ ] Add i18n keys to `l10n/nl.js` and `l10n/en.js` for all manifest `title` and `subject` strings:
  - `scholiq.page.learningplan.index.title`
  - `scholiq.page.learningplan.detail.title`
  - `scholiq.page.learningplanevaluation.index.title`
  - `scholiq.page.learningplanevaluation.detail.title`
  - `scholiq.page.sign.title`
  - `scholiq.learningplan.review.due`
  - `scholiq.learningplan.signature.requested`
  - `scholiq.widget.learningplan.status`

---

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] **Integration test (PHPUnit + OR)**: seed a `LearningPlanTemplate` + `LearningPlan` (version 1, draft) + 3 `Signature` records (all signed); fire `PATCH .../transition/submit` → assert plan transitions to `lifecycle=active` + `learningplan.activated` audit entry emitted.
- [ ] **Integration test**: missing signer → fire `PATCH .../transition/submit` with only 2 of 3 required Signatures → assert HTTP 422 with guard rejection message + plan remains `draft`.
- [ ] **Integration test**: `SignatureAssuranceGuard` — attempt `PATCH .../transition/sign` with `assuranceLevel='low'` on a template requiring `'substantial'` → assert HTTP 422; attempt with `'substantial'` → assert `lifecycle=signed`.
- [ ] **Integration test**: `goalsMetCount` calculation — seed plan with 5 goals (3 status='met', 2 status='active') → `GET /api/openregister/scholiq/LearningPlan/<id>` → assert `goalsMetCount=3` in the response body.
- [ ] **Integration test**: `quarterlyReviewReminder` notification — advance OR's clock to `nextReviewDate - 7 days`; assert notification dispatched to coordinatorId with the correct idempotency key; advance clock again (same day) → assert no duplicate notification.
- [ ] **Integration test**: `appendOnly` on Signature — attempt `PUT /api/openregister/scholiq/Signature/<id>` on a signed record → assert HTTP 405.
- [ ] **Integration test**: version chain — create plan v1 (active); supersede → create plan v2 (draft); assert v1 transitions to `lifecycle=superseded` and v1 Signature records are unmodified.
- [ ] **Playwright end-to-end**: coordinator workflow → create LearningPlan from template → submit for signing → parent signs via SignPlanModal → coordinator signs → submit again → assert plan lifecycle=active + all three signatures visible on versionHistory tab.
- [ ] **Playwright end-to-end**: teacher view → open LearningPlan index filtered by cohortId → assert active plan goals visible + edit button absent (read-only RBAC).

---

## Phase 6: Documentation

- [ ] Add `docs/features/learning-plan.md` with browser screenshots (Playwright MCP) of: plan index view, plan detail with version history tab, SignPlanModal, evaluation form, and the quarterly reminder notification.
