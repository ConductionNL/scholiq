# payments Specification

## Purpose
TBD - created by archiving change school-payments. Update Purpose after archive.
## Requirements
### Requirement: Persist FeeItem as the chargeable definition, including its voluntary posture

The system MUST persist `FeeItem` as an OpenRegister object naming what is being charged for: `kind`
(`course-enrolment | school-trip | materials | schoolkassa | mbo-contractonderwijs | other`), `amount`
(non-negative number), `currency` (default `EUR`), an optional `taxPosture` (descriptive metadata only —
`education-exempt | standard-rate | reduced-rate | not-applicable` — not a computed VAT engine), an
optional `linkedCourseId` ($ref `Course`, for `course-enrolment`/`mbo-contractonderwijs` kinds), an
optional `linkedCohortId` ($ref `Cohort`, for `school-trip` kinds), and a required `voluntary` boolean
(default `false`). `voluntary: true` MUST be used for any ouderbijdrage-style contribution (schoolkassa,
trips, materials a school chooses to make voluntary); `voluntary: false` MUST be used for a genuine paid
product (contractonderwijs, a paid course). `FeeItem` MUST carry `x-openregister-lifecycle`
(`draft → active → archived`), mirroring `AttendanceThreshold`'s configuration-object shape.

#### Scenario: A schoolkassa contribution is declared voluntary

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by PHPUnit schema-validation tests, no scholiq DOM surface for schema registration itself. -->

- **GIVEN** an administrator authoring a schoolkassa `FeeItem` for a school trip
- **WHEN** they save it with `kind: school-trip` and `voluntary: true`
- **THEN** the `FeeItem` persists with `voluntary: true`
- **AND** it is available for `Order`/`OrderLine` composition

#### Scenario: A contractonderwijs course is declared non-voluntary

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; PHPUnit schema-validation coverage only. -->

- **GIVEN** an MBO administrator authoring a `FeeItem` for a paid contractonderwijs course
- **WHEN** they save it with `kind: mbo-contractonderwijs`, `voluntary: false`, and `linkedCourseId` set
- **THEN** the `FeeItem` persists with `voluntary: false`
- **AND** it is eligible to back an `Entitlement` once paid (see the Entitlement requirement below)

### Requirement: Persist Order and OrderLine as the payer-facing request for payment, with a validated total

The system MUST persist `Order` (payer-facing header: `payerKind` (`guardian | learner | employer`),
`payerId` (nullable Nextcloud user ID), `payerName`/`payerEmail` (nullable, for an employer or other payer
with no Nextcloud account), `learnerId` (the beneficiary, required), `totalAmount`, `currency`, `dueDate`
(nullable), and `x-openregister-lifecycle`: `draft → open → partially-paid | paid`, `draft | open →
cancelled`, `paid → refunded`) and `OrderLine` (`orderId` $ref `Order`, `feeItemId` $ref `FeeItem`,
`description` (a snapshot of `FeeItem.name`/title at order time), `quantity` (integer, minimum 1),
`unitAmount`, `lineTotal`). `Order.totalAmount` MUST be validated, not merely trusted from the frontend: a
new `OrderTotalValidationGuard` MUST run on the `draft → open` ("finalize") transition, recomputing the sum
of that `Order`'s `OrderLine.lineTotal` rows and refusing the transition if the stored `totalAmount` does
not match. `Order` MUST declare a materialised `isOverdue` calculation using the identical `@now`-comparison
idiom `Enrolment.isOverdue` already uses (`lifecycle` in `open`/`partially-paid` AND `dueDate` set AND
`dueDate` in the past).

#### Scenario: Finalizing an Order with a mismatched total is refused

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit OrderTotalValidationGuardTest; no scholiq DOM surface for the guard itself (the composer UI that calls finalize is covered by the frontend requirement's scenario below). -->

- **GIVEN** a `draft` `Order` with `OrderLine`s summing to €45.00 but `totalAmount` stored as €40.00
- **WHEN** an attempt is made to transition the `Order` from `draft` to `open`
- **THEN** the transition is refused
- **AND** the validation error names the mismatch

#### Scenario: Finalizing an Order with a correct total succeeds

<!-- @e2e exclude PHPUnit OrderTotalValidationGuardTest::testMatchingTotalSucceeds; backend guard behaviour, no DOM surface. -->

- **GIVEN** a `draft` `Order` whose `OrderLine`s sum to exactly its stored `totalAmount`
- **WHEN** it transitions `draft → open`
- **THEN** the transition succeeds and the `Order` becomes visible to its payer

#### Scenario: An overdue open Order is flagged without a TimedJob

<!-- @e2e exclude Pure JSON-logic calculation, identical idiom to Enrolment.isOverdue; verified by the register-validation suite, no scholiq DOM surface for the calculation itself. -->

- **GIVEN** an `open` `Order` with `dueDate` in the past
- **WHEN** the `isOverdue` calculation is evaluated
- **THEN** it resolves to `true`
- **AND** no scholiq `TimedJob` computes it — it is a materialised `x-openregister-calculations` entry

### Requirement: A voluntary FeeItem MUST NOT gate enrolment or participation

The system MUST structurally prevent a voluntary `FeeItem` from ever gating enrolment or participation, per
the Wet vrijwillige ouderbijdrage (in force since 1 August 2021): non-payment of a voluntary contribution
MUST NOT exclude a pupil from the activity it funds, and offering a lesser/substitute activity to
non-payers is equally non-compliant. A new `FeeItemVoluntaryEntitlementGuard` MUST block the `Entitlement`
`pending → active` ("grant") transition
whenever the `Entitlement`'s referenced `FeeItem.voluntary` is `true`. Because nothing in this capability
gates access on a `pending` `Entitlement` (only `active` ones grant anything — see the next requirement),
this makes it structurally impossible for a voluntary fee to ever become an access gate through this
capability's own mechanism.

#### Scenario: An Entitlement referencing a voluntary FeeItem can never activate

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit FeeItemVoluntaryEntitlementGuardTest::testVoluntaryFeeItemBlocksGrant; no scholiq DOM surface for the guard itself. -->

- **GIVEN** an `Entitlement` in `pending` state whose `feeItemId` references a `FeeItem` with
  `voluntary: true`
- **WHEN** an attempt is made to transition the `Entitlement` from `pending` to `active`
- **THEN** the transition is refused regardless of whether the linked `Order` reached `paid`

#### Scenario: An unpaid voluntary Order does not change the learner's participation status elsewhere

<!-- @e2e exclude Cross-capability non-effect is a negative assertion over backend state (absence of any gating read), verified by PHPUnit; no scholiq DOM surface, since this capability defines no participation check itself (see design.md's fast-follow note on wiring enrolment/course-management consumption). -->

- **GIVEN** a learner whose guardian has an unpaid, overdue `Order` for a `voluntary: true` `FeeItem`
  (e.g. a school trip)
- **WHEN** any other scholiq capability checks the learner's enrolment or attendance status
- **THEN** nothing in this capability's schema exposes a gating signal derived from that unpaid `Order` —
  no `Entitlement` for a voluntary `FeeItem` can ever be `active` (per the scenario above)

### Requirement: Entitlement grants access only once its Order is paid, and only for non-voluntary chargeables

The system MUST persist `Entitlement` (`feeItemId` $ref `FeeItem`, `orderLineId` $ref `OrderLine`,
`learnerId`, `grantedResourceKind` (`course-access | trip-participation | material-access |
contractonderwijs-access`), `grantedResourceId` (nullable UUID, e.g. a `Course` ID), `x-openregister-lifecycle`:
`pending → active` ("grant") `→ revoked` ("revoke")). The `grant` transition MUST require both (a) the
`FeeItemVoluntaryEntitlementGuard` pass (previous requirement) and (b) the parent `Order` (via
`orderLineId → Order`) being in `paid` state — a new `EntitlementOrderPaidGuard`. `revoke` MUST be
available from `active` (e.g. on `Order` refund) with no additional guard.

#### Scenario: Entitlement activates once its Order is fully paid

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit EntitlementOrderPaidGuardTest::testGrantSucceedsOnPaidOrder. -->

- **GIVEN** a `pending` `Entitlement` for a non-voluntary `FeeItem` whose `Order` has just transitioned to
  `paid`
- **WHEN** the `grant` transition is attempted
- **THEN** it succeeds and the `Entitlement` becomes `active`

#### Scenario: Entitlement cannot activate while its Order is only partially paid

<!-- @e2e exclude PHPUnit EntitlementOrderPaidGuardTest::testGrantRefusedOnPartiallyPaidOrder. -->

- **GIVEN** a `pending` `Entitlement` whose `Order` is `partially-paid`
- **WHEN** the `grant` transition is attempted
- **THEN** it is refused

#### Scenario: A refunded Order revokes its Entitlement

<!-- @e2e exclude PHPUnit EntitlementOrderPaidGuardTest::testRevokeOnRefund, or a PaymentTransactionStatusHandler test asserting the cascade. -->

- **GIVEN** an `active` `Entitlement` whose `Order` transitions `paid → refunded`
- **WHEN** the refund transition completes
- **THEN** the `Entitlement` transitions `active → revoked`

### Requirement: Payment initiation and status delegate entirely to OpenConnector; scholiq implements no PSP wire protocol

The system MUST persist `PaymentTransaction` (`orderId` $ref `Order`, `pspProvider`
(`mollie | stripe` — matching the credential-broker's catalogued provider identifiers), `pspPaymentId`
(nullable, set once OpenConnector returns it), `amount`, `currency`, `initiatedBy`, `initiatedAt`,
`completedAt` (nullable), `x-openregister-lifecycle`: `pending → awaiting-redirect → succeeded | failed |
expired | cancelled`, `succeeded → refunded`; `appendOnly: true` per ADR-008, mirroring `Attestation`'s
evidentiary shape). Scholiq MUST NOT construct, sign, or verify any PSP-specific request or webhook payload
itself — a new `PaymentTransactionController::initiate()` MUST delegate to OpenConnector's (not-yet-built)
PSP adapter using the same `IClientService` + `IURLGenerator::getAbsoluteURL()` + `IAppConfig` bearer-token
shape `DataExchangeRunHandler::callOpenConnector()` and `LtiToolPlacementController::launch()` already
establish, under the existing `scholiq.openconnector_api_token` config key. The checkout URL/reference
OpenConnector returns MUST be forwarded to the frontend and rendered opaquely, without scholiq inspecting
any PSP-specific field beyond what is needed to store `pspPaymentId` and the current status. Status updates
MUST arrive via a new inbound `PaymentTransactionController::callback()` endpoint that OpenConnector's PSP
adapter calls after independently verifying the PSP's webhook signature — this is the first
OpenConnector-to-scholiq inbound call in this codebase (every existing `callOpenConnector*` call is
scholiq-initiated) and MUST use its own documented authentication mechanism, not silently reuse the
outbound `scholiq.openconnector_api_token` in the reverse direction.

#### Scenario: Initiating payment delegates to OpenConnector and returns an opaque checkout reference

<!-- @e2e exclude Thin outbound proxy with no PSP protocol logic in scholiq; contract covered by PHPUnit against a mocked OpenConnector response, mirroring LtiToolPlacementControllerTest's pattern. Cannot be live-verified end-to-end in this change since the OpenConnector mollie-stripe-payment-adapter does not exist yet (see proposal.md Why). -->

- **GIVEN** an `open` `Order` with a validated `totalAmount`
- **WHEN** the payer triggers payment initiation
- **THEN** the backend creates a `pending` `PaymentTransaction` and calls OpenConnector's PSP
  launch-initiation endpoint with the `Order`'s amount, currency, and a callback reference
- **AND** the response (a checkout URL/reference) is rendered to the payer without scholiq inspecting any
  PSP-specific claim it carries

#### Scenario: An inbound status callback updates the PaymentTransaction and rolls up to the Order

<!-- @e2e exclude Inbound webhook-relay contract covered by PHPUnit against a synthetic callback payload; no scholiq DOM surface, since the actual PSP webhook lands on OpenConnector, not scholiq. Cannot be live-verified end-to-end in this change since the OpenConnector adapter that would call this endpoint does not exist yet. -->

- **GIVEN** a `pending` `PaymentTransaction` awaiting a PSP result
- **WHEN** OpenConnector's PSP adapter calls `PaymentTransactionController::callback()` reporting success
- **THEN** the `PaymentTransaction` transitions to `succeeded`
- **AND** a `PaymentTransactionStatusHandler` (event-driven, not a `TimedJob`) rolls the parent `Order` up
  to `paid` once the sum of its `succeeded` `PaymentTransaction`s meets `totalAmount`, or to
  `partially-paid` otherwise

### Requirement: Due-date reminders for open Orders are declarative notifications honouring quiet hours

`Order` MUST declare `dueSoon` and `overdue` as `x-openregister-notifications` rules using the verified
engine dialect, in the identical shape `Enrolment.dueReminder`/`Enrolment.overdue` already use
(`scheduled` trigger, `intervalSec`, a `filter` on `dueDate` with `withinNext`/`olderThan` operators,
`recipients: [{kind: field, field: payerId}]`, inline `nl`/`en` `subject`). Delivery MUST honour the
per-user quiet-hours/delivery-window preference exposed by OpenRegister's dispatcher, per
`scholiq-notifications`'s existing posture — scholiq declares rules only and performs no local suppression
logic.

#### Scenario: A payer receives a reminder before an Order falls due

<!-- @e2e exclude Notification delivery is OpenRegister's AnnotationNotificationDispatcher; scholiq only declares the rule, verified by the register-validation suite. No scholiq DOM surface drives NC notification fan-out. -->

- **GIVEN** an `open` `Order` with `dueDate` three days out
- **WHEN** the `dueSoon` rule's scheduled trigger fires
- **THEN** OpenRegister delivers an `nc-notification` to the `Order`'s `payerId`
- **AND** a payer who has set quiet hours receives the deferred delivery per
  `scholiq-notifications`'s existing quiet-hours requirement, with no scholiq-side rewrite

### Requirement: Frontend is declarative with one named view for initiating and tracking payment

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `FeeItem`, `Order`,
`OrderLine`, `PaymentTransaction`, and `Entitlement`. The only custom Vue component MUST be
`OrderPaymentPanel.vue` — the payer's "pay now" surface for an `open`/`partially-paid` `Order`, showing its
lines and total, calling `PaymentTransactionController::initiate()`, and rendering the returned checkout
reference opaquely (no PSP-specific rendering logic). No PHP CRUD controllers for `FeeItem`/`Order`/
`OrderLine`/`Entitlement` — OpenRegister's object API serves those; `PaymentTransactionController` exists
only for the two delegation endpoints in the requirement above.

#### Scenario: A payer opens the payment panel and initiates payment

<!-- @e2e exclude Requires a live OpenConnector PSP adapter to complete the flow end-to-end, which does not exist yet (see proposal.md Why); the panel's render-and-call-initiate() surface is covered by a Playwright smoke test against a mocked initiate() response, mirroring bsa-study-progress-guard's render-without-fatal-error pattern. -->

- **GIVEN** a guardian viewing their child's `open` `Order`
- **WHEN** they open `OrderPaymentPanel` and select "pay now"
- **THEN** the panel shows the `Order`'s lines and total
- **AND** clicking "pay now" calls `PaymentTransactionController::initiate()` and navigates to (or embeds)
  the returned checkout reference without the panel parsing any PSP-specific field

