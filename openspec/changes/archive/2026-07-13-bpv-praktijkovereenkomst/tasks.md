# Tasks: bpv-praktijkovereenkomst

## 1. Schemas — Praktijkopleider, BpvPlacement

- [x] 1.1 Add `Praktijkopleider` schema to `lib/Settings/scholiq_register.json`: `givenName`,
      `familyName`, `email`, `phone`, `leerbedrijfName`, `leerbedrijfKvkNumber`, `active` (bool),
      `tenant_id`. No `lifecycle` block (a plain reference/identity object, like the fleet's other
      non-lifecycled party records) — `active` alone gates whether the portal identity is usable.
      This object's own UUID is the portal identity anchor (no NC user id, ever — this schema is
      modelled A4-clean from day one per design.md).
- [x] 1.2 Add `BpvPlacement` schema: `learnerId` (NC uid) + `learnerRef` (`format: uuid`, `$ref:
      LearnerProfile` — both from day one), `programmeId` (`$ref: Programme`), `curriculumPlanId`
      (`$ref: CurriculumPlan`), `praktijkopleiderId` (`$ref: Praktijkopleider`), `schoolCoachId` (NC
      uid), `leerbedrijfName`, `leerbedrijfKvkNumber`, `periodFrom`/`periodTo`,
      `leerbedrijfVerification` (object: `provider`, `status` enum `unverified | pending | verified
      | rejected | expired`, `erkenningNumber`, `verifiedAt`, `expiresAt`, `raw`), `tenant_id`.
- [x] 1.3 Add `x-openregister-lifecycle` on `BpvPlacement`: `proposed → sbb-verification-pending →
      confirmed → active → completed | terminated`, with a `checkLeerbedrijf` transition
      (`proposed | sbb-verification-pending → sbb-verification-pending`, self-transition — the
      coordinator-triggered "Check leerbedrijf" action a `lifecycleActions`-rendered button drives,
      picked up by the handler in task 2.2) and a `confirm` transition
      (`sbb-verification-pending → confirmed`, `requires: OCA\Scholiq\Lifecycle\BpvConfirmationGuard`).
      Also added `activate`/`complete`/`terminate` transitions (not spelled out individually in
      this task line but required to actually reach the full `... → active → completed | terminated`
      lifecycle named in the spec/design).
- [x] 1.4 Register-validation test: both schemas validate against `npm run check:register`
      (`tests/validate-register.js`) — PASS.

## 2. Schemas — Praktijkovereenkomst, PokSignature + backend guards/handler

- [x] 2.1 Add `Praktijkovereenkomst` schema: `bpvPlacementId` (`$ref: BpvPlacement`),
      `periodFrom`/`periodTo`, `terms`, `version` (int), `tenant_id`, `lifecycle` (`draft →
      pending-signatures → active → completed | terminated`, `activate` transition `requires:
      OCA\Scholiq\Lifecycle\PokActivationGuard`), `x-openregister-calculations.isFullySigned`
      (count of distinct `signerRole` values across `PokSignature` rows matching this
      `subjectId`+`subjectVersion` == 3). **Flag**: verified against the actual
      `OCA\OpenRegister\Service\Calculation\CalculationEvaluator` at HEAD
      (`/home/rubenlinde/scholiq-goal/openregister-dev`) that its confirmed v1 operator vocabulary
      has NO cross-object/relation aggregation op at all (no `countRelated`, no `lookup`) — yet
      `countRelated`/`lookup`-shaped calculations already ship elsewhere in this same register file
      (e.g. `Assignment.submissionCount`, `GradeEntry.effectiveWeight`), pre-existing, unrelated to
      this change. `isFullySigned` is expressed with that same pre-existing (not provably executed)
      `countRelated` idiom, three roles ANDed together so a duplicate `signerRole` still counts as
      one distinct role — but the REAL, tested, authoritative enforcement of "all three roles
      signed" is `PokActivationGuard`, which independently queries `PokSignature` directly via
      `ObjectService` (see task 2.7/6.2) and does not depend on this calculation executing.
- [x] 2.2 Add `PokSignature` schema, `appendOnly: true`: `subjectId` (`format: uuid`, `$ref:
      Praktijkovereenkomst`), `subjectVersion` (integer), `signerId`, `signerRole` (enum `student |
      school | praktijkopleider`), `signedAt`, `assuranceLevel` (eIDAS: `none | basic | substantial
      | high`), `method`, `evidenceRef`, `tenant_id` — field-for-field shape parity with the
      `learning-plan` `Signature` schema (`lib/Settings/scholiq_register.json:6263-6353`), per
      design.md's "pattern reuse, not schema reuse" decision.
- [x] 2.3 Create `lib/Bpv/ProvidesLeerbedrijfVerification.php` (namespace `OCA\Scholiq\Bpv`,
      matching `ProvidesProctoring`/`ProvidesPlagiarismCheck`'s docblock + SPDX style): one method,
      `verify(string $kvkOrErkenningNumber): array` returning `{status, erkenningNumber, expiresAt,
      raw}`. Interface only — no concrete implementation ships in this app.
- [x] 2.4 Create `lib/Listener/BpvLeerbedrijfVerificationHandler.php` (namespace
      `OCA\Scholiq\Listener`, `implements IEventListener`, matching `DataExchangeRunHandler.php`'s
      constructor-injected `ObjectService` + `LoggerInterface` shape — an ADR-031 "external API
      integration" exception, plus `ContainerInterface` to resolve the configured provider FQCN,
      the same DI-resolution idiom `SettingsService` already uses): `handle()` filters to
      `ObjectTransitionedEvent` with `register=scholiq`, `schema=bpv-placement`,
      `to=sbb-verification-pending`; resolves the configured provider from
      `leerbedrijfVerification.provider` via DI (no configured provider, unresolvable class, or a
      resolved service not implementing the interface → no-op, the placement simply stays in
      `sbb-verification-pending`); calls `verify()` and writes the result back onto
      `BpvPlacement.leerbedrijfVerification` (`status`, `erkenningNumber`, `verifiedAt`,
      `expiresAt`, `raw`).
- [x] 2.5 Register the listener in `lib/AppInfo/Application.php`. **Note**: verified at HEAD that
      every existing listener (`DataExchangeRunHandler`, `ExcuseApprovalHandler`, etc.) is
      registered via `$context->registerEventListener(event: ObjectTransitionedEvent::class,
      listener: X::class)` inside `register()` — there is no `addServiceListener()` call anywhere
      in this app (the task text's method name does not match HEAD reality); implemented with the
      actual, real registration call instead, matching every sibling listener exactly.
- [x] 2.6 Create `lib/Lifecycle/BpvConfirmationGuard.php` (`check(array &$transitionContext):
      bool`): reads the target `BpvPlacement`'s stored `leerbedrijfVerification.status` (already
      present on `$transitionContext['object']`, the same "read the transitioning object's own
      field directly, no re-query" idiom `AssessmentPublishGuard`/`LearningPlanSignatureGuard` use
      for their own object's fields) and returns `true` only when it equals `verified`; fails closed
      on `unverified | pending | rejected | expired` or any lookup miss. **Deviation**: constructor
      takes `LoggerInterface` only, NOT an unused `ObjectService` — injecting an `ObjectService`
      that is never called would trip `hydra-gate-stub-scan`'s "unused injected dependency" rule;
      every existing guard that DOES inject `ObjectService` (`AssessmentPublishGuard`,
      `LearningPlanSignatureGuard`) only does so to query a *different* schema, never to re-fetch
      the object already in the transition context.
- [x] 2.7 Create `lib/Lifecycle/PokActivationGuard.php` (`ObjectService` + `LoggerInterface`,
      mirroring `LearningPlanSignatureGuard`'s shape): queries `PokSignature` directly for the
      current `(subjectId, subjectVersion)`, indexes by distinct `signerRole`, and returns `true`
      only when all three required roles (`student`, `school`, `praktijkopleider`) are present —
      this is the tested, authoritative enforcement (see the `isFullySigned` flag under 2.1); it
      does NOT read the `isFullySigned` calculated field (there is no confirmed way for PHP to read
      a calculation's live value mid-guard, and doing so would make the guard depend on the very
      calc-engine gap flagged in 2.1).
- [x] 2.8 Register-validation test: both new schemas validate against `npm run check:register` —
      PASS.

## 3. Schemas — WerkprocesAssessment, BpvVisitReport

- [x] 3.1 Add `WerkprocesAssessment` schema: `bpvPlacementId` (`$ref: BpvPlacement`),
      `curriculumPlanId` (`$ref: CurriculumPlan`) + `componentId` (existing generic grading hook,
      `kind: "assessment"` on the referenced `CurriculumPlan.components[]` entry — no schema change
      to `school-structure`), `kwalificatiedossierCode`, `kerntaakCode`, `werkprocesCode`,
      `werkprocesLabel`, `assessorId` (`$ref: Praktijkopleider`), `assessedAt`, `beoordeling` (enum
      `nog-niet-competent | competent`), `toelichting`, `tenant_id`, `lifecycle` (`draft →
      submitted → confirmed`).
- [x] 3.2 Create `lib/Listener/WerkprocesGradeEmitHandler.php` (`IEventListener`, filters to
      `ObjectTransitionedEvent` with `schema=werkproces-assessment`, `to=confirmed`) that emits or
      updates a `GradeEntry` for the assessment's `curriculumPlanId`/`componentId`, matching
      `GradeRollupHandler.php`'s existing cross-schema write-bridge shape; registered in
      `lib/AppInfo/Application.php` alongside the task 2.5 listener. This schema computes no final
      grade itself. **Documented mapping choice**: `GradeEntry.value` is numeric and
      `GradeEntry.sourceKind`'s enum is untouched by this change (no existing schema is modified,
      per task 3.5) — `sourceKind` is stamped `manual` (the closest existing value: a
      human-entered assessment, not auto-scored) and `beoordeling` maps onto a 0/1 pass-scale
      (`competent` → 1.0, `nog-niet-competent` → 0.0). `learnerId`/`tenant_id` are resolved via a
      `BpvPlacement` lookup and `gradeScaleId` via the governing `CurriculumPlan`'s own
      `gradeScaleId` field (both existing fields, no schema change).
- [x] 3.3 Add `BpvVisitReport` schema: `bpvPlacementId` (`$ref: BpvPlacement`), `learnerRef` (`$ref:
      LearnerProfile`), `visitDate`, `visitKind` (enum `voortgangsbezoek | tussentijds-gesprek |
      eindgesprek | incident`), `attendees` (array of `{role, name}`), `schoolCoachId` (NC uid),
      `narrative`, `actionPoints`, `tenant_id`, `lifecycle` (`draft → finalized`),
      `x-openregister-calculations.nextVisitDue` (`dateAdd(visitDate, 60, days)` — verified against
      `CalculationEvaluator` at HEAD that `dateAdd` IS a real, implemented v1 operator, unlike the
      cross-object `countRelated` flagged under 2.1; same derived-field idiom as
      `ActionItem.isOverdue`, no TimedJob).
- [x] 3.4 Add `x-openregister-notifications.visitDueReminder` on `BpvVisitReport`: `trigger.type:
      scheduled`, keyed to `nextVisitDue` (`olderThan PT0S`, the same idiom `GradeNotification`'s
      own scheduled reminder uses), `recipients: [{kind: field, field: schoolCoachId}]`, `nl`/`en`
      subject. Targets `schoolCoachId` only (not the praktijkopleider — no NC-reachable channel;
      documented gap in design.md).
- [x] 3.5 Bump `lib/Settings/scholiq_register.json`'s `info.version` `0.3.1 → 0.4.0` (six new
      schemas at `0.1.0`; no existing schema modified).
- [x] 3.6 Register-validation test: all six new schemas + the version bump validate against `npm
      run check:register` — PASS.

## 4. Backend — Praktijkopleider portal audience

- [x] 4.1 In `lib/Portal/PortalContributionProvider.php`, added `'praktijkopleider'` to
      `getAudiences()`'s return array and a new `if` branch in `getContribution()` dispatching to
      `praktijkopleiderContribution()`, matching the existing dispatch shape. Fail-closed `null` for
      any other audience unchanged.
- [x] 4.2 Implemented `praktijkopleiderContribution()` per design.md's worked example: one
      collection `poBpvPlacements` (`register: scholiq`, `schema: bpv-placement`, `scopeField:
      praktijkopleiderId`, `scopeClaim: praktijkopleiderId` — direct match, `minTrust: 'low'`),
      `fields` whitelist excluding `schoolCoachId` and `leerbedrijfVerification.raw`.
- [x] 4.3 Added the `createWerkprocesAssessment` action exactly per the worked example.
- [x] 4.4 Added the `signPraktijkovereenkomst` action exactly per the worked example.
- [x] 4.5 Unit test additions to `tests/Unit/Portal/PortalContributionProviderTest.php`:
      `testPraktijkopleiderManifestShape` (direct-scope match, `schoolCoachId`/
      `leerbedrijfVerification` excluded from `fields`), `testPraktijkopleiderActionsAreDirectScope
      StampedAndWhitelisted` (both actions' `scopeField`/`scopeClaim`/`minTrust`/exact field
      whitelist, and that no staff/grade field like `lifecycle`/`signedAt` is exposed — server-side
      stamping itself is enforced by portaliq's writer, outside this repo, so the test pins the
      declarative contract portaliq reads, the same limit the existing `student`/`parent` tests
      already accept), `testAudienceContract`/`testGetContributionReturnsNullForUnservedSubjects`
      updated for the third audience, and `testManifestMatchesRegisterSchemas` extended to assert
      the new BPV refs exist in the register and to run all three audiences through the
      register-drift pin.

## 5. Frontend

- [x] 5.1 Added `src/manifest.json` index + detail pages for `BpvPlacement`, `Praktijkopleider`,
      `Praktijkovereenkomst`, `WerkprocesAssessment`, `BpvVisitReport` (routes under `/bpv/...`,
      plus a new "BPV" menu group). `lifecycleActions: { field: "lifecycle" }` on all four
      lifecycled schemas (`Praktijkopleider` has none — it has no lifecycle field by design, task
      1.1). `BpvPlacementDetail`'s Data widget carries no `fields` whitelist, so it renders every
      schema property including `leerbedrijfVerification` (whose `.status` is thus visible inline)
      — mirroring the fleet's existing unfiltered-`data`-widget convention (e.g. `LearningPlanDetail`).
      `BpvPlacementDetail` additionally shows related Praktijkovereenkomst/WerkprocesAssessment/
      BpvVisitReport object-lists; `PraktijkovereenkomstDetail` shows its PokSignatures.
- [x] 5.2 Added the `SignPokModal` manifest entry (`type: "custom"`, route
      `/bpv/praktijkovereenkomsten/:pokId/sign`, `component: "CnSignatureCapture"`) mirroring the
      existing `SignPlanModal` entry (verified at HEAD: `src/manifest.json:5254-5261`, exact route
      match) — mounts the shared `CnSignatureCapture` component for the student/school signing legs.
      No new Vue file.
- [x] 5.3 Manifest validation: `npm run check:manifest` PASS (Ajv, 0 errors, 110 pages).

## 6. Tests

- [x] 6.1 Unit test `tests/Unit/Lifecycle/BpvConfirmationGuardTest.php`: `verified` status allows
      `confirm`; each of `unverified | pending | rejected | expired` blocks it; missing/non-array
      `leerbedrijfVerification` fails closed. 4 tests.
- [x] 6.2 Unit test `tests/Unit/Lifecycle/PokActivationGuardTest.php`: all three roles signed allows
      `activate`; 0/1/2-of-3 roles blocks it; a duplicate role stays blocked; a missing object id
      fails closed without querying. 4 tests — this is the class doing the REAL enforcement (see
      the 2.1 flag on `isFullySigned`).
- [x] 6.3 Unit test `tests/Unit/Listener/BpvLeerbedrijfVerificationHandlerTest.php`: configured
      `verified` result writes back all four fields; unconfigured provider (`null`) is a no-op,
      never calls the container; an unresolvable class (container throws) is a no-op; a resolved
      service NOT implementing the interface is a no-op; a `rejected` result is written back without
      touching `lifecycle`; unrelated events are ignored. 6 tests.
- [x] 6.4 Unit test `tests/Unit/Listener/WerkprocesGradeEmitHandlerTest.php`: `competent` creates a
      `GradeEntry` with `value: 1.0`; `nog-niet-competent` maps to `0.0`; an existing `GradeEntry`
      for the same learner/plan/component is updated in place (same `id`, not duplicated); an
      unresolvable `BpvPlacement` is a no-op; unrelated events are ignored. 5 tests.
- [x] 6.5 Coverage on the append-only + all-three-distinct-roles semantic is via
      `PokActivationGuardTest::testDuplicateRoleStillCountsAsOneDistinctRole` — the class that
      actually enforces it. **Flag**: the JSON `isFullySigned` calculation itself is NOT
      independently unit-testable from this repo (its execution depends on OpenRegister's
      calculation engine, a separate app/repo — see the 2.1 flag); the guard's equivalent, tested
      logic is the real gate.
- [ ] 6.6 Minimum 75% coverage on `BpvConfirmationGuard`, `PokActivationGuard`,
      `BpvLeerbedrijfVerificationHandler`, `WerkprocesGradeEmitHandler` per ADR-009 — **NOT
      independently verified**: `phpunit -c phpunit-unit.xml` in this environment reports "No code
      coverage driver available" (no xdebug/pcov in the `php:8.3-cli` image used), so the percentage
      cannot actually be measured here. By inspection every branch of all four classes has a
      dedicated test, which is very likely >75%, but this is an honest gap, not a verified pass.
- [x] 6.7 Ran `npm run check:register` and `npm run check:manifest` — both PASS (0 errors) for the
      six new schemas, the version bump, and the manifest additions.

## 7. Docs + traceability

- [x] 7.1 Added `@spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-...`
      docblock tags (linked to the specific `bpv` spec requirement rather than a task-N anchor, per
      the fleet's `@spec` convention observed on every sibling class read at HEAD — e.g.
      `GradeRollupHandler`'s class-level `@spec` points at a spec requirement anchor, not a
      tasks.md line) to `ProvidesLeerbedrijfVerification`, `BpvLeerbedrijfVerificationHandler`,
      `WerkprocesGradeEmitHandler`, `BpvConfirmationGuard`, `PokActivationGuard`, and the
      `PortalContributionProvider::praktijkopleiderContribution()` addition + its `getAudiences()`/
      `getContribution()` touch-points. Also verified via `hydra-gates` gate-16 (spec-coverage):
      PASS.
- [x] 7.2 SPDX headers (`@license EUPL-1.2` + `@copyright`) on every new PHP file — verified via
      `hydra-gates` gate-1 (spdx-headers): PASS.
- [x] 7.3 i18n: English source strings used throughout the five new manifest pages, `SignPokModal`,
      and the `visitDueReminder` notification (`nl`/`en` subject pair, matching the existing
      dialect). **Gap**: no separate Dutch l10n catalogue entries were added for the new page
      titles/menu labels/column labels (could not locate an app-level `l10n/nl.json`-style catalogue
      distinct from the inline `nl`/`en` notification-subject convention within the time budget for
      this L change) — flagged, not silently dropped; per apply-common's priority order
      (schemas → provider/handler → POK signing → portal surface) this sits below all of those.
- [ ] 7.4 Add `docs/features/bpv.md` with Playwright MCP screenshots of the `BpvPlacement` detail
      page, the POK signing flow via `SignPokModal`, and a `WerkprocesAssessment` list, per ADR-010
      — **NOT done**: this apply pass has no running Scholiq instance / browser session available;
      screenshotting requires a live deploy, out of scope for a static code-apply pass. Deferred.
- [ ] 7.5 `composer check:strict` — **partially run**: `lint` (php -l) and `phpcs --standard=phpcs.xml`
      were run on every new/touched PHP file and are clean (0 errors after fixing 2 files' equals-
      sign alignment + one disallowed inline-`if` + missing class-level `@spec` PHPDoc tags flagged
      by phpcs itself — pre-existing `Application.php` `@spec`-tag warnings on its untouched
      `register()`/`boot()` methods were left as-is, not introduced by this change). `phpmd`/
      `psalm`/`phpstan` were NOT run (the full `check:strict` composite requires a working
      composer install inside the throwaway `php:8.3-cli` container, which this session did not set
      up for those three tools within the time budget) — flagged, not silently skipped.
- [x] 7.6 Ran `openspec validate bpv-praktijkovereenkomst --strict` — "Change 'bpv-praktijkovereenkomst'
      is valid".
