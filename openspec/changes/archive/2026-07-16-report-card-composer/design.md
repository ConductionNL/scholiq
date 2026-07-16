# Design: report-card-composer

## Architecture Overview

Two new schemas plus one small fan-out schema, all in OpenRegister (no new PHP database tables, ADR-001):

```
CurriculumPlan (school-structure, existing)          Cohort (school-structure, existing)
  .periods[] / .components[].period                    .learnerIds
        │                                                     │
        ▼                                                     ▼
   ReportPeriod  ── curriculumPlanIds[] ──────────────────────┘
   (NEW)            cohortIds[]
   name / academicYear / periodCode / startDate / endDate / lockDate
   lifecycle: open ──────────────► composed ──────────► archived
        │  isLocked (materialised calc, informational — see "Decisions")
        │  ReportPeriodComposeGuard requires isLocked before `compose`
        ▼
   `compose` transition ──fires──► ReportCardComposer (Listener, ADR-031
                                    cross-object write bridge, mirrors
                                    ConferenceScheduleGenerator)
                                        │  reads: FinalGrade.breakdown.periods[periodCode]
                                        │         per curriculumPlanId, per learner in
                                        │         cohortIds[] → Cohort.learnerIds
                                        │         + AttendanceRecord within [startDate,endDate]
                                        ▼
                                    ReportCard × N (one per learner)  (NEW)
                                    lifecycle: draft
                                        │  `recompose` (self-loop, re-runs composer for one learner)
                                        │  `pullIntoReview`
                                        ▼
                                    rapportvergadering-review
                                        │  teacher/mentor edit subjectGrades[].teacherComment,
                                        │  mentorComment — still not portal-visible
                                        │  `finalise` — requires ReportCardFinaliseGuard
                                        ▼
                                    finalised ──`reopen`(admin/mentor override)──► rapportvergadering-review
                                        │  `renderToPdf` (self-loop, fail-soft docudesk delegation)
                                        │  `publishToParents` — requires ReportCardVisibilityGuard
                                        ▼                        (checks every sourceGradeEntryIds[]'
                                    published-to-parents             visibleFrom has passed @now)
                                        │  `rerenderToPdf` (self-loop)
                                        ▼
                                    ReportCardPublishHandler (Listener, fans out
                                    ReportCardParentNotification per LearnerProfile.parentIds,
                                    mirrors GradeRollupHandler's parent fan-out half)
```

## Data Model

### `ReportPeriod` (new)

| Field | Type | Notes |
|---|---|---|
| `name` | string | e.g. "Rapport 1" |
| `academicYear` | string | `"YYYY-YYYY"`, same convention as `Cohort.academicYear`/`BsaTrajectory.academicYear` |
| `periodCode` | string | MUST match `GradeEntry.period`, `CurriculumPlan.components[].period`, and the key used in `FinalGrade.breakdown.periods` (verified example: `{"periods": {"1": 6.5, "2": 7.2}}`) — this is the join key the composer uses, not a new period-numbering scheme |
| `startDate` / `endDate` | date | The window `AttendanceRecord` (via its `Session.startsAt`) is summarised over |
| `curriculumPlanIds[]` | uuid[], `$ref: CurriculumPlan` | Which subjects/governing plans count toward this report ("which Courses/subjects count") |
| `cohortIds[]` | uuid[], `$ref: Cohort` | Which cohorts this period's report cards cover — same shape as `ConferenceRound.cohortIds[]` |
| `lockDate` | date-time, nullable | See "Lock date enforcement" below |
| `attendanceIncluded` | boolean, default `true` | Whether the attendance summary section is composed |
| `tenant_id` | string | Multi-tenancy |
| `lifecycle` | enum | `open → composed → archived` |

`x-openregister-calculations.isLocked` (`materialise: true`): `{"and": [{"ne": [{"prop":"lockDate"}, null]}, {"lt": [{"prop":"lockDate"}, {"now": []}]}]}` — same `lt`/`now` idiom `ConferenceRound.isBookingClosed` already uses (`lib/Settings/scholiq_register.json:11234-11249`).

### `ReportCard` (new)

| Field | Type | Notes |
|---|---|---|
| `learnerId` / `learnerRef` | string / uuid nullable, `$ref: LearnerProfile` | Same dual-identifier convention as `GradeEntry`/`FinalGrade`/`AttendanceRecord` |
| `reportPeriodId` | uuid, `$ref: ReportPeriod` | Required |
| `cohortId` | uuid nullable, `$ref: Cohort` | Denormalised for filtering, mirrors `AttendanceRecord.cohortId` |
| `subjectGrades[]` | array of object | `{curriculumPlanId, courseId, periodAverage: number\|null, passed: boolean\|null, teacherComment: string\|null, sourceGradeEntryIds: uuid[] $ref GradeEntry}` — `sourceGradeEntryIds` denormalised the same way `AttendanceFlag.breachingRecordIds` already is, so `ReportCardVisibilityGuard` can resolve each entry's `visibleFrom` without a second query hop |
| `attendanceSummary` | object | `{presentCount, absentUnexcusedCount, absentExcusedCount, lateCount, leftEarlyCount, attendancePercent: number\|null}` |
| `mentorComment` | string, nullable | Overall comment |
| `competencyAttainment` | array/object, nullable | Forward-compatible only — see "competency-framework is not a hard dependency" |
| `composedAt` | date-time, nullable | Set by `ReportCardComposer` |
| `docudeskRenderStatus` | enum `requested\|rendered\|failed`, nullable | Mirrors `Credential.walletOfferStatus`'s shape |
| `docudeskRequestedAt` / `docudeskDocumentRef` / `docudeskRenderError` | date-time / string / string, all nullable | Mirrors `Credential.walletOfferedAt`/`walletAttestationRef`/`walletOfferError` |
| `tenant_id` | string | |
| `lifecycle` | enum | `draft → rapportvergadering-review → finalised → published-to-parents`, plus `reopen` back to `rapportvergadering-review` |

### `ReportCardParentNotification` (new)

Structurally identical to `GradeNotification` (`lib/Settings/scholiq_register.json:10149-10260`) — `appendOnly: true`, `{event: "reportCardPublished", recipient, sourceId ($ref ReportCard), learnerId, learnerRef, idempotencyKey, visibleFrom, tenant_id}`, same `scheduled`/`visibleFrom`/`olderThan PT0S` dispatch mechanism. A separate schema rather than widening `GradeNotification.event`'s enum, because `GradeNotification.sourceId` is typed `$ref: GradeEntry` — reusing it for a `ReportCard` source would make that reference lie about what it points to.

## Decisions

### `lockDate` is a materialised calculation the composer/guard read directly, NOT an automatic lifecycle transition

**Chosen**: `ReportPeriod.isLocked` is a plain `x-openregister-calculations` boolean; `ReportPeriodComposeGuard` (on `compose`) and `ReportPeriodLockGuard` (on `GradeEntry.publish`/`republish`) read it directly via `ObjectService`, exactly the way `AttendanceFlagReportGuard` reads a linked `DataExchangeJob`'s state.

**Rejected**: an automatic `open → locked` lifecycle transition firing when `lockDate` passes. **Verified at HEAD** this is not how this codebase's own, very recent (same wave) precedent does it: `ConferenceRound.isBookingClosed`'s own description (`lib/Settings/scholiq_register.json:11238`) states outright that the verified `x-openregister-notifications` `scheduled` trigger "can only fire a notification, not a lifecycle transition ... A coordinator (or a future dedicated mechanism) must still invoke the close-booking transition explicitly." Building `ReportPeriod` on the assumption that a scheduled trigger *can* auto-transition would repeat a mistake this codebase already discovered and documented against. Instead, `ReportPeriod` never needs an explicit `locked` lifecycle state at all — `isLocked` is orthogonal to `lifecycle` (`open → composed → archived`), and a `lockDatePassed` reminder notification (mirroring `ConferenceRound.bookingAutoClosed`'s identical shape: `scheduled`, `intervalSec: 86400`, `filter: {lifecycle: "open", lockDate: {operator: "olderThan", value: "PT0S"}}`, recipients `groups: ["mentor", "coordinator"]`) nudges a human to run `compose` once it's true — the guard is the actual enforcement, the notification is just the reminder.

### Composer style borrowed from `DataExchangeRunHandler`, but NOT via `DataExchangeJob`

**Chosen**: `ReportCardComposer` is a standalone `Listener`, triggered by `ReportPeriod`'s `compose` transition (an OR `ObjectTransitionedEvent`), registered in `lib/AppInfo/Application.php` the same way `GradeRollupHandler`/`ConferenceScheduleGenerator` are. It assembles each `ReportCard` from `FinalGrade`, `GradeEntry`, `AttendanceRecord`, and `Cohort` data — the "assemble from multiple linked objects" *shape* `composeLeerplichtDossier`/`composeSwvDossier` already establish (`lib/Listener/DataExchangeRunHandler.php:645-718`).

**Rejected**: routing report-card composition through the `DataExchangeJob` queue those two methods actually live in. `data-exchange`'s own Purpose section (`openspec/specs/data-exchange/spec.md`) is explicit that `DataExchangeJob` exists for *external-registry* export/import ("these are ... integration adapters, not Scholiq schemas"). A report card is composed and consumed entirely inside Scholiq (parent portal, rapportvergadering) — it never leaves the tenant over a wire protocol, so forcing it through a `target`/`DataMappingProfile`/OpenConnector-delegating queue built for BRON/OSO/leerplicht would misuse a mechanism whose entire justification is "the wire protocol lives in OpenConnector." This change borrows the *coding pattern* (private compose methods assembling from linked objects) without borrowing the *queue* it happens to live in today.

### `ReportPeriodLockGuard` fails open when no `ReportPeriod` governs the entry

**Chosen**: the guard resolves whether `GradeEntry.period` + the entry's `curriculumPlanId`'s owning `CurriculumPlan` matches any `ReportPeriod` (by `periodCode` + `curriculumPlanIds` containment + `academicYear`) that is `isLocked`. If none matches, the transition proceeds unconditionally — a school not using report cards, or a `GradeEntry` outside any declared `ReportPeriod`'s scope, is completely unaffected.

**Rejected**: a hard lock on every `GradeEntry.publish` once *any* `ReportPeriod` anywhere is locked. That would silently change `grading`'s existing, `status: done`, `feature_tier: must` behaviour for every school regardless of whether they've adopted report cards — a much larger blast radius than this M-sized change should carry. The chosen shape mirrors `AttendanceFlagReportGuard`'s own "no job linked → allow unconditionally" fail-open posture (`lib/Lifecycle/AttendanceFlagReportGuard.php:96-99`) applied to a different cross-schema lookup.

### `ReportCardVisibilityGuard` re-checks `visibleFrom` at publish time, not just at compose time

**Chosen**: `publishToParents` re-resolves every `subjectGrades[].sourceGradeEntryIds[]` entry's *current* `visibleFrom` and blocks unless all have passed `@now`, even though composition already only pulls from `published` entries (per `grading`'s own "computed from the learner's published GradeEntries" rule, `FinalGrade` never reflects `concept` entries).

**Rejected**: trusting the snapshot taken at compose time. `ReportCard.draft` can sit in `rapportvergadering-review` for days between composition and the mentor clicking "publish" — and `CurriculumPlan.gradeVisibilityPolicy.mode: nextSchoolDay` exists precisely so a grade published at 23:40 doesn't surface until the next morning. If the guard only checked visibility at compose time, a report card composed the moment before a `visibleFrom` window opens could still be published-to-parents seconds later and leak a grade that specific mechanism was built to delay. Re-checking at the actual publish moment is the only way to honour `grading`'s own promise end-to-end.

### `competency-framework` is not a hard dependency

**Chosen**: `ReportCard.competencyAttainment` is a plain nullable field with no lifecycle coupling, no guard referencing it, and no requirement in this change's `report-card` spec that assumes it is populated. If the sibling wave-2 `competency-framework` change lands, it becomes a follow-up task to populate this field during composition; if it never lands, `ReportCard` still composes and publishes correctly with it `null`.

**Rejected**: declaring `depends_on: [competency-framework]` in this change's frontmatter. Per this worktree's own wave-2 convention, `depends_on` names *other wave-2 changes this one needs to function* — this change functions completely without competency data.

### docudesk PDF rendering is fail-soft and non-blocking, mirroring the POK precedent exactly

**Chosen**: `renderToPdf`/`rerenderToPdf` are self-loop transitions whose `requires` (`ReportCardPdfDelegationService`) **always returns `true`** — a PDF-render failure is logged and recorded in `docudeskRenderError`, never blocks `finalised`/`published-to-parents`. This mirrors `WalletRevocationPropagationService`'s fail-soft shape (`openspec/specs/certification/spec.md`'s "Revoking a Credential propagates ... fail-soft by design") applied to a render instead of a revoke.

**Rejected**: making PDF rendering a hard prerequisite for `publishToParents`. `bpv-praktijkovereenkomst` already established, for a structurally identical situation (a signed POK with no PDF yet), that "the POK's governing state is the OpenRegister object + its three `PokSignature`s, not a rendered document; a docudesk template is a follow-up, not required for the POK to be legally complete" (`openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/proposal.md:161-163`). The same reasoning applies here: the `ReportCard` OpenRegister object, its `subjectGrades[]`, and its `mentorComment` are the legally complete record; a rendered PDF is a convenience for printing/archival, not the source of truth.

### docudesk contract is proposed, not verified — flagged explicitly

**Verified**: zero docudesk endpoint, controller, or PHP call exists anywhere in this repo (`grep -rni docudesk` — see proposal.md). Unlike the openconnector seam (`EudiWalletController`, referenced concretely by `certification`'s wallet requirements and exercised today by `WalletOfferDelegationService`), there is no docudesk equivalent to point at.

**Chosen anyway**: `ReportCardPdfDelegationService` uses the identical `IClientService` + `IURLGenerator` + `IAppConfig` bearer-token seam (`scholiq.docudesk_api_token`, mirroring `scholiq.openconnector_api_token`) and POSTs a proposed `/apps/docudesk/api/v1/documents/render` contract (report-card UUID, `subjectGrades[]`, `mentorComment`, `attendanceSummary`, requested template slug) — **this endpoint is not confirmed to exist on the docudesk side**. This is called out as an explicit, tracked follow-up leaf (docudesk-side implementation), consistent with the `bpv-praktijkovereenkomst` precedent, rather than left as a silent gap or a fabricated "already works" claim.

## Security Considerations

- **`ReportPeriod`/`ReportCard` write access**: no dedicated "coordinator" role exists in `LearnerProfile.roles`' enum today — this is a **documented, repeated platform gap** in this register (`SupportRequest`/`TlvApplication`'s own `x-openregister-authorization._comment`: "`LearnerProfile.roles` has no dedicated zorgcoördinator/coordinator role today ... so creation is restricted to admin/principal at the declarative RBAC layer, the tightest posture available without inventing a role"). This change follows the identical tightest-posture pattern: `ReportPeriod`/`ReportCard` `x-openregister-authorization.create` and `x-property-rbac.read`(staff-side) are restricted to `admin`/`mentor`/`principal` — **not** a fabricated `coordinator` role. Where a notification needs to reach "the coordinator," it uses the already-precedented `x-openregister-notifications` `kind: "groups", groups: ["coordinator"]` NC-group recipient shape (verified working at `lib/Settings/scholiq_register.json:220-222` and `11289-11294`) — a different, already-proven mechanism from role-based object authorization.
- **Learner/parent read of `ReportCard`**: `x-property-rbac.read` `anyOf` admin/mentor/principal (staff) OR `learnerId == $userId` self-match (the learner, once `published-to-parents`) — mirroring `GradeEntry`/`FinalGrade`'s existing self-match shape exactly. `ReportPeriod` itself carries no per-object learner-readable content (it's the school's scheduling config, not a document), so it stays staff-only.
- **Guardian read is via the portal only, never a direct NC-group grant**: `parentReportCards` follows the exact `childJoin` reverse-scope pattern the 3 existing `parentContribution()` collections already use — a guardian never gets NC-group-level read access to `ReportCard`; portaliq's reverse `via` join is the only path, `minTrust: substantial` (matching `parentGrades`/`parentAttendance`).
- **`publishToParents` cannot be used to route around `visibleFrom`**: see "Decisions" above — `ReportCardVisibilityGuard` is the enforcement point, checked at the actual publish moment, not compose time.
- **Audit**: every `ReportCard`/`ReportPeriod` lifecycle transition emits OR's standard audit-trail entry (ADR-008, unchanged mechanism); `ReportCardParentNotification` is `appendOnly: true` for the same reason `GradeNotification` is.

## Trade-offs

- **`ReportPeriod.periodCode` as a free-text string joined against `GradeEntry.period`/`CurriculumPlan.components[].period` (also free-text)** — chosen because it's the only join key that already exists and is already populated across every subject's `CurriculumPlan`; the cost is that nothing enforces every `CurriculumPlan` in `curriculumPlanIds[]` actually declares a component with a matching `period` value — a subject whose PTA never defines a "period 1" component simply contributes no `subjectGrades[]` row for that report (documented, not silently wrong).
- **`ReportCardComposer` composes eagerly for every learner in `cohortIds[] → Cohort.learnerIds` on one `compose` transition, not incrementally per-learner** — chosen to mirror `ConferenceScheduleGenerator`'s bulk-generate-on-transition shape; the cost is a single `compose` click on a large cohort set does more work synchronously. Accepted at this size; a background-job split is a follow-up if a buyer's cohort count makes it necessary.
- **No automated `rapportvergadering` scheduling/minutes object** (unlike `parent-conferences`' `ConferenceRound`/`ConferenceSlot`) — the `rapportvergadering-review` lifecycle state is a status marker on each `ReportCard`, not a modelled meeting with its own attendee list or slot generator. Chosen because the brief scopes this change to the *report*, not meeting logistics; `parent-conferences` already owns meeting scheduling and a future change could link a `ConferenceRound` to a `ReportPeriod` if a buyer needs that, without this change needing to anticipate it.
