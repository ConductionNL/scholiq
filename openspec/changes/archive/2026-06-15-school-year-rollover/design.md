## Context

Scholiq's school-structure spec gives Cohorts an `academicYear`, a backing NC group (`ncGroupId`), `learnerIds`, and a lifecycle; Enrolment carries `cohortId` and `mandatory`/`dueDate`; Course has "Clone for next year". What is missing is the orchestration that every Dutch LAS ships: a guided jaarovergang that moves hundreds of learners across cohorts in one audited, reversible-by-preview operation. The operation is destructive at scale (wrong mapping = 800 pupils in wrong classes, wrong NC group ACLs on every course material), so the design centres on a *plan object* with a mandatory dry-run gate rather than an imperative "promote now" button.

Constraints honoured:

- Storage, lifecycle, audit trail, RBAC, notifications: OpenRegister (ADR-008/ADR-022/ADR-031). The plan is an OR object; the notification rule uses the verified dialect from the in-flight `scholiq-notifications` migration.
- Parents/guardians are NC addressbook contacts reached via `LearnerProfile.parentIds`; the OSO outflow reuses data-exchange's `pending-parent-review` gate — no new contact or consent schema.
- Sessions/timetables are not rolled over; next-year scheduling is a school-structure concern and calendar items belong to NC Calendar.
- Pseudonymisation fields (`eckId`, `schoolId`, `bsnEncrypted`) travel untouched on the LearnerProfile; the rollover never rewrites identity fields.

## Goals / Non-Goals

**Goals:**
- One plan object that captures the whole jaarovergang and survives interruption.
- Side-effect-free preview with per-cohort counts as a hard gate before execution.
- Correct semantics for the four learner outcomes: promote, retain (doubleur), graduate, outflow (OSO).
- Mandatory-enrolment carry-over so compliance coverage does not silently reset in August.
- NC group sync so course-material ACLs follow the new cohorts.
- Idempotent, resumable execution with a full audit trail.

**Non-Goals:**
- Bevorderingsbesluit deliberation/voting (rapportvergadering happens outside; overrides record the outcome).
- Course/Lesson cloning (existing Course "Clone for next year").
- Timetabling, Session creation, room planning for the new year.
- BRON/ROD year-start delivery (existing data-exchange profile, run separately).
- Cross-tenant moves; a rollover is scoped to one `tenant_id`.

## Decisions

**D1: Plan-object orchestration, not an imperative endpoint.**
`RolloverPlan` is an OR object with lifecycle `draft → previewed → executing → completed | failed`. Editing mappings in `previewed` drops the plan back to `draft` (the preview no longer matches), making the dry-run gate structural rather than advisory.

**D2: Default mapping by leerjaar increment, always human-confirmed.**
The wizard proposes `2A → 3A` style mappings by parsing the leading leerjaar digit from cohort names and matching the Programme; anything unparseable maps to `action: null` and blocks preview until resolved. No silent guessing.

**D3: New cohorts are created, old cohorts are archived — never mutated in place.**
The from-year Cohort keeps its `learnerIds` as the historical record and transitions to `archived`; the to-year Cohort is a new object. This preserves last year's group composition for audit/OSO purposes and matches the Course clone philosophy.

**D4: Enrolment carry-over is limited to incomplete mandatory enrolments.**
`mandatory: true` enrolments not in a terminal lifecycle get `cohortId` repointed to the learner's new cohort (dueDate untouched); completed/withdrawn enrolments stay attached to the archived cohort. Non-mandatory carry-over is a per-mapping opt-in flag. This keeps compliance coverage continuous without resurrecting finished work.

**D5: Outflow = flag + queue, not inline transfer.**
`learnerOverrides[action: outflow]` marks the learner and queues a data-exchange `DataExchangeJob {direction: export, target: oso}` per learner, which then follows its own spec (dossier composition, `pending-parent-review`, Edukoppeling via OpenConnector). If the OSO connection is unconfigured, the rollover records a pending-action list instead of failing the run.

**D6: Execution is a chunked background job with per-mapping idempotency.**
The job processes mappings sequentially, recording per-mapping completion in the plan. Re-running a `failed` plan skips completed mappings; cohort creation is idempotent on `(toAcademicYear, toCohortName, tenant_id)`. Registered via the boot-time-correct job registration path (the fleet `IRegistrationContext::registerJob` bug means jobs registered wrongly never run — verified at apply time).

**D7: NC group sync uses `IGroupManager` directly, scoped to cohort groups.**
New cohort → new NC group (or reuse by deterministic name); members synced to the new `learnerIds`. Old groups are left intact on archived cohorts (read access to last year's materials is an institution policy, not deleted by default).

**D8: One completion notification, verified dialect.**
`trigger: {type: "transition"}` on RolloverPlan terminal transitions, recipient `{kind: "field", field: "executedBy"}`, `subject{nl,en}`. The per-learner fan-out ("you are now in 3A") is deliberately NOT a rollover notification — it would spam hundreds of users in one run; schools communicate placement via their own channels.

## Risks / Trade-offs

- **Wrong mapping at scale** → mandatory preview gate (D1) + unparseable-name block (D2) + everything reversible by archiving the new cohorts and unarchiving the old (documented runbook, not an automated undo in v1).
- **Partial failure mid-run** → per-mapping idempotency + resumable `failed` state (D6); the audit trail shows exactly which learners moved.
- **NC group explosion** → deterministic group naming + reuse keeps group count linear in cohorts, not in runs.
- **OSO dependency** → degraded mode (pending-action list) when the OpenConnector OSO connection is absent (D5).
- **Examenklas edge cases** (staatsexamen, doorstroom vavo) → covered by per-learner overrides; no special-case automation in v1.

## Migration Plan

1. Add `RolloverPlan` schema + lifecycle + notification rule to `lib/Settings/scholiq_register.json`.
2. Implement the preview/execution service + background job.
3. Add the wizard manifest page + mapping-editor custom view + navigation entry.
4. nl/en i18n; PHPUnit on preview/execution semantics; Playwright on the wizard flow.
5. Bump `appinfo/info.xml` version.

Rollback: remove schema + pages + job; plans already executed remain as historical OR objects (harmless), archived cohorts can be manually unarchived.

## Open Questions

- Should archived-cohort NC groups be emptied after a retention period (material-access policy)? Defaulting to "leave intact"; revisit with the first pilot school.
- Carry-over of `AttendanceThreshold` counters across years (leerplicht counts are per school year — lean: reset, thresholds are year-scoped). Confirm with the attendance capability owner at apply time.
