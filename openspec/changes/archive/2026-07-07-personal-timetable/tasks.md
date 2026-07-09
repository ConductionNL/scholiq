# Tasks: personal-timetable

## 1. Backend cross-object read

- [x] 1.1 Add `lib/Controller/TimetableController.php` (SPDX docblock; `@NoAdminRequired` + `@NoCSRFRequired`; inject `IUserSession`, `ObjectService`, `LoggerInterface`; 401 when no user). `mine()`: parse `from`/`to` (default current week), resolve the caller's cohorts (teacher via `Cohort.teacherIds`; learner via `Cohort.learnerIds` and `Enrolment.cohortId`), then query `Session` via `ObjectService` filtered to those `cohortId`s + window, ordered by `startsAt`. Empty (not error) when the caller has no cohorts. All reads RBAC/tenancy-scoped through `ObjectService`.
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions`
  - **acceptance_criteria**:
    - Teacher and learner both resolved from cohort membership; no cross-cohort leakage
    - No new schema/storage; only reads `Session`/`Cohort`/`Enrolment`
    - Unit tests cover teacher path, learner path, empty path, and windowing
- [x] 1.2 Register `timetable#mine` (GET `/api/timetable/mine`) in `appinfo/routes.php` with explicit auth; the route resolves to `TimetableController::mine` (route-auth + route-reachability PASS).
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions`
  - **acceptance_criteria**:
    - Route registered with explicit auth posture and resolvable target method

## 2. Frontend week view

- [x] 2.1 Add `src/api/timetable.js` (stateless functions over `@nextcloud/axios` + `generateUrl`, no Pinia store): `fetchMyTimetable(from, to)`.
- [x] 2.2 Add `src/views/MyTimetable.vue`: a week view (day columns; sessions as blocks showing title/time/location; click → session detail deep-link) with a today/week toggle; strings via `t()`, data via the API/`loadState` (no DOM reads); any `NcSelect` carries `inputLabel`; any modal in its own file. Add a "My timetable" manifest menu entry.
  - **spec_ref**: `specs/personal-timetable/spec.md#requirement-the-timetable-is-a-read-surface-only-over-existing-objects`
  - **acceptance_criteria**:
    - Week view renders seeded sessions; empty state shown for a user with no cohorts

## 3. Verify

- [x] 3.1 `openspec validate personal-timetable --strict` clean; PHPUnit for the controller green (teacher/learner/empty/window); vitest for the view; no dangling refs; route resolves.
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + unit tests green; read-only invariant verified
