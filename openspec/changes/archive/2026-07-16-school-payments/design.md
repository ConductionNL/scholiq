# Design: school-payments

## Context

`enrolment` and `course-management` both punt payment to "a separate spec" that was never written
(`openspec/specs/enrolment/spec.md:66`, `openspec/specs/course-management/spec.md:186`), and no schema in
`lib/Settings/scholiq_register.json` models money. Two real needs sit behind that gap, with different legal
postures:

1. **Schoolkassa / vrijwillige ouderbijdrage** — a voluntary contribution for trips, materials, and
   extracurricular activities, universal in NL PO/VO (ParnasSys, Magister both ship it). Since 1 August
   2021 the **Wet vrijwillige ouderbijdrage** makes this a *legal* voluntary contribution: non-payment
   must not exclude a pupil, and a substitute/lesser activity for non-payers is explicitly non-compliant
   ("Een alternatief bieden is niet voldoende" — vo-raad.nl, fetched 2026-07-13).
2. **MBO contractonderwijs / paid courses** — a genuine commercial transaction (Odoo, Teachable,
   Thinkific, Kajabi, LearnDash, Absorb, D2L, Podia, Cornerstone all ship this) where payment legitimately
   gates access.

The same `FeeItem`/`Order` shape has to serve both without accidentally letting case 1 behave like case 2.
This document works out the data model, the guard that makes "no exclusion" structural rather than
conventional, the `Order → PaymentTransaction → Entitlement → enrolment` flow, the OpenConnector PSP seam
(and the credential-broker state that actually blocks/unblocks it, verified at HEAD), and the boundary
against a full invoicing/ERP system.

## Goals / Non-Goals

**Goals**
- Model what is being charged for (`FeeItem`), the payer-facing request (`Order`/`OrderLine`), the PSP
  round-trip (`PaymentTransaction`), and what payment grants (`Entitlement`) as OpenRegister objects.
- Make "an unpaid voluntary fee never blocks enrolment or participation" a structural guard, not a
  documentation note.
- Define the scholiq↔OpenConnector seam precisely enough that the OpenConnector adapter can be built
  against this contract without further scholiq-side negotiation.
- State honestly what already works (the credential-broker catalogue) versus what is still missing (the
  OpenConnector adapter spec itself, and the reverse-direction webhook callback).
- Keep the reminder mechanism as lightweight as `Enrolment.dueReminder` already is — no new machinery.

**Non-Goals**
- Building the OpenConnector `mollie-stripe-payment-adapter` (separate repo, separate spec — named as a
  follow-up).
- VAT/BTW computation. `FeeItem.taxPosture` is descriptive metadata an invoice-generation step downstream
  could read; this change computes no tax.
- Invoicing, statements, structured dunning cadences, or a general ledger. `Order`/`OrderLine` model one
  chargeable request each; there is no multi-order "account" or "statement" object. That's pipelinq/
  financeq territory (see "Boundary against financeq/pipelinq" below).
- Wiring `Entitlement` into `enrolment`'s prerequisite guard or `course-management`'s publish/enrol gate.
  This change specifies `Entitlement`'s contract; consuming it from those capabilities is a small,
  separate follow-up (see "Order → Entitlement → enrolment flow" below) because touching two `done`
  capability specs' live requirements is a coordination decision beyond this change's scope, not a
  technical blocker.
- Partial-access entitlements (e.g. granting access once 50% is paid). `EntitlementOrderPaidGuard` requires
  `Order.lifecycle == paid`, full stop — see Rejected Alternatives.

## Data Model

```
FeeItem (draft → active → archived)
  kind: course-enrolment | school-trip | materials | schoolkassa | mbo-contractonderwijs | other
  voluntary: boolean (required)            ── the legal fork point
  amount, currency, taxPosture (descriptive)
  linkedCourseId? / linkedCohortId?
        │
        │ referenced by
        ▼
OrderLine ──< Order (draft → open → partially-paid | paid → cancelled | refunded)
  feeItemId          payerKind: guardian | learner | employer
  quantity           payerId? / payerName? / payerEmail?
  unitAmount          learnerId (beneficiary)
  lineTotal           totalAmount, currency, dueDate
                       x-openregister-calculations.isOverdue (Enrolment.isOverdue idiom)
                       x-openregister-notifications: dueSoon, overdue (Enrolment.dueReminder idiom)
        │                    │
        │ grants (if paid,   │ 1:N
        │ if NOT voluntary)  ▼
        │              PaymentTransaction (pending → awaiting-redirect →
        │                                   succeeded | failed | expired | cancelled
        │                                   succeeded → refunded)
        │              appendOnly: true (ADR-008)
        │              pspProvider: mollie | stripe
        ▼
Entitlement (pending → active → revoked)
  feeItemId, orderLineId, learnerId
  grantedResourceKind: course-access | trip-participation | material-access | contractonderwijs-access
  grantedResourceId?
  grant transition requires:
    1. FeeItemVoluntaryEntitlementGuard  (refuses if FeeItem.voluntary == true)
    2. EntitlementOrderPaidGuard         (refuses unless orderLineId → Order.lifecycle == paid)
```

### Why `voluntary` lives on `FeeItem`, not on `Order` or `Entitlement`

The Wet vrijwillige ouderbijdrage attaches to *what is being charged for*, not to who is paying or what
they'd get if they paid. A single `Order` could in principle carry both a voluntary schoolkassa line and a
non-voluntary contractonderwijs line for the same payer (a guardian paying both a school-trip contribution
and a paid elective in the same checkout) — the fork has to be per-line, and `OrderLine.feeItemId` already
carries that distinction down to the guard. Putting `voluntary` on `Order` would force one order to be
either fully voluntary or fully commercial, which doesn't match how a school would actually bundle these
in one payer-facing checkout.

### Why `Entitlement` is the only object the guard touches, not `Order` itself

`Order.lifecycle` reaching `paid` is a *payment* fact — it says nothing about access. Deliberately keeping
"paid" and "grants access" as two different objects with a guarded transition between them is what makes
the voluntary case clean: a voluntary `Order` can happily become `paid` (a guardian who does pay is
recorded as having paid — useful for the school's own bookkeeping and thank-you correspondence) without
that fact ever being able to gate anything, because no `Entitlement` for a voluntary `FeeItem` can ever
reach `active`. If `Order.lifecycle == paid` were itself read anywhere as "learner may now participate,"
the voluntary/non-voluntary distinction would have to be re-checked at every read site — a convention, not
a structural guarantee. Routing every access grant through `Entitlement`'s single guarded transition means
there is exactly one place the invariant can be violated, and it's guarded.

### Order → Entitlement → enrolment flow (and why the last hop is a follow-up, not built here)

For a non-voluntary chargeable (e.g. `mbo-contractonderwijs`), the intended end-to-end flow is:

1. Administrator creates a `FeeItem` (`voluntary: false`, `linkedCourseId` set) for the paid course.
2. Learner/guardian/employer composes an `Order` with an `OrderLine` against that `FeeItem`, finalizes it
   (`draft → open`, `OrderTotalValidationGuard` passes).
3. Payer initiates payment via `OrderPaymentPanel` → `PaymentTransactionController::initiate()` →
   OpenConnector → PSP. On success, `PaymentTransactionStatusHandler` rolls the `Order` to `paid`.
4. A `pending` `Entitlement` (created alongside the `OrderLine`, or by the same handler once `Order`
   reaches `open`) transitions `pending → active` once `EntitlementOrderPaidGuard` sees `Order.lifecycle
   == paid`.
5. **Not built in this change**: `course-management`'s `Enrolment` prerequisite check (see
   `openspec/specs/enrolment/spec.md` "Validate prerequisites before persistence") would need to also
   check for an `active` `Entitlement` when a `Course` is `mandatoryTraining: false` and has been marked as
   requiring payment. That requires a small, additive field on `Course` (e.g. `requiresEntitlement:
   boolean`) and a corresponding check in `enrolment`'s existing prerequisite-validation requirement.

Step 5 is deliberately out of scope here. `enrolment` and `course-management` are both `status: done`
capabilities with their own requirement text; touching their live requirements is a decision about *how*
those capabilities should consume `Entitlement`, not a technical blocker this change needs to resolve to
deliver a working domain model. The brief's own framing — "Build the DOMAIN MODEL in scholiq" — is
satisfied by `Entitlement` existing with a precise, guarded contract (`grantedResourceKind: course-access`,
`grantedResourceId` naming the `Course`); wiring the consuming side is one small delta on `enrolment`,
filed as a fast-follow once a real paid-course buyer needs it, rather than spent here as a decision made
without that buyer's actual prerequisite-check requirements in hand.

## The OpenConnector PSP seam

### What already works (verified at HEAD, not assumed)

The scholiq→OpenConnector delegation pattern is not proposed here — it already exists, three times over:
`LtiToolPlacementController::launch()` (`lib/Controller/LtiToolPlacementController.php:168-216`),
`DataExchangeRunHandler::callOpenConnector()` (`lib/Listener/DataExchangeRunHandler.php:966-1021`), and
`WalletOfferDelegationService` (`lib/Service/WalletOfferDelegationService.php:24-36`, which explicitly
reuses the other two's shape "rather than [inventing a second cross-app auth mechanism]"). All three use
`IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig` bearer token under
`scholiq.openconnector_api_token`. `PaymentTransactionController::initiate()` (spec requirement above)
follows the identical shape — a fourth instance of an established pattern, not a new one.

The credential-broker layer this outbound call would ultimately rely on is **already fixed upstream**.
`openregister/lib/Settings/credential-providers.json` on `openregister`'s `development` branch (merged via
PR #348, further widened by PR #351) catalogues `mollie` and `stripe` as host-locked providers with scoped
`allowRules` — confirmed by reading the file at HEAD, not from the stale 2026-07-12 state the task brief
described. This means: **the "PSP adapter is blocked on the credential-broker catalogue" framing in the
brief is out of date.** The catalogue's own `$fleetComment` documents the prior gap and its fix in the same
breath — this isn't a guess, it's the file's own changelog entry.

### What is still genuinely missing

Two things, both outside scholiq's repo and therefore outside this change's Impact:

1. **The OpenConnector adapter itself.** `openconnector/openspec/architecture/adr-017-information-architecture.md:27`
   names `mollie-stripe-payment-adapter` as a planned adapter in OpenConnector's own IA, but no spec or
   change with that name (or `mollie`/`stripe`/`payment` in its slug) exists in `openconnector/openspec/
   specs/` or `openconnector/openspec/changes/` at HEAD. The credential-broker catalogue entries give this
   adapter something to call *through*; they don't build the adapter's request/response mapping,
   idempotency handling, or webhook receiver.
2. **The reverse-direction callback.** Every existing `callOpenConnector*` call in this codebase is
   scholiq-initiated and synchronous (scholiq calls OpenConnector, OpenConnector responds in the same HTTP
   round trip). A PSP status update is inherently asynchronous and arrives at whichever system exposes a
   public webhook endpoint — and per the same "scholiq implements no external wire protocol" posture
   `LtiToolPlacementController` already establishes for LTI's OIDC/JWT verification, that endpoint and its
   signature verification belong in OpenConnector, not scholiq. So OpenConnector's adapter must, after
   verifying the PSP webhook, make a *new* inbound call into scholiq's
   `PaymentTransactionController::callback()`. No such OpenConnector→scholiq direction exists anywhere in
   this codebase today. This change's spec requirement documents the endpoint's existence and its
   obligation to use its own auth mechanism (not the outbound token in reverse — a shared bearer token
   used bidirectionally would let anything holding it forge either side's calls); the concrete
   authentication mechanism (e.g. a second, narrowly-scoped `scholiq.openconnector_callback_token`, or a
   signed-request scheme) is a design decision for whoever builds the OpenConnector side, made jointly with
   scholiq at that time — asserting a specific mechanism now, without the OpenConnector adapter's actual
   constraints in hand, would be exactly the kind of invented-detail this brief's "verify against HEAD, no
   invented behavior" instruction warns against.

### Opaque-response discipline

Mirroring `LtiToolPlacementController`'s D5 rule ("forward the response as-is — Scholiq MUST NOT parse any
LTI claim from it"), `PaymentTransaction` stores only what it structurally needs (`pspPaymentId`, lifecycle
status, `completedAt`) and nothing PSP-specific (no raw Mollie/Stripe payload field). If a future need
arises to show the payer *why* a payment failed, that's a follow-up field, deliberately not added
speculatively here.

## Boundary against financeq/pipelinq

This change models exactly enough to (a) charge for something, (b) collect one payment against it, and (c)
grant what that payment buys. It deliberately does **not** build:

- Multi-order payer accounts or running balances (a guardian with three children and five open `Order`s
  sees five separate orders, not one consolidated statement).
- Dunning cadences beyond the single `dueSoon`/`overdue` notification pair (no escalating reminder
  sequence, no automatic late fee, no collections handoff).
- A general ledger, VAT return, or accounting export.

Multi-order billing relationships, statements, and structured dunning are pipelinq/financeq's domain — the
same reasoning `data-exchange`'s spec uses to keep DUO/OSO/HR wire protocols in OpenConnector rather than
reimplementing them in scholiq. If a buyer needs consolidated guardian billing across children, that's a
pipelinq/financeq integration consuming scholiq's `Order` objects as a source, not a reason to grow
`Order` into an accounts-receivable system here.

## Rejected Alternatives

- **Put `voluntary` on `Order` instead of `FeeItem`.** Rejected — forces one checkout to be either fully
  voluntary or fully commercial, which doesn't match a guardian paying both a trip contribution and a paid
  elective in one basket. See "Why `voluntary` lives on `FeeItem`" above.
- **Gate access directly off `Order.lifecycle == paid` instead of introducing `Entitlement`.** Rejected —
  collapses "payment recorded" and "access granted" into one field, which is exactly the property that
  makes accidental gating of a voluntary fee possible: every read site would need its own voluntary-check
  convention instead of one guarded transition. See "Why `Entitlement` is the only object the guard
  touches" above.
- **Allow `Entitlement` to activate on `Order.lifecycle == partially-paid`.** Rejected for this change —
  partial-access-for-partial-payment is a real pattern some platforms support, but it multiplies the
  guard's cases (how much partial payment is "enough"?) without a concrete buyer requirement driving the
  threshold. `EntitlementOrderPaidGuard` requiring full `paid` is the simpler, safer default; a future
  change can add a `minimumPaidPercent` to `FeeItem` if a buyer needs it.
  - **Reconsider if**: an MBO contractonderwijs buyer specifically needs installment-based access.
- **Build the OpenConnector adapter's request/response shape speculatively in this change's design.md, to
  save a round trip later.** Rejected — the LTI precedent (`LtiToolPlacementController`'s own comment
  block) shows exactly what goes wrong: it documented an "ASSUMED" endpoint path and body shape that later
  needed correction once the real adapter landed. This design instead states the seam's *obligations*
  (opaque forwarding, existing outbound auth pattern, a new inbound endpoint with its own auth) without
  inventing the OpenConnector-side request/response JSON shape, which is that repo's decision to make.
- **Skip `OrderLine` and store an inline `lines[]` array on `Order`.** Rejected — no verified precedent in
  `lib/Settings/scholiq_register.json` for an array-of-composite-objects property carrying its own $ref
  relations (arrays in this register are plain UUID-string lists, e.g. `Programme.courseIds`, or scalar
  lists). `OrderLine` as its own child-row object matches the register's dominant pattern (e.g.
  `AttendanceRecord`, `GradeEntry` as rows referencing a parent) and lets `OrderTotalValidationGuard` query
  it with the same `ObjectService`-based approach `BsaProgressEvaluator` already uses for cross-row sums.
- **Declare `Order.totalAmount` as an `x-openregister-aggregations` sum over `OrderLine`.** Rejected — a
  full-file grep of `lib/Settings/scholiq_register.json` confirms only `count` and `count_distinct` are
  used as verified aggregation metrics anywhere in this register; no `sum` metric has a working precedent.
  Rather than assume the aggregation engine supports summing an arbitrary numeric field (unverified), this
  change uses the same ADR-031 PHP-exception pattern `GradeFormulaEvaluator`/`BsaProgressEvaluator`
  establish for sums the declarative engine can't do — except as a *validation guard* at the finalize
  transition rather than a continuously materialised field, since `Order.totalAmount` only needs to be
  correct at the moment a payer can no longer edit the lines.

## Security / Privacy Posture

- `PaymentTransaction` is `appendOnly: true` (ADR-008) — a financial-transaction record must not be
  editable after the fact, matching `Attestation`'s evidentiary shape.
- `x-property-rbac` on `Order`/`PaymentTransaction`/`Entitlement` (to be declared alongside the schemas in
  tasks.md) MUST mirror `FinalGrade`'s pattern: the payer (`payerId`) and the beneficiary learner
  (`learnerId`) read their own records; `admin`/finance-role users read all. A learner MUST NOT read another
  learner's `Order`.
- `x-openregister-authorization.create` on `PaymentTransaction` restricts creation to the
  `PaymentTransactionController::initiate()` code path (server-side, not a raw OR object-API POST) —
  mirroring the `xapi-statement` stopgap-admin-only-create precedent's reasoning: a client-controlled
  `PaymentTransaction.amount` would let a payer forge a cheaper transaction than their `Order.totalAmount`.
- `PaymentTransactionController::callback()` MUST authenticate the caller as OpenConnector specifically
  (not "any authenticated Nextcloud user") before accepting a status update — the concrete mechanism is a
  cross-repo decision (see "What is still genuinely missing" above), but the requirement that it be
  OpenConnector-specific, not the general-purpose outbound token reused in reverse, is decided here.
- No BSN, IBAN, or PSP credential is ever persisted in scholiq's register — `PaymentTransaction` stores only
  the PSP's opaque payment identifier and status, matching the "opaque forwarding" discipline above. Actual
  payment-method details (card, iDEAL bank) never transit scholiq at all; they flow directly between the
  payer's browser and the PSP's hosted checkout, per how Mollie/Stripe checkout flows normally work.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects (`lib/Settings/scholiq_register.json`); no new database tables
  (ADR-001).
- No pass-through CRUD controller for `FeeItem`/`Order`/`OrderLine`/`Entitlement` — OpenRegister's object
  API serves those directly; `PaymentTransactionController` exists only for the two delegation endpoints
  the declarative engine cannot express (ADR-022).
- Wire protocol (PSP HTTP calls, webhook signature verification) delegated to OpenConnector — scholiq
  implements none of it (matches `data-exchange`'s and `course-management`'s LTI-delegation posture).
- Threshold/roll-up detection (`PaymentTransactionStatusHandler` rolling `PaymentTransaction` status up to
  `Order`) is event-driven (`ObjectTransitionedEvent`), never a `TimedJob` (ADR-022).
- Notifications via the `x-openregister-notifications` dialect only (ADR-031) — `dueSoon`/`overdue` reuse
  `Enrolment`'s exact verified shape.
- UI is manifest-driven; the one custom view (`OrderPaymentPanel`) is a genuine payment-initiation surface,
  not a CRUD form — same bar `attendance`'s `MarkAttendanceView` and `study-progress`'s `BsaRiskDashboard`
  were held to.
- i18n keys in English; SPDX headers on all new PHP files.
