---
kind: code
depends_on: []
---

## Why

Both capability specs that could plausibly own payment punt it, and the promised follow-up spec was
never written:

- `openspec/specs/enrolment/spec.md:66` lists under Out of Scope: "Payment processing for paid
  enrolments (separate spec; routes to billing system)."
- `openspec/specs/course-management/spec.md:186` lists under Out of Scope: "Marketplace / paid course
  storefront (separate spec if pursued)."
- No such spec exists. `ls openspec/specs/` enumerates all 30 capabilities in this repo
  (`ai-companion-tools` … `study-progress`) and none is named `payments`, `billing`, or similar.
- Neither `Course` (`lib/Settings/scholiq_register.json:809-903`, read in full) nor `Enrolment`
  (`lib/Settings/scholiq_register.json:1451-1568`, read in full) carries a price, fee, or
  payment-status field anywhere. There is no schema in the register that models money at all.

Meanwhile two revenue/compliance paths this gap blocks are universal, not niche:

- **Schoolkassa / vrijwillige ouderbijdrage** (voluntary parental contribution for school trips,
  materials, and extracurricular activities) is standard practice in NL PO/VO — ParnasSys and Magister,
  the two dominant Dutch school-administration systems, both ship a schoolkassa/ouderbijdrage module
  (per the task brief's competitive scan). It is also a **legal duty**, not just a feature: the **Wet
  vrijwillige ouderbijdrage** (in force since 1 August 2021, amending WPO/WVO) makes
  non-payment-driven exclusion illegal. Fetched 2026-07-13:
  - rijksoverheid.nl: "De vrijwillige ouderbijdrage voor extra activiteiten buiten de lessen om is niet
    verplicht" and "Ook als hun ouders niet betalen, mag uw kind toch meedoen aan alle extra's."
  - vo-raad.nl: "het niet betalen van een vrijwillige ouderbijdrage door de ouders voor een bepaalde
    activiteit, [mag] niet leiden tot het uitsluiten van een leerling voor de betreffende activiteit,"
    and, critically, **"Een alternatief bieden is niet voldoende"** — a school may cancel an activity
    for lack of funds, but if it runs, every pupil participates identically regardless of payment. A
    lesser/substitute programme for non-payers is explicitly not a compliant workaround.
- **MBO contractonderwijs** and paid-course commerce are revenue paths the market already serves: Odoo,
  Teachable, Thinkific, Kajabi, LearnDash, Absorb, D2L, Podia, and Cornerstone all ship a paid-course /
  e-commerce capability (per the task brief's competitive scan) — exactly the gap
  `course-management`'s "Marketplace / paid course storefront" line names and defers.

The fleet already has the delegation muscle this needs, verified at HEAD in the `scholiq-goal2/scholiq`
worktree (`development` branch, `062f119`):

- The scholiq→OpenConnector delegation seam is an established, repeated pattern, not a one-off:
  `LtiToolPlacementController::launch()` (`lib/Controller/LtiToolPlacementController.php:168-216`) and
  `DataExchangeRunHandler::callOpenConnector()` (`lib/Listener/DataExchangeRunHandler.php:966-1021`)
  both call OpenConnector via `IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig`
  bearer token under the `scholiq.openconnector_api_token` config key —
  `lib/Service/WalletOfferDelegationService.php:24-36` explicitly reuses this same shape "rather than
  the [...]" instead of inventing a second cross-app auth mechanism. This change's PSP-initiation seam
  follows the identical shape (see design.md).
- OpenConnector's own IA ADR already reserves a name for this adapter and has not built it:
  `openconnector/openspec/architecture/adr-017-information-architecture.md:27` lists
  `mollie-stripe-payment-adapter` among ~15 planned adapter specs in its catalogue. Checked at HEAD
  (`openconnector` repo, `wip/public-audit-query-endpoint` branch, and its `development` ancestor):
  `openconnector/openspec/specs/` (38 specs) and `openconnector/openspec/changes/` (48 changes) contain
  no spec or change named `mollie-stripe-payment-adapter`, `mollie`, `stripe`, or `payment`. The adapter
  genuinely does not exist yet — this is a real, still-open cross-repo follow-up, not a solved problem
  this proposal can silently assume away.

**Correction to the brief's stated blocker — verified against HEAD, not assumed.** The brief described
the fleet credential-broker catalogue as brokering only github/gitlab/doffin, which was true as of
2026-07-12 but is **no longer** the state at HEAD. `openregister/lib/Settings/credential-providers.json`
on `openregister`'s `development` branch (merged via PR #348 "add the fleet providers to the catalogue"
and PR #351) now catalogues `mollie` and `stripe` as first-class, host-locked providers with scoped
`allowRules`:
- `mollie`: `baseUrl: https://api.mollie.com`, rules `GET /v2/methods`, `POST /v2/payments`,
  `GET /v2/payments/*`, `POST /v2/payments/*/refunds`.
- `stripe`: `baseUrl: https://api.stripe.com`, rules `GET /v1/balance`, `POST /v1/payment_intents`,
  `GET /v1/payment_intents/*`, `POST /v1/payment_intents/*/capture`.

The catalogue's own `$fleetComment` documents the prior gap verbatim ("2026-07-12 — the catalogue held
only github/gitlab/doffin... Mollie, Stripe, Adyen, CCV... could not be brokered AT ALL") and records the
fix. So the PSP adapter's *credential-custody* problem is already solved upstream, one layer below where
the brief's memory note was looking. What is still genuinely missing — and what this change cannot itself
close, being scoped to scholiq — is (a) the `mollie-stripe-payment-adapter` spec/build in openconnector
itself, and (b) the reverse-direction (OpenConnector → scholiq) webhook-callback contract this proposal's
design.md defines but does not implement, since no such reverse call exists anywhere in this codebase
today (every existing `callOpenConnector*` call is scholiq-initiated, one-directional).

## What Changes

- **New `payments` capability** with five OpenRegister objects:
  - **`FeeItem`** — the chargeable definition: what is being charged for (course enrolment, school trip,
    materials, schoolkassa line, MBO contractonderwijs), its amount/currency/tax posture, and a required
    `voluntary` flag. `voluntary: true` is the ouderbijdrage case; `voluntary: false` is a real paid
    product (contractonderwijs, a paid course).
  - **`Order`** — the payer-facing request for payment: payer (guardian/learner/employer), beneficiary
    learner, total, currency, due date, and a lifecycle (`draft → open → partially-paid | paid →
    cancelled | refunded`). Declarative `isOverdue` calculation and `dueSoon`/`overdue` notification
    rules reuse the exact idiom `Enrolment.isOverdue`/`Enrolment.dueReminder`/`Enrolment.overdue` already
    use (`lib/Settings/scholiq_register.json:1594-1628,1736-1790`), honouring quiet hours per
    `scholiq-notifications`'s dispatcher-side posture — no scholiq-local suppression logic.
  - **`OrderLine`** — one priced line on an `Order`, referencing a `FeeItem`; `Order.totalAmount` is
    written by the frontend line-editor and validated (not silently trusted) by a new
    `OrderTotalValidationGuard` at the `draft → open` transition, because the register's verified
    aggregation metrics (`count`, `count_distinct` — confirmed by grep, no `sum` metric exists anywhere
    in `lib/Settings/scholiq_register.json`) cannot express a same-object-graph sum the way they express
    a cross-schema count; this mirrors the same ADR-031 PHP-exception rationale `GradeFormulaEvaluator`
    and `BsaProgressEvaluator` already establish for sums the declarative engine can't do.
  - **`PaymentTransaction`** — one PSP round-trip against an `Order` (`pending → awaiting-redirect →
    succeeded | failed | expired | cancelled`, `succeeded → refunded`); append-only per ADR-008, mirrors
    `Attestation`'s evidentiary shape. Records only the opaque status the OpenConnector adapter returns —
    scholiq never parses a Mollie/Stripe-specific payload field, matching the "forward the response as-is"
    posture `LtiToolPlacementController::launch()` already establishes for LTI.
  - **`Entitlement`** — what a paid, non-voluntary `Order` grants (e.g. access to a paid `Course`).
    Structurally cannot become a gate for a voluntary fee: `FeeItemVoluntaryEntitlementGuard` blocks the
    `pending → active` ("grant") transition whenever the referenced `FeeItem.voluntary` is `true` — the
    Wet-vrijwillige-ouderbijdrage "no exclusion, and no substitute programme either" duty encoded as a
    structural guard, not a policy note.
- **The PSP wire protocol is explicitly NOT built here.** `design.md` specifies the seam precisely — a
  `PaymentTransactionController::initiate()` outbound call (scholiq → OpenConnector, reusing the
  established `IClientService`/`IAppConfig` shape) and a new inbound
  `PaymentTransactionController::callback()` endpoint (OpenConnector → scholiq, the first
  reverse-direction cross-app call in this codebase, flagged as a new pattern needing its own auth
  design) — and names the still-open cross-repo follow-up: the `mollie-stripe-payment-adapter` spec in
  `openconnector` that owns the actual Mollie/Stripe HTTP calls, webhook signature verification, and the
  credential-broker `mollie`/`stripe` provider identifiers (already catalogued and usable, per Why).
- **No invoicing/dunning/ERP engine.** Reminders are the same lightweight declarative notification rule
  Enrolment already uses for overdue mandatory training — not a payment-plan, late-fee, or collections
  system. That territory (multi-invoice accounts, statements, structured dunning cadences) belongs to
  pipelinq/financeq; `design.md` states the boundary explicitly.
- **Frontend**: declarative `src/manifest.json` index/detail pages for all five objects, plus one named
  custom view, `OrderPaymentPanel.vue` — the payer's "pay now" surface that calls
  `PaymentTransactionController::initiate()` and renders the OpenConnector-returned checkout URL
  opaquely (same "forward as-is" rule as the LTI launch response).

## Impact

- **`lib/Settings/scholiq_register.json`** — five new schemas: `FeeItem`, `Order`, `OrderLine`,
  `PaymentTransaction`, `Entitlement`. No existing schema is modified (this change is purely additive;
  wiring `Entitlement` into `enrolment`'s or `course-management`'s existing prerequisite/publish
  requirements is named as a fast-follow in design.md, not built in this change — see Out of Scope).
- **New PHP** — `OCA\Scholiq\Lifecycle\OrderTotalValidationGuard`,
  `OCA\Scholiq\Lifecycle\FeeItemVoluntaryEntitlementGuard`,
  `OCA\Scholiq\Listener\PaymentTransactionStatusHandler` (event-driven `Order` status roll-up on
  `PaymentTransaction` transitions — not a `TimedJob`, per ADR-022), and
  `OCA\Scholiq\Controller\PaymentTransactionController` (`initiate()` outbound,
  `callback()` inbound — both thin, opaque proxies per the LTI precedent). No pass-through CRUD
  controller for `FeeItem`/`Order`/`OrderLine`/`Entitlement` — OpenRegister's object API serves those.
- **`src/manifest.json`** — index/detail pages for the five new objects; one new custom view
  `OrderPaymentPanel.vue`.
- **Affected specs**: new `payments` capability spec only. `enrolment`, `course-management`,
  `scholiq-notifications` are read-only precedents this change reuses, not modified.
- **Cross-repo follow-up (not built here)**: file `mollie-stripe-payment-adapter` as a spec/change in
  `openconnector`, implementing the outbound PSP calls (using the already-catalogued `mollie`/`stripe`
  credential-broker providers) and the inbound webhook receiver that calls this change's
  `PaymentTransactionController::callback()`.
- **Out of scope**: wiring `Entitlement` into `enrolment`'s prerequisite check or `course-management`'s
  publish/enrol gate (this change specifies the `Entitlement` contract those capabilities would consume;
  wiring the actual gate is a small follow-up once a real paid-course buyer needs it — see design.md);
  invoicing/dunning/multi-line statements (pipelinq/financeq); VAT/BTW calculation (`taxPosture` is
  descriptive metadata only, not a computed tax engine); refund-policy automation beyond the single
  `succeeded → refunded` transition.
