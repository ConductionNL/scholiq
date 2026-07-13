# Tasks: pupil-dossier-notes

## 1. Schema — pupil-dossier capability

- [ ] 1.1 Add `DossierNote` to `lib/Settings/scholiq_register.json`: `learnerId`, `authorId`, `date`
  (format: date), `category` (enum: `observation`/`conversation`/`phone-call-home`/`concern`/`positive`),
  `body`, `confidentiality` (enum: `team-visible`/`care-team-only`/`private-to-author`, default
  `care-team-only`), `tenant_id`. `appendOnly: true`. No `x-openregister-lifecycle`.
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `appendOnly: true` set; no in-place edit path exists

- [ ] 1.2 Add `x-openregister-authorization.create: ["admin", "mentor", "coordinator"]` and
  `x-property-rbac.read` (`anyOf`: `role: admin`, `role: mentor`, `role: coordinator`, `match: field=
  authorId, operator=eq, value=$userId`) to `DossierNote`, per design.md Decision 1.
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-dossiernote-confidentiality-is-enforced-server-side-at-the-object-level-per-tier-rbac-beyond-that-floor-is-a-named-platform-gap`
  - **acceptance_criteria**:
    - A user holding none of `admin`/`mentor`/`coordinator` and not matching `authorId` cannot read any
      `DossierNote` via the object API
    - A `learner`-only user cannot create a `DossierNote`

- [ ] 1.3 Add `x-openregister-processing` to `DossierNote`: `code: scholiq-pupil-dossier-notes`,
  `rechtsgrond: public-task`, `dataCategories: [learnerId, authorId, category, confidentiality, body]`,
  `backend: scholiq.pupil-dossier-notes`, `lifecycle: draft`, `logReads: true`, `ownerUserId: admin`,
  `reviewIntervalMonths: 12`, `nextReviewAt: 2027-06-14` — same shape as `LearnerProfile`'s own block
  (`lib/Settings/scholiq_register.json:2537-2568`).
  - **spec_ref**: `openspec/changes/pupil-dossier-notes/specs/avg-verwerkingsregister/spec.md#requirement-scholiq-must-ship-its-processing-catalogue-as-draft-seed-content`
  - **acceptance_criteria**:
    - Entry seeds as `draft`; no personal-data values are copied into the seed

- [ ] 1.4 Add `BehaviourIncident` to `lib/Settings/scholiq_register.json`: `learnerId`, `reportedBy`,
  `occurredAt` (date-time), `what`, nullable `location`, nullable `involvedUserIds` (array of string),
  `severity` (enum: `low`/`medium`/`high`), `followUpActions` (array of `{recordedBy, recordedAt, action}`,
  default `[]` — same shape as `AttendanceFlag.interventions`,
  `lib/Settings/scholiq_register.json:8554-8592`), nullable `resolution`, nullable
  `escalatedSupportRequestId` (`$ref: SupportRequest`), `tenant_id`. `appendOnly: true`.
  `x-openregister-lifecycle`: `field: lifecycle`, `initial: open`, transitions `startHandling` (open →
  in-handling), `resolve` (open|in-handling → resolved). No guard on either transition (mirrors
  `AttendanceThreshold`'s own unguarded `activate`/`archive`).
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - `followUpActions` entries accumulate without mutating prior entries
    - `resolve` is reachable from both `open` and `in-handling`

- [ ] 1.5 Add `x-openregister-authorization.create: ["admin", "mentor", "coordinator"]` and
  `x-property-rbac.read` (`anyOf`: `role: admin`, `role: mentor`, `role: coordinator`, `match: field=
  reportedBy, operator=eq, value=$userId`) to `BehaviourIncident`.
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-creation-is-role-restricted-wellbeingcheckin-is-learner-authored`
  - **acceptance_criteria**:
    - Same denial/allow behaviour as task 1.2, scoped to `BehaviourIncident`

- [ ] 1.6 Add `x-openregister-notifications.incidentRecorded` to `BehaviourIncident`: `trigger: {type:
  created}`, `recipients: [{kind: groups, groups: [mentor, coordinator]}]`, `subject.nl`/`subject.en` set —
  same `kind: groups` shape `TlvApplication.tlvDecisionReceived` already uses
  (`lib/Settings/scholiq_register.json:7527-7543`).
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - Notification fires once per created incident; no PHP dispatch code

- [ ] 1.7 Add `x-openregister-processing` to `BehaviourIncident`: `code: scholiq-behaviour-incidents`,
  `rechtsgrond: public-task`, `dataCategories: [learnerId, reportedBy, what, location, involvedUserIds,
  severity, followUpActions, resolution, escalatedSupportRequestId]`, same owner/review shape as task 1.3.
  - **spec_ref**: `openspec/changes/pupil-dossier-notes/specs/avg-verwerkingsregister/spec.md#requirement-scholiq-must-ship-its-processing-catalogue-as-draft-seed-content`
  - **acceptance_criteria**: same as task 1.3, scoped to `BehaviourIncident`

- [ ] 1.8 Add `WellbeingCheckIn` to `lib/Settings/scholiq_register.json`: `learnerId`, `submittedAt`
  (date-time), `moodScale` (integer, `minimum: 1`, `maximum: 5`), nullable `comment`, `tenant_id`.
  `appendOnly: true`. No `x-openregister-lifecycle`. No `x-openregister-authorization` restriction (any
  authenticated user may create their own check-in — server-side enforcement that `learnerId` equals the
  submitting user is the same documented gap as `SupportRequest.raisedBy`, not addressed by this change).
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister`
  - **acceptance_criteria**:
    - `moodScale` rejects values outside 1-5
    - No `x-openregister-authorization.create` block present

- [ ] 1.9 Add `x-property-rbac.read` (`anyOf`: `role: admin`, `role: mentor`, `role: coordinator`, `match:
  field=learnerId, operator=eq, value=$userId`) and `x-openregister-notifications.checkInSubmitted`
  (`trigger: {type: created}`, `recipients: [{kind: groups, groups: [mentor]}]`, `subject.nl`/`subject.en`)
  to `WellbeingCheckIn`.
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-creation-is-role-restricted-wellbeingcheckin-is-learner-authored`
  - **acceptance_criteria**:
    - The submitting learner can read their own check-ins; a different learner cannot
    - `mentor` group receives a notification on every new check-in

- [ ] 1.10 Add `x-openregister-processing` to `WellbeingCheckIn`: `code: scholiq-wellbeing-checkins`,
  `rechtsgrond: public-task`, `dataCategories: [learnerId, moodScale, comment]`, same owner/review shape as
  task 1.3. Flag in the PR description (not the schema) that the privacy officer should confirm whether
  AVG Art. 9 special-category handling applies before activating this entry (design.md Security/Privacy
  Posture).
  - **spec_ref**: `openspec/changes/pupil-dossier-notes/specs/avg-verwerkingsregister/spec.md#requirement-scholiq-must-ship-its-processing-catalogue-as-draft-seed-content`
  - **acceptance_criteria**: same as task 1.3, scoped to `WellbeingCheckIn`

## 2. Frontend

- [ ] 2.1 Add `src/manifest.json` index/detail pages for `DossierNote`, `BehaviourIncident`,
  `WellbeingCheckIn` (list/create/edit/detail per the standard declarative pattern used by `attendance`/
  `grading`).
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-frontend-is-declarative-surfaced-on-the-learner-dossier-page-with-one-shared-custom-timeline-view`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added

- [ ] 2.2 Add three `object-list` widgets to `LearnerProfileDetail` (`lprof-dossier-notes`,
  `lprof-behaviour-incidents`, `lprof-wellbeing-checkins`), each `filter: {learnerId: "@objectId"}`,
  matching the existing `lprof-plans`/`lprof-attendance` shape. Extend the page's `layout` grid accordingly.
  - **spec_ref**: `specs/pupil-dossier/spec.md#requirement-frontend-is-declarative-surfaced-on-the-learner-dossier-page-with-one-shared-custom-timeline-view`
  - **acceptance_criteria**:
    - All three widgets render seeded objects scoped to the viewed learner
    - No existing `LearnerProfileDetail` widget or layout entry is removed

- [ ] 2.3 Add `src/views/PupilDossierTimelineView.vue`: fetches and chronologically merges `DossierNote`,
  `BehaviourIncident`, `WellbeingCheckIn`, `LearningPlan`, `SupportRequest`, `DeliberationRecord` for one
  learner via the OpenRegister object API (no DOM reads, no PHP controller); strings via `t()`; any
  `NcSelect` carries `inputLabel`. Add a manifest `type: "custom"` page entry and a link from
  `LearnerProfileDetail` (e.g. a `related` widget action or a page-level button).
  - **spec_ref**: `specs/pupil-dossier/spec.md#scenario-the-timeline-view-merges-notes-incidents-check-ins-and-the-care-chain`
  - **acceptance_criteria**:
    - Timeline renders a mix of seeded objects from all six schemas in chronological order
    - Empty state shown when a learner has none of the six

## 3. Tests and docs

- [ ] 3.1 Add `tests/e2e/spec-coverage/pupil-dossier.spec.ts` (Playwright): staff creates a `DossierNote`
  and a `BehaviourIncident` for a seeded learner, a learner submits a `WellbeingCheckIn`, and a mentor opens
  `PupilDossierTimelineView` to see all of them merged.
  - **spec_ref**: all `pupil-dossier` requirements carrying an `@e2e` reference
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance; matches every `@e2e` reference in the spec

- [ ] 3.2 PHPUnit schema-validation coverage for the three new schemas' `x-property-rbac`/
  `x-openregister-authorization` boundaries (deny-outside-floor, create-role-restriction) per the
  acceptance criteria in tasks 1.2/1.5/1.9.
  - **spec_ref**: `specs/pupil-dossier/spec.md#scenario-a-user-outside-the-enforced-floor-cannot-read-a-dossiernote-at-all`, `specs/pupil-dossier/spec.md#scenario-a-learner-cannot-author-a-dossiernote-about-another-learner`
  - **acceptance_criteria**:
    - All RBAC denial/allow cases covered

- [ ] 3.3 Add Dutch and English translations for all new i18n keys (notification subjects, manifest page
  titles, `PupilDossierTimelineView.vue` strings) per the platform's translation-extraction pipeline
  (ADR-005) — no hand-edited `l10n/*.json`.
  - **spec_ref**: all `pupil-dossier` requirements
  - **acceptance_criteria**:
    - No hardcoded strings in `PupilDossierTimelineView.vue`

- [ ] 3.4 File an OpenRegister platform-capability issue for row-conditional `x-property-rbac` (a `match`
  comparing one object field against another object field or against a caller-role-scoped value, not only
  against `$userId`), referencing this change's design.md Decision 1 as the motivating case.
  - **spec_ref**: `specs/pupil-dossier/spec.md#scenario-the-three-way-confidentiality-tier-is-a-documented-gap-not-a-fabricated-guarantee`
  - **acceptance_criteria**:
    - Issue filed against the OpenRegister core repo, linked from this change

## 4. Verify

- [ ] 4.1 `openspec validate pupil-dossier-notes --strict` clean; PHPUnit green; no dangling `$ref`s in the
  register JSON (including `BehaviourIncident.escalatedSupportRequestId → SupportRequest`); Playwright
  `pupil-dossier.spec.ts` green against a seeded dev instance; the RBAC-floor denial behaviour re-verified
  live (a non-staff, non-author account genuinely cannot fetch a `DossierNote` via the object API).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; RBAC-floor invariant re-verified end-to-end, not just
      asserted in a unit test
