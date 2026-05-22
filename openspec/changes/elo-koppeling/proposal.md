## Why

Scholiq manages administrative data (rosters, users, enrollments, timetables), while ELOs (Moodle, itslearning, Magister, Somtoday, Google Classroom, Teams) manage didactic data (content, assignments, grades, progress). Today these are separate: teachers maintain duplicate class lists, grades are manually exported from the ELO and re-entered in scholiq, and new staff get delayed access. This creates friction and data corruption.

An adapter-based integration layer solves this: scholiq pushes administrative changes to the ELO in real-time (courses, users, enrollments), pulls didactic results back (submissions, scores, progress), and provides SSO so users land in the ELO without re-login. Standards-based adapters (OneRoster, LTI 1.3, xAPI, SCORM) ensure portability across ELO vendors.

## What Changes

### New Schemas (7) — `lib/Settings/scholiq_register.json`

- **EloKoppeling** (slug `elo-koppeling`) — configuration record for one ELO connection per institution. Type (moodle/itslearning/magister/somtoday/classroom/teams), endpoint URL, credentials ref, sync status, last sync, sync direction (push/pull/bidi), retry policy.
- **CursusSync** (slug `cursus-sync`) — tracks scholiq course ↔ ELO course mapping. Status (synced/conflict/error), sync fields, last diff, manual override option.
- **InschrijvingSync** (slug `inschrijving-sync`) — tracks scholiq enrollment → ELO enrollment mapping. Learner, course, role (student/teacher/observer), sync status, synced-at timestamp.
- **OpdrachtPull** (slug `opdracht-pull`) — learner assignment pulled from ELO. Assignment ID, name, deadline, max score, weighting. Read-only in scholiq.
- **ResultaatPull** (slug `resultaat-pull`, appendOnly: true) — learner submission result pulled from ELO. Assignment, learner, submitted-at, score, grader, status (submitted/graded/late/missing), feedback ref. Append-only history.
- **SsoSessie** (slug `sso-sessie`, appendOnly: true) — SSO session token issued to a user for an ELO launch. User, ELO, session token, expiry, launch context (course/assignment/dashboard). Append-only audit.
- **SyncLog** (slug `sync-log`, appendOnly: true) — audit trail of sync cycles. Connection, direction, payload hash, items processed, errors, duration. Append-only immutable.

### New PHP (6, ADR-031 legitimate exceptions)

- `lib/Adapter/EloAdapterInterface.php` — common contract: `pushUser()`, `pushCourse()`, `pushEnrollment()`, `pullResults()`, `launchSso()`.
- `lib/Adapter/MoodleAdapter.php`, `ItslearningAdapter.php`, `MagisterAdapter.php`, `SomtodayAdapter.php`, `GoogleClassroomAdapter.php`, `TeamsAdapter.php` — per-ELO concrete adapters.
- `lib/Service/EloSyncService.php` — event listener on scholiq objects (Course, User, Enrollment changes); queues pushes, retries failed syncs.
- `lib/Service/EloPullService.php` — scheduled job (nightly) pulling results via xAPI/Caliper statements; maps to ResultaatPull.
- `lib/Service/SsoLaunchService.php` — generates LTI 1.3 launch context and SURFconext/Entree-reused SAML/OIDC tokens; creates SsoSessie.
- `lib/Service/ConflictResolutionService.php` — detects & presents dual-mutate conflicts; implements per-koppeling conflict policy (scholiq-wins / elo-wins / manual).

### New Frontend

Manifest pages: EloKoppelingen / EloKoppelingDetail, CursusSyncs / CursusSyncDetail, InschrijvingSyncs (list, read-only), OpdrachtPulls (list, read-only), ResultaatPulls (list, read-only), SyncLogs (list, read-only). Custom pages: SyncDashboard (status overview, retry controls), ConflictResolutionUI (diff view, merge decision). One nav `menu` entry: "ELO Koppeling".

Vue components: `EloKoppelingForm.vue` (create/edit with type selector, endpoint URL, credentials), `SyncDashboard.vue` (push/pull status, sync duration charts, error log), `ConflictDiffView.vue` (scholiq vs ELO diff, merge decision radio buttons), `SsoLaunchButton.vue` (one-click launch widget for courses).

### i18n

`l10n/en.json` + `l10n/nl.json` — adapter names, sync status terms, conflict resolution options, push/pull terminology, SSO launch labels.

## Capabilities

### New Capabilities

- `elo-koppeling`: EloKoppeling, CursusSync, InschrijvingSync, OpdrachtPull, ResultaatPull, SsoSessie, SyncLog schemas with lifecycle and append-only flags; adapter pattern for Moodle / itslearning / Magister / Somtoday / Classroom / Teams; event-driven user/course/enrollment push with exponential backoff retry; nightly xAPI/Caliper pull of results; conflict detection with per-koppeling resolution policy; LTI 1.3 + SURFconext/Entree SSO launch; manifest pages + custom sync dashboard, diff viewer, launch controls.

### Modified Capabilities

*(none — this is a new integration layer; no existing scholiq schemas are modified)*

## Impact

- 7 new schemas registered in scholiq `_register.json`
- 6 PHP adapters + 3 sync/conflict services + 1 SSO service
- 5 manifest pages + 2 custom Vue views + 1 dashboard component
- Event listeners on Course, User, Enrollment creates/updates/deletes
- Scheduled job for nightly xAPI pull
- i18n keys for adapter UX, conflict resolution, sync status
- Nextcloud notifications on sync errors & conflict detection
- Audit trail via SyncLog (append-only)

## Open Questions

*(none — all data model and architecture from context-brief is concrete)*
