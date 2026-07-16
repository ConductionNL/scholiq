# Design: admissions-and-subject-choice

## Context

Two linked gaps, one shared toolkit. Admissions (aanmelding) is the front door before any Scholiq identity
exists; subject choice (vakkenpakket) is a mid-programme decision after enrolment. Neither has a schema
today (verified in `proposal.md` "Why"). Both reuse machinery this repo already shipped and proved: the
ADR-046 `*Id`/`*Ref` dual-identity pattern (`ConferenceSignup`, `ExcuseRequest`), a guard that blocks a
decision transition unless a legal precondition holds (`BsaDecisionGuard`), and an event-driven handler that
reacts to a freed resource and promotes the next waitlisted row (`ConferenceScheduleGenerator`).

## Goals / Non-Goals

**Goals**
- Model PO→VO, VO/HO→MBO, and generic intake as an `Application` that a coordinator scores through an
  intake conversation to a `placed`/`waitlisted`/`rejected` decision, converting to `LearnerProfile` +
  `Enrolment` on acceptance.
- Encode the MBO toelatingsrecht safeguard (apply-by-deadline + completed mandatory intake + demonstrable
  studiekeuzeadvies ⇒ right to admission) and the VO schooladvies/doorstroomtoets upward-adjustment rule
  structurally, not as UI convention.
- Reuse the existing waitlist-promotion shape for admissions capacity.
- Let a learner (with guardian consent where required) choose electives against `CurriculumPlan`-declared
  rules, with the approved package feeding `Enrolment`.
- Reuse the ADR-046 guardian dual-identity pattern for both halves — no second parent-account mechanism.

**Non-Goals**
- NC user-account/LMS provisioning on conversion. `Enrolment.source: "admission"` intentionally mirrors
  `EnrolmentRule`'s absence from the register (`proposal.md` "Why") — this change creates the
  `LearnerProfile` and `Enrolment` rows a school administrator needs to run the year, not an NC login. HE's
  Studielink path (`openspec/specs/enrolment/spec.md:51-57`) already owns account provisioning for its own
  channel; extending that machinery to every PO/VO/MBO admission is a follow-up, out of scope here.
- A guardian-self-service `admissions` audience on `PortalContributionProvider`. This change ships the
  `guardianId`/`guardianRef` fields the audience would scope by (the same fields `portal-identity` already
  landed on eight other schemas), but does not touch the provider file itself — that file is owned by the
  separate, in-flight `portal-contribution`/`portal-parent` changes, and adding a ninth audience there is
  their call, not this change's. Today, `Application` creation is staff-assisted (front desk / intake
  coordinator enters what the family supplies by phone, email, or a paper form) — the `guardianId`/
  `guardianRef` fields are populated as data, not as an auth-gated create action.
- A new `avg-verwerkingsregister` processing-activity catalogue entry for `Application`. `Application`
  carries applicant + guardian PII (name, birth date, prior school, contact details) comparable in
  sensitivity to `LearnerProfile`, which does carry a `x-openregister-processing` annotation
  (`lib/Settings/scholiq_register.json:2537-2564`). But `avg-verwerkingsregister`'s own Requirement enumerates
  a **fixed** "at minimum" catalogue (`openspec/specs/avg-verwerkingsregister/spec.md` Requirement 1) that
  this change's declared scope (`enrolment` + `school-structure` deltas) does not touch. Flagged here as a
  real gap for a follow-up change to `avg-verwerkingsregister`, not silently dropped — see
  DEFERRED_QUESTIONS in the return summary.
- Room/timetable conflict resolution for the intake conversation itself (`Application.intakeScheduledAt` is
  a plain date-time, same posture `school-structure` already took for `Session.location` — "a Session just
  records a location string," `openspec/specs/school-structure/spec.md:96`).
- Numerus fixus / lottery-style HE admission and combined (`vmbo-gt/havo`-style) schooladvies levels.
  `Application.schooladviesLevel`/`doorstroomtoetsLevel` are single-track ordinals (see "Ordinal levels,
  not free text" below); a combined advice is recorded as its lower single-track value plus a free-text
  `adviesNote` — a deliberate simplification, not a correctness bug, and cheaper to fix later (widen the
  enum) than to build a general interval type now for a case the brief doesn't ask for.

## Data Model

```
AdmissionsRound (norm config; kind: mbo-toelatingsrecht | vo-schooladvies-doorstroomtoets | generic)
      │  applicationDeadline (nullable, institution-set — no hardcoded "1 April")
      │  mandatoryIntake, capacity (nullable = uncapped)
      ▼
Application (draft → submitted → intake-scheduled → intake-completed
             → placed | waitlisted | rejected → converted; waitlisted → placed; * → withdrawn)
      │  guardianId / guardianRef (ADR-046 dual identity, reused from ConferenceSignup)
      │  requiredDocuments[] ($ref Material)
      │  studiekeuzeadviesGiven/-GivenAt (MBO safeguard)
      │  schooladviesLevel / doorstroomtoetsLevel / schooladviesAdjustedLevel / adjustmentMotivation (VO branch)
      │  requires AdmissionsDecisionGuard on → placed|waitlisted|rejected
      │
      ├─ (placed) ──▶ ApplicationConversionHandler ──▶ LearnerProfile.guardianRefs stamped
      │                                             └─▶ Enrolment×N (source: "admission")
      │
      └─ (withdrawn/rejected FROM placed) ──▶ AdmissionsWaitlistPromoter
                                             ──▶ promotes oldest submittedAt `waitlisted` Application

CurriculumPlan.electiveRules (additive, nullable)
      │  minElectives/maxElectives, mandatoryCombinations[], mutuallyExclusive[], capacityByCourseId
      ▼
SubjectChoice (draft → submitted → validated | needs-revision → approved → locked; needs-revision → draft)
      │  requires SubjectChoiceConsentGuard on → submitted (mirrors ConferenceSignupGuardianGuard exactly)
      │  submitted ──▶ SubjectChoiceValidator ──▶ validated | needs-revision (+ validationErrors[])
      └─ approved → locked ──▶ SubjectChoiceEnrolmentBridge ──▶ Enrolment×N (source: "subject-choice")
```

### `AdmissionsRound`

One row per `(programmeId | level, academicYear)`, mirroring `BsaTrajectory`'s role as the rule config a
handler/guard reads (`lib/Settings/scholiq_register.json:8716-…`). `programmeId` is nullable — a null value
scopes the round to every Programme at the declared `level` in the tenant, the same null-scoping convention
`AttendanceThreshold.cohortId` uses ("Null = applies to all cohorts in the tenant,"
`lib/Settings/scholiq_register.json:8228-8233`). `applicationDeadline` has **no default**, for the identical
reason `BsaTrajectory.normEcts` has none: the "1 April" MBO date and any VO/PO admission-window date are
institution/instance policy, not a Scholiq constant (the earlier BSA change already established this
precedent; see its design.md "This is a deliberate reading of the legal grounding").

### `Application`

The `guardianId`/`guardianRef` pair is copied verbatim from `ConferenceSignup`
(`lib/Settings/scholiq_register.json:11434-11446`): `guardianId` (nullable NC uid, populated when the
family already has an NC/LearnerProfile presence — e.g. a sibling application) and `guardianRef` (nullable
ADR-046 domain UUID, populated once the family is later linked via the portal). Because at intake time there
is usually **no** existing LearnerProfile or NC account for the applicant *or* the guardian, `Application`
additionally carries free-text `guardianGivenName`/`guardianFamilyName`/`guardianEmail`/`guardianPhone` —
the only PII capture this change adds beyond what `ExcuseRequest.submittedAuthLevel` already modeled
(`lib/Settings/scholiq_register.json:8074-8085`), reused verbatim as `submittedAuthLevel`.

`requiredDocuments[]` is an array of `{kind, materialId}`, where `kind` is an enum (`schooladvies`,
`doorstroomtoets-result`, `id-document`, `prior-report`, `medical-statement`, `other`) and `materialId`
`$ref`s a `Material` object — reusing `school-structure`'s established "Materials reference OpenRegister file
attachments; this app MUST NOT store file bytes itself" rule (`openspec/specs/school-structure/spec.md:70-76`)
rather than inventing a parallel nc:files-path property the way `Course.certificateTemplate` did before
`Material` existed.

### Ordinal levels, not free text

`schooladviesLevel`/`doorstroomtoetsLevel`/`schooladviesAdjustedLevel` share one enum, ordered low→high:
`pro`, `vmbo-bb`, `vmbo-kb`, `vmbo-gt`, `havo`, `vwo`. `AdmissionsDecisionGuard` needs a strict ordinal
comparison to decide "did the toets score higher than the advies" — a free-text field cannot support that
comparison declaratively or in PHP without a lookup table anyway, so the enum *is* the lookup table.
Combined advices (`vmbo-gt/havo`) are out of scope (see Non-Goals) — recorded as the lower value plus
`adviesNote`.

### The two guard branches in one class

`AdmissionsDecisionGuard` mirrors `BsaDecisionGuard`'s shape (one PHP class, `requires`-declared on a
transition, branching on the round's `kind` — `lib/Lifecycle/BsaDecisionGuard.php` branches on
`decisionType`, this one branches on `AdmissionsRound.kind`) rather than one class per legal branch, for the
same reason the earlier change kept `BsaDecisionGuard` singular: both rules are "block this transition
unless condition X," evaluated once, at the same transition point (`intake-completed → decided`), over the
same input object. Splitting them into `ToelatingsrechtGuard` + `SchooladviesAdjustmentGuard` +
`AdmissionsCapacityGuard` would triple the `requires` wiring for zero behavioural gain — considered and
rejected.

- **MBO toelatingsrecht branch** (`kind: mbo-toelatingsrecht`): blocks `→ rejected` when
  `submittedAt <= AdmissionsRound.applicationDeadline` **and** `intake-completed` was reached **and**
  `studiekeuzeadviesGiven` is true, **unless** `decisionReason` names a specific unmet prerequisite or
  additional requirement (the "correct secondary school diploma" / "medical examination" carve-outs the
  legal grounding names). This is the structural encoding of "no rejection without a named, permitted
  ground," the same shape `BsaDecisionGuard` uses for "no negative decision without a logged warning."
- **VO schooladvies branch** (`kind: vo-schooladvies-doorstroomtoets`): blocks any decision when
  `doorstroomtoetsLevel` outranks `schooladviesLevel` on the shared ordinal and `schooladviesAdjustedLevel`
  was not raised to match, **unless** `adjustmentMotivation` is non-empty (the "not in the pupil's best
  interest" exception) **or** both `schooladviesLevel` and `doorstroomtoetsLevel` are `pro`/`vmbo-bb` (the
  documented carve-out).
- **Capacity branch** (any `kind`): blocks `→ placed` once `count(Application where admissionsRoundId = X
  and lifecycle in [placed, converted]) >= AdmissionsRound.capacity` (when `capacity` is set) — the
  transition must target `waitlisted` instead. This is a cross-object count, the same reason
  `BsaProgressEvaluator` needed a PHP engine rather than pure JSON-logic (ADR-031).

### Waitlist promotion reuses `ConferenceScheduleGenerator`'s shape, not its algorithm

`AdmissionsWaitlistPromoter` listens for the same event class `GradeRollupHandler`/`ExcuseApprovalHandler`
already react to (`ObjectTransitionedEvent`), scoped to `Application` transitioning into `withdrawn` or
`rejected` **from** `placed` (a freed seat). It selects the single oldest-`submittedAt` `waitlisted`
`Application` for the same `admissionsRoundId` and re-runs the normal `→ placed` transition (so
`AdmissionsDecisionGuard`'s capacity branch still applies — promotion cannot silently over-fill a round).
Unlike `ConferenceScheduleGenerator`, this is a single-row promotion, not a batch solver — no greedy
slot-packing algorithm is needed because admissions capacity is a plain integer, not a calendar. This
directly closes the gap `enrolment/spec.md:67` named ("Waitlist auto-promotion (V1 enhancement)"): the
capability the earlier spec deferred already has the machinery to build it, once `Application`'s capacity
and waitlist states exist.

### Conversion is a write bridge, not a controller

`ApplicationConversionHandler` mirrors `GradeRollupHandler`'s shape (an `IEventListener` reacting to a real
`ObjectTransitionedEvent`, not a synthetic marker): on `Application` → `placed`, it creates one
`LearnerProfile` (role `learner`, `guardianRefs: [Application.guardianRef]` when set) and one `Enrolment`
per `Programme.courseIds` entry (`source: "admission"`), then stamps `Application.convertedLearnerProfileId`
/ `convertedEnrolmentIds` and self-transitions the `Application` to `converted`. This is the identical
cross-object write-bridge exception ADR-031 already grants `GradeRollupHandler`/`ExcuseApprovalHandler`
/`ConferenceScheduleGenerator` — a genuine multi-object write triggered by one event, not expressible as a
declarative calculation.

### Subject choice validation is a listener, not a lifecycle guard

`SubjectChoiceValidator` is modeled on `ConferenceScheduleGenerator`, not on `BsaDecisionGuard` — a
deliberate choice. A lifecycle `requires` guard can only **block** a transition; it cannot itself decide
between two legal destination states. Validating a `vakkenpakket` naturally has two live outcomes
(`validated` on success, `needs-revision` with a named list of unmet rules on failure) — the same shape
`ConferenceScheduleGenerator` already uses to route each `ConferenceSignup` to `scheduled` or `waitlisted`
rather than just blocking. `SubjectChoiceValidator` reads `CurriculumPlan.electiveRules` plus every
sibling `SubjectChoice` in `approved`/`locked` state referencing the same `curriculumPlanId` (for
`capacityByCourseId`) — a cross-object read the pure per-object JSON-logic engine cannot express, the same
ADR-031 rationale `BsaProgressEvaluator` already established.

`SubjectChoiceConsentGuard` is a genuine lifecycle guard (it only ever blocks or allows `submit`), and its
body is copy-identical to `ConferenceSignupGuardianGuard`
(`lib/Lifecycle/ConferenceSignupGuardianGuard.php`, `openspec/specs/parent-conferences/spec.md:70-77`):
resolve the caller's NC user id server-side, pass if it is in the target `LearnerProfile.parentIds` or
equals the learner's own `ncUserId`. No new guardian-authorization logic is written — the existing class's
rule is reapplied to a new schema.

## Security Considerations

- `Application` creation carries no `x-openregister-authorization.create` role restriction beyond
  `["user"]` (any authenticated NC user — front-desk/intake staff), mirroring `secure-exam-test-mode`'s
  documented rationale for a schema with no dedicated intake-coordinator role
  (`lib/Settings/scholiq_register.json:5265-5270`) rather than restricting to `admin`/`principal` the way
  the zorg-sensitive `SupportRequest`/`FraudCase` schemas do — `Application` PII is comparable to
  `LearnerProfile`'s, not to zorg data. Object-level scoping (only the assigned coordinator/admin can
  decide) is a known platform gap, the same one already documented for `secure-exam-test-mode` and
  `SupportRequest` — not solved here, not silently ignored.
- `AdmissionsDecisionGuard` and `SubjectChoiceValidator` never trust a client-supplied "this passes" flag —
  both recompute their condition from persisted fields at transition time, matching every guard/handler in
  this register.
- `guardianRef` on `Application` is an opaque UUID (ADR-046 A4) — no Nextcloud user id is ever used as a
  portal scope key, consistent with `portal-identity`'s posture.
- `SubjectChoiceConsentGuard` resolves the caller server-side (never a client-supplied claim), identical to
  `ConferenceSignupGuardianGuard`.

## Alternatives Considered

- **One combined `AdmissionsAndVakkenpakketGuard`** spanning both halves — rejected: the two workflows share
  a pattern, not a runtime dependency; a `SubjectChoice` never references an `Application`, so a shared guard
  class would only add an unused branch to each call site.
- **`Application` inherits from/reuses `Enrolment` directly** (e.g. an `Enrolment` in a `pending-decision`
  state) instead of a separate schema — rejected: `Enrolment` requires `courseId` and `learnerId` (an NC
  uid) as `required` fields (`lib/Settings/scholiq_register.json:1461-1467`); an applicant has neither
  before conversion. Reusing `Enrolment` would force those fields to go optional platform-wide, a breaking
  change to every existing `Enrolment` consumer (grading, attendance, rollover) for no gain — the same
  "additive, not repoint" reasoning `portal-identity`'s design.md already used for `learnerRef` vs `learnerId`.
- **Capacity as a materialised count field on `AdmissionsRound`** — rejected for the same reason
  `BsaTrajectory` did not materialise `ectsEarned` per learner on itself (its own design.md task 2.1 note):
  a single config row cannot hold a per-round running count without a race between concurrent `placed`
  transitions; `AdmissionsDecisionGuard` counts `Application` rows live at decision time instead.
