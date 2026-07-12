# Tasks: bpv-praktijkovereenkomst

## 1. Schemas — Praktijkopleider, BpvPlacement

- [ ] 1.1 Add `Praktijkopleider` schema to `lib/Settings/scholiq_register.json`: `givenName`,
      `familyName`, `email`, `phone`, `leerbedrijfName`, `leerbedrijfKvkNumber`, `active` (bool),
      `tenant_id`. No `lifecycle` block (a plain reference/identity object, like the fleet's other
      non-lifecycled party records) — `active` alone gates whether the portal identity is usable.
      This object's own UUID is the portal identity anchor (no NC user id, ever — this schema is
      modelled A4-clean from day one per design.md).
- [ ] 1.2 Add `BpvPlacement` schema: `learnerId` (NC uid) + `learnerRef` (`format: uuid`, `$ref:
      LearnerProfile` — both from day one), `programmeId` (`$ref: Programme`), `curriculumPlanId`
      (`$ref: CurriculumPlan`), `praktijkopleiderId` (`$ref: Praktijkopleider`), `schoolCoachId` (NC
      uid), `leerbedrijfName`, `leerbedrijfKvkNumber`, `periodFrom`/`periodTo`,
      `leerbedrijfVerification` (object: `provider`, `status` enum `unverified | pending | verified
      | rejected | expired`, `erkenningNumber`, `verifiedAt`, `expiresAt`, `raw`), `tenant_id`.
- [ ] 1.3 Add `x-openregister-lifecycle` on `BpvPlacement`: `proposed → sbb-verification-pending →
      confirmed → active → completed | terminated`, with a `checkLeerbedrijf` transition
      (`proposed | sbb-verification-pending → sbb-verification-pending`, self-transition — the
      coordinator-triggered "Check leerbedrijf" action a `lifecycleActions`-rendered button drives,
      picked up by the handler in task 2.2) and a `confirm` transition
      (`sbb-verification-pending → confirmed`, `requires: OCA\Scholiq\Lifecycle\BpvConfirmationGuard`).
- [ ] 1.4 Register-validation test: both schemas validate against `npm run check:register`
      (`tests/validate-register.js`).

## 2. Schemas — Praktijkovereenkomst, PokSignature + backend guards/handler

- [ ] 2.1 Add `Praktijkovereenkomst` schema: `bpvPlacementId` (`$ref: BpvPlacement`),
      `periodFrom`/`periodTo`, `terms`, `version` (int), `tenant_id`, `lifecycle` (`draft →
      pending-signatures → active → completed | terminated`, `activate` transition `requires:
      OCA\Scholiq\Lifecycle\PokActivationGuard`), `x-openregister-calculations.isFullySigned`
      (count of distinct `signerRole` values across `PokSignature` rows matching this
      `subjectId`+`subjectVersion` == 3).
- [ ] 2.2 Add `PokSignature` schema, `appendOnly: true`: `subjectId` (`format: uuid`, `$ref:
      Praktijkovereenkomst`), `subjectVersion` (integer), `signerId`, `signerRole` (enum `student |
      school | praktijkopleider`), `signedAt`, `assuranceLevel` (eIDAS: `none | basic | substantial
      | high`), `method`, `evidenceRef`, `tenant_id` — field-for-field shape parity with the
      `learning-plan` `Signature` schema (`lib/Settings/scholiq_register.json:6263-6353`), per
      design.md's "pattern reuse, not schema reuse" decision.
- [ ] 2.3 Create `lib/Bpv/ProvidesLeerbedrijfVerification.php` (namespace `OCA\Scholiq\Bpv`,
      matching `ProvidesProctoring`/`ProvidesPlagiarismCheck`'s docblock + SPDX style): one method,
      `verify(string $kvkOrErkenningNumber): array` returning `{status, erkenningNumber, expiresAt,
      raw}`. Interface only — no concrete implementation ships in this app.
- [ ] 2.4 Create `lib/Listener/BpvLeerbedrijfVerificationHandler.php` (namespace
      `OCA\Scholiq\Listener`, `implements IEventListener`, matching `DataExchangeRunHandler.php`'s
      constructor-injected `ObjectService` + `LoggerInterface` shape — an ADR-031 "external API
      integration" exception): `handle()` filters to `ObjectTransitionedEvent` with
      `register=scholiq`, `schema=bpv-placement`, `to=sbb-verification-pending`; resolves the
      configured provider from `leerbedrijfVerification.provider` via DI (no configured provider →
      no-op, the placement simply stays in `sbb-verification-pending`); calls `verify()` and writes
      the result back onto `BpvPlacement.leerbedrijfVerification` (`status`, `erkenningNumber`,
      `verifiedAt`, `expiresAt`, `raw`).
- [ ] 2.5 Register the listener in `lib/AppInfo/Application.php` against
      `ObjectTransitionedEvent::class` via `addServiceListener()` (same call shape as the existing
      `ExcuseApprovalHandler`/`DataExchangeRunHandler` registrations).
- [ ] 2.6 Create `lib/Lifecycle/BpvConfirmationGuard.php` (namespace `OCA\Scholiq\Lifecycle`,
      matching `AssessmentPublishGuard.php`'s constructor-injected `ObjectService` +
      `LoggerInterface` shape, `check(array &$transitionContext): bool`): reads the target
      `BpvPlacement`'s stored `leerbedrijfVerification.status` and returns `true` only when it
      equals `verified`; fails closed (returns `false`) on `unverified | pending | rejected |
      expired` or any lookup miss.
- [ ] 2.7 Create `lib/Lifecycle/PokActivationGuard.php` (same constructor/`check()` shape): resolves
      the target `Praktijkovereenkomst`'s `isFullySigned` calculated field and returns `true` only
      when it is `true`; fails closed otherwise.
- [ ] 2.8 Register-validation test: both new schemas validate against `npm run check:register`.

## 3. Schemas — WerkprocesAssessment, BpvVisitReport

- [ ] 3.1 Add `WerkprocesAssessment` schema: `bpvPlacementId` (`$ref: BpvPlacement`),
      `curriculumPlanId` (`$ref: CurriculumPlan`) + `componentId` (existing generic grading hook,
      `kind: "assessment"` on the referenced `CurriculumPlan.components[]` entry — no schema change
      to `school-structure`), `kwalificatiedossierCode`, `kerntaakCode`, `werkprocesCode`,
      `werkprocesLabel`, `assessorId` (`$ref: Praktijkopleider`), `assessedAt`, `beoordeling` (enum
      `nog-niet-competent | competent`), `toelichting`, `tenant_id`, `lifecycle` (`draft →
      submitted → confirmed`).
- [ ] 3.2 Add `x-openregister-notifications`-adjacent bridge for the `confirmed` transition: create
      `lib/Listener/WerkprocesGradeEmitHandler.php` (`IEventListener`, filters to
      `ObjectTransitionedEvent` with `schema=werkproces-assessment`, `to=confirmed`) that emits or
      updates a `GradeEntry` for the assessment's `curriculumPlanId`/`componentId`, matching
      `GradeRollupHandler.php`'s existing cross-schema write-bridge shape; register it in
      `lib/AppInfo/Application.php` alongside the task 2.5 listener. This schema computes no final
      grade itself — the emitted `GradeEntry` is consumed by the `grading` spec's existing rollup.
- [ ] 3.3 Add `BpvVisitReport` schema: `bpvPlacementId` (`$ref: BpvPlacement`), `learnerRef` (`$ref:
      LearnerProfile`), `visitDate`, `visitKind` (enum `voortgangsbezoek | tussentijds-gesprek |
      eindgesprek | incident`), `attendees` (array of `{role, name}`), `schoolCoachId` (NC uid),
      `narrative`, `actionPoints`, `tenant_id`, `lifecycle` (`draft → finalized`),
      `x-openregister-calculations.nextVisitDue` (derived from `visitDate` + a cadence — same
      derived-field idiom as `ActionItem.isOverdue`, no TimedJob).
- [ ] 3.4 Add `x-openregister-notifications.visitDueReminder` on `BpvVisitReport`: `trigger.type:
      scheduled`, keyed to `nextVisitDue`, `recipients: [{kind: field, field: schoolCoachId}]`,
      `nl`/`en` subject — per the verified dialect (`openspec/specs/scholiq-notifications/spec.md`).
      Targets `schoolCoachId` only (not the praktijkopleider — no NC-reachable channel; documented
      gap in design.md).
- [ ] 3.5 Bump `lib/Settings/scholiq_register.json`'s `info.version` `0.3.1 → 0.4.0` (six new
      schemas at `0.1.0`; no existing schema modified).
- [ ] 3.6 Register-validation test: all six new schemas + the version bump validate against `npm
      run check:register`.

## 4. Backend — Praktijkopleider portal audience

- [ ] 4.1 In `lib/Portal/PortalContributionProvider.php`, add `'praktijkopleider'` to
      `getAudiences()`'s return array (currently `['student', 'parent']`, line 89), and a new `if`
      branch in `getContribution()` dispatching to a new private method
      `praktijkopleiderContribution()`, matching the existing `studentContribution()`/
      `parentContribution()` dispatch shape. Fail-closed `null` for any other audience (unchanged
      default).
- [ ] 4.2 Implement `praktijkopleiderContribution()` per design.md's worked example: one collection
      `poBpvPlacements` (`register: scholiq`, `schema: bpv-placement`, `scopeField:
      praktijkopleiderId`, `scopeClaim: praktijkopleiderId` — direct match, `minTrust: 'low'`),
      `fields` whitelist `praktijkopleiderId, learnerRef, curriculumPlanId, leerbedrijfName,
      periodFrom, periodTo, lifecycle` (excludes `schoolCoachId` and
      `leerbedrijfVerification.raw`).
- [ ] 4.3 Add the `createWerkprocesAssessment` action (`type: create`, `schema:
      werkproces-assessment`, `scopeField: assessorId`, `scopeClaim: praktijkopleiderId`,
      `minTrust: substantial`, fields `bpvPlacementId, curriculumPlanId, componentId,
      kwalificatiedossierCode, kerntaakCode, werkprocesCode, werkprocesLabel, beoordeling,
      toelichting` — no grade/status/staff-decision field in the whitelist).
- [ ] 4.4 Add the `signPraktijkovereenkomst` action (`type: create`, `schema: pok-signature`,
      `scopeField: signerId`, `scopeClaim: praktijkopleiderId`, `minTrust: substantial`, fields
      `subjectId, subjectVersion, assuranceLevel, method, evidenceRef`).
- [ ] 4.5 Unit test additions to `tests/Unit/Portal/PortalContributionProviderTest.php`: a
      `praktijkopleider` subject sees only `BpvPlacement`s where `praktijkopleiderId ==
      subjectRef`; the returned collection's `fields` excludes `schoolCoachId` and
      `leerbedrijfVerification.raw`; `createWerkprocesAssessment`/`signPraktijkovereenkomst` stamp
      `assessorId`/`signerId` from `subject.subjectRef` server-side (never from the request body);
      an unrecognised audience still returns `null`; extend the existing register-drift pin
      assertion to cover the three new BPV schemas.

## 5. Frontend

- [ ] 5.1 Add `src/manifest.json` index + detail pages for `BpvPlacement`, `Praktijkopleider`,
      `Praktijkovereenkomst`, `WerkprocesAssessment`, `BpvVisitReport` — declarative CRUD
      forms/lists over the OpenRegister objects, `lifecycleActions: { field: "lifecycle" }` on each
      (renders the `checkLeerbedrijf`/`confirm`/`activate` transition buttons without custom code).
      `BpvPlacement`'s detail page Data block includes `leerbedrijfVerification.status` so the
      coordinator sees the gate state inline.
- [ ] 5.2 Add the `SignPokModal` manifest entry (`type: "custom"`, route
      `/praktijkovereenkomsten/:pokId/sign`, `component: "CnSignatureCapture"`) mirroring the
      existing `SignPlanModal` entry (`src/manifest.json:5254-5261`) — mounts the shared
      `CnSignatureCapture` component from `@conduction/nextcloud-vue` for the student/school signing
      legs inside Scholiq's own UI. No new Vue file (manifest-only, matching `SignPlanModal`).
- [ ] 5.3 Manifest validation: `npm run check:manifest` passes with the new pages + `SignPokModal`
      entry.

## 6. Tests

- [ ] 6.1 Unit test `tests/Unit/Lifecycle/BpvConfirmationGuardTest.php`: `verified` status allows
      `confirm`; `unverified | pending | rejected | expired` block it; missing
      `leerbedrijfVerification` block fails closed.
- [ ] 6.2 Unit test `tests/Unit/Lifecycle/PokActivationGuardTest.php`: `isFullySigned: true` allows
      `activate`; `false` (0, 1, or 2 of 3 roles signed) blocks it.
- [ ] 6.3 Unit test `tests/Unit/Listener/BpvLeerbedrijfVerificationHandlerTest.php`: a configured
      fake `ProvidesLeerbedrijfVerification` returning `verified` writes back
      `leerbedrijfVerification.status/erkenningNumber/expiresAt/raw` onto the `BpvPlacement`; an
      unconfigured provider is a no-op (placement stays `sbb-verification-pending`, no exception
      thrown); a `rejected`/`pending` result is written back without advancing the lifecycle.
- [ ] 6.4 Unit test `tests/Unit/Listener/WerkprocesGradeEmitHandlerTest.php`: a `WerkprocesAssessment`
      reaching `confirmed` emits (create case) or updates (existing `GradeEntry` for the same
      `curriculumPlanId`/`componentId` case) exactly one `GradeEntry`; the schema itself computes no
      final grade.
- [ ] 6.5 Unit test coverage on `PokSignature` append-only + `isFullySigned` calculation: three
      `PokSignature`s with distinct `signerRole`s (`student`, `school`, `praktijkopleider`) yield
      `isFullySigned: true`; two distinct roles yield `false`; a duplicate `signerRole` (e.g. two
      `student` signatures) still counts as one distinct role toward the three.
- [ ] 6.6 Minimum 75% coverage on `BpvConfirmationGuard`, `PokActivationGuard`,
      `BpvLeerbedrijfVerificationHandler`, `WerkprocesGradeEmitHandler` per ADR-009.
- [ ] 6.7 Run `npm run check:register` and `npm run check:manifest`; resolve any errors from the six
      new schemas, the version bump, and the manifest additions.

## 7. Docs + traceability

- [ ] 7.1 Add `@spec openspec/changes/bpv-praktijkovereenkomst/tasks.md#task-N` docblock tags to
      `ProvidesLeerbedrijfVerification`, `BpvLeerbedrijfVerificationHandler`,
      `WerkprocesGradeEmitHandler`, `BpvConfirmationGuard`, `PokActivationGuard`, and the
      `PortalContributionProvider::praktijkopleiderContribution()` addition.
- [ ] 7.2 SPDX headers (`@license EUPL-1.2` + `@copyright`) on every new PHP file, per CLAUDE.md.
- [ ] 7.3 i18n: English source strings for the five new manifest pages, `SignPokModal`, and the
      `visitDueReminder` notification subject; Dutch translations per ADR-007. i18n keys in English
      per fleet convention.
- [ ] 7.4 Add `docs/features/bpv.md` with Playwright MCP screenshots of the `BpvPlacement` detail
      page (showing the leerbedrijf-verification gate + lifecycle actions), the POK signing flow via
      `SignPokModal`, and a `WerkprocesAssessment` list, per ADR-010.
- [ ] 7.5 Run `composer check:strict` on all new/touched PHP files and fix any pre-existing
      warnings encountered in them, per CLAUDE.md.
- [ ] 7.6 Run `openspec validate bpv-praktijkovereenkomst --strict` and resolve any errors.
