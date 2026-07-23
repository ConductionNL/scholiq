---
kind: code
depends_on: []
---

# Proposal: admissions-and-subject-choice

## Why

Scholiq has no pre-enrolment intake path and no elective-choice workflow. Both gaps are confirmed at HEAD.

**Admissions.** `openspec/specs/enrolment/spec.md` (read in full) covers exactly one intake channel:
Studielink for HE. Its own "What" section says so directly — "auto-enrol … on Studielink intake (HE)"
(`openspec/specs/enrolment/spec.md:18`) — and every Requirement is either bulk-enrolment, a prerequisite
check, or the Studielink/Edukoppeling LMS-provisioning path
(`openspec/specs/enrolment/spec.md:35-57`). There is no `Application`/`aanmelding` schema anywhere:
a case-insensitive full-file grep of `lib/Settings/scholiq_register.json` for `application|aanmelding|
toelating|schooladvies|doorstroomtoets|studiekeuzeadvies` returns zero schema hits (the only `Application`
in the file is the register's own `"type": "application"` header key and the unrelated `AiFeature`
processing-catalogue schema). PO→VO and VO/HO→MBO intake — where a learner does not yet have any Scholiq
identity — has nowhere to go. The spec's own Data Model line even names schemas that were never built
(`Learner`, `OnboardingTemplate`, `EnrolmentRule` — `openspec/specs/enrolment/spec.md:63`; only `Enrolment`
and `Cohort` exist in the register), and its Out of Scope explicitly defers "Waitlist auto-promotion (V1
enhancement)" (`openspec/specs/enrolment/spec.md:67`) — exactly the capability an admissions intake needs,
because a placement round is the one place in Scholiq that already has a natural notion of "a seat opened
up, who's next."

**Subject choice.** `CurriculumPlan.electiveCourseIds` exists (`lib/Settings/scholiq_register.json:2904-2913`,
alongside `requiredCourseIds` at `:2894-2903`) but is a bare UUID array with no producer: nothing writes to
it, no rule validates a selection against it, and no schema records a learner's choice. A full-file grep for
`vakkenpakket|profielkeuze|electiveRule|SubjectChoice` is zero hits. `school-structure`'s own Data Model
section lists `CurriculumPlan` as a schema this app owns (`openspec/specs/school-structure/spec.md:92`) but
the elective list is decorative — a school can declare which courses are optional, but a learner has no way
to pick from them, no rule stops an invalid combination, and nothing feeds the pick into `Enrolment`.

**Both are the same shape and reuse the same landed machinery, not a new one.**

- **Guardian identity.** `ConferenceSignup` already carries the exact dual-identity shape this change needs:
  a nullable NC-uid actor field (`guardianId`) plus a nullable ADR-046 domain-UUID ref
  (`guardianRef`, `lib/Settings/scholiq_register.json:11441-11446`), and `ExcuseRequest` pairs
  `submittedBy`/`submittedByRef` with a `submittedAuthLevel` eIDAS-assurance enum
  (`lib/Settings/scholiq_register.json:8025-8037,8074-8085`). `LearnerProfile.guardianRefs`
  (`lib/Settings/scholiq_register.json:2658-2666`) is the resolved-target end of that ref, already shipped
  (register `info.version` history line documents it as "v0.3.0 adds ADR-046 portal-identity scoping refs …
  additive, optional, fail-closed", `lib/Settings/scholiq_register.json:5`). `portal-contribution`'s design
  documents the `parent` audience resolving guardian → child by matching `subject.guardianRef` against
  `LearnerProfile.guardianRefs` (`openspec/changes/portal-contribution/design.md:60-98`). This change reuses
  that exact `guardianId`/`guardianRef` pair on `Application` and stamps `LearnerProfile.guardianRefs` on
  conversion — it does **not** touch `PortalContributionProvider` (out of scope; a future `admissions`
  portal audience is a named follow-up) and does **not** invent a second guardian-account mechanism.
- **Waitlist-and-promotion.** `ConferenceSignup`'s `waitlisted` state and `ConferenceScheduleGenerator`
  (`lib/Listener/ConferenceScheduleGenerator.php`, `openspec/specs/parent-conferences/spec.md:100-117`) are a
  working precedent for "an event handler reacts to a freed resource and promotes the next waitlisted row,
  oldest-first, without disturbing already-confirmed rows." Admissions reuses the same shape for placement
  capacity.
- **Guard-blocks-a-transition-unless.** `BsaDecisionGuard` (`lib/Lifecycle/BsaDecisionGuard.php`,
  `lib/Settings/scholiq_register.json:9268-…`) blocks a `negative` decision transition unless a prerequisite
  record exists. The MBO toelatingsrecht rule and the VO schooladvies-adjustment rule are both
  "block this decision unless condition X holds" — the identical shape, reused, not reinvented.
- **Declared-not-TimedJob.** `AttendanceThreshold`'s dual-mode `window` (`fixed-date` vs relative,
  `lib/Settings/scholiq_register.json:8181-8380`) is the precedent for the MBO/VO deadline profiles running
  on different clocks without hardcoding either one.
- **File attachments.** `school-structure` already established "Materials reference OpenRegister file
  attachments; this app MUST NOT store file bytes itself" (`openspec/specs/school-structure/spec.md:70-76`).
  `Application`'s required documents (schooladvies, doorstroomtoets result, etc.) reuse the `Material` $ref,
  not a parallel file-storage shape.

**Legal grounding** (fetched 2026-07-13):
- [OCO — Wat is het recht op toelating in het mbo?](https://www.onderwijsconsument.nl/toelatingsrecht-mbo-keuzerecht-student/)
  and the [1-april-2026 deadline notice](https://ocoamsterdam.nl/vragen/save-the-date-1-april-2026-deadline-voor-aanmelden-mbo):
  registering before **1 April** and completing the programme's **mandatory intake activities** gives the
  **right to admission** (`toelatingsrecht`), subject to the prerequisite diploma and any additional
  requirement (e.g. a medical exam) actually being met. Registering before 1 April also gives the right to
  a **studiekeuzeadvies** (study-choice advice) — the school must be able to show it was given, though the
  advice itself is non-binding.
- [VO-raad — Minister: maatregel bijstellen schooladvies van groot belang voor kansengelijkheid](https://www.vo-raad.nl/nieuws/minister-maatregel-bijstellen-schooladvies-van-groot-belang-voor-kansengelijkheid)
  and [OCO — Veelgestelde vragen over het schooladvies en de doorstroomtoets](https://www.onderwijsconsument.nl/veelgestelde-vragen-het-basisschooladvies/):
  when the doorstroomtoets score is **higher** than the preliminary schooladvies, the primary school **must
  adjust the advice upward**, in principle — it may only decline to do so when raising it would not be in
  the pupil's best interest, and (per the same source) a student advised `pro`/`vmbo-bb` whose toets result
  is also `pro`/`vmbo-bb` is exempt from the adjustment requirement entirely.

## What Changes

**Home: `enrolment` (Application converts into `LearnerProfile` + `Enrolment`, the capability's own stated
purpose — "the gateway from identity to learning record," `openspec/specs/enrolment/spec.md:14`).**

- New `AdmissionsRound` — the norm config per `(programmeId | level, academicYear)`, mirroring
  `BsaTrajectory`'s role: `kind` (`mbo-toelatingsrecht` | `vo-schooladvies-doorstroomtoets` | `generic`),
  institution-set `applicationDeadline` (nullable — no hardcoded "1 April," same reasoning `BsaTrajectory
  .normEcts` used for the BSA credit norm), `mandatoryIntake`, `capacity` (nullable = uncapped), lifecycle
  `draft → open → closed → archived`.
- New `Application` (aanmelding): applicant details, the `guardianId`/`guardianRef` dual-identity pair (the
  reused ADR-046 shape, plus free-text `guardianGivenName`/`guardianFamilyName`/`guardianEmail`/
  `guardianPhone` for the common case where no NC account or LearnerProfile exists yet at intake time),
  `submittedAuthLevel`, chosen `programmeId`, `requiredDocuments[]` ($ref `Material`), an intake-conversation
  record, the MBO toelatingsrecht fields (`studiekeuzeadviesGiven`/`studiekeuzeadviesGivenAt`), the VO
  schooladvies/doorstroomtoets fields and adjustment fields, and a decision
  (`placed`/`waitlisted`/`rejected` + `decisionReason`). Lifecycle: `draft → submitted →
  intake-scheduled → intake-completed → placed | waitlisted | rejected`; `waitlisted → placed`
  (promotion); `placed → converted`; any pre-decision or `waitlisted`/`placed` state → `withdrawn`.
- New `AdmissionsDecisionGuard` (PHP, `requires` on the `intake-completed → {placed|waitlisted|rejected}`
  transitions, mirroring `BsaDecisionGuard`'s single-class multi-condition shape): for
  `mbo-toelatingsrecht` rounds, blocks `rejected` when the applicant applied by the deadline, completed the
  mandatory intake, and was given a studiekeuzeadvies, unless `decisionReason` names an unmet prerequisite
  or additional requirement; for `vo-schooladvies-doorstroomtoets` rounds, blocks a decision when
  `doorstroomtoetsLevel` outranks `schooladviesLevel` and `schooladviesAdjustedLevel` was not raised to
  match, unless `adjustmentMotivation` is non-empty or the pro/vmbo-bb exemption applies; for any round,
  blocks `placed` once the round's `capacity` is reached (must go to `waitlisted` instead).
- New `AdmissionsWaitlistPromoter` (PHP listener on `Application` → `withdrawn`/`rejected` **from**
  `placed`, mirroring `ConferenceScheduleGenerator`'s freed-resource shape): promotes the oldest
  `submittedAt` `waitlisted` `Application` for the same round to `placed`, re-running
  `AdmissionsDecisionGuard`.
- New `ApplicationConversionHandler` (PHP listener on the `placed` `ObjectTransitionedEvent`, mirroring
  `GradeRollupHandler`'s cross-object write-bridge shape): creates a `LearnerProfile` stamped with
  `guardianRefs` from `Application.guardianRef`, bulk-enrols the learner into the chosen `Programme`'s
  `courseIds` (new `Enrolment.source: "admission"`), stamps `Application.convertedLearnerProfileId` /
  `convertedEnrolmentIds`, and transitions the `Application` to `converted`. NC user-account provisioning
  is explicitly **not** part of conversion (Non-Goal below).
- `Enrolment.source` (`lib/Settings/scholiq_register.json:1503-1512`) gains `"admission"` and
  `"subject-choice"` enum values (additive).
- Frontend: declarative `src/manifest.json` pages for `AdmissionsRound`/`Application`; one named custom view,
  `AdmissionsReviewBoard.vue` (coordinator's intake/decision queue, cross-referencing each `Application`
  against its round's deadline/kind/capacity — a join a generic list widget cannot express, same
  justification as `BsaRiskDashboard`).

**Home: `school-structure` (the elective list and its governing plan already live there).**

- `CurriculumPlan` gains an additive, nullable `electiveRules` object: `minElectives`/`maxElectives`,
  `mandatoryCombinations[]` (course-id sets that must be chosen together), `mutuallyExclusive[]`, and
  `capacityByCourseId` (nullable = uncapped). Unset on every existing row (fail-closed, same posture as
  every other additive block in this register).
- New `SubjectChoice` (vakkenpakket): `learnerId`/`learnerRef`, `curriculumPlanId`, `programmeId`,
  `selectedElectiveCourseIds[]`, `guardianConsentGiven`/`guardianConsentBy(Ref)`, `validationErrors[]`.
  Lifecycle: `draft → submitted → validated | needs-revision → approved → locked`; `needs-revision →
  draft`.
- New `SubjectChoiceConsentGuard` (PHP, `requires` on `submit`, mirroring `ConferenceSignupGuardianGuard`
  exactly: passes when the caller is in `LearnerProfile.parentIds` for the target learner, or the caller
  **is** the learner).
- New `SubjectChoiceValidator` (PHP listener on `submitted`, mirroring `ConferenceScheduleGenerator`'s
  shape — reads the plan's `electiveRules` plus sibling `SubjectChoice` rows for capacity, writes
  `validated` or `needs-revision` + `validationErrors[]`).
- New `SubjectChoiceEnrolmentBridge` (PHP listener on `approved → locked`, mirroring
  `ExcuseApprovalHandler`'s cross-object write-bridge shape): creates/updates an `Enrolment`
  (`source: "subject-choice"`) per selected elective course.
- Frontend: declarative `src/manifest.json` pages for `SubjectChoice`; one named custom view,
  `SubjectChoicePicker.vue` (interactive elective picker showing live rule/capacity feedback — a generic
  form cannot render cross-object rule validation, same justification as `BookConferenceSlotsView`).

## Impact

- **`lib/Settings/scholiq_register.json`**: two new schemas (`AdmissionsRound`, `Application`) under the
  `enrolment` home; two new schemas (`SubjectChoice`) plus one modified schema (`CurriculumPlan` gains
  `electiveRules`) under the `school-structure` home; `Enrolment.source` enum gains two values.
- **New PHP**: `OCA\Scholiq\Lifecycle\AdmissionsDecisionGuard`, `OCA\Scholiq\Listener
  \AdmissionsWaitlistPromoter`, `OCA\Scholiq\Listener\ApplicationConversionHandler`,
  `OCA\Scholiq\Lifecycle\SubjectChoiceConsentGuard`, `OCA\Scholiq\Listener\SubjectChoiceValidator`,
  `OCA\Scholiq\Listener\SubjectChoiceEnrolmentBridge`. No new controller, no new route.
- **`src/manifest.json`**: index/detail pages for `AdmissionsRound`, `Application`, `SubjectChoice`; two new
  custom views, `AdmissionsReviewBoard.vue` and `SubjectChoicePicker.vue`.
- **Affected specs**: `enrolment` (ADDED requirements for admissions), `school-structure` (ADDED
  requirements for subject choice, one additive `CurriculumPlan` field).
- **Out of scope**: an `admissions` audience on `PortalContributionProvider` (guardians self-serving an
  application without staff assistance) — a named follow-up, not this change, since `lib/Portal/
  PortalContributionProvider.php` is owned by the separate `portal-contribution`/`portal-parent` changes;
  NC user-account/LMS provisioning on conversion (HE's Studielink path already owns that for HE — extending
  it to PO/VO/MBO admissions is a follow-up); DUO/Studielink/OOAPI reporting of admissions outcomes; a new
  `avg-verwerkingsregister` processing-catalogue entry for `Application` (flagged as a gap, not fixed here
  — see design.md).
