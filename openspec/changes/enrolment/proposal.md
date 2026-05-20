## Why

Enrolment is the gateway from identity to learning record. Without enrolment objects in OpenRegister, every downstream capability — assessment, certification, compliance audit — has no subject. Phase 1 shipped corporate/government bulk-enrol and mandatory-flag tracking. This change extends enrolment with the three flows that unlock the higher-education and corporate-onboarding markets: automated Studielink intake (Dutch HE legal requirement), 30-60-90 onboarding templates (line-manager top workflow), and prerequisite/eligibility enforcement (compliance and accreditation obligation).

## What Changes

- Add OpenRegister schema `OnboardingTemplate` — a named set of milestone-day → course-list pairs (days 1, 30, 60, 90) that HR assigns to a new hire's role to schedule enrolments automatically.
- Add OpenRegister schema `EnrolmentRule` — a declarative trigger (hire event / Studielink intake / certificate expiry) + audience condition + course-set + optional template reference. OR's lifecycle engine evaluates rules and creates Enrolment objects without PHP controllers.
- Extend `Enrolment` schema with `prerequisitesMet` (boolean), `onboardingTemplateId` (uuid|null), `onboardingMilestoneDay` (integer|null), and `lmsProvisionedAt` (datetime|null) to track prerequisite check status, template linkage, and LMS provisioning.
- Add `lib/Lifecycle/PrerequisiteCheckGuard.php` — validates that all prerequisite course Enrolments for the target Course are `completed` before allowing a new Enrolment to be created. Blocks with structured error payload listing the failed prerequisites.
- Add `lib/Lifecycle/StudielinkEnrolmentHandler.php` — receives the `openconnector.studielink.intake.received` event published by OpenConnector's Edukoppeling adapter; idempotently creates or updates a `LearnerProfile` + creates an `Enrolment` with `source=studielink`; dispatches an LMS account provisioning job that MUST complete within 60 seconds.
- Add `lib/Lifecycle/OnboardingTemplateApplicator.php` — receives the `learner.profile.created` audit event; if the new LearnerProfile has a `roleSlug` matching an active `EnrolmentRule` with `triggerEvent=hire`, applies the matched `OnboardingTemplate` by creating Enrolment objects at their scheduled milestone days.
- Extend `src/manifest.json` with `EnrolmentRules` index/detail pages and a `TeamBulkEnrolModal` custom component for line-manager bulk-enrolment.

## Capabilities

### New Capabilities

- `auto-enrol-studielink`: Incoming Studielink enrolments (via OpenConnector Edukoppeling adapter) automatically create a `LearnerProfile` + `Enrolment` and provision an LMS account within 60 seconds.
- `onboarding-template`: HR selects a role-matched `OnboardingTemplate` when creating a new hire; Enrolment objects are scheduled across milestone days 1, 30, 60, and 90.
- `enrolment-rule`: Declarative `EnrolmentRule` objects trigger Enrolment creation on hire events, Studielink intake, or certification expiry — no imperative code required for new trigger definitions.
- `prerequisite-enforcement`: `PrerequisiteCheckGuard` blocks Enrolment creation and returns a structured list of unmet prerequisites when a Course declares prerequisite courseIds.
- `team-bulk-enrol`: Line managers multi-select direct reports in `TeamBulkEnrolModal` and enrol them in a course with a shared deadline; the modal polls a team progress bar via OR's batch endpoint.

### Modified Capabilities

- `enrolment` (Phase 1): `Enrolment` schema gains `prerequisitesMet`, `onboardingTemplateId`, `onboardingMilestoneDay`, and `lmsProvisionedAt` fields. All additions are optional/nullable — backward-compatible.

## Impact

- **`lib/Settings/scholiq_register.json`**: add `OnboardingTemplate` + `EnrolmentRule` schema blocks; patch `Enrolment` schema with 4 new optional fields; add `x-openregister-lifecycle.requires: PrerequisiteCheckGuard` precondition on the `activate` transition.
- **`lib/Lifecycle/PrerequisiteCheckGuard.php`**: new file — lifecycle guard (ADR-031 legitimate seam).
- **`lib/Lifecycle/StudielinkEnrolmentHandler.php`**: new file — OR audit-event handler (ADR-031 legitimate seam); bridges OpenConnector Edukoppeling adapter to Scholiq's Enrolment domain.
- **`lib/Lifecycle/OnboardingTemplateApplicator.php`**: new file — OR audit-event handler (ADR-031 legitimate seam); applies milestone-day Enrolment scheduling on hire.
- **`lib/AppInfo/Application.php`**: register the two new OR audit-event listeners.
- **`src/manifest.json`**: add `EnrolmentRules` index/detail pages, `TeamBulkEnrol` custom page.
- **`src/views/TeamBulkEnrolModal.vue`**: new custom Vue component for line-manager multi-select enrolment.
- **OpenConnector**: Edukoppeling adapter (openconnector adapter, NOT Scholiq code) must publish `openconnector.studielink.intake.received` events to OR's event bus — this is a coordination item with the `data-exchange` spec, not a Scholiq code change.
