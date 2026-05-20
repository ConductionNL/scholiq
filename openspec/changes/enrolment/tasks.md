# Tasks — Enrolment (Extended)

> **Scope**: Two new schemas (`OnboardingTemplate`, `EnrolmentRule`) + four optional fields on the existing `Enrolment` schema. Three PHP files as ADR-031 legitimate exceptions (`PrerequisiteCheckGuard`, `StudielinkEnrolmentHandler`, `OnboardingTemplateApplicator`). One new custom Vue component (`TeamBulkEnrolModal`). Two new manifest page pairs (EnrolmentRules, OnboardingTemplates). No `EnrolmentRuleController`, no `OnboardingTemplateController`, no `EnrolmentRuleEvaluationService`, no `StudielinkSyncJob`.
>
> **In-fleet references**: Enrolment schema (Phase 1) for lifecycle + calculations + notifications shape. Cohort schema (school-structure) for the x-openregister-relations pattern. `decidesk/lib/Settings/decidesk_register.json` ActionItem schema for calculations shape. `XapiCompletionHandler` (Phase 1) for the audit-event handler pattern.

---

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add `OnboardingTemplate` schema block per design §1.1 — lifecycle (`draft → active → archived`), calculations (`milestoneCount`, `totalCourseSlots`), relation to `EnrolmentRule` (one-to-many). Validate via OR schema-validation endpoint. Confirm milestone items accept day values `[1, 30, 60, 90]` only.
- [ ] Add `EnrolmentRule` schema block per design §1.2 — lifecycle (`draft → active → archived`), triggerEvent enum (`hire`, `studielink-intake`, `certificate-expiry`, `cohort-activate`), audienceType enum, relation to `OnboardingTemplate` (many-to-one). Validate via OR schema-validation endpoint.
- [ ] Patch existing `Enrolment` schema: add 4 optional/nullable fields (`prerequisitesMet`, `onboardingTemplateId`, `onboardingMilestoneDay`, `lmsProvisionedAt`) per design §1.3. Confirm no existing seed data is broken.
- [ ] Patch `Enrolment.x-openregister-lifecycle.transitions.activate` to add `"requires": "OCA\\Scholiq\\Lifecycle\\PrerequisiteCheckGuard"` per design §1.3. Confirm the OR schema-validation endpoint accepts the guard reference format.
- [ ] Add `onboardingTemplate` relation to `Enrolment.x-openregister-relations` per design §1.3.
- [ ] Write a JSON-validation test asserting all three schemas parse against OR's schema-extension contract (lifecycle + calculations + relations resolve correctly against sample objects).

---

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/PrerequisiteCheckGuard.php` per design §2: queries `ObjectService::findAll(['register'=>'scholiq','schema'=>'Enrolment','filters'=>['learnerId'=>$learnerId,'courseId'=>{'$in'=>$prerequisiteCourseIds},'lifecycle'=>'completed']])` to build the set of completed prerequisites; compares against `Course.prerequisiteCourseIds`; returns `false` with structured payload `{blocked: true, missing: [{courseId, title}]}` when any prerequisite is missing; returns `true` when all are met or course has none. Annotate with `@spec openspec/changes/enrolment/tasks.md#phase-2`. Unit test: assert guard blocks when prerequisite is not completed AND passes when it is.

- [ ] Create `lib/Lifecycle/StudielinkEnrolmentHandler.php` per design §2: registers as listener on `openconnector.studielink.intake.received` via OR's `IEventDispatcher` extension point (ADR-022). Method signature: `handle(array $payload): void`. Implementation: (1) upsert `LearnerProfile` by student identifier (idempotent — check existing via `ObjectService::findAll` first); (2) check for existing active Enrolment (skip if duplicate); (3) create Enrolment with `source=studielink`, `mandatory=true`, `lifecycle=pending`; (4) dispatch `lms.account.provision` job via OR's background-job dispatcher, setting `lmsProvisionedAt` on completion. Annotate with `@spec openspec/changes/enrolment/tasks.md#phase-2`. Integration test: publish a sample intake event → assert LearnerProfile created + Enrolment created + `lmsProvisionedAt` set within 60 s.

- [ ] Create `lib/Lifecycle/OnboardingTemplateApplicator.php` per design §2: registers as listener on `learner.profile.created`. For each active `EnrolmentRule` matching `triggerEvent=hire` and `audienceType+audienceValue` matching the new LearnerProfile's role/department: resolve the linked `OnboardingTemplate` milestones; create one Enrolment per milestone per courseId with `source=system`, `onboardingTemplateId`, `onboardingMilestoneDay`, `dueDate = hireDate + milestoneDay`, `mandatory = milestone.mandatory`. Skip milestones where an active/completed Enrolment already exists. Annotate with `@spec openspec/changes/enrolment/tasks.md#phase-2`. Integration test: create LearnerProfile with roleSlug='medewerker' → assert N Enrolment objects created with correct milestone days + dueDate values.

- [ ] Register `StudielinkEnrolmentHandler` and `OnboardingTemplateApplicator` in `lib/AppInfo/Application.php` as OR audit-event listeners (via OR's `IEventDispatcher::addListener(...)` extension point per ADR-022). Confirm event type strings match OR's published event vocabulary.

---

## Phase 3: Frontend — manifest extension and TeamBulkEnrolModal

- [ ] Extend `src/manifest.json` with the four new manifest pages per design §3.1: `EnrolmentRules` (index, schema=EnrolmentRule), `EnrolmentRuleDetail` (detail), `OnboardingTemplates` (index, schema=OnboardingTemplate), `OnboardingTemplateDetail` (detail). Re-run `npm run check:manifest` — must pass. Verify that CnAppRoot renders the EnrolmentRule index with triggerEvent, audienceType, and lifecycle columns.

- [ ] Extend `src/manifest.json` with `TeamBulkEnrol` custom page per design §3.1. Re-run `npm run check:manifest`.

- [ ] Create `src/views/TeamBulkEnrolModal.vue` per design §3.2 — 4 steps: (1) audience picker (NC OCS groups API + multi-select user list + CSV upload), (2) course + mandatory + dueDate config, (3) preview summary, (4) submit + progress bar. Step 4 POSTs to `POST /api/openregister/scholiq/Enrolment/batch`; polls `GET /api/openregister/scholiq/Enrolment?bulkJobId=<uuid>` every 2 s for progress. Renders `{enrolled: X, skipped: Y, failed: Z}` summary when polling completes. Playwright test: log in as manager → open TeamBulkEnrolModal → select group + course + dueDate → submit → assert N Enrolment objects exist in OR with `source=manager` and matching `bulkJobId`.

- [ ] Register `TeamBulkEnrolModal` via `customComponents` on `CnAppRoot` in `src/main.js`.

- [ ] **Do NOT** create `src/router/index.js` entries, `src/stores/enrolmentRuleStore.js`, `src/stores/onboardingTemplateStore.js`, or bespoke list/edit Vue files for EnrolmentRule or OnboardingTemplate — `CnAppRoot` index/detail renderers cover them.

---

## Phase 4: Seed data

- [ ] Add 3 `OnboardingTemplate` seed objects to `lib/Settings/scholiq_register.json` per design §4.1 (Medewerker Standaard, ICT-medewerker, Teamleider). Use placeholder Course UUIDs that the course-management spec's seeds provide. Validate seed format against the schema.
- [ ] Add 4 `EnrolmentRule` seed objects per design §4.2 (hire/medewerker, Studielink Bedrijfskunde, certificate-expiry AVG, cohort-activate). Validate seed format.
- [ ] Confirm existing Phase 1 Enrolment seeds remain valid after the 4-field schema patch (no required fields added — backward-compat guaranteed).

---

## Phase 5: Audit-event vocabulary — none

- [ ] **Do NOT** add `enrolment.prerequisite.blocked`, `enrolment.lms.provisioned`, `enrolment.studielink.unmatched-programme`, or `enrolment.lms.provision.failed` to a Scholiq-side `AuditEventTypes::KNOWN` enum. OR's lifecycle + audit engine emits these automatically based on schema declarations and handler return values. ADR-022 + ADR-008 prohibit a parallel app-side audit-event vocabulary.

---

## Phase 6: Quality gate

- [ ] Run `composer check:strict`; fix all violations. Confirm `PrerequisiteCheckGuard`, `StudielinkEnrolmentHandler`, and `OnboardingTemplateApplicator` each have `@spec` PHPDoc tags per ADR-003.
- [ ] Run `npm run lint`; fix all ESLint violations in `TeamBulkEnrolModal.vue`.
- [ ] Run `npm run check:manifest`; must pass with all 5 new pages declared.
- [ ] Unit test (PHPUnit): `PrerequisiteCheckGuard` — assert it blocks when prerequisite Enrolment is missing AND allows when present AND passes unconditionally when course has no prerequisites.
- [ ] Unit test (PHPUnit): `OnboardingTemplateApplicator` — assert milestone-day Enrolments are created with correct `dueDate` values; assert department-scoped template takes precedence over generic template; assert no Enrolments created when no matching rule exists.
- [ ] Integration test (PHPUnit + OR): publish a `openconnector.studielink.intake.received` event → assert `LearnerProfile` created + `Enrolment` created with source=studielink → assert `lmsProvisionedAt` is set within the 60 s SLA window in the test environment.
- [ ] Integration test (PHPUnit + OR): activate an `EnrolmentRule` with triggerEvent=certificate-expiry → emit a simulated `credential.expiry.detected` event → assert renewal Enrolment created with correct dueDate.
- [ ] Playwright E2E: compliance officer workflow — log in, open EnrolmentRules, create a rule with triggerEvent=hire, activate it; create a new LearnerProfile matching the rule; assert milestone Enrolments appear in the Enrolments index within 5 s.
- [ ] Playwright E2E: prerequisite blocking — attempt to activate an Enrolment for a course with an unmet prerequisite; assert the UI displays the blocked-prerequisites message with the missing course title.
