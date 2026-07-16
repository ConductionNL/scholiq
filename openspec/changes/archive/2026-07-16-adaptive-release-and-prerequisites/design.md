# Design: adaptive-release-and-prerequisites

## Context

Three related gaps in how Scholiq gates access to learning content, all sharing one root cause: every
gate that matters — "can this learner enrol," "can this learner open this lesson," "when does this content
become available to this specific learner" — is a question about a **(object, learner)** pair, not about
the object alone. OpenRegister's declarative dialect in this register is built entirely around per-object
computation (`proposal.md` §3 shows this precisely: every `x-openregister-calculations` expression resolves
only `@self`, `@aggregate.*`, or `@ref.*` — never a viewer/requesting-user token). That is why none of these
three problems can be solved by adding a materialised field and calling it done; each needs a small,
narrowly-scoped PHP evaluation step at the moment the (object, learner) pair actually matters, with the
*static* configuration (which prerequisites, which conditions, how many days) staying fully declarative in
the schema. This document works out where that PHP step lives for each of the three problems, why it can't
be pushed further into the declarative layer, and the guard mechanics for the one truly hard invariant
(enrolment prerequisites).

## Goals / Non-Goals

**Goals**
- Make `openspec/specs/enrolment/spec.md`'s "Validate prerequisites before persistence" requirement
  structurally true — a learner literally cannot create an `Enrolment` for a course whose prerequisites they
  have not completed.
- Give lesson/assessment authors a declarative way to require completion of a prior item, or a minimum
  score, before a later item unlocks.
- Give authors a way to delay a lesson/assessment's availability by N days relative to *each learner's own*
  enrolment date, not one shared absolute instant.
- Reuse existing precedent shapes (`ObjectCreatingEvent` listener, stateless ADR-031 resolver service,
  array-of-`$ref` relation) rather than inventing new dialect capability.

**Non-Goals**
- Competency-attainment as a release-condition kind (deferred to the `competency-framework` sibling change —
  see `proposal.md` §2).
- `Programme`-level prerequisites (deferred — see `proposal.md` DEFERRED_QUESTIONS §2; no object exists to
  enforce against today).
- A self-service course catalog page with a disabled "Enrol" button (deferred — no such page exists yet in
  `src/manifest.json`/`src/views/` to attach the check to; see `proposal.md`'s course-management "What
  Changes").
- Fixing `AssessmentResult`'s missing `x-openregister-calculations` block (a pre-existing gap in the
  `assessment` capability, noted but explicitly out of scope — `LessonReleaseEvaluator` computes the score
  sum itself instead of depending on it).
- Retrofitting `GradeEntry`'s soft-publish workflow into the minimum-score release condition (rejected below).

## Data Model

```
Course.prerequisiteCourseIds[] ──▶ Course            (array of $ref, mirrors CurriculumPlan.requiredCourseIds)
        │
        ▼ read at Enrolment-creation time (NOT a lifecycle transition — see "The prerequisite guard" below)
EnrolmentPrerequisiteListener  ──▶ queries Enrolment{learnerId, courseId: <prereq>, lifecycle: completed}
        │  blocks (ObjectCreatingEvent::stopPropagation) if any required prerequisite has no completed row
        ▼
     Enrolment created (pending)

Lesson.releaseConditions[]      { kind: lesson-completed | assessment-min-score, lessonId?, assessmentId?, minScore? }
Lesson.availableAfterDays        nullable int ≥ 0, relative to the learner's OWN Enrolment.created
Assessment.releaseConditions[]   same shape as Lesson's
Assessment.availableAfterDays    same shape as Lesson's
        │
        ▼ read at launch/render time, per (item, learner) — NEVER materialised on the item itself
LessonReleaseEvaluator::evaluate(item, learnerId, enrolment) -> {available: bool, reason: ?string}
        │  lesson-completed  → XapiStatement{lessonId, verified_actor_id: learnerId, verb: completed|passed}
        │  assessment-min-score → AssessmentResult{assessmentId, learnerId, lifecycle: graded},
        │                          sum(responses[].autoScore ?? manualScore) >= minScore
        │  availableAfterDays → now >= enrolment.created + N days
        ▼
LessonReleaseController::status()  (GET, per-object, learner-or-staff authorized)
        ▼
LessonPlayer.vue  — calls before rendering ANY contentType; renders locked state + reason if unavailable
```

### `Course.prerequisiteCourseIds` (course-management delta)

Array of `$ref Course` UUIDs, additive, default `[]`. Structurally identical to
`CurriculumPlan.requiredCourseIds`/`electiveCourseIds` (`lib/Settings/scholiq_register.json:2993+` region)
and `Course.programmeIds` (`:937+`) — this register already has the "array of foreign-key UUIDs" idiom
established twice; a third instance is not a new pattern.

### `Lesson.releaseConditions` / `Assessment.releaseConditions`

Same inline object shape on both schemas (this register does not use `$defs`/shared sub-schemas anywhere
observed — every array-of-objects shape, e.g. `CurriculumPlan.components`/`.periods`, is defined inline
per-schema, so duplicating the shape across `Lesson` and `Assessment` matches convention rather than
diverging from it). `kind` is a discriminator (`lesson-completed | assessment-min-score`); AND semantics
across the array — an item with two conditions requires both. Empty/absent array = today's behaviour,
unchanged.

### `Lesson.availableAfterDays` / `Assessment.availableAfterDays`

A plain nullable integer — **this part genuinely is declarative and materialisable**, because it is a
*duration*, the same for every learner, not a *datetime*. What cannot be materialised is the per-learner
resolved instant (`enrolment.created + N days`), because that instant differs per learner. This is the
precise distinction the brief asked design.md to work out — see "Why per-learner relative time can/cannot
be a declarative calculation" below.

## The prerequisite guard: why it is an `ObjectCreatingEvent` listener, not a `Lifecycle/*Guard.php`

Every existing `lib/Lifecycle/*Guard.php` in this codebase (`CohortMembershipGuard`, `CoursePublishGuard`,
`AssessmentPublishGuard`, …) is wired via a schema's `x-openregister-lifecycle.transitions.<name>.requires`
clause, and every one of those transitions has a non-null `from` — they guard movement *between* two
already-persisted states. Tracing the OR core engine confirms this is not incidental:

- **Create path**: `MagicMapper::insertObjectEntity()` dispatches `ObjectCreatingEvent`
  (`apps/openregister/lib/Db/MagicMapper.php:5810`). The only listener that reacts to it in OR core,
  `LifecycleInitialStateListener` (`apps/openregister/lib/Listener/LifecycleInitialStateListener.php:90-108`),
  force-sets the schema's declared `initial` lifecycle value and stops there — it never resolves a
  `requires` guard and has no notion of "transitioning from nothing."
- **Update path**: `MagicMapper::updateObjectEntity()` dispatches `ObjectUpdatingEvent`
  (`apps/openregister/lib/Db/MagicMapper.php:5947`). `LifecycleValidationListener` is the only listener that
  resolves `requires` guards (`apps/openregister/lib/Listener/LifecycleValidationListener.php:218-219`), and
  it explicitly bails when there is no prior object: `if ($oldObject === null) { return; }`
  (`:107-112`, comment: "No prior state — nothing to validate against. Initial state is enforced by
  LifecycleInitialStateListener.").

So a `requires`-style guard on `Enrolment`'s `x-openregister-lifecycle` can never fire before the object's
first persist, no matter what transition name is invented — there is no `from` state for a brand-new object
to have come from. The only real creation-time hook is the raw `ObjectCreatingEvent`, and it genuinely
supports blocking: it `implements StoppableEventInterface`, and `MagicMapper::insertObjectEntity()` checks
`isPropagationStopped()` after dispatch and throws `HookStoppedException` (aborting the insert) when a
listener calls `stopPropagation()` (`apps/openregister/lib/Db/MagicMapper.php:5802-5827`). Two apps in this
fleet already use exactly this mechanism as a creation-time business-rule veto:
`apps-extra/decidesk/lib/Listener/SubmissionDeadlineListener.php` (rejects a `motion`/`amendment` created
after its meeting's submission deadline) and `apps-extra/larpingapp/lib/Listener/CharacterRequirementListener.php`
(rejects character creation on failed game-rule checks). `EnrolmentPrerequisiteListener` follows
`SubmissionDeadlineListener`'s shape line for line: resolve the schema slug from the creating entity, bail
early (allow) when the rule doesn't apply, look up the referenced `Course`, check the rule, and either allow
or `setErrors()` + `stopPropagation()`, wrapped in a `try/catch` that fails open on infrastructure errors and
fails closed on the rule itself — the same deliberate split `SubmissionDeadlineListener`'s own docblock
documents ("the deadline gate is a submission RULE, not an auth guard... Infrastructure failures during
lookups log a warning and allow").

## Why per-learner relative time can — and can't — be a declarative calculation

The `x-openregister-calculations` dialect resolves `@self.<field>` (the object's own properties),
`@aggregate.<name>` (a cross-schema count/aggregate pre-filtered by `@self.id`, e.g.
`Course.enrolledLearners`), and `@ref.<field>.<prop>` (a single foreign-key lookup, e.g.
`@ref.assignment.latePenaltyPercent` on `Submission`). None of these tokens carry "who is asking" — every
example in the register (extracted by grepping every `@`-prefixed token across all 59 schemas) resolves
purely from the object graph, never from session/request context. This is a real, structural limit, not an
oversight: `x-openregister-calculations` fields are *materialised* — stored as a value on the row, computed
once (or on `calculatedChange`) and read many times by any viewer. A value that legitimately differs per
viewer cannot be a single materialised column on a row every viewer shares.

`GradeEntry.visibleFrom` (the wave-1 precedent) sidesteps this because it does NOT need a per-viewer value —
one `GradeEntry` already belongs to exactly one learner (`GradeEntry.learnerId`), so "when does *the*
learner see it" has exactly one answer per row, safely materialisable.

`Lesson`/`Assessment` are the opposite shape: one row, shared by every learner enrolled in the course. Their
own `availableAfterDays` (a duration) is safely materialisable — it truly is the same number for every
learner. But "is it past that duration *for learner X*" requires combining that duration with `X`'s own
`Enrolment.created` timestamp, and there is no schema position where that combined, per-learner answer could
live without either (a) creating one row per (lesson, learner) pair — a real design, rejected below — or (b)
computing it on demand. This change takes (b): `LessonReleaseEvaluator` reads `Lesson.availableAfterDays`
and the caller's own `Enrolment` (already resolved for authorization purposes in
`LessonReleaseController`) and does the arithmetic in PHP, at request time, exactly the same way
`XapiCompletionHandler` already resolves other per-learner facts (which `XapiStatement`s exist for learner
X) by querying directly rather than relying on a materialised field. No new architectural pattern is
introduced; this is the same "PHP resolves the per-viewer question, the schema only holds static
configuration" split the prerequisite guard uses.

## Rejected Alternatives

- **A per-(lesson, learner) `LessonProgress` row materialising `availableFrom`/`isAvailable` per learner.**
  Rejected for this M-sized change — it would require backfilling one row per active `(learner, lesson)`
  pair on every enrolment and every new lesson publish (a write-amplification pattern this register avoids
  elsewhere — e.g. `FinalGrade` is computed on demand from `GradeEntry`s, not backfilled per learner up
  front). On-demand evaluation at launch time is cheaper and has no backfill/staleness problem. Reconsider
  if a future requirement needs to *list* "everything available to me right now" cheaply across hundreds of
  lessons — at that scale, a materialised per-learner view becomes worth its write cost.
- **Gate `minScore` against `GradeEntry.value` instead of `AssessmentResult.responses[]` directly.**
  Rejected for scope: `GradeEntry` only exists once `AssessmentGradeGuard` has run and the grading capability
  has soft-published it (`GradeEntry.visibleFrom`/lifecycle), which is a second workflow with its own
  latency and review gate. Coupling drip/release logic to that workflow is arguably more pedagogically
  correct (don't reveal unlock eligibility before the teacher formally publishes the grade) but adds a
  second capability's lifecycle into this evaluator's dependency graph for a scope that does not need it.
  Computing the sum directly from the already-`graded` `AssessmentResult` is self-contained and correct for
  "has the learner in fact scored enough," even if it can reveal an unlock slightly before the grade is
  formally visible in the gradebook. Flagged as a legitimate follow-up if that pedagogical distinction turns
  out to matter.
- **A `requires`-style `Lifecycle/*Guard.php` for the prerequisite check**, as the task brief's own phrasing
  first suggested. Rejected with evidence — see "The prerequisite guard" above; OR's engine structurally
  cannot resolve a `requires` guard before an object's first persist.
- **`Programme.prerequisiteProgrammeIds`.** Rejected for this change — see `proposal.md`
  DEFERRED_QUESTIONS §2. Adding the field without an enforcement point would repeat the exact
  spec-promises-code-doesn't-deliver pattern this change exists to close.
- **A `competency-attained` release-condition kind now.** Rejected — `CompetencyAttainment` does not exist
  in this worktree; referencing an unverified sibling change's schema would violate this task's own
  ground-truth requirement. See `proposal.md` §2 and DEFERRED_QUESTIONS §3.

## Security / Authorization Posture

- `EnrolmentPrerequisiteListener` runs on every `Enrolment` creation regardless of `source`
  (`self|manager|hr|bulk|migrated|system`) — uniform, fail-closed on the rule, fail-open on infrastructure
  errors (see "The prerequisite guard" above). It never authenticates or authorizes the caller itself; that
  remains whatever `x-openregister-authorization` already governs `Enrolment` creation — this listener only
  adds a business-rule veto on top.
- `LessonReleaseController::status()` requires `#[NoAdminRequired]` (any authenticated user may call it) but
  performs its own per-object check: the caller must hold an `Enrolment` for the lesson's/assessment's
  `courseId`, or hold an `admin`/`teacher`-equivalent role — mirroring the authorization note already on
  `LtiToolPlacementController::launch()` ("per-object visibility is whatever already gates the
  placement/Lesson"), made explicit here since this endpoint computes new information (a lock/unlock
  decision) rather than just proxying an existing read.
- No new data is exposed: `release-status` responses carry only `{available, reason, availableAt}` — never
  the raw `releaseConditions` configuration or another learner's `AssessmentResult`/`XapiStatement` data.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects (`lib/Settings/scholiq_register.json`); no new database tables.
- No pass-through CRUD controller: `LessonReleaseController` computes a genuine gate decision, it does not
  proxy an existing object read; `prerequisiteCourseIds`/`releaseConditions`/`availableAfterDays` are all
  plain schema fields edited through the existing manifest-driven `Course`/`Lesson`/`Assessment` forms — no
  bespoke create/update endpoint added for them.
- Declarative first: the only PHP added is one `ObjectCreatingEvent` listener (a cross-object invariant no
  JSON-logic expression can check — it must query the `Enrolment` collection) and one stateless resolver
  service plus its thin controller (a genuinely per-viewer computation the calculation dialect cannot
  express, per "Why per-learner relative time..." above) — both are the same class of ADR-031 exception
  already accepted for `GradeFormulaEvaluator`/`BsaProgressEvaluator`/`SubmissionDeadlineListener`.
- UI: `LessonPlayer.vue` gains a call + a locked-state render; no new manifest custom view needed beyond
  that, since `Course`/`Lesson`/`Assessment` forms for the new fields are fully expressible by the existing
  manifest-driven create/edit pattern.
- i18n keys in English; SPDX docblocks on all new PHP files.
