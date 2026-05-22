# Tasks — ELO Koppeling: Push, Pull & SSO

## 1. Schema registration and seed data

- [ ] 1.1 Create `lib/Settings/scholiq_register.json` with 7 schemas (EloKoppeling, CursusSync, InschrijvingSync, OpdrachtPull, ResultaatPull, SsoSessie, SyncLog) following ADR-011 standards
- [ ] 1.2 Define lifecycle transitions: EloKoppeling (draft → active → paused → archived), CursusSync (draft → active → archived), InschrijvingSync (draft → active → archived)
- [ ] 1.3 Define append-only flags: ResultaatPull, SsoSessie, SyncLog with `appendOnly: true`
- [ ] 1.4 Define relations: EloKoppeling → CursusSync/InschrijvingSync/SyncLog, CursusSync → OpdrachtPull/InschrijvingSync, OpdrachtPull → ResultaatPull
- [ ] 1.5 Define calculations: EloKoppeling.syncAge, CursusSync.isSynced, OpdrachtPull.deadline, ResultaatPull.isLate, SyncLog.errorCount
- [ ] 1.6 Add seed data: 2 EloKoppelingen (Moodle, itslearning), 2 CursusSyncs (one synced, one conflict), 2 InschrijvingSyncs, 1 OpdrachtPull, 2 ResultaatPulls, 1 SsoSessie, 2 SyncLogs
- [ ] 1.7 Verify `openregister test validate scholiq_register.json` passes

## 2. Adapter infrastructure

- [ ] 2.1 Create `lib/Adapter/EloAdapterInterface.php` with methods: pushUser(), pushCourse(), pushEnrollment(), pullResults(), launchSso()
- [ ] 2.2 Create `lib/Adapter/MoodleAdapter.php` implementing EloAdapterInterface; import + use OpenConnector MoodleClient for REST calls
- [ ] 2.3 Create `lib/Adapter/ItslearningAdapter.php` — OData endpoint calls via OpenConnector
- [ ] 2.4 Create `lib/Adapter/MagisterAdapter.php` — CDM API calls via OpenConnector
- [ ] 2.5 Create `lib/Adapter/SomtodayAdapter.php` — Somtoday API calls via OpenConnector
- [ ] 2.6 Create `lib/Adapter/GoogleClassroomAdapter.php` — Google Classroom API (v1) calls via OpenConnector
- [ ] 2.7 Create `lib/Adapter/TeamsAdapter.php` — MS Graph API calls via OpenConnector
- [ ] 2.8 Create `lib/Adapter/AdapterFactory.php` to instantiate the correct adapter by eloType enum
- [ ] 2.9 Each adapter SHALL implement OneRoster 1.2 CSV format export for course/user/enrollment data
- [ ] 2.10 Unit test each adapter with mock API responses (success, 400/500 errors, timeout, rate-limit)

## 3. Sync orchestration services

- [ ] 3.1 Create `lib/Service/EloSyncService.php` listening to ObjectCreated/ObjectUpdated events for User, Course, Enrollment objects
- [ ] 3.2 Implement event listener to queue push jobs via EloSyncQueue (or existing queue mechanism)
- [ ] 3.3 Implement exponential backoff retry: maxRetries=5 (configurable), backoffMs=2000 (configurable), 2^n multiplier
- [ ] 3.4 On push failure, log to SyncLog with error record and trigger Nextcloud notification if itemsFailed > 0
- [ ] 3.5 Create `lib/Service/EloPullService.php` as a scheduled job (cron job, OCP\BackgroundJob\IJob)
- [ ] 3.6 Implement xAPI statement fetch using xAPI endpoint (if ELO supports) or IMS Caliper event feed; use cursorToken for pagination
- [ ] 3.7 Map xAPI Submitted/Scored statements to ResultaatPull records; link to OpdrachtPull via assignment ID and learner via enrollment
- [ ] 3.8 On pull cycle completion, create SyncLog record with itemsProcessed, itemsSucceeded, itemsFailed, cursorToken
- [ ] 3.9 Test pull cycle with mock xAPI statement feed (fixtures in tests/fixtures/xapi-statements.json)

## 4. Conflict detection and resolution

- [ ] 4.1 Create `lib/Service/ConflictResolutionService.php` with detectConflict(CursusSync, localData, remoteData): bool method
- [ ] 4.2 Implement field-by-field diff: if localField !== remoteField, set CursusSync.lastDiff and mark syncStatus=conflict
- [ ] 4.3 Implement resolution policies: scholiq-wins (re-push local), elo-wins (accept remote), manual (present diff to user)
- [ ] 4.4 Apply conflict policy on resolution: write manualOverride.decidedBy/decidedAt, set syncStatus=synced, re-sync if applicable
- [ ] 4.5 Unit test conflict detection with realistic field changes (title, startDate, description)

## 5. SSO and launch infrastructure

- [ ] 5.1 Create `lib/Service/SsoLaunchService.php` with launchSso(userId, context, contextId, eloKoppelingId): SsoLaunchResult
- [ ] 5.2 Implement LTI 1.3 JWT generation: iss, sub, aud, nonce, context_id, roles, name, email claims per LTI 1.3 spec
- [ ] 5.3 Implement JWT signing with RS256 (Nextcloud system key or app-specific keypair)
- [ ] 5.4 Check for valid federationAssertion (SURFconext SAML or OIDC token); if valid, embed in launch context; if expired, return null to trigger re-auth
- [ ] 5.5 Generate HTML form with auto-submit: POST to ELO LTI login endpoint, hidden input lti_message_hint=JWT, name/role/email fields
- [ ] 5.6 Create SsoSessie record with sessionToken=JWT, expiresAt=now+1h, launchContext (course/assignment/dashboard), federationAssertion
- [ ] 5.7 Unit test LTI JWT generation and signature validation; integration test with mock ELO endpoint

## 6. Frontend pages and components

- [ ] 6.1 Create `src/views/EloKoppelingen.vue` listing all EloKoppelingen with status indicator (active/paused/draft), last sync times
- [ ] 6.2 Create `src/views/EloKoppelingDetail.vue` with form editor (name, type, endpoint, credentials selector, sync direction, retry policy, conflict policy)
- [ ] 6.3 Create transition buttons: draft→active, active→paused, paused→active, any→archived
- [ ] 6.4 Create `src/views/CursusSyncs.vue` list with status badges (synced/conflict/error), last sync timestamp, manual override indicator
- [ ] 6.5 Create `src/views/CursusSyncDetail.vue` with read-only course mapping, sync status, error log, conflict resolution UI (if status=conflict)
- [ ] 6.6 Create `src/components/ConflictDiffView.vue` displaying scholiq vs. ELO field values side-by-side with radio buttons for merge decision
- [ ] 6.7 Create `src/components/SyncDashboard.vue` showing: push/pull status, sync duration trend chart, error count, last sync times, manual sync trigger buttons
- [ ] 6.8 Create `src/components/SsoLaunchButton.vue` widget for courses: onClick triggers launchSso and opens new window to ELO
- [ ] 6.9 Create `src/views/InschrijvingSyncs.vue` (read-only list, no detail page — pure sync audit)
- [ ] 6.10 Create `src/views/OpdrachtPulls.vue` (read-only list of assignments pulled from ELO)
- [ ] 6.11 Create `src/views/ResultaatPulls.vue` (read-only list of learner results pulled from ELO, sorted by submittedAt desc)
- [ ] 6.12 Create `src/views/SyncLogs.vue` (read-only list, searchable by eloKoppeling, direction, date range, error filter)
- [ ] 6.13 Add manifest menu entry: label="ELO Koppeling", icon="icon-network", visible if eloKoppeling schema is installed

## 7. Internationalization

- [ ] 7.1 Create `l10n/en.json` with keys: elo-koppeling, cursus-sync, inschrijving-sync, opdracht-pull, resultaat-pull, sso-sessie, sync-log (all pages and field labels)
- [ ] 7.2 Create `l10n/nl.json` with Dutch translations for all keys (use official Dutch terminology from Edu-iX spec)
- [ ] 7.3 Add i18n keys for: adapter type names (Moodle, itslearning, Magister, Somtoday, Classroom, Teams), sync statuses (gesynct, conflict, fout), roles (student, docent, observer)
- [ ] 7.4 Add i18n keys for error messages: "Credentials expired", "ELO offline", "Conflict detected", "Retry exhausted"
- [ ] 7.5 Verify all UI labels and messages use i18n keys (no hardcoded English/Dutch)

## 8. Integration and API endpoints

- [ ] 8.1 Create `lib/Controller/EloKoppelingController.php` — REST endpoints: GET /api/elo-koppeling, POST /api/elo-koppeling, PATCH /api/elo-koppeling/{id}, DELETE /api/elo-koppeling/{id}
- [ ] 8.2 Create `lib/Controller/CursusSyncController.php` — CRUD endpoints; add custom POST /api/cursus-sync/{id}/resolve-conflict for manual conflict resolution
- [ ] 8.3 Create `lib/Controller/SsoController.php` — GET /api/sso/launch?courseId=X&eloId=Y returns HTML form; handles LTI launch request from ELO
- [ ] 8.4 Create `lib/Controller/SyncDashboardController.php` — GET /api/sync-dashboard returns JSON with status, error counts, duration trends
- [ ] 8.5 Wire event listeners: ObjectCreatedEvent, ObjectUpdatedEvent → EloSyncService
- [ ] 8.6 Wire scheduled job: EloPullService → app.php (recurring nightly)
- [ ] 8.7 Create `lib/Listener/EloSyncEventListener.php` with listen() mapping events to sync queue

## 9. Testing and validation

- [ ] 9.1 Unit tests: EloAdapterInterface, each adapter, ConflictResolutionService (fixtures for each ELO format)
- [ ] 9.2 Integration tests: EloSyncService (event → queue), EloPullService (xAPI fetch → ResultaatPull), SsoLaunchService (JWT generation)
- [ ] 9.3 E2E test: Create EloKoppeling → verify active → push Course/User/Enrollment → verify CursusSync/InschrijvingSync synced → pull results → verify ResultaatPull
- [ ] 9.4 Functional test: Conflict scenario — modify course title in scholiq and ELO between syncs → verify conflict detected → verify resolution options work
- [ ] 9.5 Functional test: SSO launch — click button → verify SsoSessie created → verify HTML form POSTs to ELO with valid LTI JWT
- [ ] 9.6 Performance test: Push 100+ users → verify exponential backoff doesn't hammer ELO API
- [ ] 9.7 Security test: Verify credentials are stored encrypted (not plaintext); verify JWT is signed with RS256; verify append-only enforcement on ResultaatPull

## 10. Documentation and compliance

- [ ] 10.1 Add app README section: ELO Koppeling feature, supported adapters, sync flow diagram, SSO setup steps
- [ ] 10.2 Add admin guide: "ELO Koppeling Configuration" — create connection, test endpoint, configure push/pull, set conflict policy, monitor sync dashboard
- [ ] 10.3 Add user guide: "Launch in ELO" — one-click course launch for learners, role mapping (scholiq role → ELO role)
- [ ] 10.4 Document credential storage: credential store location, encryption method, rotation policy, DPA compliance
- [ ] 10.5 Document data minimization: which fields are pushed (name, email, role only), which fields are pulled (assignment name, score, submission timestamp only)
- [ ] 10.6 Add DPIA reference: which ELOs are recommended, which require AVG DPA, compliance notes for decidesk
- [ ] 10.7 Document OneRoster export format and CSV schema mapping
- [ ] 10.8 Document xAPI statement types pulled: Submitted, Scored; mapping to ResultaatPull fields

## 11. Seed data generation and import

- [ ] 11.1 Add seed objects in `lib/Settings/scholiq_register.json` under `components.objects[]` (or as separate seed file)
- [ ] 11.2 Verify `openregister test seed-import` loads EloKoppelingen, CursusSyncs, InschrijvingSyncs without errors
- [ ] 11.3 Verify demo SyncLogs are created with realistic timestamps and error examples
- [ ] 11.4 Manual QA: Install app → verify seed data visible in UI → verify SyncDashboard displays demo sync logs

## 12. Deduplication check

- [ ] 12.1 Verify no overlap with existing ObjectService, ImportService, WebhookService, NotificationService
- [ ] 12.2 Search openregister codebase for existing "sync", "adapter", "conflict", "pull" functionality — document findings
- [ ] 12.3 Confirm all CRUD, list, export, import operations use platform-provided services (NOT custom controllers)
- [ ] 12.4 Document custom code scope: adapters (domain-specific), sync services (orchestration), conflict resolution (business logic), Vue pages (custom UI not provided)

## 13. Build and release

- [ ] 13.1 Run `openspec validate specs/elo-koppeling/spec.md` — verify GIVEN/WHEN/THEN format
- [ ] 13.2 Run `npm run lint` (Vue/JS) and `vendor/bin/psalm` (PHP) — zero errors
- [ ] 13.3 Run `npm run build` — app bundle builds successfully
- [ ] 13.4 Run full test suite (`npm test`, `vendor/bin/phpunit`) — all tests pass
- [ ] 13.5 Merge to main and tag as `v{semantic-version}`
