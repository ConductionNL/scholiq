# Tasks — Enrolment

## Phase 1: OpenRegister schema

- [ ] Create `openregister/schemas/scholiq-enrolment.json` with all fields including status enum (pending/active/completed/withdrawn/failed/overdue), reminder_*_sent booleans, source enum, bulk_job_id. Add indexes on (learner_id, tenant_id, status), (course_section_id, tenant_id, status), (mandatory, due_date, status, tenant_id), (bulk_job_id). Write unit test confirming OpenRegister accepts the schema.

## Phase 2: PHP services

- [ ] Create `Scholiq\Service\BulkEnrolmentService`: implement `resolveAudience()` supporting nc_group_id, role, department, and csv_user_ids strategies using IGroupManager + IUserManager; implement `bulkEnrol()` with deduplication query, batch insert of max 100 Enrolments, BulkEnrolmentResult return. Unit tests for each audience strategy (mock IGroupManager/IUserManager).
- [ ] Create `Scholiq\Service\EnrolmentNotificationService`: wrap IManager with SUBJECT_SETTING_MAP constant; implement `dispatchEnrolmentNotification()` and `dispatchDueReminder()` checking user preference before dispatch via IConfig::getUserValue. Unit tests mock IManager and IConfig.
- [ ] Create `Scholiq\EventListener\EnrolmentCompletionListener`: listen for `scholiq.xapi.statement.received` event via IEventDispatcher; filter on verb.id = 'completed' or 'passed' + lesson.mandatory_training=true; look up Enrolment via ObjectService; transition to completed if final lesson; emit 'enrolment.completed' audit event; dispatch `scholiq.enrolment.completed` NC event. Integration test: post cmi5.completed xAPI statement, assert Enrolment status transitions.
- [ ] Create `Scholiq\BackgroundJob\EnrolmentDueReminderJob` extending TimedJob (interval 86400s): query mandatory active enrolments with upcoming due_date; dispatch reminders at T-30/T-7/T-1 using idempotency fields; set overdue status past due_date; emit audit events. Add job registration to Application.php. Integration test: seed Enrolment with due_date=T-7, run job, assert reminder_7_sent=true and notification dispatched.

## Phase 3: PHP controller

- [ ] Create `Scholiq\Controllers\EnrolmentController` extending `AuditedController`: list with filters (learner_id, course_section_id, mandatory, status, source), show, create single (emit 'enrolment.created'), PATCH status/reason (emit 'enrolment.withdrawn' or 'enrolment.completed'), POST bulk (calls BulkEnrolmentService, returns 202 + job_id), GET bulk/{jobId} for polling. Role guards: learner sees own only. Integration tests: single enrolment CRUD cycle, bulk-enrol with NC group, duplicate rejection (409).

## Phase 4: Add audit event types

- [ ] Add `enrolment.overdue`, `enrolment.reminder.sent` to `AuditEventTypes::KNOWN` in `lib/Bootstrap/AuditEventTypes.php`. PHPStan build must pass.

## Phase 5: Vue frontend

- [ ] Add route entries to `src/router/index.js` for /enrolments and /enrolments/:id.
- [ ] Create `src/stores/enrolmentStore.js` using `createObjectStore('/api/enrolments')`. Vitest test for list, create, update actions.
- [ ] Create `src/views/EnrolmentListView.vue` using CnDataTable; columns: learner, course section, status badge (red = overdue), mandatory icon, due_date, source; filter controls for status and mandatory. NcEmptyContent empty state with "Bulk Enrol" action button for admin/hr.
- [ ] Create `src/components/BulkEnrolmentModal.vue`: 3-step modal (audience picker with nc:group dropdown + CSV upload option; section + mandatory + due_date config; confirm summary); POST to /api/enrolments/bulk; poll job status with progress bar; show final summary (enrolled/skipped/failed counts). Playwright test: select group, set due_date, submit, assert summary appears.
- [ ] Create `src/views/EnrolmentDetailView.vue` using CnDetailPage + CnObjectSidebar; Audit Trail tab shows enrolment.* events from AuditTrail; Withdraw action opens reason capture dialog then sends PATCH.

## Phase 6: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Playwright integration test: full compliance-officer workflow — create course section → bulk-enrol via NC group → verify Enrolment list shows all members → simulate xAPI completed statement → verify Enrolment status transitions to completed.
