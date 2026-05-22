---
slug: elo-koppeling
title: ELO Koppeling — Push, Pull & SSO
status: proposed
feature_tier: must
depends_on_adrs: [ADR-001, ADR-002, ADR-005, ADR-022, ADR-031]
created: 2026-05-22
updated: 2026-05-22
profiles: [scholiq-koppeling-moodle, scholiq-koppeling-itslearning, scholiq-koppeling-sso]
---

# ELO Koppeling — Push, Pull & SSO

## Why

Scholiq manages administrative data (rosters, enrollments, timetables), while Educational Learning Environments (ELOs: Moodle, itslearning, Magister, Somtoday, Google Classroom, MS Teams) manage didactic data (content, assignments, grades, progress). Today these systems operate independently: teachers maintain duplicate class lists, grades are manually exported and re-entered, and new staff experience delayed access. This creates data corruption and operational friction.

An adapter-based integration layer solves this: scholiq pushes administrative data to the ELO in real-time (courses, users, enrollments), pulls didactic results back (submissions, scores, progress), and provides SSO via SURFconext/Entree so users land in the ELO without re-login. Standards-based adapters (OneRoster, LTI 1.3, xAPI, SCORM, Edu-iX) ensure portability across ELO vendors and institutional silos.

## ADDED Requirements

### Requirement: EloKoppeling creation and lifecycle

The system SHALL persist `EloKoppeling` objects representing one ELO connection per institution with lifecycle states draft, active, paused, and archived.

#### Scenario: EloKoppeling is created for a specific ELO type

GIVEN an ICT coordinator creates an EloKoppeling with eloType=moodle and syncDirection=bidi
WHEN the object is persisted
THEN the EloKoppeling SHALL exist in draft state and SHALL have empty lastPushSync and lastPullSync timestamps.

#### Scenario: EloKoppeling transitions to active after configuration

GIVEN an EloKoppeling in draft with a valid endpointUrl, credentialsRef, and retryPolicy configured
WHEN the ICT coordinator triggers the active transition
THEN the system SHALL move it to active state and allow push/pull operations to begin on the next sync cycle.

### Requirement: Course push via adapter pattern

The system SHALL implement an `EloAdapterInterface` with a `pushCourse()` method; each ELO type (Moodle, itslearning, Magister, Somtoday, Classroom, Teams) SHALL have a concrete adapter implementing this interface.

#### Scenario: Moodle adapter pushes a course via OneRoster CSV

GIVEN a CursusSync connects scholiq course "HAVO-4A-2026" to Moodle
WHEN the push cycle executes
THEN the MoodleAdapter SHALL serialize the course to OneRoster CSV format (academicSession, class, course rows) and POST it to the Moodle endpoint; CursusSync.syncStatus SHALL become synced and lastSyncAt SHALL be set.

#### Scenario: itslearning adapter pushes a course via OData REST

GIVEN a CursusSync connects scholiq course to itslearning with eloType=itslearning
WHEN the push cycle executes
THEN the ItslearningAdapter SHALL call the OData REST endpoint PATCH /odata/courses/{eloCourseId} with OneRoster-mapped fields (title, startDate, endDate); CursusSync.syncedFields SHALL list which fields succeeded.

### Requirement: User/Enrollment push on scholiq create/update events

The system SHALL listen to scholiq-generated object create/update events for Users and Enrollments and queue a push to all active EloKoppelingen for that institution within 60 seconds.

#### Scenario: User is created in scholiq → push queued within 60 seconds

GIVEN a new User object is created in scholiq (e.g., a new teacher)
WHEN the ObjectCreated event fires
THEN EloSyncService SHALL queue a `pushUser()` call to every active EloKoppeling for that tenant within 60 seconds; the queue entry SHALL include a retry counter starting at 0.

#### Scenario: Failed user push is retried with exponential backoff

GIVEN a pushUser() call fails (e.g., ELO endpoint returns 500)
WHEN the retry counter is < maxRetries (default 5)
THEN the system SHALL re-queue the push after backoffMs * 2^(retryCount) milliseconds (e.g., 2000ms, 4000ms, 8000ms...); if retries are exhausted, the SyncLog SHALL record the error and a Nextcloud notification SHALL alert the ICT coordinator.

### Requirement: Assignment and result pull via xAPI statements

The system SHALL implement a scheduled nightly pull job that fetches xAPI statements (or IMS Caliper events) from active pull-enabled EloKoppelingen and maps them to ResultaatPull records.

#### Scenario: Pull cycle fetches xAPI Submitted statements since last cursor

GIVEN an EloKoppeling has pullEnabled=true and a stored cursorToken from the previous pull
WHEN the nightly pull job executes
THEN EloPullService SHALL request xAPI statements where `timestamp > cursorToken` and `verb = Submitted OR verb = Scored`; each statement's object field (assignment ID) SHALL be matched to an OpdrachtPull and the actor (learner) to an InschrijvingSync to create a ResultaatPull record with status=submitted or status=graded.

#### Scenario: Pull result is append-only and immutable

GIVEN a ResultaatPull is created from an xAPI statement
WHEN a direct PATCH is attempted on the ResultaatPull
THEN the system SHALL reject the request and enforce read-only behavior (appendOnly: true schema flag prevents any field edits).

### Requirement: Conflict detection on dual mutation

The system SHALL detect cases where a field (e.g., course title) is mutated in both scholiq and the ELO between two sync cycles and flag the CursusSync with status=conflict.

#### Scenario: Course title mutated in scholiq and ELO → conflict detected

GIVEN CursusSync.lastDiff is null (no known conflict) and the course title is "HAVO-4A" in scholiq
WHEN a push cycle reads the ELO's current course title as "HAVO Year 4A"
THEN the system SHALL set CursusSync.syncStatus=conflict and store the diff in lastDiff: { title: { scholiq: "HAVO-4A", elo: "HAVO Year 4A" } }; the ConflictDiffView SHALL present both values to the ICT coordinator with merge decision options (scholiq-wins / elo-wins / manual).

#### Scenario: Conflict resolution policy applied

GIVEN a conflict is detected and the EloKoppeling.conflictPolicy is set to scholiq-wins
WHEN the ICT coordinator confirms conflict resolution
THEN the system SHALL re-push the scholiq value, update ELO with "HAVO-4A", set CursusSync.syncStatus=synced, and record the resolution in manualOverride with decidedBy/decidedAt.

### Requirement: LTI 1.3 launch with OIDC and context claims

The system SHALL implement `SsoLaunchService` that generates a LTI 1.3 Resource Link launch request with OIDC, signed JWT, and context claims (course, role, learner ID, assignment link if applicable).

#### Scenario: Learner clicks "Launch in Moodle" → LTI 1.3 launch

GIVEN a learner is authenticated in scholiq and clicks a "Launch in Course" button for a CursusSync
WHEN SsoLaunchService.launchSso(learnerId, context=course, courseId) is called
THEN the service SHALL:
1. Generate a signed LTI 1.3 JWT with claims: iss (scholiq URL), sub (learner ID), aud (ELO endpoint), nonce, context_id (course ID), roles (Student), name, email
2. Create an SsoSessie record with launchContext=course, launchContextId=courseId, sessionToken=JWT, expiresAt=(now + 1 hour)
3. Return an HTML form with target to the ELO's LTI login endpoint, hidden input with the JWT, and auto-submit JavaScript
4. The ELO SHALL accept the JWT, validate the signature, and place the learner in the course context without re-login.

### Requirement: SSO via SURFconext / Entree Federatie

The system SHALL reuse an existing SURFconext (HO/MBO) or Entree Federatie (PO/VO) SAML assertion or OIDC token in the LTI launch to avoid re-authentication.

#### Scenario: Learner has valid SURFconext session → federation token reused

GIVEN a learner is authenticated in scholiq via SAML assertion from SURFconext and clicks "Launch in Moodle"
WHEN SsoLaunchService checks for a valid federationAssertion
THEN the service SHALL embed the SURFconext SAML assertion (or OIDC token if via OIDC) into the LTI launch context; the ELO SHALL trust the SURFconext identity and create/confirm the user without requiring a login redirect.

#### Scenario: Federated session expired → re-authenticate before launch

GIVEN a learner's SURFconext session has expired
WHEN SsoLaunchService checks federationAssertion
THEN the service SHALL return null and trigger a SURFconext re-authentication flow before generating the LTI JWT; once re-authenticated, the launch proceeds.

### Requirement: OneRoster CSV export for nightly bulk sync

The system SHALL support exporting scholiq roster data in OneRoster 1.2 CSV format (academicSessions, classes, courses, enrollments, users, demographics) for ELOs that prefer batch import over per-object push.

#### Scenario: EloKoppeling with syncDirection=push and eloType=moodle exports OneRoster CSV

GIVEN an active EloKoppeling for Moodle with push enabled
WHEN the nightly sync job executes
THEN the system SHALL generate five CSV files per OneRoster 1.2 spec:
- academicSessions.csv (school calendar, terms)
- classes.csv (scholiq class groups)
- courses.csv (scholiq courses)
- enrollments.csv (learner, teacher enrollments)
- users.csv (scholar/staff identity, name, email, role)
4. POST the CSVs to the Moodle OneRoster endpoint and record success/failure in SyncLog.

### Requirement: SCORM 2004 package distribution

The system SHALL support distributing SCORM 2004 packages stored in scholiq as required learning objects to all active EloKoppelingen for a course.

#### Scenario: Course requires SCORM package → distributed on push

GIVEN a course has an attached SCORM 2004 package (stored as a File in scholiq) and syncDirection=push
WHEN the push cycle runs
THEN the system SHALL retrieve the SCORM ZIP, upload it to each ELO's SCORM provider endpoint, and record the deployment in CursusSync.syncedFields; the ELO SHALL serve the SCORM package to learners and report RTE (Run-Time Environment) statements back via xAPI.

### Requirement: Append-only audit trail for results and sessions

The system SHALL enforce append-only semantics on ResultaatPull, SsoSessie, and SyncLog to prevent accidental or malicious rewriting of result history.

#### Scenario: ResultaatPull cannot be edited after creation

GIVEN a ResultaatPull exists in the system
WHEN a PATCH request attempts to change the `score` field
THEN the system SHALL reject the request with a 405 Method Not Allowed; audit traces show the attempted mutation in AuditTrailService but the object remains unchanged.

### Requirement: Sync monitoring dashboard and error alerting

The system SHALL provide a SyncDashboard page showing sync status, duration, error counts, and last-sync timestamps for each EloKoppeling; errors and conflicts SHALL trigger Nextcloud notifications to the ICT coordinator.

#### Scenario: Push cycle fails → notification sent

GIVEN a push cycle completes with 3+ errors in SyncLog.errors
WHEN the cycle is marked complete
THEN NotificationService SHALL send a Nextcloud notification to users with the `elo-sync-admin` permission role: "ELO Push failed for Moodle (3 items): [errors list]"; the SyncDashboard SHALL highlight the error state.

#### Scenario: Conflict detected → ICT coordinator notified

GIVEN a conflict is detected and CursusSync.syncStatus becomes conflict
WHEN the sync cycle completes
THEN NotificationService SHALL alert the ICT coordinator with a link to the ConflictDiffView where they can review and merge the conflicting fields.

### Requirement: Deduplication check — no custom business logic beyond standard sync

The system SHALL leverage existing OpenRegister ObjectService, ImportService, WebhookService, and NotificationService without reimplementing CRUD, import, or notification capabilities.

**Reuse verified:**
- ObjectService for all 7 schema CRUD operations — NO custom entity managers or API endpoints
- CnDataTable + CnDetailPage for list/detail views — NO custom table components
- ImportService for OneRoster CSV parsing and bulk import — NO custom CSV parser
- WebhookService for sync event subscriptions (Course.updated → push) — NO custom event dispatchers
- NotificationService for ICT coordinator alerts — NO custom Nextcloud API calls
- AuditTrailService for append-only enforcement — NO custom immutability logic

Custom code is limited to:
- EloAdapterInterface + 6 concrete adapters (Moodle/itslearning/Magister/Somtoday/Classroom/Teams) — domain-specific external API mapping
- EloSyncService, EloPullService, SsoLaunchService — orchestration and retry policy
- ConflictResolutionService — diff detection and conflict merge decision logic
- Vue custom pages: SyncDashboard, ConflictDiffView, SsoLaunchButton — UI not provided by platform

### Requirement: Seed data provided

The system SHALL include 3-5 realistic example objects per schema in `lib/Settings/scholiq_register.json` for dev/test harness setup.

#### Scenario: Seed EloKoppelingen load on app install

GIVEN the scholiq app is installed
WHEN the SettingsLoadService runs the repair step
THEN the seed EloKoppelingen (demo-moodle-vo, demo-itslearning-po) SHALL be imported with idempotency (skip if versions match); developers can immediately see two active connections and related CursusSyncs, InschrijvingSyncs, and SyncLogs for testing.

## Standards Conformance

- **OneRoster 1.2** — Roster CSV/REST export and import
- **LTI 1.3 (Advantage)** — Tool launch with OIDC and context claims
- **xAPI (Tin Can)** — Statement pull for assignments, submissions, grades
- **IMS Caliper 1.2** — Alternative event feed for pull
- **SCORM 2004 4th Edition** — Package distribution and RTE communication
- **SURFconext / Entree Federatie** — SAML/OIDC token reuse for educational SSO
- **Edu-iX / Edu-V** — Dutch data exchange alignment

## Cross-app Dependencies

- **openconnector** — external API client library (Moodle REST, itslearning OData, Magister CDM, Somtoday API, Google Classroom API, MS Teams Graph)
- **openregister** — schema registration, ObjectService CRUD, import/export pipeline
- **openklant** — master data for learner/staff identity and lookup
- **decidesk** — legal/compliance gate for data minimization and DPA requirements
- **docudesk** — file storage for archived assignments and feedback artifacts
