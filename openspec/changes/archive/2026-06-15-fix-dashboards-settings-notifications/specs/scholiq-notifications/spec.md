---
status: draft
---

# scholiq-notifications

## Purpose

Confirm that the four learner-facing lifecycle events emit real Nextcloud notifications through OpenRegister's declarative engine (no imperative `INotifier` in scholiq, per ADR-031), and that each is user-controllable through OpenRegister's override-only per-user preference gate that the new per-user settings panel writes to.

## ADDED Requirements

### Requirement: Core learner events MUST emit declarative nc-notification rules
The four core learner-facing events MUST be declared as `x-openregister-notifications` rules in `lib/Settings/scholiq_register.json` using only the verified engine dialect, each on the `nc-notification` channel with a `recipients[]` entry of `kind: field` resolving to the affected user, and an inline `subject` with `nl` and `en` strings: (1) a grade/final-grade becoming available to the learner, (2) a credential being issued to the learner, (3) an attendance flag being raised for the learner (and/or their mentor), and (4) a course/lesson completion. No scholiq PHP code MUST imperatively create Nextcloud notifications; delivery and rendering MUST be performed by OpenRegister's `AnnotationNotificationDispatcher` + `AnnotationNotifier` under app id `openregister`.

#### Scenario: Credential issuance notifies the learner
- **GIVEN** a `Credential` with an `issuedToLearner`-style rule on the `issue` transition
- **WHEN** the credential transitions to issued
- **THEN** OpenRegister delivers an `nc-notification` to the learner resolved from the `learnerId` field
- **AND** no scholiq class calls `INotificationManager::createNotification()`

#### Scenario: Grade availability notifies the learner
- **GIVEN** a grade/final-grade record gaining learner-visible status
- **WHEN** the triggering transition or calculated change fires
- **THEN** a verified-dialect rule delivers an `nc-notification` to the affected learner

### Requirement: Notification delivery MUST honor the per-user override preference
Each declared rule MUST be subject to OpenRegister's override-only per-`(schema, notification)` user preference. When a user has set a preference of disabled for a rule, OpenRegister MUST NOT deliver that notification to that user (dispatcher `preference-off` gate). The scholiq per-user settings panel MUST be the surface through which a user sets these overrides.

#### Scenario: Opted-out user receives nothing
- **GIVEN** a user who disabled the credential-issued notification via the per-user settings panel
- **WHEN** a credential is issued to that user
- **THEN** OpenRegister records a `preference-off` skip and the user receives no notification

#### Scenario: Default-on delivery
- **GIVEN** a user who has set no override for the attendance-flag notification
- **WHEN** an attendance flag is raised for them
- **THEN** the notification is delivered (default on)
