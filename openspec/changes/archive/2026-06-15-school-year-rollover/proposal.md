## Why

Every Dutch school performs the jaarovergang each July: pupils are promoted to the next year's classes (klas 2A → 3A), doubleurs stay behind, examenklas leavers graduate or flow out to another school, enrolments are carried over or closed, and last year's structure is archived. ParnasSys, Somtoday, and Magister all ship a dedicated overgang/bevordering workflow because doing this by hand for 800 pupils is the single biggest admin pain of the school year. Scholiq has the structural pieces — `Cohort` (with `academicYear`, `ncGroupId`, `learnerIds`), `Enrolment` (with `cohortId`), Course "Clone for next year", cohort lifecycles — but no workflow that connects them. The 2026-06-11 feature re-evaluation lists "school-year rollover / cohort promotion" as the top expected-gap: "a real-school admin pain point every July" with nothing in description, specs, or changes.

The same machinery serves the corporate wedge: an annual compliance cycle (new training year, re-enrol everyone whose mandatory training renews, retire last year's cohorts) is the identical operation with different vocabulary.

## What Changes

- **New OR schema `RolloverPlan`**: `fromAcademicYear`, `toAcademicYear`, cohort `mappings[]` (`{fromCohortId, action: promote | graduate | dissolve, toCohortName, toProgrammeId?, toCourseId?}`), `learnerOverrides[]` (`{learnerId, action: promote | retain | graduate | outflow, note}`), `dryRunReport`, `executedBy`, `executedAt`, lifecycle (`draft → previewed → executing → completed | failed`), `tenant_id`.
- **Rollover wizard**: a manifest page plus one custom Vue view (the mapping editor — same "custom view only where a manifest page can't render it" exception school-structure grants the cohort timetable). The wizard proposes a default mapping by incrementing the leerjaar embedded in cohort names and lets the admin adjust per cohort and per learner.
- **Dry-run preview (mandatory gate)**: executing requires a prior side-effect-free preview producing a per-cohort report — promotions, retentions, graduations, outflows, new cohorts to create, enrolments to carry over, NC groups to sync. The `previewed → executing` transition is only reachable from a plan whose preview matches its current mappings.
- **Execution semantics**: create next-year Cohorts (`academicYear = toAcademicYear`), move `learnerIds` per mapping + overrides, create/sync the backing NC groups (`ncGroupId`), archive `from`-year cohorts via their lifecycle, and carry over incomplete `mandatory: true` Enrolments to the learner's new cohort context (completed enrolments stay closed under the old year). Courses are NOT cloned by the rollover — the existing Course "Clone for next year" remains the content-side operation; the wizard links to it.
- **Doubleurs (retain)**: a retained learner joins the new-year cohort of the *same* leerjaar instead of the promoted one.
- **Outflow**: learners leaving the school are flagged for an OSO transfer — the rollover queues the existing data-exchange `target=oso` job (with its `pending-parent-review` gate; parents resolved from `LearnerProfile.parentIds`, contact data stays in the NC addressbook). No new wire code, no new parent schema.
- **Auditability + resumability**: execution runs as a background job; every learner movement and cohort transition emits an OR audit-trail entry (ADR-008); a failed run records progress in the plan and is resumable without duplicating already-created cohorts or carried-over enrolments (idempotent per mapping).
- **Notification**: one `transition`-trigger rule in the verified dialect — plan reaches `completed`/`failed` → notify `executedBy` (dialect per the in-flight `scholiq-notifications` migration; referenced, not re-specified).
- **Out of scope**: next-year timetabling/Session scheduling (sessions belong to school-structure; calendar items live in NC Calendar), formal bevorderingsbesluit deliberation (the rapportvergadering decision happens outside; the wizard records its outcome via overrides), BRON/ROD year-start delivery (existing data-exchange profile).

## Capabilities

### New Capabilities

- `school-year-rollover`: plan, preview, and execute the jaarovergang — bulk cohort promotion with per-learner overrides (retain/graduate/outflow), enrolment carry-over, NC-group sync, old-year archival, OSO outflow handoff, all audit-trail-backed and resumable.

### Modified Capabilities

*(none — school-structure's Cohort/Enrolment schemas already carry `academicYear`/`cohortId`; the rollover consumes them without shape changes)*

## Impact

- `lib/Settings/scholiq_register.json` — new `RolloverPlan` schema + lifecycle + one verified-dialect notification rule.
- `src/manifest.json` — Rollover wizard page; navigation entry under School Structure.
- New custom Vue view for the mapping editor; new background job for execution (registered via the proper boot mechanism — NOT `IRegistrationContext::registerJob`, per the fleet jobs-never-ran bug).
- New service for preview/execution semantics (cohort creation, learner movement, NC group sync via `IGroupManager`, enrolment carry-over, OSO job queuing).
- Reuses unchanged: Cohort/Enrolment schemas, Course clone, data-exchange OSO profile + parent-review gate, OR audit trail.
- Depends on: nothing new. The OSO outflow path requires the data-exchange OSO OpenConnector connection to be configured; absent that, outflow learners are flagged with a pending action instead of a queued job.
