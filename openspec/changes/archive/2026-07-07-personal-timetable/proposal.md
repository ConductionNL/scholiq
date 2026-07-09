---
kind: code
---

# Proposal: personal-timetable

## Why

scholiq already models scheduled class instances: the `Session` schema carries `cohortId`,
`courseId`, `lessonId`, `title`, `startsAt`, `endsAt`, and `location`, and cohort
membership is fully represented (`Cohort.teacherIds`, `Cohort.learnerIds`, and
`Enrolment.cohortId` for learners). But there is **no personal timetable surface**: nothing
answers a signed-in user's most basic question — "what are *my* upcoming classes, and
when/where?" Every school-information / LMS product (Magister, Somtoday, itslearning,
Canvas, Google Classroom) treats "my schedule / rooster" as a table-stake, and it is the
single most-opened screen for both learners and teachers.

The data to build it already exists; the gap is purely a read surface. A personal timetable
is a **one-hop cross-object query** — resolve the caller's cohorts (as teacher via
`Cohort.teacherIds`, as learner via `Cohort.learnerIds` / `Enrolment.cohortId`), then list
the `Session` objects for those cohorts in a time window, ordered by `startsAt`. No new
schema, no scheduling engine, no new storage — it consumes existing OpenRegister objects
through `ObjectService` (ADR-022), so OR's RBAC/tenancy scope it and the view can never
show a session the user isn't entitled to.

## What Changes

- Add `lib/Controller/TimetableController.php` (SPDX docblock; `@NoAdminRequired`,
  `@NoCSRFRequired`; inject `IUserSession`, `ObjectService`): `mine()` returns the caller's
  `Session` objects for a `from`/`to` window (default: current week), resolved by first
  finding the caller's cohorts (teacher-of via `teacherIds`; learner-of via `learnerIds` /
  `Enrolment`), then querying `Session` filtered to those `cohortId`s and the window,
  ordered by `startsAt`. All reads go through `ObjectService` (RBAC/tenancy-scoped); a user
  with no cohorts gets an empty timetable, never an error.
- Register the route `timetable#mine` (GET `/api/timetable/mine?from=&to=`) in
  `appinfo/routes.php` with explicit auth (route-auth + route-reachability PASS).
- Add `src/api/timetable.js` (stateless functions over `@nextcloud/axios` + `generateUrl`,
  no store) and `src/views/MyTimetable.vue`: a week view (day columns, sessions as blocks
  with title/time/location, click → session detail deep-link), plus a `today`/`week` toggle;
  strings via `t()`, server data via `loadState`/the API (no DOM reads). Add a "My timetable"
  entry to the manifest menu.
- The role context is derived server-side (teacher vs learner) so the same endpoint serves
  both; a teacher sees the sessions of cohorts they teach, a learner the sessions of cohorts
  they're enrolled in.

## Impact

- Affected: new `TimetableController`, one route, `src/api/timetable.js`, `src/views/MyTimetable.vue`,
  manifest menu entry, and their unit/e2e tests. NO schema change, NO new storage, NO
  scheduling engine — pure read surface over existing `Session`/`Cohort`/`Enrolment` objects.
- Out of scope: creating/editing sessions (owned by the existing session/attendance surfaces),
  room-conflict planning, and iCal export (a natural follow-up, not required here).
