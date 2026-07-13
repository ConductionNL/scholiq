---
kind: code
depends_on: []
---

## Why

The `grading` spec's soft-publish flow already promises to shield learners/parents from the raw
per-grade pings Magister/SOMtoday are notorious for: `openspec/specs/grading/spec.md:19` — "soft-publish
lets a teacher review the cohort distribution before any parent/learner notification fires" — and
`openspec/specs/grading/spec.md:26` — "Only `published` entries notify (per the learner's / parent's
notification-preference: instant ping vs daily digest...)". That promise stops at *whether* a grade
notifies and *how often* (instant vs digest) — it says nothing about **when in the day** a night-time
batch publish is allowed to surface. A teacher who finishes grading at 23:40 and hits "publish" today
notifies instantly (or on tomorrow's fixed digest time, which is itself unconfigurable) — there is no
concept of a visibility window at all. `grep -n "quiet" openspec/specs/*/spec.md` returns zero hits
(re-verified), and `grep -n "visibleFrom" lib/Settings/scholiq_register.json` returns exactly one field,
on `Assignment` (`lib/Settings/scholiq_register.json:3838-3845` — "Earliest datetime at which the
assignment is visible to learners. Null = immediately visible.") — **not** on `GradeEntry`. `GradeEntry`'s
own schema block (`lib/Settings/scholiq_register.json:5348-5553`) has no `visibleFrom`-shaped field.

Re-verifying the requirement text itself turned up a second, adjacent gap: `openspec/specs/grading/spec.md:48`
claims `GradeEntry` "has `x-openregister-lifecycle`... and `x-openregister-notifications` keyed so a
re-publish/backfill doesn't double-notify" — but a direct read of `lib/Settings/scholiq_register.json:5501-5553`
(the full `GradeEntry` block, from `x-openregister-lifecycle` through the closing `x-property-rbac`) shows
**no `x-openregister-notifications` block exists on `GradeEntry` at all**. The only notification path that
exists today is `GradeNotification` (`lib/Settings/scholiq_register.json:7793-7889`), whose own description
says it is explicitly "a lightweight **parent**-notification record" — `lib/Listener/GradeRollupHandler.php:116-145`
(`handleGradeEntryPublished` → `fanOutParentNotifications`) confirms it is only ever created for parents, via
a PHP bridge, because "OR's declarative notification system addresses a single field, not a related array"
(`lib/Listener/GradeRollupHandler.php:9-12`). The **learner's own** "your grade is published" notification —
the thing `openspec/specs/scholiq-notifications/spec.md:68-72` already describes as a scenario ("a
grade/final-grade record gaining learner-visible status... a verified-dialect rule delivers an
`nc-notification` to the affected learner") — has no declared rule anywhere in the register today. There is
no field or mechanism in the current code that could answer "has this GradeEntry gained learner-visible
status yet?" — `lifecycle: published` is the only signal that exists, and it fires the instant the teacher
clicks publish, day or night.

`GradeNotification.gradePublished` (`lib/Settings/scholiq_register.json:7868-7887`) fires on
`trigger.type: "created"` — synchronously with the PHP write, i.e. the moment `GradeRollupHandler` runs,
which is the moment the teacher published. No time-of-day gate exists anywhere in the pipeline.

**Evidence for demand**: gap-report insight 1173 ("Neither Magister nor Somtoday can schedule grade
visibility — the top emotional pain", high-confidence), corroborated by insights 933 and 1186; stories
`grade-publish-schedule-windows` (10103, external-review) and `deadline-lead-time-guarantee` (10118);
journeys 1751 and 1645; competitor check: Magister "Cijfertijd" has an instant/close toggle but no
scheduling; the AOb's 24/7-school-pings tracking article and Somtoday's 1.1/5 Trustpilot rating (both cited
in the gap report) name unscheduled night-time delivery specifically. This is the #1 emotional-pain item
in the Dutch K-12 review mining and a direct wedge differentiator against both VO incumbents.

The paired leaf item (#2 in the gap report, `notification-delivery-windows`) covers the OpenRegister-side
per-user quiet-hours dispatcher gate as a **separate, cross-repo change in the `openregister` project**
(not created here — `depends_on` in this proposal's frontmatter is same-repo only per the OpenSpec
convention this worktree uses). Scholiq's leaf half of that item — a settings surface plus a deadline
lead-time guarantee for scholiq's own scheduled reminder rules — is in scope here because
`openspec/specs/scholiq-notifications/spec.md:74-75` ("Notification delivery MUST honor the per-user
override preference... The scholiq per-user settings panel MUST be the surface") already names the exact
panel (`src/views/ScholiqNotificationSettings.vue`) that must grow the quiet-hours control, and because the
only `scheduled`-trigger reminder rules that exist today (`Enrolment.dueReminder` /
`Enrolment.overdue`, `lib/Settings/scholiq_register.json:1560-1610`, both `intervalSec: 86400`) currently
declare no lead-time guarantee at all — a quiet-hours deferral could silently push a reminder past its own
deadline.

## What Changes

### Grading — scheduled visibility window (specs/grading)

- **`CurriculumPlan`** (`lib/Settings/scholiq_register.json:2669+`) gains a new nullable
  `gradeVisibilityPolicy` object: `{ mode: "immediate" | "nextSchoolDay", time: "10:00", timezone:
  "Europe/Amsterdam" }`. `null` (the default for every existing plan) resolves to `immediate` —
  today's behaviour is unchanged unless a school opts in.
- **`GradeEntry`** (`lib/Settings/scholiq_register.json:5348+`) gains a new nullable `visibleFrom`
  date-time field, mirroring `Assignment.visibleFrom`'s shape (`lib/Settings/scholiq_register.json:3838-3845`).
  A teacher may set it explicitly as part of the same batch-publish action (an override); when left
  unset, it is resolved server-side from the `CurriculumPlan.gradeVisibilityPolicy` at the moment the
  entry transitions to `published`.
- **New `lib/Grading/GradeVisibilityResolver.php`** — a stateless service (ADR-031 exception, the same
  shape as the existing `GradeFormulaEvaluator` exception named in
  `openspec/specs/grading/spec.md:64`): given an optional teacher override, the `CurriculumPlan`'s
  `gradeVisibilityPolicy`, and the publish timestamp, returns the resolved `visibleFrom`. `nextSchoolDay`
  computes the next non-weekend day at `policy.time` in `policy.timezone` (rolling to the day after if
  the publish moment is already past that time on the same day).
- **`GradeRollupHandler::handleGradeEntryPublished`** (`lib/Listener/GradeRollupHandler.php:125-145`)
  calls the resolver once per publish, persists the resolved `visibleFrom` back onto the `GradeEntry` via
  `ObjectService` (the same write pattern this handler already uses for `FinalGrade` and
  `GradeNotification`), and stamps the identical value onto every `GradeNotification` row it fans out to
  parents (new `GradeNotification.visibleFrom` field). `FinalGrade` recompute (`recomputeFinalGrade`,
  same method, unchanged) stays synchronous and immediate — the roll-up calculation is unaffected; only
  learner/parent-facing notification is gated.
- **Close the missing-rule gap**: `GradeEntry` gains its first-ever `x-openregister-notifications` block
  (a `gradePublished` rule, verified-dialect, `recipients: [{kind:"field", field:"learnerId"}]`) — the
  direct learner notification the spec has described since `openspec/specs/scholiq-notifications/spec.md:68-72`
  but that has never actually been declared. Its trigger — and `GradeNotification.gradePublished`'s
  existing trigger (`lib/Settings/scholiq_register.json:7869-7872`, currently `type: "created"`) — both
  move to the `scheduled` trigger type (verified per `openspec/specs/scholiq-notifications/spec.md:16`),
  gated on `visibleFrom` rather than the `created`/write instant. `GradeNotification.idempotencyKey`
  (already present, `lib/Settings/scholiq_register.json:7856-7860`) prevents duplicate delivery across
  the engine's re-evaluation. **Assumption flagged below**: the exact scheduled-trigger sub-key that binds
  the fire time to a schema field is inferred, not yet observed in the register (existing `scheduled`
  examples are interval-poll-based — `Enrolment.dueReminder`/`overdue`, `lib/Settings/scholiq_register.json:1560-1610`).
- **Out of scope**: hard read-time gating of the grade *value* itself before `visibleFrom` (a
  `x-property-rbac` time-window check). `Assignment.visibleFrom` establishes the schema-field precedent
  but is itself unenforced anywhere in code today (`grep -rn visibleFrom src/` — zero hits) — this change
  does not extend that gap, it only gates the notification-dispatch timing, which is the concrete,
  testable "never pings at 3 a.m." promise the gap report asks for. Full portal/read-time visibility
  gating is a natural, larger follow-up.

### scholiq-notifications — quiet hours adoption + deadline lead-time (specs/scholiq-notifications)

- **Cross-repo dependency (prose only, not `depends_on`)**: the OpenRegister project's
  `notification-delivery-windows` change (gap-report item #2) is what actually implements per-user
  quiet-hours suppression at the dispatcher. This change adopts it on the scholiq side once available;
  per ADR-031, scholiq declares rules and consumes OR's dispatcher preferences — it does not implement
  suppression mechanics itself.
- **`src/views/ScholiqNotificationSettings.vue`** (currently a per-`(schema, notification)` enable/disable
  toggle list reading/writing `GET`/`PUT /apps/openregister/api/notification-preferences` — verified at
  `src/views/ScholiqNotificationSettings.vue:104-168`) gains a quiet-hours control section that reads/writes
  whatever global per-user preference surface the OR `notification-delivery-windows` change exposes on
  that same API family. No new scholiq-local preference schema — same "apps consume OR abstractions"
  posture the file's own docblock already states (`src/views/ScholiqNotificationSettings.vue:7-11`).
- **Deadline lead-time guarantee**: any `scheduled`-trigger reminder rule tied to a deadline field (today:
  `Enrolment.dueReminder` / `Enrolment.overdue`, `lib/Settings/scholiq_register.json:1560-1610`) MUST be
  declared with enough lead time that the first reminder still lands before the deadline even after a
  quiet-hours deferral. This change brings the two existing rules into compliance as the concrete
  worked example.

## Capabilities

### Modified Capabilities

- `grading`: adds the scheduled-visibility-window schema shape (`GradeEntry.visibleFrom`,
  `CurriculumPlan.gradeVisibilityPolicy`) and ties notification dispatch timing to it; closes the
  pre-existing gap where `GradeEntry` had no declared learner-direct notification rule at all.
- `scholiq-notifications`: adopts OR's per-user quiet-hours preference surface (cross-repo) and adds a
  deadline lead-time guarantee for scheduled reminder rules.

## Impact

- **`lib/Settings/scholiq_register.json`** — `CurriculumPlan.gradeVisibilityPolicy` (new),
  `GradeEntry.visibleFrom` (new) + `GradeEntry.x-openregister-notifications.gradePublished` (new),
  `GradeNotification.visibleFrom` (new) + `GradeNotification.x-openregister-notifications.gradePublished.trigger`
  (changed), `Enrolment.dueReminder`/`overdue` lead-time alignment.
- **`lib/Grading/GradeVisibilityResolver.php`** (new) — stateless ADR-031 exception service.
- **`lib/Listener/GradeRollupHandler.php`** — `handleGradeEntryPublished` resolves + persists
  `visibleFrom` on `GradeEntry` and `GradeNotification`.
- **`src/views/ScholiqNotificationSettings.vue`** — quiet-hours control section.
- **`src/views/GradeImpactDetail.vue`** — small addition to the existing lifecycle badge
  (`src/views/GradeImpactDetail.vue:43-45`) showing a "scheduled" state while `now < visibleFrom`.
- No PHP CRUD controllers added; no TimedJob added (per `openspec/specs/grading/spec.md:63-64`).

## DEFERRED_QUESTIONS

1. **Exact `scheduled` trigger field-binding syntax.** I assumed a `scheduled` trigger can bind its fire
   time to a schema field (`visibleFrom`) rather than only a recurring `intervalSec` poll — every verified
   example in the register today is interval-based (`Enrolment.dueReminder`/`overdue`). If OR's engine
   only supports interval polling, the fallback is a short poll (e.g. every 5 minutes) filtered on
   `visibleFrom <= now`, relying on `GradeNotification.idempotencyKey` for exactly-once delivery — noted
   as an implementation task either way.
2. **`nextSchoolDay` weekend/holiday awareness.** Scoped to "not Saturday/Sunday" only for this S-sized
   change; true academic-calendar/holiday awareness (school-structure `academicYear` breaks) is a
   follow-up, not blocking the core "don't ping at 3 a.m." promise.
3. **Quiet-hours preference API shape** is owned by the not-yet-built OR `notification-delivery-windows`
   change; `ScholiqNotificationSettings.vue`'s new control section is scoped to "read/write whatever that
   endpoint exposes" and may need a follow-up PR once that endpoint's real contract lands.
