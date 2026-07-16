---
kind: code
depends_on: []
---

## Why

Scholiq has no way for a school to run a formal course/module evaluation cycle, aggregate the results
per course or teacher, or close the loop with a recorded improvement action — even though the raw survey
mechanics it would sit on top of already exist for free elsewhere in the Nextcloud ecosystem.

- **The abstraction boundary that matters: Nextcloud Forms is a real, usable survey instrument today, and
  this change must not rebuild it.** Forms already gives a school a working form builder, anonymous
  response collection UI, and a results view — none of that is Scholiq's problem to solve. What Forms
  cannot do, and what does not exist anywhere else in this codebase either, is know that a given form is
  *about* a specific `Course`/`Cohort`/teacher, aggregate its answers into a per-course quality trend over
  time, or drive a recurring evaluation *cycle* (open a window, remind non-responders, close it, feed the
  result to a governance review, record what changed for next period). That education-domain linkage layer
  is the actual gap, not the survey UI.
- **Zero prior art anywhere in scholiq.** A case-insensitive grep of the whole repo for
  `evaluation|vakevaluatie|course.review|module.evaluation|kwaliteitszorg|opleidingscommissie|examencommissie|NSE`
  turns up exactly one unrelated hit family: `LearningPlanEvaluation`
  (`openspec/specs/learning-plan/spec.md:19-24`, `lib/Settings/scholiq_register.json` schema
  `learning-plan-evaluation`), which is a *support-plan progress review* for one individual learner (goals
  met/adjusted, narrative, next review date) — a different domain object entirely from a course-quality
  survey aggregated across a cohort. There is no `course-review`, `course-evaluation`, or
  `module-evaluation` schema, spec, or manifest page anywhere in the register (`lib/Settings/scholiq_register.json`,
  full-file grep) or in `src/manifest.json`.
- **`course-management` stops at authoring and publication; it says nothing about quality feedback.**
  `openspec/specs/course-management/spec.md` ("What", line 16) scopes the capability to "Authoring of
  courses, modules, and lessons; cloning of templates; ordered learning paths; published-vs-draft state;
  ECTS workload declaration for HE; programme-committee approval workflow ... Open Onderwijs API
  publication" — the full Requirements section (`spec.md:35`–end) covers the Course/Module/Lesson
  hierarchy, OOAPI publication, cmi5/xAPI/SCORM runtime, LTI placement, and `ectsCredits`. Nothing in that
  spec, and nothing in `Course`'s own schema (`lib/Settings/scholiq_register.json:809-964`), references a
  survey, a rating, a response, or a quality score. `Course` and `Cohort` (`lib/Settings/scholiq_register.json:3128-3237`)
  already carry everything a per-course evaluation needs to key against — `Cohort.teacherIds`
  (`:3169-3176`), `Cohort.learnerIds` (`:3177-3184`), `Cohort.courseId`/`programmeId` (`:3153-3168`), and
  `Cohort.academicYear`/`period` (`:3191-3202`) — but nothing consumes them for evaluation purposes.
- **No integration seam exists to ingest Nextcloud Forms submissions, and one genuinely comparable seam
  (Talk) shows what a real one looks like.** `openspec/specs/nextcloud-app/spec.md:172` lists the OCP
  interfaces Scholiq is built against — `IAppManager`, `IConfig`, `IUserSession`, `IRootFolder`,
  `IGroupManager`, `Calendar\IManager`, `Notification\IManager`, `Talk\IBroker`, `Activity\IManager` — and
  `Talk\IBroker` is exactly the kind of public, other-app-facing integration contract that would justify
  "reference the sibling app instead of rebuilding it": Talk ships an OCP interface precisely so other apps
  can create/join conversations. The Nextcloud Forms app ships no equivalent public OCP contract for
  another app to *read* individual submissions server-side, and a repo-wide grep for
  `forms|OCA\\Forms|nc_forms` (excluding this app's own `docs/intro.md` marketing line) returns zero hits —
  no openconnector adapter, no existing wire-protocol delegation, nothing to build on. Per the architecture
  rule "wire protocols → openconnector," inventing a first-of-its-kind Forms-ingestion adapter (screen-
  scraping or DB-coupling a sibling app with no public read API) is a materially riskier, larger build than
  the education-domain layer this change actually needs — see design.md's "NC Forms vs built-in instrument"
  decision.
- **The declarative machinery this change needs already exists and works**, so this stays a thin,
  precedent-following addition rather than new infrastructure:
  - `Enrolment.dueReminder`/`overdue` (`lib/Settings/scholiq_register.json:1736-1786`) is the proven
    `scheduled`-trigger + `filter` (`withinNext`/`olderThan`) reminder shape this change reuses verbatim for
    "remind learners who have not yet responded" — the exact "wave-1 delivery windows" pattern named in the
    brief.
  - `ConferenceSignupGuardianGuard` (`lib/Lifecycle/ConferenceSignupGuardianGuard.php`) is the proven
    pattern for "a lifecycle guard resolves the caller's identity via `IUserSession`, looks it up against a
    *different* schema via `ObjectService::findAll()`, and never writes that identity onto the object being
    guarded" — exactly the mechanism this change needs to let a learner submit one anonymous response per
    campaign without the response ever carrying a learner reference.
  - `GradeRollupHandler` (`lib/Listener/GradeRollupHandler.php`) is the proven "listener reacts to an
    `ObjectTransitionedEvent`, resolves a value via a small `engine`-keyed calculation class, and
    find-or-creates a roll-up object" shape (mirrored by `FinalGrade`'s `x-openregister-aggregations` +
    `x-openregister-calculations.engine`, `lib/Settings/scholiq_register.json:5830-5849`) — this change
    reuses it for the per-course/per-teacher quality-score roll-up, because averaging a numeric field across
    responses is, like `FinalGrade.value`, beyond the register's proven declarative aggregation metrics
    (only `count`/`count_distinct` appear anywhere in `scholiq_register.json` — a full-file grep for
    `"metric":` returns no `avg`/`sum` hit).
- **Formal grounding for why this matters in NL/HE, not just "nice to have":** module/course evaluation is
  a recurring kwaliteitszorg (quality-assurance) duty in Dutch higher education — NSE-style module
  evaluation results feed the opleidingscommissie's advice and the examencommissie's oversight of teaching
  quality, mirroring the same "governance body reviews evidence, records a decision, closing the loop
  matters for accreditation" shape this codebase already builds for BSA
  (`openspec/changes/archive/2026-07-13-bsa-study-progress-guard/`) and exam-board case handling
  (`openspec/specs/exam-board/spec.md`). This change is given as canonical feature `course-reviews` /
  `course-review-and-ratings`, with Blackboard Evaluate, OpenOLAT, and ILIAS's built-in course-evaluation
  modules named as the competitive baseline — three separate LMS/VLE product lines that all ship this as a
  first-class capability, which is consistent with Course Management already ranking #2 of 354 canonical
  features with all 13 OSS LMS leaders shipping it (`openspec/specs/course-management/spec.md` Purpose)
  while quality-feedback-on-that-catalog remains unbuilt.

## What Changes

- **New `course-evaluation` capability** (no `course-management` schema change needed — `Course`/`Cohort`
  already expose everything this needs by `$ref`, so this is additive-only, no MODIFIED requirement on the
  `course-management` spec):
  - **`EvaluationCampaign`** — the config object: which `Course`(s)/`Cohort`(s) are in scope
    (`courseIds[]`/`cohortIds[]`, at least one populated), `academicYear`/`period` (same shape as
    `Cohort.academicYear`/`period`), the instrument (`instrumentKind`: `built-in` — a minimal Likert +
    free-text `questions[]` array Scholiq owns end-to-end, the default; or `external-form` — an optional
    `externalFormUrl` pointing learners at a supplementary Nextcloud Forms survey for open-ended input that
    Scholiq does **not** ingest or aggregate, since no read seam exists), a documentation-only
    `anonymityPolicy` field fixed to `fully-anonymous` (not a per-campaign toggle — anonymity is a schema-
    enforced platform invariant, not configurable), and `reminderSchedule` (`enabled`, `leadDays`,
    currently informing a fixed `P5D`-before-close declarative rule — see Caveats), and a
    `draft → open → closed → archived` lifecycle.
  - **`EvaluationInvitation`** — one append-free row per learner per campaign, created when the campaign
    opens (`EvaluationInvitationProvisioningHandler`, mirrors `CohortMembershipGuard`'s activation-time
    provisioning shape): `campaignId`, `courseId`, `cohortId`, `learnerId`, `hasResponded` (boolean,
    default `false`), `respondedAt` (nullable), `campaignClosesAt` (denormalised copy of the campaign's
    close date, so the reminder's `scheduled`+`filter` rule is self-contained exactly like
    `Enrolment.dueReminder`). This is the object that makes "remind non-responders without seeing who said
    what" possible: it is the *only* place a learner's identity and their response status co-exist, and it
    never references which `CourseEvaluationResponse` satisfied it.
  - **`CourseEvaluationResponse`** — the anonymous response: `appendOnly: true`, `campaignId`, `courseId`,
    `cohortId` (nullable), `teacherId` (nullable, for teacher-scoped evaluations), `overallScore` (nullable
    1–5), `answers[]` (`{questionId, ratingValue, textValue}`, matching the campaign's `questions[]`),
    `submittedAt`. It **MUST NOT ever carry a field identifying the responding learner** — this is enforced
    by the schema having no such property at all (not by RBAC hiding an existing one), and by a
    `submit` transition guard (`CourseEvaluationEligibilityGuard`, mirrors `ConferenceSignupGuardianGuard`)
    that resolves the caller via `IUserSession`, checks (without persisting) that they hold an eligible,
    not-yet-responded `EvaluationInvitation` for this campaign, and blocks otherwise — the same guard call
    structurally prevents duplicate submission. A companion listener
    (`CourseEvaluationResponseSubmittedHandler`, mirrors `GradeRollupHandler`) re-resolves the same
    session identity after the transition succeeds and flips that learner's `EvaluationInvitation` to
    `hasResponded: true`/`respondedAt: now` — a write to a *different*, unlinked object, never onto the
    response itself.
  - **Aggregation — `CourseQualityScore`** — one materialised row per
    (`courseId`, `teacherId` nullable, `academicYear`, `period`), recomputed by
    `CourseQualityScoreRollupHandler` (listener on `CourseEvaluationResponse`'s `submit` transition,
    mirrors `GradeRollupHandler`'s find-or-create-and-recompute shape) via a small `CourseQualityScoreEvaluator`
    engine class (mirrors `GradeFormulaEvaluator`/`BsaProgressEvaluator` — needed because averaging is not
    among this register's proven declarative aggregation metrics): `responseCount` and `invitationCount`
    (both plain `x-openregister-aggregations` `count`, the register's proven metric), `averageOverallScore`
    and `responseRate` (both PHP-computed by the evaluator from the counts + the response set). A
    declarative `src/manifest.json` quality-report page surfaces this over time per course/teacher; charts
    are the standard nc-vue chart components consuming this materialised object, not new infrastructure.
    Free-text `answers[].textValue` is surfaced as a raw list on the report, **not** AI-summarised into
    themes — automatic theme clustering would need an LLM and must go through hermiq behind the `AiFeature`
    gate per the architecture rules, which is out of scope here (see Out of Scope).
  - **Cycle — `ImprovementAction`** — the light closing-the-loop object: `campaignId`, `courseId`,
    `reviewedBy` (opleidingscommissie/vaksectie reviewer, NC user id), `reviewedAt`, `findings`,
    `actionDescription`, `targetPeriod` (the next period the action should show up in), and a
    `planned → in-progress → done | dropped` status — purely declarative CRUD via manifest pages, no PHP
    class, matching the "housekeeping tracker" shape `RolloverPlan`/`CatalogChangeRequest`-style objects
    already use elsewhere in the register.
- **Frontend**: declarative index/detail manifest pages for `EvaluationCampaign`, `CourseEvaluationResponse`
  (read-only, respecting appendOnly), and `ImprovementAction`; one named custom view,
  `CourseQualityReport.vue`, showing a course/teacher's `CourseQualityScore` trend + response rate + raw
  free-text list, with a link to draft an `ImprovementAction`. `EvaluationInvitation` gets no dedicated UI
  (it is a system-provisioned tracking row, not something a user authors).
- **No wire protocol, no PHP CRUD controller.** Three narrowly-scoped PHP classes only:
  `CourseEvaluationEligibilityGuard`, `CourseEvaluationResponseSubmittedHandler`,
  `CourseQualityScoreRollupHandler` (+ the small `CourseQualityScoreEvaluator` calculation engine and
  `EvaluationInvitationProvisioningHandler`), all instances of ADR-031's declared exceptions (guard,
  event-to-object-write bridge, calculation engine).

## Impact

- **`lib/Settings/scholiq_register.json`** — five new schemas: `EvaluationCampaign`, `EvaluationInvitation`,
  `CourseEvaluationResponse`, `CourseQualityScore`, `ImprovementAction`. No existing schema is modified —
  `Course`/`Cohort` are referenced by `$ref` only.
- **New PHP** — `OCA\Scholiq\Lifecycle\CourseEvaluationEligibilityGuard`,
  `OCA\Scholiq\Listener\CourseEvaluationResponseSubmittedHandler`,
  `OCA\Scholiq\Listener\CourseQualityScoreRollupHandler`,
  `OCA\Scholiq\Listener\EvaluationInvitationProvisioningHandler`,
  `OCA\Scholiq\CourseEvaluation\CourseQualityScoreEvaluator` (calculation engine). No new controller, no
  new route.
- **`src/manifest.json`** — index/detail pages for `EvaluationCampaign`, `CourseEvaluationResponse`,
  `ImprovementAction`; one new custom view `CourseQualityReport.vue`.
- **Affected specs**: new `course-evaluation` capability spec only. `course-management` is a read-only
  precedent (`Course`/`Cohort` referenced, not modified) — no delta on that spec.
- **Out of scope**: ingesting or aggregating actual Nextcloud Forms submissions (no integration seam
  exists — see design.md); AI-generated free-text theme summarisation (a hermiq/`AiFeature`-gated
  follow-up); per-campaign dynamic reminder lead time (v1 ships one fixed `P5D` declarative rule, matching
  the single fixed-lead-time precedent `Enrolment.dueReminder` already uses; making it dynamic per campaign
  is a follow-up, documented as a Caveat); DUO/OOAPI reporting of evaluation outcomes (no such requirement
  exists for course evaluation, unlike BSA).
