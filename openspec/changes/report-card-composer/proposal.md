---
kind: code
depends_on: []
---

## Why

Both Dutch K-12 incumbents ship a periodic report card as a first-class feature — ParnasSys builds its
"rapport" directly from the LVS (leerlingvolgsysteem), and ESIS ships "Schoolrapport" — and Scholiq cannot
produce one. This is the concrete evidence handed to me for this item (competitor names + the feature
gap); I was not given specific Specter insight/story ids for it, so this Why section grounds itself
entirely in that competitor evidence plus direct HEAD verification of the codebase, not fabricated
identifiers.

**Verified at HEAD (2026-07-13, this worktree):**

- **`grading`'s own Out of Scope only excludes transcript/diploma-supplement generation, not a periodic
  report.** `openspec/specs/grading/spec.md:200-202`: "Out of Scope ... Transcript / diploma-supplement
  document generation (the `certification` spec issues the credential; DocuDesk does templating)." A
  transcript (an end-of-programme cumulative record) and a periodic report card (a per-term snapshot
  handed to parents at a rapportvergadering) are different documents serving different moments in the
  school year — the grading spec's scoping language never mentions the latter, and grepping every
  `openspec/specs/*/spec.md` and `lib/Settings/scholiq_register.json` for `rapport|report-card|ReportCard|
  ReportPeriod` (case-insensitive) turns up zero schema or requirement hits (the only two hits anywhere in
  the repo are both in `openspec/changes/archive/2026-06-15-school-year-rollover/{proposal,design}.md`,
  both Out-of-Scope lines — see below).
- **No `ReportPeriod` or `ReportCard` object exists among the register's schemas.** `grep -n
  '"ReportPeriod"\|"ReportCard"' lib/Settings/scholiq_register.json` returns zero hits. The register (75
  schema definitions, `info.version: 0.7.0` at `lib/Settings/scholiq_register.json:3,6`) has every
  ingredient a report card needs — `GradeEntry`/`FinalGrade` (`grading`), `AttendanceRecord`/`AttendanceFlag`
  (`attendance`), `CurriculumPlan.periods[]`/`components[].period` (`school-structure`,
  `lib/Settings/scholiq_register.json:3018-3056`) — but nothing that ties them together into one
  per-learner, per-term document.
- **`rapportvergadering` (the grade-deliberation meeting) is a named, explicitly out-of-scope concept
  elsewhere in this codebase — not an invented term.** `openspec/changes/archive/2026-06-15-school-year-
  rollover/proposal.md:17`: "formal bevorderingsbesluit deliberation (the rapportvergadering decision
  happens outside; the wizard records its outcome via overrides)"; same wording repeated in that change's
  `design.md:23`. That change explicitly scopes the *promotion decision* out, but confirms the
  rapportvergadering itself is a real school-calendar event this codebase already expects to exist
  somewhere — it just isn't modelled anywhere.
- **`parent-conferences` already writes "for a report period" as prose, with no schema backing it.**
  `openspec/specs/parent-conferences/spec.md` (a `ConferenceRound` requirement): "GIVEN a coordinator
  creates a `ConferenceRound` for a report period" — the spec's own acceptance language presupposes a
  report-period concept that, per the grep above, does not exist as an object anywhere in the register.
- **The periodic subject average is already computed and sitting unused for this purpose.**
  `FinalGrade.breakdown.periods` (`lib/Settings/scholiq_register.json` — `FinalGrade`'s `breakdown`
  property, example `{"periods": {"1": 6.5, "2": 7.2}, ...}`) already carries the exact per-period,
  per-subject average a report card needs, keyed by the same period identifier
  `GradeEntry.period`/`CurriculumPlan.components[].period` use. Nothing currently reads it for a report
  card because nothing composes one.
- **The grade-visibility-scheduling safeguard (`GradeEntry.visibleFrom`) exists and must not be
  bypassable.** `lib/Settings/scholiq_register.json:5588-5592` — `GradeEntry.visibleFrom`, "Earliest
  datetime at which this entry's gradePublished notification is eligible to fire ... resolved server-side
  by GradeVisibilityResolver from the governing CurriculumPlan.gradeVisibilityPolicy." This shipped
  (archived `2026-07-13-grade-visibility-scheduling`) specifically to stop 3 a.m. grade pings. A report
  card that snapshots subject averages and pushes them to the parent portal without checking each
  contributing `GradeEntry.visibleFrom` would silently reopen exactly that hole — a rapport line for
  "Biologie" could leak a grade the teacher deliberately scheduled for tomorrow morning.
- **Parent portal read access for a learner's other records already exists and is a proven, working
  pattern to extend.** `lib/Portal/PortalContributionProvider.php:370-464` (`parentContribution()`) already
  declares `parentGrades`/`parentAttendance`/`parentExcuseRequests` — three reverse-joined, `minTrust:
  substantial` read collections scoped through `LearnerProfile.guardianRefs` (contract v2.2's
  scope-value `via` join, `openspec/changes/portal-parent/proposal.md`). A fourth `parentReportCards`
  collection is a direct, structurally-identical extension of a pattern already shipped and tested
  (`tests/Unit/Portal/PortalContributionProviderTest.php`), not new infrastructure.
- **No docudesk wire contract exists anywhere in this repo to reuse.** `grep -rni docudesk` across every
  non-vendor file returns only prose mentions — `docs/Integrations/index.md:15` ("DocuDesk: diploma and
  certificate document templating (optional)"), the `certification` spec's Out of Scope line ("Manual paper
  certificate printing (handed to docudesk if needed)"), and `bpv-praktijkovereenkomst`'s explicit "a
  `docudesk` leaf note, follow-up" pattern (`openspec/changes/archive/2026-07-13-bpv-
  praktijkovereenkomst/proposal.md:161-163`) — never a concrete endpoint, controller, or PHP call. The
  nearest *working* cross-app REST seam in this codebase is `DataExchangeRunHandler::callOpenConnector()`
  (`lib/Listener/DataExchangeRunHandler.php:966-1020`) and `WalletOfferDelegationService`
  (`openspec/specs/certification/spec.md`'s "Cross-app wallet delegation over openconnector's REST
  adapter" requirement) — both `IClientService` + `IURLGenerator` + `IAppConfig` bearer-token calls to a
  sibling app. This change reuses that seam *mechanism* for docudesk (a new, explicitly-flagged,
  not-yet-verified contract) rather than inventing a different one, and follows the
  `bpv-praktijkovereenkomst` precedent of treating the actual PDF artefact as a documented follow-up, not
  a blocking dependency — the `ReportCard` OpenRegister object is the legally complete record either way,
  exactly as that change argued for the POK.

This is a genuine gap in a `feature_tier: must` spec's neighbourhood (`grading`, `attendance` are both
`must`) that both named Dutch competitors ship as a named feature, and every data ingredient it needs
already exists in this register except the composition itself.

## What Changes

- **New capability `report-card`** (`specs/report-card/spec.md`) with two new OpenRegister schemas:
  - **`ReportPeriod`** — the school-wide term a report card is composed for: `name`, `academicYear`,
    `periodCode` (MUST match `GradeEntry.period`/`CurriculumPlan.components[].period`/
    `FinalGrade.breakdown.periods` keys), `startDate`/`endDate`, `curriculumPlanIds[]` (which subjects
    count), `cohortIds[]` (which cohorts this period covers), and a nullable `lockDate`.
  - **`ReportCard`** — one composed document per (learner, `ReportPeriod`): `subjectGrades[]` (one entry
    per `curriculumPlanId`, snapshotting `FinalGrade.breakdown.periods[periodCode]` plus the source
    `GradeEntry` ids and a per-subject teacher comment), an `attendanceSummary` composed from
    `AttendanceRecord`, a `mentorComment`, an inert-until-populated `competencyAttainment` field for the
    sibling wave-2 `competency-framework` change (not a hard dependency — this schema and its composer
    work correctly with it absent/null), and nullable `docudesk*` wallet-offer-shaped fields for PDF
    delivery state.
  - **`ReportCardParentNotification`** — a small, `appendOnly` fan-out record mirroring
    `GradeNotification`'s exact shape and reason (`lib/Listener/GradeRollupHandler.php:9-12` — "OR's
    declarative notification system addresses a single field, not a related array").
- **Composition is a PHP composer, mirroring `DataExchangeRunHandler::composeLeerplichtDossier`/
  `composeSwvDossier`'s shape** (assemble from multiple linked objects, ADR-031 "cross-object write
  bridge" exception) — **not** the `DataExchangeJob` queue those methods live in. A report card is an
  internal Scholiq artefact, not an external-registry export, so this change borrows the *composition
  style* only, triggered by a `ReportPeriod.compose` lifecycle transition (mirroring
  `ConferenceScheduleGenerator`'s "transition triggers a handler" shape), not a `DataExchangeJob`.
- **Lock date has real enforcement, expressed the way this codebase has already learned to express it.**
  `parent-conferences`' own `ConferenceRound.isBookingClosed` calculation (`lib/Settings/
  scholiq_register.json:11234-11249`) documents, in its own description, that OR's `scheduled`-type
  notification trigger "can only fire a notification, not a lifecycle transition." This change follows
  that lesson exactly: `ReportPeriod.lockDate` backs a materialised `isLocked` calculation (not an
  automatic lifecycle transition), which `ReportPeriodComposeGuard` requires before `compose` runs, and
  which a new `ReportPeriodLockGuard` — added as a **second** `requires` entry on `GradeEntry`'s existing
  `publish`/`republish` transitions, mirroring how `certification`'s `WalletRevocationPropagationService`
  was added as a second `requires` on `Credential.revoke` — blocks ordinary teacher grade-publishing for a
  locked period (fails open when no `ReportPeriod` governs the entry, same "fail open when nothing links"
  posture as `AttendanceFlagReportGuard`).
- **Grade-visibility interaction**: `publishToParents` (`finalised → published-to-parents`) is gated by a
  new `ReportCardVisibilityGuard` that reads every source `GradeEntry` referenced by `subjectGrades[]`
  (`sourceGradeEntryIds[]`, denormalised the same way `AttendanceFlag.breachingRecordIds` already is) and
  blocks the transition unless every one's `visibleFrom` has already passed `@now`.
- **docudesk seam is defined, not assumed.** A `renderToPdf`/`rerenderToPdf` self-loop transition pair
  (mirroring `Credential.offerToWallet`'s self-loop-as-PHP-hook idiom) invokes a new, fail-soft
  `ReportCardPdfDelegationService` (mirroring `WalletRevocationPropagationService`'s fail-soft shape — a
  PDF-render failure never blocks the report card's own lifecycle) against a **proposed, unverified**
  docudesk REST contract, since none exists in this repo to point at. The docudesk-side endpoint itself is
  filed as a follow-up leaf, exactly as `bpv-praktijkovereenkomst` did for the POK PDF.
- **Parent visibility reuses the existing, already-shipped portal surface.**
  `PortalContributionProvider::parentContribution()` gains a fourth `parentReportCards` read collection,
  using the identical `childJoin` reverse/scope-value `via` pattern its three existing collections already
  use — declared as a requirement in this change's own `report-card` spec (mirroring how
  `bpv-praktijkovereenkomst` declared its `praktijkopleider` portal audience under `bpv`'s own spec, not
  under `portal-contribution`'s, because `portal-contribution`'s capability spec is still `status:
  in-progress` and its real requirements are not yet synced into a baseline this change can safely target).

## Impact

- `openspec/specs/report-card/spec.md` — **NEW** capability spec (ADDED Requirements): `ReportPeriod` and
  `ReportCard`/`ReportCardParentNotification` persistence + lifecycle; composition mechanism; lock-date
  enforcement; grade-visibility publish gate; parent-notification fan-out; docudesk PDF delegation
  (fail-soft, contract-proposed); portal exposure; declarative-frontend posture.
- `openspec/specs/grading/spec.md` — MODIFIED Requirement "Persist grading domain objects in OpenRegister":
  `GradeEntry.publish`/`republish` gain a second `requires` entry, `ReportPeriodLockGuard`.
- `lib/Settings/scholiq_register.json` — three new schemas (`ReportPeriod`, `ReportCard`,
  `ReportCardParentNotification`); register `info.version` bump (implementation-time — see `tasks.md`).
- `lib/Lifecycle/` — new guards: `ReportPeriodComposeGuard`, `ReportPeriodLockGuard`,
  `ReportCardFinaliseGuard`, `ReportCardVisibilityGuard`, `ReportCardReopenGuard`.
- `lib/Listener/` — new: `ReportCardComposer` (ADR-031 cross-object write bridge, triggered by
  `ReportPeriod.compose`), `ReportCardPublishHandler` (parent fan-out, mirrors `GradeRollupHandler`'s
  fan-out half).
- `lib/Service/` — new: `ReportCardPdfDelegationService` (fail-soft docudesk delegation, mirrors
  `WalletRevocationPropagationService`).
- `lib/Portal/PortalContributionProvider.php` — add `parentReportCards` to `parentContribution()`'s
  `collections[]`.
- `src/manifest.json` — new declarative index/detail pages for `ReportPeriod`/`ReportCard`; a
  `ComposeReportPeriodModal` and a `RapportvergaderingReviewView` custom view (the only genuinely
  non-generic UI — a cohort-wide grid for the review meeting, no manifest page can express it, mirroring
  `GradebookView`'s existing precedent for the same reason).
- Does NOT touch: `FinalGrade` computation itself, `AttendanceRecord`/`AttendanceFlag` schemas, the
  `DataExchangeJob` queue/`data-exchange` spec, or `school-year-rollover`'s bevorderingsbesluit
  deliberation (still explicitly out of scope — this change composes the *report*, not the promotion
  decision the rapportvergadering may also make).
- `competency-framework` (sibling wave-2 change, not yet built): `ReportCard.competencyAttainment` is a
  nullable, forward-compatible field only — this change does not depend on it and works correctly with it
  absent.
