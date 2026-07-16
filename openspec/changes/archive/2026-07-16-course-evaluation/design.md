# Design: course-evaluation

## Context

A school running Scholiq has no way to ask "how was this course/module," link the answer back to a
`Course`/`Cohort`/teacher, watch quality trend over time, or make sure the opleidingscommissie's review of
those results turns into a recorded action for next period. `Course`/`Cohort` already carry every field a
per-course evaluation needs to key against (`Cohort.teacherIds` at `:3169-3176`, `Cohort.learnerIds` at
`:3177-3184`, `Cohort.academicYear`/`period` at `:3191-3202`); nothing consumes them for
evaluation purposes (`proposal.md` "Why"). Two decisions shape everything else in this change and are
worth writing down explicitly, because both were genuine trade-offs rather than a single obvious answer.

## Goals / Non-Goals

**Goals**
- Let a school scope, open, and close a recurring per-course/per-cohort evaluation campaign.
- Collect responses that are anonymous by construction — not merely RBAC-hidden — while still being able to
  remind the specific learners who have not yet responded.
- Aggregate responses into a per-course/per-teacher quality trend over time (score, response rate,
  free-text list).
- Close the loop: a governance reviewer records findings and an improvement action against a campaign.
- Reuse existing declarative machinery (notification dialect, calculation-engine pattern, lifecycle-guard
  pattern) — no new parallel mechanism, per ADR-022.

**Non-Goals**
- Building a general-purpose form builder. `EvaluationCampaign.questions[]` is a fixed, minimal Likert +
  free-text shape, not a configurable question-type engine.
- Ingesting Nextcloud Forms submissions (see Decision 1).
- AI-generated theme extraction from free text (hermiq/`AiFeature` follow-up).
- A fully dynamic per-campaign reminder cadence (see Caveats).
- Reporting evaluation outcomes to any external system (no such requirement exists for this domain, unlike
  BSA's DUO/Studielink follow-up).

## Decision 1: NC Forms vs. a minimal built-in instrument

**The question:** the brief's framing is correct that Nextcloud Forms is a real, working survey instrument
today — a school could hand a learner a Forms link right now and collect free-text feedback with zero new
code. So should `EvaluationCampaign` *be* a reference to an NC Form, with Scholiq reading its submissions?

**What was checked:** `openspec/specs/nextcloud-app/spec.md:172` lists every OCP interface Scholiq
integrates against — `Talk\IBroker` is on that list specifically because Talk ships a public, other-app-
facing contract for exactly this kind of cross-app use (create/join a conversation from another app). A
full-repo grep for `forms|OCA\\Forms|nc_forms` (excluding this app's own marketing copy in `docs/intro.md`)
returns zero hits: no openconnector adapter, no existing wire-protocol delegation, no prior integration
attempt of any kind to build on. Nextcloud Forms does not ship an equivalent public OCP contract for
another app to *read* individual submissions server-side — there is no `Forms\IFormsManager`-shaped
interface the way there is a `Talk\IBroker`. Reading Forms answers from outside Forms today means either
direct database coupling to a sibling app's internal schema (fragile, breaks on every Forms upgrade, and
violates "wire protocols → openconnector" since there is no protocol, just a private table) or scraping the
authenticated results page, neither of which this codebase does anywhere for any other app.

**Decision:** hybrid, split by what each half is good at.
- The **quantitative, linked, aggregated half** — the one this change actually needs to build (course
  quality scores over time, response-rate-driven reminders, anonymity-with-targeted-follow-up) — is a
  **minimal built-in instrument**: `EvaluationCampaign.questions[]` (a small array of `{questionId, text:
  {nl,en}, kind: likert-5|free-text, required}`), answered into `CourseEvaluationResponse.answers[]` plus a
  denormalised `overallScore` for cheap PHP aggregation (see Decision-adjacent note below). This is
  entirely inside OpenRegister, so the anonymity mechanism in Decision 2 and the roll-up in
  `CourseQualityScoreRollupHandler` can both be built with the same guard/listener primitives every other
  capability in this register already uses.
- The **open-ended, non-aggregated half** stays exactly where the brief says it already lives for free:
  `EvaluationCampaign.instrumentKind: external-form` + `externalFormUrl` lets a school point learners at a
  supplementary Nextcloud Forms survey for free-text-only qualitative input. Scholiq never reads it back —
  it is a convenience link, not an integration. This preserves the "don't rebuild the instrument" boundary
  for the one piece Forms is genuinely better at (rich free-form question authoring) without pretending a
  read seam exists that does not.

**Rejected alternative — build a Forms-ingestion adapter now.** Rejected because it is a bigger, riskier
build than the feature this change is actually asked for (a first-of-its-kind private-schema coupling to
another app, versus a five-schema addition following six existing patterns in this same file), and because
"the instrument is free" was never really in question — what was missing is the linkage/aggregation/cycle
layer, which a Forms adapter would not even provide (Forms has no concept of "this response is about
Course X" either). If a real `Forms\IFormsManager`-style OCP contract ships upstream, `instrumentKind:
external-form` already has a home to grow an ingestion mode into without a breaking schema change.

## Decision 2: anonymity vs. targeted reminders — the crux mechanism

**The tension, stated precisely:** a coordinator needs to remind the learners who have *not yet* responded
(so response rate stays usable), but must not be able to join any specific response's content back to the
learner who submitted it — not via a hidden field, not via RBAC, structurally not at all in the domain
model.

**Rejected alternative — store `learnerId` on the response, RBAC-hide it.** This is the pattern the rest
of the register uses for other sensitive fields (`x-property-rbac`, e.g. `FinalGrade`,
`lib/Settings/scholiq_register.json:5854-5867`). Rejected here specifically because RBAC controls *who can
read* a field, not *whether the field exists* — an admin export, a database dump, or a future RBAC bug
would all defeat it. The brief is explicit that this must be "a hard, server-enforced requirement," which
this design reads as: the schema itself must have no such property, so there is nothing to hide.

**Rejected alternative — no per-learner tracking at all, blast reminders to the whole cohort every time.**
Simpler, but defeats the entire point of a targeted reminder and produces reminder fatigue for learners who
already responded — not what "wave-1 delivery windows" reuse is meant to achieve.

**Chosen mechanism — split the identity-bearing row from the content-bearing row, and only ever touch the
identity-bearing one through a transient, non-persisted lookup:**

```
EvaluationCampaign (open) ──provisions──> EvaluationInvitation × N   (one per learner; DOES carry learnerId)
                                              │  hasResponded: false → true (flipped, never read back into
                                              │  the response); respondedAt: timestamp only
                                              │
                                              │  x-openregister-notifications.reminder:
                                              │  scheduled + filter(hasResponded:false,
                                              │  campaignClosesAt: withinNext P5D)
                                              │  — same shape as Enrolment.dueReminder
                                              ▼
                    (no FK from either side)
CourseEvaluationResponse (submit transition)  ── NEVER carries learnerId/submittedBy/any identity field
   requires: CourseEvaluationEligibilityGuard  ── resolves caller via IUserSession (server-side only,
                                                   never a client-supplied claim, mirrors
                                                   ConferenceSignupGuardianGuard), looks up that caller's
                                                   EvaluationInvitation via ObjectService::findAll()
                                                   (campaignId + learnerId match, hasResponded:false),
                                                   blocks the transition if none exists — this is also
                                                   the duplicate-submission guard, for free.
   → triggers: CourseEvaluationResponseSubmittedHandler (listener, mirrors GradeRollupHandler) re-resolves
     the SAME caller identity (IUserSession again — the identity is never read from the response event
     payload, because it was never written there) and updates that learner's EvaluationInvitation:
     hasResponded=true, respondedAt=now. This write targets a DIFFERENT, unlinked object; the invitation
     row never stores which response satisfied it (no responseId field).
```

The identity is used **transiently, at request time, exactly twice** (once by the guard to authorise, once
by the listener to flip the invitation) and is **never persisted onto `CourseEvaluationResponse` or into
`EvaluationInvitation` in a form that points back to a specific response**. Anyone reading the two tables —
including an admin — can see "learner X was invited to campaign Y and has responded" and, separately, "some
response with this content exists for campaign Y," but cannot join the two through any domain field.

**Caveat, stated honestly (matching the codebase's existing honesty pattern, e.g. the BSA change's
IMPLEMENTATION NOTEs):** OpenRegister's own core object store necessarily records *which authenticated
session created every row* at the platform/audit-log level — that is how NC's underlying framework and
audit trail work for every object in every app, referenced throughout this register as the generic
"metadata + audit-trail" sidebar (`src/manifest.json`, dozens of `_note` occurrences). This platform-level
creator/audit stamp is outside scholiq's control and outside this change's scope to alter; it is a
materially narrower exposure than a queryable domain `learnerId` field (it requires platform-admin-level
log/DB access, not app-level query access, and carries no response *content* alongside it), but it is not
literally zero. The hard, server-enforced requirement this change delivers is at the **domain-model
layer**: no scholiq code path, schema property, API filter, or export ever associates response content
with a learner. Closing the platform-level audit-log gap, if a school's threat model requires it, is a
follow-up outside this change (would likely mean OpenRegister accepting a "run this write as a shared
service identity" mode — a platform capability that does not exist today).

## Why aggregation needs a small PHP evaluator, not a declarative metric

A full-file grep of `scholiq_register.json` for `"metric":` shows only `count` and `count_distinct` in use
anywhere in this register — no `avg`/`sum`. `FinalGrade.value` hit the identical wall (summing/weighting
across a cross-schema join) and solved it with `x-openregister-aggregations` (declares the cross-schema
pull) + an `engine`-keyed `x-openregister-calculations` entry (`GradeFormulaEvaluator`,
`lib/Settings/scholiq_register.json:5830-5849`). `CourseQualityScoreEvaluator` follows the same shape:
`responseCount`/`invitationCount` are plain declarative `count` aggregations (the register's proven
metric); `averageOverallScore` (mean of `CourseEvaluationResponse.overallScore` for the matching
course/teacher/period) and `responseRate` (`responseCount / invitationCount`) are simple PHP arithmetic —
no formula-tree complexity like `GradeFormulaEvaluator` needs, just an average and a division, which is why
this stays a small, narrowly-scoped class rather than a generalised aggregation engine.

`overallScore` is a **denormalised flat field** on `CourseEvaluationResponse`, duplicating one designated
"overall satisfaction" question's answer from `answers[]`. This trades a small amount of redundancy (the
frontend copies one `answers[]` entry's value into `overallScore` at submit time) for a materially simpler
evaluator — averaging a flat numeric column, rather than the evaluator having to parse a JSON array to find
"the" satisfaction question inside every response it scans.

## Caveats

- **Fixed `P5D` reminder lead time, not per-campaign configurable.** `EvaluationCampaign.reminderSchedule`
  carries a `leadDays` field for forward-compatibility, but v1's declarative `EvaluationInvitation.reminder`
  rule ships with a single hardcoded `P5D` window (matching `Enrolment.dueReminder`'s own single fixed
  `P3D`, `lib/Settings/scholiq_register.json:1736-1761` — there is no existing precedent anywhere in this
  register for a *dynamic*, per-object-configurable `scheduled`+`filter` window; building one is a bigger
  change than this evaluation cycle needs). Making `leadDays` actually drive the rule is a follow-up.
- **`instrumentKind: external-form` is a link, not an integration**, per Decision 1 — Scholiq never reads
  or aggregates what a learner submits through the linked Forms survey.
- **No automatic free-text theme clustering.** The quality-report page lists raw free-text answers; a
  hermiq-backed summarisation feature would be a separate, `AiFeature`-gated change.
