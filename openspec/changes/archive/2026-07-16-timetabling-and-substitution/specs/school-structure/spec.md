## ADDED Requirements

### Requirement: Room is persisted as a bookable resource

The system MUST persist `Room` as an OpenRegister object: `name`/`code`, `capacity` (integer), `kind`
(`classroom|lab|gym|auditorium|online|other`), `facilities` (array of strings), optional `buildingCode`/
`floor`, `tenant_id`. `Room` MUST NOT carry an `x-openregister-lifecycle` workflow beyond the standard active
toggle — it is a resource-metadata object, the same shape as `Material`, not a Session-like state machine.

#### Scenario: A coordinator creates a Room with capacity and facilities

- **GIVEN** a coordinator creating a bookable resource
- **WHEN** they set `name`, `capacity`, `kind`, and `facilities`
- **THEN** a `Room` object is persisted with those values and is available for a `Session` to reference

### Requirement: Session references a Room and carries substitution and import metadata

`Session` MUST gain the following additive fields, all nullable/optional so existing rows continue to
validate unchanged: `roomId` (`$ref Room`) — the canonical structured room reference, kept alongside the
existing free-text `location` (which remains the display label for an online-meeting URL or for a Session
with no `Room` row); `externalRef` (string) — an idempotency key used only by timetable import to upsert
without duplicating; `substituteTeacherId` (Nextcloud user id); `changeReasonKind` (enum:
`teacher-absence|room-unavailable|timetable-change|other`); `changeReason` (free text); `affectedLearnerIds`
/ `affectedParentIds` (materialised Nextcloud-user-id arrays, computed and persisted at the `cancel`/
`substitute-teacher` transitions, mirroring `ConferenceRound.invitedLearnerIds`). `Session.x-openregister-
lifecycle.transitions` MUST gain a `substitute-teacher` self-loop (`from: [scheduled, in-progress], to:` the
same state) alongside the existing `cancel` transition; both `cancel` and `substitute-teacher` MUST declare
`requires: SessionChangeGuard`.

#### Scenario: A Session references a real Room instead of only a free-text location

- **GIVEN** a `Room` object with a declared `capacity`
- **WHEN** a coordinator sets a Session's `roomId` to that Room
- **THEN** the Session's structured room reference resolves to the Room's real capacity and facilities
- **AND** the Session's existing `location` field is left as-is (unchanged, still usable for an online URL
  or display label)

#### Scenario: An imported Session carries an externalRef without being user-visible as such

<!-- @e2e exclude Field-shape/idempotency-key invariant only; behaviour is covered by the timetabling capability's import scenarios. -->

- **GIVEN** a `Session` created via a `timetable-import` `DataExchangeJob`
- **WHEN** the Session is persisted
- **THEN** its `externalRef` is set to the source system's occurrence id
- **AND** the field is used only for import matching, never rendered as a primary user-facing value

#### Scenario: An existing Session without any new field set continues to validate

- **GIVEN** a pre-existing `Session` row with `roomId`, `externalRef`, `substituteTeacherId`,
  `changeReasonKind`, `changeReason`, `affectedLearnerIds`, and `affectedParentIds` all unset
- **WHEN** the row is read or re-saved unchanged
- **THEN** it validates without error — none of the new fields are required

## MODIFIED Requirements

### Requirement: Persist school-structure domain objects in OpenRegister

The system MUST persist `Programme`, `CurriculumPlan`, `Cohort`, `Session`, `Material`, and `Room` as
OpenRegister objects with `x-openregister-lifecycle` (draft → published → archived for Programme/Course;
scheduled → in-progress → completed | cancelled for Session, plus the guarded `substitute-teacher` self-loop
transition described below) and `x-openregister-relations` (Cohort↔Programme/Course, Session↔Cohort/Course/
Room, Material↔Course/Lesson/Session). `Room` carries no workflow lifecycle of its own (see "Room is
persisted as a bookable resource").

#### Scenario: School-structure objects persisted in OpenRegister

- **GIVEN** the school-structure domain schemas are registered
- **WHEN** a coordinator creates a Programme, CurriculumPlan, Cohort, Session, Material, or Room
- **THEN** each is stored as an OpenRegister object carrying the declared lifecycle states and relations
