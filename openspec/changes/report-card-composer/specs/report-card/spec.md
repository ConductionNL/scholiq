---
slug: report-card
title: Report Card — Periodic Rapport Composition & Rapportvergadering Review
status: in-progress
feature_tier: must
depends_on_adrs: [ADR-001, ADR-008, ADR-022, ADR-024, ADR-031, ADR-046]
created: 2026-07-13
profiles: [rapport-vo-po, generic-periodic-report]
---

# Report Card

@e2e exclude Mostly a backend/data-model + composer spec. Requirements that describe declarative-manifest UI surfaces (composing a report period, the rapportvergadering review grid, portal parent access) carry their own `#### Scenario:` `@e2e` refs where a genuine Scholiq DOM surface exists; pure schema/guard/composer requirements are individually annotated `@e2e exclude`.

## Purpose

Both Dutch K-12 incumbents ship a periodic report card as a named feature — ParnasSys builds its "rapport"
directly from the LVS (leerlingvolgsysteem), ESIS ships "Schoolrapport" — and Scholiq cannot produce one.
Every ingredient already exists in this register (`GradeEntry`/`FinalGrade` roll-ups from `grading`,
`AttendanceRecord`/`AttendanceFlag` from `attendance`, `CurriculumPlan.periods[]` from `school-structure`,
guardian portal access already shipped by `portal-contribution`/`portal-parent`) except the composition
itself and the rapportvergadering (grade-deliberation meeting) review step a Dutch school's grading
calendar already expects to exist. This spec adds a `ReportPeriod` (the school-wide term a report is
composed for, with a lock date) and a `ReportCard` (one composed document per learner per period,
snapshotting each subject's period average, an attendance summary, teacher and mentor comments) with a
draft → rapportvergadering-review → finalised → published-to-parents lifecycle that respects the existing
`grading` spec's scheduled grade-visibility promise end to end.

## What

- **ReportPeriod** — the school-wide term: name, academic year, a `periodCode` matching the same period
  identifier `GradeEntry.period`/`CurriculumPlan.components[].period`/`FinalGrade.breakdown.periods` already
  use, start/end dates, which `CurriculumPlan`s (subjects) and `Cohort`s it covers, and a lock date after
  which ordinary grade publishing for that period is blocked.
- **ReportCard** — one composed document per (learner, ReportPeriod): a snapshot of each in-scope subject's
  period average and pass/fail state (from `FinalGrade.breakdown.periods[periodCode]`), a per-subject
  teacher comment, an attendance summary, an overall mentor comment, and (forward-compatible, optional) a
  competency-attainment section a sibling change may populate.
- **A PHP composer**, `ReportCardComposer`, triggered by a `ReportPeriod.compose` transition, mirroring the
  "assemble from multiple linked objects" shape `data-exchange`'s OSO/leerplicht dossier composers already
  establish — without using the `DataExchangeJob` queue those composers live in, because a report card
  never leaves the tenant over a wire protocol.
- **The rapportvergadering review lifecycle**: `draft → rapportvergadering-review → finalised →
  published-to-parents`, with `finalised → rapportvergadering-review` reopening available as an admin/mentor
  correction path before parent publication.
- **A grade-visibility-respecting publish gate**: `publishToParents` re-checks every contributing
  `GradeEntry.visibleFrom` at the moment of publication, not just at compose time, so a report card can
  never surface a grade before its own scheduled visibility window.
- **A proposed, explicitly-flagged docudesk PDF-rendering seam**, fail-soft and non-blocking — the
  `ReportCard` OpenRegister object is the legally complete record regardless of whether a PDF exists.
- **Guardian portal access** via a fourth `parentReportCards` collection on the already-shipped
  `PortalContributionProvider::parentContribution()`.

## User Stories

- As a mentor, I want to compose the whole cohort's report cards for "Rapport 2" in one action once every
  subject's grades are locked, instead of manually assembling each learner's averages by hand.
- As a subject teacher, I want to add a short comment to my subject's line on a learner's report card
  before the rapportvergadering, so my colleagues see my context during the deliberation.
- As a mentor, I want to add an overall comment and finalise the report card after the rapportvergadering,
  so it's locked before it goes to parents.
- As a parent, I want to see my child's report card in the same portal I already use for grades and
  attendance, once the school has published it — and never a grade before its own scheduled release time,
  even inside the report card.
- As a compliance-minded school administrator, I want teachers to be unable to quietly change a subject's
  grade for a period after its lock date without an explicit override, so the report card a parent receives
  matches what was actually decided at the rapportvergadering.

## Acceptance Criteria

- GIVEN a `ReportPeriod` whose `lockDate` has passed, WHEN a coordinator/mentor triggers `compose`, THEN one
  `ReportCard` (`draft`) is created per learner in `cohortIds[] → Cohort.learnerIds`, each with one
  `subjectGrades[]` row per `curriculumPlanIds[]` entry populated from that learner's
  `FinalGrade.breakdown.periods[periodCode]`.
- GIVEN a `ReportPeriod` whose `lockDate` has NOT passed, WHEN `compose` is attempted, THEN
  `ReportPeriodComposeGuard` blocks it.
- GIVEN a `ReportPeriod` that is `isLocked`, WHEN an ordinary teacher attempts to `publish`/`republish` a
  `GradeEntry` whose `period` and owning `CurriculumPlan` match that period's scope, THEN
  `ReportPeriodLockGuard` blocks the transition.
- GIVEN a `ReportCard` in `finalised` whose `subjectGrades[].sourceGradeEntryIds[]` include an entry whose
  `visibleFrom` is still in the future, WHEN `publishToParents` is attempted, THEN
  `ReportCardVisibilityGuard` blocks it.
- GIVEN a `ReportCard` transitions `finalised → published-to-parents`, WHEN the transition completes, THEN
  the learner receives a declared notification and each `LearnerProfile.parentIds` entry receives a
  `ReportCardParentNotification`-driven notification.
- GIVEN a guardian authenticated via portaliq, WHEN they request their child's report cards, THEN they see
  only `published-to-parents` `ReportCard`s for their own child(ren), scoped by the same reverse `via` join
  `parentGrades`/`parentAttendance` already use.

## ADDED Requirements

### Requirement: Persist ReportPeriod and ReportCard domain objects in OpenRegister

The system MUST persist `ReportPeriod`, `ReportCard`, and `ReportCardParentNotification` as OpenRegister
objects. `ReportPeriod` MUST carry `x-openregister-lifecycle` (`open → composed → archived`).
`ReportCard` MUST carry `x-openregister-lifecycle` (`draft → rapportvergadering-review → finalised →
published-to-parents`, with `finalised → rapportvergadering-review` available as a `reopen` transition).
`ReportCardParentNotification` MUST be `appendOnly: true` (audit per ADR-008), mirroring `GradeNotification`'s
shape exactly (`event`, `recipient`, `sourceId` `$ref: ReportCard`, `learnerId`, `learnerRef`,
`idempotencyKey`, `visibleFrom`, `tenant_id`).

#### Scenario: ReportPeriod and ReportCard persist with their declared lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface for registration itself, covered by register-JSON assertion tests mirroring the established `*RegisterTest` convention (e.g. `VerzuimReportComposerRegisterTest`). -->

- **GIVEN** the `report-card` schemas are registered in OpenRegister
- **WHEN** a `ReportPeriod` and a `ReportCard` are each created
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle state
- **AND** `ReportCardParentNotification` is `appendOnly: true`

### Requirement: ReportPeriod scopes the subjects, cohorts, and window a report card is composed from

`ReportPeriod` MUST carry `periodCode` (a string matching `GradeEntry.period`/
`CurriculumPlan.components[].period`/the key used in `FinalGrade.breakdown.periods`), `academicYear`,
`startDate`/`endDate`, `curriculumPlanIds[]` (`$ref: CurriculumPlan` — which subjects count toward this
report), and `cohortIds[]` (`$ref: Cohort` — which cohorts this period's report cards cover).

#### Scenario: A ReportPeriod scopes exactly the declared subjects and cohorts

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a coordinator creates a `ReportPeriod` naming 3 `curriculumPlanIds` and 2 `cohortIds`
- **WHEN** the period is saved
- **THEN** it persists exactly those subjects and cohorts as its composition scope

### Requirement: Lock date is enforced by a materialised calculation and guards, not an automatic transition

`ReportPeriod` MUST carry a nullable `lockDate` (date-time) and a declared `x-openregister-calculations`
boolean `isLocked` (`lockDate` is set AND has passed `@now`) — `materialise: true`, mirroring
`ConferenceRound.isBookingClosed`'s `lt`/`now` idiom. `ReportPeriod` MUST NOT declare an automatic
`open → locked` lifecycle transition driven by a `scheduled` notification trigger: per this codebase's own
verified precedent (`ConferenceRound.isBookingClosed`'s description), a `scheduled`-type
`x-openregister-notifications` trigger can only fire a notification, never a lifecycle transition. Instead,
`isLocked` MUST be read directly by `ReportPeriodComposeGuard` (gating the `compose` transition) and
`ReportPeriodLockGuard` (see `grading`'s modified requirement) via `ObjectService`, and a `lockDatePassed`
reminder notification (mirroring `ConferenceRound.bookingAutoClosed`'s shape) MUST prompt a human to act.

#### Scenario: compose is blocked before the lock date

<!-- @e2e exclude Lifecycle guard is backend logic verified by PHPUnit ReportPeriodComposeGuardTest; no scholiq DOM surface for the guard itself. -->

- **GIVEN** a `ReportPeriod` with `lockDate` in the future
- **WHEN** a coordinator/mentor attempts the `compose` transition
- **THEN** `ReportPeriodComposeGuard` blocks it because `isLocked` is `false`

#### Scenario: compose succeeds once the lock date has passed

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `ReportPeriod` with `lockDate` in the past
- **WHEN** a coordinator/mentor attempts the `compose` transition
- **THEN** `ReportPeriodComposeGuard` allows it and the period moves to `composed`

#### Scenario: A reminder notification fires once the lock date passes, without auto-transitioning

<!-- @e2e exclude Declared scheduled-trigger notification, no lifecycle side effect and no scholiq DOM surface; verified by reasoning over the register JSON (same scope boundary as ConferenceRound.bookingAutoClosed). -->

- **GIVEN** a `ReportPeriod` in `open` whose `lockDate` has just passed
- **WHEN** the declared `scheduled` trigger evaluates
- **THEN** a `lockDatePassed` reminder notification reaches the `mentor`/`coordinator` NC groups
- **AND** the `ReportPeriod`'s `lifecycle` remains `open` — no automatic transition occurs

### Requirement: Composition is a declared-transition-triggered PHP composer, not a DataExchangeJob and not a TimedJob

`ReportPeriod`'s `compose` transition (`open → composed`, requiring `ReportPeriodComposeGuard`) MUST trigger
`ReportCardComposer`, an OR-event-driven `Listener` (ADR-031 "cross-object write bridge" exception,
mirroring `ConferenceScheduleGenerator`'s shape), NOT a PHP `TimedJob` and NOT a `data-exchange`
`DataExchangeJob`. For every learner in `cohortIds[] → Cohort.learnerIds`, it MUST create one `ReportCard`
(`draft`) with one `subjectGrades[]` entry per `curriculumPlanIds[]` entry, populated from that learner's
`FinalGrade.breakdown.periods[periodCode]` (value, passed) plus the `FinalGrade`'s contributing published
`GradeEntry` ids (`sourceGradeEntryIds[]`), and (when `attendanceIncluded`) an `attendanceSummary` composed
from `AttendanceRecord`s within `[startDate, endDate]`. A `recompose` self-loop transition on `draft`
`ReportCard`s MUST allow re-running the composer for a single learner without recreating the object.

#### Scenario: Composing a period creates one ReportCard per cohort learner

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a locked `ReportPeriod` covering 2 cohorts totalling 30 learners and 4 `curriculumPlanIds`
- **WHEN** `compose` runs
- **THEN** exactly 30 `draft` `ReportCard`s are created, each with up to 4 `subjectGrades[]` rows
- **AND** no `DataExchangeJob` and no PHP `TimedJob` is involved in the composition

#### Scenario: A subject with no matching period component contributes no row, not an error

<!-- @e2e exclude Composer null-handling is backend logic verified by PHPUnit ReportCardComposerTest; no scholiq DOM surface. -->

- **GIVEN** a `curriculumPlanId` in `ReportPeriod.curriculumPlanIds` whose `CurriculumPlan.components[]`
  declares no component with `period` matching `ReportPeriod.periodCode`
- **WHEN** `ReportCardComposer` runs for a learner enrolled in that subject
- **THEN** no `subjectGrades[]` row is created for that subject and composition completes without error for
  the learner's other subjects

### Requirement: The rapportvergadering review lifecycle gates parent visibility behind a finalise step

`ReportCard` MUST support `draft → rapportvergadering-review` (`pullIntoReview`, no guard) →
`finalised` (`finalise`, requiring `ReportCardFinaliseGuard`) → `published-to-parents`
(`publishToParents`, requiring `ReportCardVisibilityGuard` — see below). `finalised` MUST support a
`reopen` transition back to `rapportvergadering-review`, restricted via `x-openregister-authorization` to
`admin`/`mentor`/`principal`, for pre-publication correction. `ReportCardFinaliseGuard` MUST require
`mentorComment` to be non-empty and `subjectGrades[]` to be non-empty before allowing `finalise`.

#### Scenario: finalise is blocked without a mentor comment

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `ReportCard` in `rapportvergadering-review` with `mentorComment` unset
- **WHEN** a mentor attempts `finalise`
- **THEN** `ReportCardFinaliseGuard` blocks the transition

#### Scenario: A mentor reopens a finalised report card to correct it before publication

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `ReportCard` in `finalised`, not yet `published-to-parents`
- **WHEN** a mentor triggers `reopen`
- **THEN** it returns to `rapportvergadering-review` and its `subjectGrades[]`/`mentorComment` become
  editable again

### Requirement: publishToParents MUST NOT surface a grade before its own scheduled visibility window

`ReportCard`'s `publishToParents` transition (`finalised → published-to-parents`) MUST require
`ReportCardVisibilityGuard`, which resolves every `subjectGrades[].sourceGradeEntryIds[]` entry's *current*
`GradeEntry.visibleFrom` and blocks the transition unless every one has already passed `@now`. This check
MUST be performed at the moment of publication, not trusted from compose time, so a `CurriculumPlan.
gradeVisibilityPolicy.mode: nextSchoolDay` deferral already in effect for a contributing grade is honoured
even if the `ReportCard` was composed and finalised before that grade's window opened.

#### Scenario: Publish is blocked while a contributing grade's visibility window has not opened

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `finalised` `ReportCard` whose Biologie `subjectGrades[]` row references a `GradeEntry` with
  `visibleFrom` still in the future
- **WHEN** a mentor attempts `publishToParents`
- **THEN** `ReportCardVisibilityGuard` blocks the transition and names the subject whose grade is not yet
  visible

#### Scenario: Publish succeeds once every contributing grade's window has opened

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** a `finalised` `ReportCard` whose every `subjectGrades[].sourceGradeEntryIds[]` entry's
  `visibleFrom` has already passed
- **WHEN** a mentor attempts `publishToParents`
- **THEN** the transition succeeds and the `ReportCard` moves to `published-to-parents`

### Requirement: Publication fans out a learner + parent notification, mirroring GradeNotification's reason

`ReportCard`'s `publishToParents` transition MUST declare an `x-openregister-notifications` `transition`-type
rule (`recipients: [{kind: "field", field: "learnerId"}]`) for the learner directly. A new
`ReportCardPublishHandler` (`Listener`, mirroring `GradeRollupHandler`'s parent fan-out half) MUST, on the
same transition, create one `ReportCardParentNotification` per `LearnerProfile.parentIds` entry for the
learner — because, per `GradeRollupHandler`'s own documented reason, OR's declarative notification system
addresses a single field, not a related array.

#### Scenario: Publishing notifies the learner directly and fans out to each parent

<!-- @e2e exclude Notification fan-out is backend/lifecycle logic verified by PHPUnit ReportCardPublishHandlerTest; no scholiq DOM surface for the fan-out itself (the resulting nc-notification is a platform-level surface, not a scholiq page). -->

- **GIVEN** a `ReportCard` about to transition `finalised → published-to-parents` for a learner with 2
  linked parents
- **WHEN** the transition completes
- **THEN** the learner receives one `nc-notification` via the declared `transition` rule
- **AND** exactly 2 `ReportCardParentNotification` records are created, one per parent, each carrying its
  own `idempotencyKey`

### Requirement: docudesk PDF rendering is fail-soft, non-blocking, and its contract is explicitly proposed

`ReportCard` MUST carry nullable `docudeskRenderStatus` (`requested | rendered | failed`),
`docudeskRequestedAt`, `docudeskDocumentRef`, and `docudeskRenderError`, mirroring `Credential`'s
wallet-offer-state field shape. A `renderToPdf` (`finalised → finalised`) and `rerenderToPdf`
(`published-to-parents → published-to-parents`) self-loop transition pair MUST each `require`
`OCA\Scholiq\Service\ReportCardPdfDelegationService`, which MUST always return `true` (fail-soft — a
render failure is logged and recorded in `docudeskRenderError` but MUST NOT block any `ReportCard`
lifecycle transition, mirroring `bpv-praktijkovereenkomst`'s POK precedent that the OpenRegister object is
the legally complete record regardless of a rendered document's existence). The service posts to a
**proposed, not-yet-verified** docudesk REST contract (no docudesk endpoint exists in this repo to
reference), using the same `IClientService` + `IURLGenerator` + `IAppConfig` bearer-token seam
`DataExchangeRunHandler::callOpenConnector()`/`WalletOfferDelegationService` already establish. The
docudesk-side endpoint implementation is an explicit, tracked follow-up leaf, not built by this change.

#### Scenario: A PDF render failure does not block publication

<!-- @e2e exclude Fail-soft backend hook; no scholiq DOM surface — the renderToPdf action behaves identically to a no-op from the user's perspective regardless of docudesk reachability. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` and docudesk unreachable or absent
- **WHEN** `renderToPdf` is triggered
- **THEN** the transition succeeds regardless, `docudeskRenderStatus` becomes `failed`,
  `docudeskRenderError` records the failure, and `lifecycle` remains `finalised`

#### Scenario: A successful render records the docudesk document reference

<!-- @e2e exclude Backend hook success path; no scholiq DOM surface beyond the existing generic lifecycleActions button. Covered by ReportCardPdfDelegationServiceTest per tasks.md. -->

- **GIVEN** a `finalised` `ReportCard` and a reachable docudesk endpoint accepting the proposed contract
- **WHEN** `renderToPdf` is triggered and docudesk returns 2xx with a document reference
- **THEN** `docudeskRenderStatus` becomes `rendered`, `docudeskDocumentRef` is set, and
  `docudeskRenderError` is cleared

### Requirement: ReportCard is exposed to guardians via the existing parent portal-contribution audience

`OCA\Scholiq\Portal\PortalContributionProvider::parentContribution()` MUST gain a fourth read collection,
`parentReportCards`, scoped `learnerRef`/`scopeClaim: guardianRef` through the same `childJoin`
reverse/scope-value `via` join (`match: 'scopeField'`) its three existing collections
(`parentGrades`/`parentAttendance`/`parentExcuseRequests`) already use, `minTrust: substantial`, listing
only `ReportCard`s in `lifecycle: published-to-parents` (never `draft`/`rapportvergadering-review`/
`finalised`).

#### Scenario: A guardian sees only their child's published report cards

<!-- @e2e exclude Backend-only contract class rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php), same scope boundary as the existing parentGrades/parentAttendance/parentExcuseRequests scenarios. -->

- **GIVEN** a guardian whose `guardianRef` matches a `LearnerProfile.guardianRefs` entry, and that learner
  has one `published-to-parents` and one `draft` `ReportCard`
- **WHEN** portaliq resolves the guardian's `parentReportCards` collection
- **THEN** only the `published-to-parents` `ReportCard` is returned

### Requirement: Frontend is declarative with two named custom views

Frontend MUST be declarative: `src/manifest.json` pages for `ReportPeriod`/`ReportCard` index+detail. A
custom `ComposeReportPeriodModal` (triggering the `compose` transition with a confirmation of scope/lock
state — no manifest page can express a bulk cross-object action) and a `RapportvergaderingReviewView`
(the cohort-wide grid used during the review meeting — genuine UI, mirroring `GradebookView`'s existing
precedent for the same "a manifest page can't render a cohort grid" reason) are the only custom Vue
components. No PHP CRUD controllers.

#### Scenario: Pages and custom views are manifest-declared

<!-- @e2e tests/e2e/spec-coverage/report-card.spec.ts -->

- **GIVEN** the report-card frontend is configured
- **WHEN** the app renders `ReportPeriod`/`ReportCard` screens
- **THEN** index/detail pages come from `src/manifest.json` and only `ComposeReportPeriodModal` and
  `RapportvergaderingReviewView` exist as custom Vue components, with no PHP CRUD controllers

## Standards

Schema.org `Grade`/`EducationalOccupationalProgram` context already established by `grading`/
`school-structure` (a `ReportCard` composes existing schema.org-aligned data, it does not introduce a new
vocabulary); NL VO/PO "rapport" convention (periodic report handed out at a rapportvergadering); AVG-Onderwijs
(parent vs 18+-learner visibility, inherited unchanged from the `grading` spec's existing notification-
preference posture — this spec adds no new consent mechanism).

## Data Model

All in OpenRegister. New: `ReportPeriod`, `ReportCard`, `ReportCardParentNotification`. Consumes:
`CurriculumPlan`/`Cohort` (`school-structure`), `GradeEntry`/`FinalGrade` (`grading`), `AttendanceRecord`
(`attendance`), `LearnerProfile.parentIds`/`.guardianRefs` (`portal-identity`). Two ADR-031 PHP exceptions:
`ReportCardComposer` (event-driven cross-object write bridge, mirrors `ConferenceScheduleGenerator`) and
`ReportCardPublishHandler` (parent-notification fan-out, mirrors `GradeRollupHandler`'s fan-out half). One
fail-soft external-delegation service: `ReportCardPdfDelegationService`. See `docs/ARCHITECTURE.md`.

## Out of Scope

- The bevorderingsbesluit (promotion) decision the rapportvergadering may also make — `school-year-rollover`
  already scopes this out explicitly ("the rapportvergadering decision happens outside; the wizard records
  its outcome via overrides") and this change does not reopen it. `ReportCard` composes the *report*, not
  a promotion verdict.
- Scheduling the rapportvergadering meeting itself (attendee lists, time slots) — that is `parent-
  conferences`' `ConferenceRound`/`ConferenceSlot` machinery; a future change may link a `ConferenceRound`
  to a `ReportPeriod` if a buyer needs it, not built here.
- The docudesk-side PDF rendering endpoint implementation (filed as a follow-up leaf — see design.md).
- Competency-attainment data population (`ReportCard.competencyAttainment` is a forward-compatible nullable
  field only; the sibling wave-2 `competency-framework` change, if built, is what would populate it — not a
  dependency of this change).
- Historical/legacy report-card import from a prior SIS (ParnasSys/ESIS/Magister export) — a follow-up if a
  migrating buyer needs it.
