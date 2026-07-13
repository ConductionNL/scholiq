# scholiq-notifications — Quiet Hours Adoption + Deadline Lead-Time Delta

**Spec refs**: `scholiq-notifications`, `grading`, ADR-031; cross-repo: `openregister`
`notification-delivery-windows` (dispatcher-side quiet-hours suppression — implemented in the
`openregister` project, not this one; referenced here as a consumed dependency only)

## MODIFIED Requirements

### Requirement: Core learner events MUST emit declarative nc-notification rules

The four core learner-facing events MUST be declared as `x-openregister-notifications` rules in
`lib/Settings/scholiq_register.json` using only the verified engine dialect, each on the `nc-notification`
channel with a `recipients[]` entry of `kind: field` resolving to the affected user, and an inline
`subject` with `nl` and `en` strings: (1) a grade/final-grade becoming available to the learner, (2) a
credential being issued to the learner, (3) an attendance flag being raised for the learner (and/or their
mentor), and (4) a course/lesson completion. For (1), "becoming available" means the `GradeEntry` has both
transitioned to `published` AND reached its resolved `visibleFrom` timestamp (per the `grading` spec's
scheduled-visibility-window requirement) — `lifecycle: published` alone is no longer sufficient to
determine learner-visible status. No scholiq PHP code MUST imperatively create Nextcloud notifications;
delivery and rendering MUST be performed by OpenRegister's `AnnotationNotificationDispatcher` +
`AnnotationNotifier` under app id `openregister`.

#### Scenario: Credential issuance notifies the learner

<!-- @e2e exclude Notification delivery is OpenRegister's AnnotationNotificationDispatcher (app id openregister); scholiq only declares the rule (verified by the register-validation suite). No scholiq DOM surface drives NC notification fan-out. -->
- **GIVEN** a `Credential` with an `issuedToLearner`-style rule on the `issue` transition
- **WHEN** the credential transitions to issued
- **THEN** OpenRegister delivers an `nc-notification` to the learner resolved from the `learnerId` field
- **AND** no scholiq class calls `INotificationManager::createNotification()`

#### Scenario: Grade availability notifies the learner only once visible

<!-- @e2e exclude Notification delivery is OpenRegister-side; scholiq declares the verified-dialect rule only. No scholiq DOM surface. -->
- **GIVEN** a `GradeEntry` transitions to `published` with a `visibleFrom` timestamp in the future
- **WHEN** the transition completes
- **THEN** no `nc-notification` is delivered yet
- **AND** once the current time reaches `visibleFrom`, the declared `scheduled`-trigger rule delivers an
  `nc-notification` to the affected learner

### Requirement: Notification delivery MUST honor the per-user override preference

Each declared rule MUST be subject to OpenRegister's override-only per-`(schema, notification)` user
preference. When a user has set a preference of disabled for a rule, OpenRegister MUST NOT deliver that
notification to that user (dispatcher `preference-off` gate). Delivery additionally MUST honour the
per-user quiet-hours / delivery-window preference exposed by OpenRegister's notification-engine dispatcher
(the `openregister` project's `notification-delivery-windows` change) once available — scholiq declares
rules only and performs no local quiet-hours suppression logic, per ADR-031. The scholiq per-user settings
panel (`src/views/ScholiqNotificationSettings.vue`) MUST be the surface through which a user sets both
kinds of override: the existing per-`(schema, notification)` enable/disable toggle, and the new quiet-hours
/ delivery-window preference, both persisted through OpenRegister's preference API — no scholiq-local
preference store. Any `scheduled`-trigger reminder rule tied to a deadline field (e.g.
`Enrolment.dueReminder` / `Enrolment.overdue`) MUST declare enough lead time that its first firing still
lands before the deadline even after a quiet-hours deferral, so a suppressed reminder can never silently
collapse into "delivered after the deadline has passed".

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

#### Scenario: Quiet hours suppress a rule without a scholiq-side rewrite

<!-- @e2e exclude Quiet-hours suppression is OpenRegister dispatcher behaviour (openregister notification-delivery-windows); no scholiq DOM surface drives the suppression itself. -->
- **GIVEN** a user has set quiet hours 22:00–07:00 via the scholiq settings panel
- **AND** a scholiq notification rule becomes eligible to fire at 23:00
- **WHEN** OpenRegister's dispatcher evaluates delivery
- **THEN** delivery is deferred until quiet hours end, with no change required to the scholiq-declared rule

#### Scenario: Settings panel surfaces the quiet-hours control

- **GIVEN** a user opens the scholiq notification settings panel
- **WHEN** the panel loads
- **THEN** it shows both the existing per-notification enable/disable toggles and a quiet-hours /
  delivery-window control
- **AND** saving either writes through OpenRegister's preference API, with no scholiq-local persistence

#### Scenario: Deadline reminder lead time survives quiet-hours deferral

- **GIVEN** `Enrolment.dueReminder` is declared with a lead time sized so its first firing precedes the
  enrolment deadline by at least the configured minimum margin
- **WHEN** a recipient's quiet hours defer that firing
- **THEN** the deferred delivery still lands before the deadline has passed
