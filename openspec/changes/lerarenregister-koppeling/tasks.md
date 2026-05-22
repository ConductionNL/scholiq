## 1. Data Model & OpenRegister Integration

- [ ] 1.1 Create `openspec/specs/lerarenregister-integratie/spec.md` defining all 5 schemas (docent-profiel, bevoegdheid, nascholing-activiteit, herregistratie-cyclus, lerarenregister-sync-event) with schema.org vocabulary, required/optional flags, descriptions
- [ ] 1.2 Create `lib/Settings/scholiq_lerarenregister.json` register template with seed data (3× docent-profiel, 5× bevoegdheden, 4× nascholing-activiteiten, 4× herregistratie-cycli, marked with `@self` envelope)
- [ ] 1.3 Create repair-step in `lib/Repair/` to import register template via `ConfigurationService::importFromApp()` with idempotency check (match by slug)
- [ ] 1.4 Create database migration (if needed) for any app-specific columns beyond OpenRegister generic storage
- [ ] 1.5 Verify seed data loads correctly via `npm run test` or `occ app:enable scholiq && inspect DB`

## 2. Backend: hrmq Integration Layer

- [ ] 2.1 Create `Service/HrmqEmployeeService.php` with GraphQL-query wrapper
  - [ ] Method `getEmployee(employeeId): ?array` → queries hrmq via GraphQL, returns `{ name, email, startDate, ... }` or null
  - [ ] Method `findByBsn(bsn): ?array` → searches hrmq for employee by BSN
  - [ ] Error handling for GraphQL failures (log, return null with hint)
- [ ] 2.2 Create `Listener/HrmqEventListener.php` subscribing to hydra-shared event-bus
  - [ ] Event `employee.created` → no action yet (docent-profiel created explicitly by coordinator)
  - [ ] Event `employee.updated` → call `SyncService::pushProfileUpdate()` if docent-profiel exists
  - [ ] Event `employee.terminated` → call `ProfielService::markInactive(employeeId)`
- [ ] 2.3 Create tests: mock GraphQL responses, test listener event dispatch

## 3. Backend: Lerarenregister API Integration

- [ ] 3.1 Create `Service/LerarenregisterApiClient.php` wrapping openconnector
  - [ ] Method `verifyCompetency(registernummer, vak, niveau): bool` → POST /competencies/verify
  - [ ] Method `submitActivity(registernummer, activity): array` → POST /activities
  - [ ] Method `pullStatus(registernummer): array` → GET /docent/{registernummer}
  - [ ] Retry logic: 3 attempts, exponential backoff (5s/30s/120s)
  - [ ] All calls logged via SyncService
- [ ] 3.2 Create `Service/SyncService.php` managing two-way sync
  - [ ] Method `pushProfileUpdate(docentProfielId, fields)`: calls LerarenregisterApiClient, logs SyncEvent
  - [ ] Method `pushActivity(trainingActivityId)`: calls LerarenregisterApiClient, updates lerarenregisterId
  - [ ] Method `pullAllStatus()`: batch pull all docenten, detect drift
  - [ ] Method `resolveConflict(docentProfielId, driftLog)`: present UI options, require manual approval
- [ ] 3.3 Create tests: mock API responses (success, 422-error, timeout), verify SyncEvent logging

## 4. Backend: Core Services

- [ ] 4.1 Create `Service/DocentProfielService.php`
  - [ ] Method `create(employeeId, registernummer?)`: lookup hrmq employee, validate, create docent-profiel with refs
  - [ ] Method `update(profielId, fields)`: validation, audit-trail
  - [ ] Method `markInactive(profielId)`: set active=false, notify coordinator
  - [ ] Transactions + locking for concurrent updates
- [ ] 4.2 Create `Service/BevoegdheidService.php`
  - [ ] Method `add(profielId, vak, niveau, type, diplomaRef?)`: call LerarenregisterApiClient.verify, set status
  - [ ] Method `markExpiring(daysUntilExpiry)`: run daily, notify docent + coordinator
  - [ ] Method `validateAssignment(profielId, vak, niveau): bool` → used by rooster-spec
  - [ ] Audit-trail for all state changes
- [ ] 4.3 Create `Service/NascholingService.php`
  - [ ] Method `submit(profielId, formData): TrainingActivity` → status='aangevraagd', create Task
  - [ ] Method `validate(trainingId, { status, motivatie })`: state-machine enforcer, history-tracking
  - [ ] Method `calculateBalance(cyclusId)`: returns { totalPoints, categories, shortfall }
  - [ ] Automatic validation for lerarenregister-vooraf-erkend aanbieders
- [ ] 4.4 Create `Service/CyclusService.php`
  - [ ] Method `generateCycles(profielId, geregistreerdSinds)`: create 4-year cycles at T+0, T+4y, T+8y
  - [ ] Method `checkAllCycles()`: run daily via n8n, detect T-12/6/3/1 month reminders
  - [ ] Method `generateClosureReport(cyclusId)`: render PDF, upload to docudesk
  - [ ] Method `markSubmitted(cyclusId)`, `markUnmet(cyclusId)`
- [ ] 4.5 Create `Service/ReportingService.php`
  - [ ] Method `inspectionReport()`: export PDF + Excel with competency matrix
  - [ ] Method `complianceDashboard()`: fetch KPIs (registration %, avg saldo, overdue requests)
  - [ ] Method `incidentReport()`: list all uncompetency-givens incidents
  - [ ] Method `retentionReport()`: identify files approaching retention-end
  - [ ] SQL-view for performance (> 100 docenten)

## 5. Scheduled Jobs (n8n)

- [ ] 5.1 Create n8n workflow: Daily Cycle Check
  - [ ] Trigger: daily at 01:00 UTC
  - [ ] Logic: call CyclusService.checkAllCycles() → dispatch reminders T-12/6/3/1
  - [ ] Logging: log job execution + reminder count
- [ ] 5.2 Create n8n workflow: Daily Status Pull-Sync
  - [ ] Trigger: daily at 02:00 UTC
  - [ ] Logic: call SyncService.pullAllStatus() → detect drift
  - [ ] Notification: dispatch to coordinators if drift detected
- [ ] 5.3 Create n8n workflow: Monthly Retention Check
  - [ ] Trigger: 1st of month at 03:00 UTC
  - [ ] Logic: call ReportingService.retentionReport() → mail to FG-email
  - [ ] Logging: log report generation + recipient

## 6. Frontend: Docent Portaal

- [ ] 6.1 Create `src/views/DocentPortal/Dashboard.vue`
  - [ ] Components: CyclusProgressWidget, CategorieBalanceChart, OpenActivitiesTable, UpcomingDeadlines
  - [ ] Data: fetch docent-profiel, active cyclus, activiteiten via ObjectService
  - [ ] Responsive design (mobile-first)
- [ ] 6.2 Create `src/views/DocentPortal/SubmitActivity.vue`
  - [ ] Form: titel, aanbieder, categorie, punten, datum-range, bewijsstuk-upload
  - [ ] Validation: required fields, file-type check (PDF only)
  - [ ] Submit: call NascholingService.submit(), show confirmation
- [ ] 6.3 Create `src/views/DocentPortal/MyDocuments.vue`
  - [ ] List all bewijsstukken (refs) with download links
  - [ ] Button "AVG-Export": trigger ExportService.generateGdprExport()
- [ ] 6.4 Create `src/views/DocentPortal/BezwaarForm.vue`
  - [ ] Modal triggered from rejected-activity detail-view
  - [ ] Fields: motivatie-textarea
  - [ ] Submit: call NascholingService.submitBezwaar(), notify HR-director

## 7. Frontend: Coordinator Dashboards

- [ ] 7.1 Create `src/views/Coordinator/TeamOverview.vue`
  - [ ] Team-level KPIs: registration %, avg saldo, overdue activiteiten
  - [ ] Docent-list with filters (status, cyclus-phase, activiteit-pending)
  - [ ] Bulk-actions: send reminder, batch-validate (when landing)
- [ ] 7.2 Create `src/views/Coordinator/CompetencyMatrix.vue`
  - [ ] Table: docent, registernummer, status, bevoegdheden (vak/niveau/type), rooster-inzetting
  - [ ] Export-button: generate inspectie-rapport (PDF/Excel)
- [ ] 7.3 Create `src/views/Coordinator/IncidentRegister.vue`
  - [ ] Table: incident-type, docent, vak, niveau, rooster-periode, status
  - [ ] Detail-view: assignments, notes, resolution-option

## 8. Frontend: HR Validation Queue

- [ ] 8.1 Create `src/views/HrValidator/ActivitiesQueue.vue`
  - [ ] Task-list of status='aangevraagd' activiteiten
  - [ ] Detail-view: docent-naam, activiteit-details, bewijsstukken (download), kategorie-hint
  - [ ] Approve/Reject buttons with motivatie-field
  - [ ] Bulk-actions: validate batch, export queue-report
- [ ] 8.2 Create `src/views/HrValidator/BezwaarQueue.vue`
  - [ ] Task-list of status='bezwaar-ingediend' activiteiten
  - [ ] Show original rejection-motivatie + docent-bezwaar
  - [ ] Approve/Reject buttons with new motivatie

## 9. Integration Tests

- [ ] 9.1 Test hrmq-lookup: create mock employee, verify docent-profiel-creation pulls correct NAW
- [ ] 9.2 Test Lerarenregister-verification: mock API success/failure, verify bevoegdheid status-transitions
- [ ] 9.3 Test sync-flow: push aktiviteit, mock API response, verify SyncEvent + lerarenregisterId assignment
- [ ] 9.4 Test drift-detection: create docent with local status=geregistreerd, mock API returning status=opgeschort, verify drift-log + notification
- [ ] 9.5 Test nascholing-state-machine: create aktiviteit, submit, validate with autorized user, verify audit-trail
- [ ] 9.6 Test cyclus-generation: create docent with geregistreerdSinds=2020-06-15, verify cycli generated at T+0, T+4y, T+8y

## 10. End-to-End Tests (Browser)

- [ ] 10.1 Docent-flow: login as docent → dashboard → submit aktiviteit → upload bewijsstuk → confirm submission
- [ ] 10.2 Coordinator-flow: login as coordinator → create docent-profiel (employee lookup) → add bevoegdheid (verify via API mock)
- [ ] 10.3 Validator-flow: login as validator → open validation-queue → review bewijsstuk → approve/reject → see docent-notification
- [ ] 10.4 Sync-flow: activiteit status changes to 'gevalideerd' → background job pushes to Lerarenregister mock → verify SyncEvent

## 11. Performance & Load Testing

- [ ] 11.1 Profile bevoegdhedenmatrix-export for 500+ docenten: verify < 5s with SQL-view caching
- [ ] 11.2 Load-test daily pull-sync: 1000 docenten status-pull, verify job completes within 1 hour
- [ ] 11.3 Search-performance: index on register + schema + userId for fast filtered queries

## 12. Security & Compliance

- [ ] 12.1 Verify Lerarenregister-API-token stored in Nextcloud secrets-store (NOT hardcoded, NOT in config.php)
- [ ] 12.2 Verify all bewijsstuk-downloads check role + ownership (no IDOR)
- [ ] 12.3 Verify registernummer masked in logs (`LR-123****`) unless user has `unmask-registernumber` role
- [ ] 12.4 Test AVG-export completeness: verify all PII included (profiel, activiteiten, bewijsstukken)
- [ ] 12.5 Test retention-audit-trail: files marked for deletion, FG-approval, soft-delete logged

## 13. Documentation & Deployment

- [ ] 13.1 Create admin-guide: register configuration, Lerarenregister-API-credential setup, seed-data import
- [ ] 13.2 Create docent-guide: portaal overview, activiteit-submission workflow, bewijsstuk-upload
- [ ] 13.3 Create coordinator-guide: team-management, competency-matrix-generation, incident-handling
- [ ] 13.4 Create config-reference: nascholing-beleid JSON structure, notification-channel-config, retention-periods
- [ ] 13.5 Release notes: schema changes, new capabilities, migration-path (from manual spreadsheet if applicable)
