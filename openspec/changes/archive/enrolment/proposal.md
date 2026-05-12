## Why

Enrolment is the link between a learner identity and a course section. For the compliance-audit wedge the critical workflow is: a compliance officer bulk-enrols all active employees in a mandatory regulation-linked course, assigns a due date, and the system handles notifications at T-30 / T-7 / T-1. Without `Enrolment` objects in OpenRegister, the compliance-audit spec cannot compute coverage % (who is enrolled vs who has completed) and the dashboard cannot show mandatory training status per employee.

## What Changes

- Add OpenRegister schema `scholiq-enrolment` (`Enrolment` entity per ARCHITECTURE.md §3.1) with: id, learner_id (NC user id), course_section_id, status enum, enrolled_at, completed_at, mandatory bool, due_date, source enum, manager_id.
- Add `Scholiq\Controllers\EnrolmentController` (list, show, create single, bulk-create, update status, withdraw).
- Add `Scholiq\Service\BulkEnrolmentService` that resolves audience (NC group, role, dept, CSV) into individual Enrolment objects.
- Add `Scholiq\Service\EnrolmentNotificationService` implementing the T-30/T-7/T-1 due-date reminder dispatch using `OCP\Notification\IManager`.
- Add `OCP\BackgroundJob\TimedJob` `EnrolmentDueReminderJob` running daily, querying Enrolments where mandatory=true and due_date within T-30/T-7/T-1 threshold.
- Add Vue views: `EnrolmentListView`, `BulkEnrolmentModal`.
- All mutations emit audit events per ADR-008.

## Capabilities

### New Capabilities

- `enrolment`: Enrolment entity CRUD, bulk-enrol via audience selection, mandatory flag + due_date, source tracking, T-30/T-7/T-1 notification dispatch.

### Modified Capabilities

(none — course-management/nextcloud-app already landed)

## Impact

- **`scholiq-enrolment` schema**: compliance-audit spec queries Enrolment objects to compute "how many mandatory enrolments exist per regulation" (denominator); xAPI statements provide the numerator (completions).
- **`BulkEnrolmentService`**: audience resolution touches `OCP\IGroupManager` (NC groups → user list) and `OCP\IUserManager` (active user check). Must respect tenant_id scoping.
- **`EnrolmentDueReminderJob`**: registers as a `TimedJob` in `Application.php`; fires daily. Downstream specs (compliance-audit) do not need to duplicate this logic.
- **`OCP\Notification\IManager`**: the notification subjects `compliance_due`, `assignment_due_soon`, `cohort_enrolment_done` defined here; downstream compliance-audit spec emits `compliance_evidence_required` separately.
- **Wedge scope**: Studielink HE enrolment, 30-60-90 onboarding templates, and cross-institution credit-transfer are explicitly out of scope for Phase 1. Only corporate/government bulk-enrol and manual enrolment are in scope.
