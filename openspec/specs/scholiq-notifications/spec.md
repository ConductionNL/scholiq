---
status: done
---

# scholiq-notifications Specification

**Status**: in-progress
**OpenSpec changes**:
- fix-dashboards-settings-notifications

## Purpose
Declares Scholiq's learner-facing notifications as `x-openregister-notifications` rules in the register, using only OpenRegister's verified engine dialect so the platform delivers them. Covers the four core learner events — grade availability, credential issuance, attendance flags, and course/lesson completion — each routed to the affected user, with delivery honouring the per-user override preference set through the Scholiq settings panel. Notification rendering and dispatch are performed by OpenRegister; Scholiq declares rules only and issues no imperative Nextcloud notifications.
## Requirements
### Requirement: Annotated schemas MUST declare notifications in the verified engine dialect

Every `x-openregister-notifications` block in `scholiq_register.json` MUST use only verified keys: `trigger.type` (one of created|updated|transition|scheduled|threshold|calculatedChange), `channels[]`, `recipients[]` with `kind` of field|groups|object-acl, and inline `subject` with `nl` and `en` strings. Legacy keys (`channel`, `recipient`/`recipientField`/`recipientFromTenantRole`, `@self.`, `lifecycleEnter`, boolean `calculated`, `userPreferenceKey`, `idempotencyKey`, `alsoDispatchLifecycle`, `event`, `template`) MUST NOT remain.

#### Scenario: Legacy lifecycle-enter rule migrated to a transition trigger
<!-- @e2e exclude Register-JSON migration mechanics (verified-dialect rewrite); asserted by the register-validation suite and tests/validate-register.js, no scholiq DOM surface to drive -->

- **GIVEN** the legacy `Credential.issuedToLearner` rule used `trigger.lifecycleEnter: "issued"` and `recipient: "@self.learnerId"`
- **WHEN** the rule is migrated
- **THEN** it uses `trigger.type: "transition"` with `action: "issue"`
- **AND** `recipients` is `[{"kind": "field", "field": "learnerId"}]`
- **AND** `subject` carries both `nl` and `en` strings

#### Scenario: Legacy boolean-expiry flag reworked into a numeric calculatedChange
<!-- @e2e exclude Register-JSON migration mechanics; asserted by the register-validation suite, no scholiq DOM surface -->

- **GIVEN** the legacy `Credential.expiringSoonAlert` rule used `trigger.calculated: "isExpiringIn30Days", eq: true`
- **WHEN** the rule is migrated
- **THEN** it uses a numeric `trigger.type: "calculatedChange"` on a days-to-expiry field crossing a threshold
- **AND** no boolean `calculated` flag remains

### Requirement: Field recipients MUST resolve to Nextcloud user IDs and tenant roles MUST map to groups

Every `kind:field` recipient MUST reference a schema property holding a Nextcloud user ID (`learnerId`, `managerId`, `requestedBy`, `submittedBy`). Legacy `recipientFromTenantRole` values MUST become `kind:groups`.

#### Scenario: Tenant-role recipient becomes a groups recipient
<!-- @e2e exclude Register-JSON recipient-mapping mechanics; asserted by the register-validation suite, no scholiq DOM surface -->

- **GIVEN** the legacy `Regulation.onPublished` rule used `recipientFromTenantRole: "compliance-officer"`
- **WHEN** the rule is migrated
- **THEN** `recipients` is `[{"kind": "groups", "groups": ["compliance-officer"]}]`

### Requirement: Rules with no verified-dialect equivalent MUST be deferred, not left in the legacy dialect

Rules depending on engine features with no verified equivalent (non-numeric field change, expression conditions, per-role fan-out, `idempotencyKey`/`alsoDispatchLifecycle`) MUST be removed from the register and documented as deferred, because the engine silently ignores legacy-dialect blocks.

#### Scenario: Non-numeric field-change rule is held pending the engine change
<!-- @e2e exclude Deferred-rule bookkeeping (rule removed from register + recorded in proposal Caveats); no scholiq DOM surface -->

- **GIVEN** the legacy `Regulation.officerAlertOnCoverageDrop` rule changed `ragStatus` to `red` (a string)
- **WHEN** the migration runs
- **THEN** the rule is removed from `scholiq_register.json`
- **AND** the proposal's Caveats record it as held pending `notification-updated-field-change-condition`

### Requirement: Core learner events MUST emit declarative nc-notification rules
The four core learner-facing events MUST be declared as `x-openregister-notifications` rules in `lib/Settings/scholiq_register.json` using only the verified engine dialect, each on the `nc-notification` channel with a `recipients[]` entry of `kind: field` resolving to the affected user, and an inline `subject` with `nl` and `en` strings: (1) a grade/final-grade becoming available to the learner, (2) a credential being issued to the learner, (3) an attendance flag being raised for the learner (and/or their mentor), and (4) a course/lesson completion. No scholiq PHP code MUST imperatively create Nextcloud notifications; delivery and rendering MUST be performed by OpenRegister's `AnnotationNotificationDispatcher` + `AnnotationNotifier` under app id `openregister`.

#### Scenario: Credential issuance notifies the learner
<!-- @e2e exclude Notification delivery is OpenRegister's AnnotationNotificationDispatcher (app id openregister); scholiq only declares the rule (verified by the register-validation suite). No scholiq DOM surface drives NC notification fan-out. -->
- **GIVEN** a `Credential` with an `issuedToLearner`-style rule on the `issue` transition
- **WHEN** the credential transitions to issued
- **THEN** OpenRegister delivers an `nc-notification` to the learner resolved from the `learnerId` field
- **AND** no scholiq class calls `INotificationManager::createNotification()`

#### Scenario: Grade availability notifies the learner
<!-- @e2e exclude Notification delivery is OpenRegister-side; scholiq declares the verified-dialect rule only. No scholiq DOM surface. -->
- **GIVEN** a grade/final-grade record gaining learner-visible status
- **WHEN** the triggering transition or calculated change fires
- **THEN** a verified-dialect rule delivers an `nc-notification` to the affected learner

### Requirement: Notification delivery MUST honor the per-user override preference
Each declared rule MUST be subject to OpenRegister's override-only per-`(schema, notification)` user preference. When a user has set a preference of disabled for a rule, OpenRegister MUST NOT deliver that notification to that user (dispatcher `preference-off` gate). The scholiq per-user settings panel MUST be the surface through which a user sets these overrides.

#### Scenario: Opted-out user receives nothing
<!-- @e2e exclude The preference-off delivery gate is OpenRegister's dispatcher behaviour (app id openregister); the per-user toggle UI that writes the override is covered by nextcloud-app#user-disables-a-notification-type. The skip itself has no scholiq DOM surface. -->
- **GIVEN** a user who disabled the credential-issued notification via the per-user settings panel
- **WHEN** a credential is issued to that user
- **THEN** OpenRegister records a `preference-off` skip and the user receives no notification

#### Scenario: Default-on delivery
<!-- @e2e exclude Default-on delivery is OpenRegister dispatcher behaviour; no scholiq DOM surface. -->
- **GIVEN** a user who has set no override for the attendance-flag notification
- **WHEN** an attendance flag is raised for them
- **THEN** the notification is delivered (default on)

