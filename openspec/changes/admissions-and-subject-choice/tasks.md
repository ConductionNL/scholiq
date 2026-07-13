# Tasks: admissions-and-subject-choice

## 1. Schema — admissions (enrolment home)

- [ ] 1.1 Add `AdmissionsRound` to `lib/Settings/scholiq_register.json`: `name`, `kind`
  (`mbo-toelatingsrecht` | `vo-schooladvies-doorstroomtoets` | `generic`), `programmeId` (nullable $ref
  `Programme`), `level` (po|vo|mbo|hbo|wo|corporate), `academicYear`, `applicationDeadline` (nullable date,
  no default), `mandatoryIntake` (bool, default true), `capacity` (nullable integer), `lifecycle`
  (`draft → open → closed → archived`), `tenant_id`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-persist-admissionsround-and-application-domain-objects-in-openregister`
  - **acceptance_criteria**: schema validates against the register's OpenAPI 3.0.0 conventions; `kind` enum
    and nullable `programmeId`/`applicationDeadline`/`capacity` match the `AttendanceThreshold`/
    `BsaTrajectory` null-scoping precedent
- [ ] 1.2 Add `Application` to `lib/Settings/scholiq_register.json`: applicant fields
  (`applicantGivenName`, `applicantFamilyName`, `applicantBirthDate` nullable, `priorSchool` nullable),
  `admissionsRoundId` ($ref `AdmissionsRound`), `programmeId` ($ref `Programme`), `guardianId`/`guardianRef`
  (ADR-046 dual pair, copying `ConferenceSignup`'s shape), `guardianGivenName`/`guardianFamilyName`/
  `guardianEmail`/`guardianPhone` (nullable), `submittedAuthLevel` (eIDAS enum, copying
  `ExcuseRequest.submittedAuthLevel`), `requiredDocuments[]` (`{kind, materialId}`, `materialId` $ref
  `Material`), `intakeScheduledAt`/`intakeConductedBy`/`intakeNotes`/`intakeCompleted` (intake conversation
  record), `studiekeuzeadviesGiven`/`studiekeuzeadviesGivenAt` (MBO branch), `schooladviesLevel`/
  `doorstroomtoetsLevel`/`schooladviesAdjustedLevel`/`adjustmentMotivation`/`adviesNote` (VO branch, shared
  ordinal enum `pro|vmbo-bb|vmbo-kb|vmbo-gt|havo|vwo`), `decisionType`
  (`placed`|`waitlisted`|`rejected`, nullable), `decisionReason`/`decidedBy`/`decidedAt`,
  `convertedLearnerProfileId`/`convertedEnrolmentIds` (nullable, stamped on conversion),
  `submittedAt` (date-time), `lifecycle` (`draft → submitted → intake-scheduled → intake-completed →
  placed | waitlisted | rejected`; `waitlisted → placed`; `placed → converted`; * → `withdrawn`),
  `tenant_id`. `x-openregister-authorization.create: ["user"]` with a rationale comment mirroring
  `secure-exam-test-mode`'s precedent.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-persist-admissionsround-and-application-domain-objects-in-openregister`,
    `specs/enrolment/spec.md#requirement-application-captures-guardian-identity-via-the-reused-adr-046-dual-identity-pattern`,
    `specs/enrolment/spec.md#requirement-required-intake-documents-reference-openregister-file-attachments`
  - **acceptance_criteria**: schema validates; `guardianId`/`guardianRef` field-for-field match
    `ConferenceSignup`'s existing pair; `requiredDocuments[].materialId` is a UUID $ref to `Material`; no
    file-bytes property added
- [ ] 1.3 Add `"admission"` and `"subject-choice"` to the `Enrolment.source` enum
  (`lib/Settings/scholiq_register.json:1503-1512`). Purely additive.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-enrolment-records-its-origin-including-admission-and-subject-choice-sources`
  - **acceptance_criteria**: existing `Enrolment` rows with any prior `source` value remain valid

## 2. Backend — admissions guards and handlers

- [ ] 2.1 Add `OCA\Scholiq\Lifecycle\AdmissionsDecisionGuard` (SPDX; `@spec` tags per requirement; `requires`
  on `Application`'s `intake-completed → placed|waitlisted|rejected` transitions): implements the
  toelatingsrecht branch, the schooladvies-adjustment branch, and the capacity branch described in
  design.md, branching on `AdmissionsRound.kind`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-an-mbo-applicant-who-applies-by-the-deadline-and-completes-the-mandatory-intake-has-a-right-to-admission`,
    `specs/enrolment/spec.md#requirement-a-vo-schooladvies-must-be-adjusted-upward-when-the-doorstroomtoets-scores-higher-unless-motivated`,
    `specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up`
  - **acceptance_criteria**: unit tests cover every scenario in the three linked requirements (toelatingsrecht
    block/allow, schooladvies-adjustment block/exemption, capacity block)
- [ ] 2.2 Add `OCA\Scholiq\Listener\AdmissionsWaitlistPromoter` (SPDX; `@spec` tag; listens for
  `Application` → `withdrawn`/`rejected` from `placed`): promotes the oldest-`submittedAt` `waitlisted`
  `Application` for the same round to `placed`, re-running `AdmissionsDecisionGuard`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-placement-capacity-is-enforced-and-a-waitlisted-application-is-auto-promoted-when-a-seat-frees-up`
  - **acceptance_criteria**: unit tests cover promotion of the oldest waitlisted application; a later
    waitlisted application is left untouched when an earlier one is promoted
- [ ] 2.3 Add `OCA\Scholiq\Listener\ApplicationConversionHandler` (SPDX; `@spec` tag; listens for
  `Application` → `placed`): creates a `LearnerProfile` with `guardianRefs` stamped from `guardianRef`,
  bulk-creates `Enrolment` rows (`source: "admission"`) for the chosen `Programme.courseIds`, stamps
  `convertedLearnerProfileId`/`convertedEnrolmentIds`, transitions the `Application` to `converted`.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-an-accepted-application-converts-into-a-learnerprofile-and-enrolments`
  - **acceptance_criteria**: unit tests cover LearnerProfile creation with guardianRefs stamped; one
    Enrolment per Programme course created; no NC user account or LMS provisioning side effect

## 3. Schema — subject choice (school-structure home)

- [ ] 3.1 Add `electiveRules` (nullable object, additive) to `CurriculumPlan`
  (`lib/Settings/scholiq_register.json:2856-…`): `minElectives`/`maxElectives` (nullable integers),
  `mandatoryCombinations` (array of course-id arrays), `mutuallyExclusive` (array of course-id arrays),
  `capacityByCourseId` (nullable object map).
  - **spec_ref**: `specs/school-structure/spec.md#requirement-curriculumplan-declares-elective-selection-validation-rules`
  - **acceptance_criteria**: existing `CurriculumPlan` rows validate unchanged with `electiveRules` absent
- [ ] 3.2 Add `SubjectChoice` to `lib/Settings/scholiq_register.json`: `learnerId`/`learnerRef`,
  `curriculumPlanId` ($ref `CurriculumPlan`), `programmeId` (nullable $ref `Programme`), `academicYear`,
  `selectedElectiveCourseIds[]` ($ref `Course`), `guardianConsentGiven` (bool, default false),
  `guardianConsentBy`/`guardianConsentByRef` (nullable), `validationErrors[]` (nullable array of strings),
  `lifecycle` (`draft → submitted → validated | needs-revision → approved → locked`;
  `needs-revision → draft`), `tenant_id`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-persist-subjectchoice-domain-objects-in-openregister`
  - **acceptance_criteria**: schema validates; lifecycle transitions match the declared state machine

## 4. Backend — subject-choice guard, validator, enrolment bridge

- [ ] 4.1 Add `OCA\Scholiq\Lifecycle\SubjectChoiceConsentGuard` (SPDX; `@spec` tag; `requires` on `submit`):
  copy `ConferenceSignupGuardianGuard`'s rule exactly — pass when the caller's NC user id is in the target
  `LearnerProfile.parentIds` or equals the learner's own `ncUserId`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minors-subject-choice-submission`
  - **acceptance_criteria**: unit tests cover linked-guardian allow, self (18+) allow, unrelated-user block —
    mirroring `ConferenceSignupGuardianGuardTest`
- [ ] 4.2 Add `OCA\Scholiq\Listener\SubjectChoiceValidator` (SPDX; `@spec` tag; listens for `SubjectChoice` →
  `submitted`): checks `selectedElectiveCourseIds` against `CurriculumPlan.electiveRules` (min/max,
  mandatory combinations, mutually exclusive) and against sibling `SubjectChoice` capacity occupancy;
  writes `validated` or `needs-revision` + `validationErrors[]`.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-a-submitted-subject-choice-is-validated-against-the-plans-elective-rules-not-persisted-unchecked`
  - **acceptance_criteria**: unit tests cover valid-choice pass, mandatory-combination violation, capacity
    conflict — each naming the correct `validationErrors` entry
- [ ] 4.3 Add `OCA\Scholiq\Listener\SubjectChoiceEnrolmentBridge` (SPDX; `@spec` tag; listens for
  `SubjectChoice` → `approved → locked`): creates/updates an `Enrolment` (`source: "subject-choice"`) per
  selected elective course not already enrolled.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-an-approved-subject-choice-feeds-enrolment`
  - **acceptance_criteria**: unit test covers Enrolment creation for each selected course; no duplicate
    Enrolment created for a course the learner is already enrolled in

## 5. Frontend

- [ ] 5.1 Add `src/manifest.json` index/detail pages for `AdmissionsRound`, `Application`, and
  `SubjectChoice` (list/create/edit/detail per the standard declarative pattern used by `attendance`/
  `grading`).
  - **spec_ref**: `specs/enrolment/spec.md#requirement-frontend-is-declarative-with-one-named-admissions-review-exception`,
    `specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception`
  - **acceptance_criteria**: pages render seeded objects; no PHP CRUD controller added
- [ ] 5.2 Add `src/views/AdmissionsReviewBoard.vue`: lists `Application`s in `intake-completed` for the
  coordinator's scope, each cross-referencing its `AdmissionsRound`'s deadline, kind, and remaining
  capacity, with a link to record a decision; strings via `t()`, data via the OpenRegister object API; any
  `NcSelect` carries `inputLabel`. Add a manifest menu entry.
  - **spec_ref**: `specs/enrolment/spec.md#requirement-frontend-is-declarative-with-one-named-admissions-review-exception`
  - **acceptance_criteria**: board renders seeded pending applications; empty state shown when none exist
- [ ] 5.3 Add `src/views/SubjectChoicePicker.vue`: an interactive elective picker for a `CurriculumPlan`,
  showing live feedback against `minElectives`/`maxElectives`, mandatory combinations, and remaining
  `capacityByCourseId` before submission; strings via `t()`, data via the OpenRegister object API; any
  `NcSelect` carries `inputLabel`. Add a manifest menu entry.
  - **spec_ref**: `specs/school-structure/spec.md#requirement-frontend-is-declarative-with-one-named-subject-choice-picker-exception`
  - **acceptance_criteria**: picker renders a plan's electives with live rule feedback; blocks submission
    when a mandatory combination or capacity rule is violated

## 6. Tests and docs

- [ ] 6.1 PHPUnit for `AdmissionsDecisionGuard`, `AdmissionsWaitlistPromoter`, `ApplicationConversionHandler`,
  `SubjectChoiceConsentGuard`, `SubjectChoiceValidator`, `SubjectChoiceEnrolmentBridge` per the acceptance
  criteria in tasks 2.1–2.3 and 4.1–4.3 (minimum 75% coverage for new code per ADR-009).
  - **spec_ref**: all requirements in both delta specs
  - **acceptance_criteria**: all PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 6.2 Add `tests/e2e/spec-coverage/admissions-and-subject-choice.spec.ts` (Playwright): a coordinator
  reviews a pending application on `AdmissionsReviewBoard.vue`; a learner picks electives on
  `SubjectChoicePicker.vue` and sees live rule feedback.
  - **spec_ref**: `specs/enrolment/spec.md#scenario-a-coordinator-reviews-pending-applications-on-the-review-board`,
    `specs/school-structure/spec.md#scenario-a-learner-picks-electives-with-live-rule-feedback`
  - **acceptance_criteria**: test passes against a seeded dev instance; matches the `@e2e` references in
    both delta specs
- [ ] 6.3 Add Dutch and English translations for all new i18n keys (ADR-005). No hardcoded strings in either
  new custom view.
  - **spec_ref**: all requirements in both delta specs
  - **acceptance_criteria**: `nl`/`en` both populated for every new string

## 7. Verify

- [ ] 7.1 `openspec validate admissions-and-subject-choice --strict` clean; PHPUnit green for all six new
  PHP classes; Playwright `admissions-and-subject-choice.spec.ts` green; no dangling `$ref`s in the register
  JSON; `AdmissionsDecisionGuard`'s toelatingsrecht/schooladvies/capacity branches and
  `SubjectChoiceValidator`'s rule checks re-verified against seeded fixtures.
  - **spec_ref**: all
  - **acceptance_criteria**: strict validation + full test suite green; every guard/handler invariant
    re-verified end-to-end
