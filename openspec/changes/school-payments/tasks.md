# Tasks: school-payments

## 1. Schema — chargeable + order

- [ ] 1.1 Add `FeeItem` to `lib/Settings/scholiq_register.json`: `name`, `description`, `kind`
  (`course-enrolment | school-trip | materials | schoolkassa | mbo-contractonderwijs | other`), `amount`
  (number, `minimum: 0`), `currency` (string, default `EUR`), `voluntary` (boolean, required, default
  `false`), `taxPosture` (nullable enum: `education-exempt | standard-rate | reduced-rate |
  not-applicable`), `linkedCourseId` (nullable $ref `Course`), `linkedCohortId` (nullable $ref `Cohort`),
  `academicYear` (nullable string), `validFrom`/`validUntil` (nullable date), `lifecycle`
  (`draft → active → archived`), `tenant_id`. English `title`/`description` on every property.
  - **spec_ref**: `specs/payments/spec.md#requirement-persist-feeitem-as-the-chargeable-definition-including-its-voluntary-posture`
  - **acceptance_criteria**:
    - `voluntary` has no default that silently defaults to `true` — an author must opt in explicitly if
      that's the intent, but the schema default itself is `false`
    - Schema validates against the OpenAPI 3.0.0 register conventions used elsewhere in the file
- [ ] 1.2 Add `Order` to `lib/Settings/scholiq_register.json`: `payerKind` (`guardian | learner |
  employer`), `payerId` (nullable string, NC user id), `payerName`/`payerEmail` (nullable string),
  `learnerId` (required string, NC user id — beneficiary), `learnerRef` (nullable $ref `LearnerProfile`,
  ADR-046 pattern), `totalAmount` (number, `minimum: 0`), `currency`, `dueDate` (nullable date), `notes`
  (nullable), `lifecycle` (`draft → open → partially-paid | paid`, `draft | open → cancelled`, `paid →
  refunded`), `tenant_id`. Add `x-openregister-calculations.isOverdue` — materialised boolean, JSON-logic
  `and[eq(lifecycle in [open, partially-paid]), ne(dueDate, null), lt(dueDate, now)]`, mirroring
  `Enrolment.isOverdue` (`lib/Settings/scholiq_register.json:1594-1628`).
  - **spec_ref**: `specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total`
  - **acceptance_criteria**:
    - `isOverdue` evaluates identically to the scenario in the spec (open + past-due → true)
    - Lifecycle transitions match the enumerated set exactly, no extra states
- [ ] 1.3 Add `OrderLine` to `lib/Settings/scholiq_register.json`: `orderId` (required $ref `Order`),
  `feeItemId` (required $ref `FeeItem`), `description` (string, snapshot of the `FeeItem`'s name at order
  time), `quantity` (integer, `minimum: 1`, default 1), `unitAmount` (number, `minimum: 0`), `lineTotal`
  (number, `minimum: 0`), `tenant_id`.
  - **spec_ref**: `specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total`
  - **acceptance_criteria**:
    - `orderId`/`feeItemId` resolve via OR's standard $ref relation mechanism, no dangling refs

## 2. Schema — entitlement + payment transaction

- [ ] 2.1 Add `Entitlement` to `lib/Settings/scholiq_register.json`: `feeItemId` (required $ref
  `FeeItem`), `orderLineId` (required $ref `OrderLine`), `learnerId` (required string), `grantedResourceKind`
  (`course-access | trip-participation | material-access | contractonderwijs-access`), `grantedResourceId`
  (nullable uuid string), `grantedAt` (nullable date-time), `lifecycle` (`pending → active → revoked`,
  transition names `grant`/`revoke`), `tenant_id`. `x-property-rbac`: learner reads own record; admin/
  finance roles read all (mirror `FinalGrade`'s block, `lib/Settings/scholiq_register.json:5848-5867`).
  - **spec_ref**: `specs/payments/spec.md#requirement-entitlement-grants-access-only-once-its-order-is-paid-and-only-for-non-voluntary-chargeables`
  - **acceptance_criteria**:
    - `grant` transition declares `requires` naming both guards from tasks 3.1 and 3.2 (array form, same
      as any existing multi-guard transition in this register, or two chained guard classes if the
      lifecycle engine's `requires` only accepts one — confirm against OR's actual transition schema
      during implementation and note which shape was used)
- [ ] 2.2 Add `PaymentTransaction` to `lib/Settings/scholiq_register.json`: `orderId` (required $ref
  `Order`), `pspProvider` (`mollie | stripe`), `pspPaymentId` (nullable string), `amount` (number),
  `currency`, `initiatedBy` (string, NC user id), `initiatedAt` (date-time), `completedAt` (nullable
  date-time), `lifecycle` (`pending → awaiting-redirect → succeeded | failed | expired | cancelled`,
  `succeeded → refunded`), `tenant_id`. `appendOnly: true`.
  `x-openregister-authorization.create`: restricted (server-side controller only — see task 4.1's
  acceptance criteria for how this is actually enforced, since `x-openregister-authorization.create` names
  roles, not code paths; confirm during implementation whether a role-based restriction plus
  controller-side amount validation is the correct combination, or whether an additional guard is needed).
  - **spec_ref**: `specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol`
  - **acceptance_criteria**:
    - `appendOnly: true` set; lifecycle transitions match the enumerated set exactly

## 3. Backend — guards and handler

- [ ] 3.1 Add `OCA\Scholiq\Lifecycle\FeeItemVoluntaryEntitlementGuard` (SPDX; `@spec` tag): on
  `Entitlement`'s `pending → active` transition, resolve `feeItemId` and refuse the transition if
  `FeeItem.voluntary === true`.
  - **spec_ref**: `specs/payments/spec.md#requirement-a-voluntary-feeitem-must-not-gate-enrolment-or-participation`
  - **acceptance_criteria**:
    - Unit tests: voluntary `FeeItem` blocks grant regardless of `Order` status; non-voluntary `FeeItem`
      is unaffected by this guard (other guard still applies)
- [ ] 3.2 Add `OCA\Scholiq\Lifecycle\EntitlementOrderPaidGuard` (SPDX): on `Entitlement`'s `pending →
  active` transition, resolve `orderLineId → Order` and refuse unless `Order.lifecycle === 'paid'`.
  - **spec_ref**: `specs/payments/spec.md#requirement-entitlement-grants-access-only-once-its-order-is-paid-and-only-for-non-voluntary-chargeables`
  - **acceptance_criteria**:
    - Unit tests: paid Order allows grant; partially-paid/open/draft Order refuses grant
- [ ] 3.3 Add `OCA\Scholiq\Lifecycle\OrderTotalValidationGuard` (SPDX): on `Order`'s `draft → open`
  transition, query all `OrderLine`s where `orderId === this.id`, sum `lineTotal`, and refuse the
  transition if the sum does not equal the stored `totalAmount`.
  - **spec_ref**: `specs/payments/spec.md#requirement-persist-order-and-orderline-as-the-payer-facing-request-for-payment-with-a-validated-total`
  - **acceptance_criteria**:
    - Unit tests: matching total succeeds; mismatched total refused with an error naming the mismatch;
      an `Order` with zero `OrderLine`s is refused (nothing to finalize)
- [ ] 3.4 Add `OCA\Scholiq\Listener\PaymentTransactionStatusHandler` (SPDX; mirrors `GradeRollupHandler`'s
  event-listener shape): listens for `PaymentTransaction` lifecycle transitions
  (`ObjectTransitionedEvent`). On `→ succeeded`, sum `succeeded` `PaymentTransaction.amount` for the
  parent `Order` and transition the `Order` to `paid` (sum `>= totalAmount`) or `partially-paid`
  (otherwise). On `paid → refunded` (via a `PaymentTransaction` moving to `refunded`), transition any
  `active` `Entitlement`s linked through that `Order`'s `OrderLine`s to `revoked`.
  - **spec_ref**: `specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol`
  - **acceptance_criteria**:
    - Unit tests: single full payment → `Order` reaches `paid`; a payment less than `totalAmount` →
      `partially-paid`; a refund on a `paid` `Order` with an `active` `Entitlement` → `Entitlement`
      becomes `revoked`
    - Registered in `lib/AppInfo/Application.php`
- [ ] 3.5 Add `OCA\Scholiq\Controller\PaymentTransactionController`: `initiate(orderId)` — creates a
  `pending` `PaymentTransaction`, calls OpenConnector's PSP launch-initiation endpoint using the
  established `IClientService` + `IURLGenerator::getAbsoluteURL()` + `IAppConfig`
  (`scholiq.openconnector_api_token`) shape (mirror `LtiToolPlacementController::callOpenConnectorLaunch()`
  / `DataExchangeRunHandler::callOpenConnector()`), stores the returned `pspPaymentId` and checkout
  reference, forwards the response opaquely. `callback()` — inbound endpoint OpenConnector's (future) PSP
  adapter calls with a verified status update; authenticates the caller via its own mechanism (NOT the
  outbound `scholiq.openconnector_api_token` reused in reverse — see design.md); updates the matching
  `PaymentTransaction`'s lifecycle.
  - **spec_ref**: `specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol`
  - **acceptance_criteria**:
    - Unit tests cover `initiate()` against a mocked OpenConnector response (success + 502-on-failure,
      mirroring `LtiToolPlacementControllerTest`'s pattern) and `callback()` against a synthetic payload
    - `callback()`'s auth mechanism is documented in its docblock as provisional pending the real
      OpenConnector adapter's actual constraints (per design.md's explicit non-commitment to inventing
      that mechanism speculatively)
    - Routes registered in `appinfo/routes.php` with explicit auth attributes on both methods

## 4. Frontend

- [ ] 4.1 Add `src/manifest.json` index/detail pages for `FeeItem`, `Order`, `OrderLine`,
  `PaymentTransaction`, `Entitlement` (list/create/edit/detail per the standard declarative pattern used
  by `attendance`/`grading`/`study-progress`).
  - **spec_ref**: `specs/payments/spec.md#requirement-frontend-is-declarative-with-one-named-view-for-initiating-and-tracking-payment`
  - **acceptance_criteria**:
    - Pages render seeded objects; no PHP CRUD controller added for these five objects beyond
      `PaymentTransactionController`'s two delegation endpoints
- [ ] 4.2 Add `src/views/OrderPaymentPanel.vue`: for an `open`/`partially-paid` `Order`, shows its
  `OrderLine`s and `totalAmount`, and a "pay now" action calling
  `PaymentTransactionController::initiate()`; renders the returned checkout reference (URL) without
  parsing any PSP-specific field. Strings via `t()`; data via the OpenRegister object API (no DOM reads);
  any `NcSelect` carries `inputLabel`. Add a manifest menu entry.
  - **spec_ref**: `specs/payments/spec.md#requirement-frontend-is-declarative-with-one-named-view-for-initiating-and-tracking-payment`
  - **acceptance_criteria**:
    - Panel renders seeded order lines and total; "pay now" click calls `initiate()` and displays the
      (mocked, in dev) checkout reference
    - Empty/paid states shown appropriately (no "pay now" action on an already-`paid` `Order`)

## 5. Tests and docs

- [ ] 5.1 PHPUnit for `FeeItemVoluntaryEntitlementGuard`, `EntitlementOrderPaidGuard`,
  `OrderTotalValidationGuard`, `PaymentTransactionStatusHandler`, `PaymentTransactionController` per the
  acceptance criteria in tasks 3.1–3.5 (minimum 75% coverage for new code per ADR-009).
  - **spec_ref**: all `payments` requirements
  - **acceptance_criteria**:
    - All PHPUnit test names referenced in the spec scenarios exist and pass
- [ ] 5.2 Add `tests/e2e/spec-coverage/payments.spec.ts` (Playwright): a payer opens `OrderPaymentPanel`
  for a seeded `open` `Order`, sees the lines/total, and triggers "pay now" against a mocked
  `initiate()` response (no live PSP — see design.md's non-goal on live-verifying the OpenConnector leg).
  - **spec_ref**: `specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment`
  - **acceptance_criteria**:
    - Test passes against a seeded dev instance with `initiate()` mocked at the network layer; matches
      the `@e2e` reference in the spec scenario
- [ ] 5.3 Add Dutch and English translations for all new i18n keys (ADR-005): `OrderPaymentPanel.vue`
  strings, and both `nl`/`en` subjects on the `dueSoon`/`overdue` notification rules.
  - **spec_ref**: all `payments` requirements
  - **acceptance_criteria**:
    - No hardcoded strings; `nl`/`en` both populated

## 6. Verify

- [ ] 6.1 `openspec validate school-payments --strict` clean; PHPUnit green for all five new PHP classes;
  Playwright `payments.spec.ts` green; no dangling `$ref`s in the register JSON; the voluntary-guard
  invariant re-verified end-to-end (a voluntary `FeeItem`'s `Entitlement` cannot reach `active` under any
  `Order` status, including `paid`).
  - **spec_ref**: all
  - **acceptance_criteria**:
    - Strict validation + full test suite green; voluntary-fee invariant exhaustively covered, including
      the specific case of a `voluntary: true` `FeeItem` whose `Order` reaches `paid` (payer did pay
      anyway) — `Entitlement` still must not be creatable as an active gate
