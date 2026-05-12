# Tasks — Learning Plan (capability)

> Scope: 4 new schemas (LearningPlanTemplate, LearningPlan, LearningPlanEvaluation, Signature), 2 PHP exceptions (LearningPlanSignatureGuard + LearningPlanEvaluationHandler), manifest pages + 2 custom Vue views, l10n (en+nl). Count: 25 → 29.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [x] Add `LearningPlanTemplate` schema per design §2.1 — name, kind enum, description, sections array, goalDomains string[], requiredSignerRoles string[], defaultReviewCadenceMonths int, tenant_id; lifecycle draft → active → archived (bidirectional reactivate). Required: name, kind, tenant_id.
- [x] Add `LearningPlan` schema per design §2.2 — learnerId, kind enum, templateId, cohortId, courseId, coordinatorId, goals array, supportMeasures array, period, reviewCadenceMonths, nextReviewAt, version, supersedesId, tenant_id; lifecycle draft → active → under-evaluation → closed | superseded; activate requires LearningPlanSignatureGuard; x-openregister-relations (learner/template/cohort/course/supersedes); x-openregister-calculations (goalsMetCount/goalsTotalCount/nextReviewDue/isFullySigned); x-openregister-notifications (quarterlyReviewReminder via calculatedChange + signatureRequested on lifecycle.enter.draft). Required: learnerId, kind, coordinatorId, tenant_id.
- [x] Add `LearningPlanEvaluation` schema per design §2.3 — appendOnly:true; learningPlanId, evaluatedAt, evaluatedBy, goalOutcomes array, narrative, attendeeIds, nextReviewAt, tenant_id; lifecycle draft → recorded; x-openregister-relations (learningPlan).
- [x] Add `Signature` schema per design §2.4 — appendOnly:true; subjectKind, subjectId, subjectVersion, signerId, signerRole enum, signedAt, assuranceLevel enum, method, evidenceRef, tenant_id; no lifecycle; x-openregister-relations (subject → LearningPlan).
- [x] Validate JSON (`python3 -c 'import json; json.load(open(...))'`); no duplicate slugs; schema count 25 → 29. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [x] Create `lib/Lifecycle/LearningPlanSignatureGuard.php` — single `check(array &$transitionContext): bool` method; fetches template's requiredSignerRoles; fetches Signatures for plan+version; checks each required role has assurance ≥ minimum (substantial for parent/opp, basic for others); on pass calls TransitionEngine::transition($supersedesId, 'supersede').
- [x] Create `lib/Listener/LearningPlanEvaluationHandler.php` — IEventListener for ObjectTransitionedEvent; filters to learning-plan-evaluation → recorded; updates parent LearningPlan goals statuses and nextReviewAt; persists via ObjectService::saveObject.
- [x] Register `LearningPlanEvaluationHandler` in `Application.php` for `ObjectTransitionedEvent`.
- [x] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [x] Add LearningPlanTemplates / LearningPlanTemplateDetail, LearningPlans / LearningPlanDetail, LearningPlanEvaluations / LearningPlanEvaluationDetail (readOnly), Signatures / SignatureDetail (readOnly) pages.
- [x] Add SignPlanModal (custom, component=SignPlanModal) and LearningPlanEditor (custom, component=LearningPlanEditor) pages.
- [x] Add "Learning Plans" nav menu entry (order=49, route=LearningPlans).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.

## Phase 4: Frontend Vue + main.js

- [x] Create `src/views/SignPlanModal.vue` — plan version display; role+method selector; DigiD placeholder; POST Signature. Options API + direct fetch; no Pinia module.
- [x] Create `src/views/LearningPlanEditor.vue` — goals grouped by template goalDomains; add/edit/remove goals and supportMeasures; save via PUT; read-only when active with "Create new version" button. Options API + direct fetch; no Pinia module.
- [x] Register both in `src/main.js` via customComponents.
- [ ] `npm run lint` 0 errors; `npm run stylelint` clean for new files; `npm run build` succeeds.

## Phase 5: i18n

- [x] Add new keys to `l10n/en.json` + `l10n/nl.json` for all new pages and the two custom views.

## Phase 6: Spec-validation gate

- [ ] `node tests/validate-json-strict.js` PASS.
- [ ] `node tests/validate-register.js` PASS (slug uniqueness, lifecycle requires → PHP class exists).
- [x] `node tests/validate-manifest.js` PASS (0 Ajv errors). CONFIRMED.
