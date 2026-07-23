---
kind: code
depends_on: []
---

> **Implementation note (2026-07-13, at archive):** This proposal was authored around ADR-041's
> typed-event recipe (scholiq dispatching `WalletOfferRequestedEvent`/`WalletRevocationRequestedEvent`).
> During implementation openconnector's companion adapter (`eudi-wallet-credential-issuance`) shipped a
> **REST** interface — `EudiWalletController` (`POST /api/eudi/credential-offers`, `.../{id}/revoke`) — not
> an event listener. The delivered scholiq side therefore delegates over REST via
> `WalletOfferDelegationService`/`WalletRevocationPropagationService` (reusing the
> `scholiq.openconnector_api_token` seam from `DataExchangeRunHandler::callOpenConnector()`), and the
> planned event classes were not built. `WalletOfferConcludedListener` is kept as a `class_exists`-guarded
> forward-compat shim for a future inbound wallet-claim notification. The delta spec
> (`specs/certification/spec.md`) and the shipped code are authoritative; the event-mechanism prose below
> is retained as the original design rationale. Follow-ups: openconnector consumer-JWT auth + wallet-claim
> trigger.

## Why

Regulation (EU) 2024/1183 (eIDAS 2.0) requires every member state to offer at least one certified
EUDI wallet by end of December 2026, and wallets explicitly hold diplomas/professional qualifications
as electronic attestations of attributes (Spectr insight 1150, impact `high`: "certification
capability should plan for issuing diplomas/certificates as wallet-consumable attestations —
converges with EDCI/ELM + Open Badges 3.0, all W3C VC based — one credential model, three delivery
rails. Issuing/sealing adapter belongs in openconnector or a dedicated trust-service integration;
scholiq owns the credential source data."). The three standards this converges (Spectr `nl_standards`
rows EDCI, ELM, Open-Badges) each independently note the same split: scholiq maps its data to the
credential model, the sealing/wallet-delivery adapter is a separate integration. Two stories give the
learner-facing shape: story 9803 "As a student I want an edubadge for each completed module so I can
share verifiable credentials with employers" and story 9779 "GIVEN learner completes a recognized
RADIO certification WHEN the certificate is issued THEN it is stored in a portable transcript with
verifiable URL/QR that any ministry can validate when the learner moves."

**scholiq already promises wallet delivery it cannot perform today.** `certification/spec.md:28` —
"the certificate is issued, signed, and pushed to the learner's wallet within 30 seconds" — is an
existing MUST-level acceptance criterion. `certification/spec.md:36-41` ("Issue EDCI/Europass and
Open Badges 3.0 credentials") requires only "a verifiable URL," not a wallet push. Grepping
`lib/Settings/scholiq_register.json:175-708` (the full `Credential` schema) confirms there is no
wallet field, no offer-status, and no OpenID4VCI anywhere in the register — the "pushed to the
learner's wallet" acceptance criterion has never had a schema seam to attach to. This is the gap the
gap-report shortlisted as item #9.

**The credential source data already exists; only the wallet seam is missing.** `Credential`
(`lib/Settings/scholiq_register.json:175-708`) carries `kind` (diploma/certificate/badge/
microcredential), `openbadges3Payload` (required, OB3 JSON-LD), `edciPayload` (nullable, "EDCI/Europass
ELM payload. Phase 3.", line 277-282), `signature`, `issuerDid`, `verificationUrl`, and a `lifecycle`
of `issued → revoked | expired` (`x-openregister-lifecycle`, lines 351-371). `x-property-rbac`
(line 689-705) already scopes read to the credential-holder + admin. The schema is `appendOnly: true`
(line 707) — issuance is append-only compliance evidence; existing precedent already writes derived
fields during a transition rather than a raw update: the `issue` transition
(`from: null, to: "issued"`) declares `requires: ["OCA\\Scholiq\\Service\\CredentialSigningService"]`
(lines 363-369), and `CredentialSigningService`'s own docblock (`lib/Service/CredentialSigningService.php:9-13`)
states the exact hook contract this change reuses: "OpenRegister's lifecycle engine resolves this
class via DI and calls `check()` before executing the transition. `check()` assembles + signs the OB3
payload, injects it into the transitionContext object, and returns `true` to allow the transition."
`check(array &$transitionContext): bool` is the concrete method (`lib/Service/CredentialSigningService.php:94`).

**Wire protocols to external systems belong in openconnector, not scholiq** — the same split the
`data-exchange` capability already applies to BRON/ROD/OSO (`openspec/specs/data-exchange/spec.md:19,55`:
"The actual wire protocols ... live in OpenConnector ... separate issues filed against
`ConductionNL/openconnector`"). scholiq has no OpenID4VCI code today (repo-wide grep for
`OpenID4VCI|openid4vci|wallet` across `lib/` returns nothing) and this change adds none — it defines
only the cross-app contract/seam per the scope given.

**The sanctioned cross-app mechanism is ADR-041's typed-event recipe, not a REST call or a registry
leaf.** `hydra/openspec/architecture/adr-041-cross-app-commands-via-events.md` records that every
prior "call another Conduction app's business action" attempt that used the OpenRegister integration
registry or a server-to-server HTTP POST to a `#[NoAdminRequired]` route was a phantom no-op (401s,
non-existent methods) — the sanctioned pattern is a producer app's own `IEventDispatcher`-dispatched
`XxxRequestedEvent` (provenance + payload + synchronous result slot) handled by the target app's
listener, with a follow-up `XxxConcludedEvent` for async terminal outcomes. This is already shipped
fleet-wide: decidesk's `DecisionRequestedEvent`/`DecisionConcludedEvent`
(`decidesk/lib/Event/DecisionRequestedEvent.php`, `DecisionConcludedEvent.php`) consumed by procest's
`ContractDecisionDelegationService` (`procest/lib/Service/ContractDecisionDelegationService.php:65-68,
190-230` — `class_exists`-guarded FQCN constant, fails closed with a `RuntimeException` when the target
app is absent, dispatches synchronously, reads the result slot back off the same event instance) and a
`class_exists`-guarded listener registration in `procest/lib/AppInfo/Application.php:542`. This change
adopts the exact same shape for scholiq → openconnector: scholiq is the producer of
`WalletOfferRequestedEvent`/`WalletRevocationRequestedEvent` and the consumer of
`WalletOfferConcludedEvent`.

**A pre-existing risk this change inherits (not created by it):** `OpenRegister\Service\ObjectService::saveObject()`
unconditionally rejects any update carrying a `uuid` on an append-only schema —
`openregister/lib/Service/ObjectService.php:1082-1089` — `if ($uuid !== null && ... isAppendOnly() === true) { throw new AppendOnlyException(...) }`,
with no bypass parameter on `saveObject()`, and `TransitionEngine::transition()`
(`openregister/lib/Service/Lifecycle/TransitionEngine.php:186-193`) saves through this exact same
`saveObject()` call with the object's `uuid` set. `Credential`'s own existing `revoke`/`expire`
transitions (append-only, `from: "issued"` to an existing object) appear to hit this same guard today,
independent of this change. This change's `offerToWallet`/`recordWalletClaim` transitions are declared
using the identical, already-established pattern (so they are no worse off than `revoke`/`expire`),
but `tasks.md` includes an explicit verification task because building on an already-broken write path
would silently ship a second broken feature. This is flagged for a human decision rather than
"fixed" here: fixing `ObjectService`/`TransitionEngine` is OpenRegister foundation work, out of an
S-sized scholiq leaf's scope.

## What Changes

- **`Credential` schema (`lib/Settings/scholiq_register.json`) gains 5 new nullable properties**,
  orthogonal to the existing `lifecycle` enum (issued/revoked/expired), which continues to describe the
  credential's own validity — not wallet delivery:
  - `walletOfferStatus` — enum `offered | claimed | revoked`, null until first pushed.
  - `walletOfferedAt` — date-time, set when the offer is dispatched.
  - `walletClaimedAt` — date-time, set when openconnector reports the wallet holder claimed the offer.
  - `walletAttestationRef` — string, the opaque reference openconnector's OpenID4VCI adapter returns
    for the credential-offer/issuance session; the correlation key for claim sync-back and revocation.
  - `walletOfferError` — string, the last push/propagation failure message, cleared on the next
    successful `offerToWallet`.
- **Three new `x-openregister-lifecycle` transitions on `Credential`**, each a self-loop on the
  existing `lifecycle` field (`from: ["issued"], to: "issued"`) so they never collide with the
  credential's own issued/revoked/expired meaning:
  - `offerToWallet` — user-triggered (the "push to wallet" action on the Credential detail page,
    surfaced automatically by the existing `lifecycleActions: {field: "lifecycle"}` config on the
    `Credential` page, `src/manifest.json:1812-1814` — no manifest change needed). `requires` a new
    `OCA\Scholiq\Service\WalletOfferDelegationService`, built to the `check(array &$transitionContext): bool`
    contract `CredentialSigningService` already establishes. It dispatches `WalletOfferRequestedEvent`
    (`class_exists`-guarded, fails closed per ADR-041 when openconnector/the event class is absent —
    mirrors `ContractDecisionDelegationService::dispatchDecisionRequest`), and on a handled result writes
    `walletOfferStatus=offered`, `walletOfferedAt=now`, `walletAttestationRef=<returned ref>`, clears
    `walletOfferError`, into `$transitionContext`. On failure it sets `walletOfferError` and returns
    `false` (blocks the transition — same fail-closed posture as decidesk's consumers).
  - `recordWalletClaim` — system-triggered only (never a user-facing button; see DEFERRED_QUESTIONS),
    invoked by a new `WalletOfferConcludedListener` registered in `lib/AppInfo/Application.php`
    (`class_exists`-guarded on `\OCA\OpenConnector\Event\WalletOfferConcludedEvent`, mirroring the
    existing `registerEventListener` block at `lib/AppInfo/Application.php:177-250`) when openconnector
    reports the wallet holder claimed the offer. `requires` a small `WalletClaimSyncService` that writes
    `walletOfferStatus=claimed`, `walletClaimedAt=now`.
  - `revoke` (existing, `from: "issued", to: "revoked"`, lines 355-358) gains a second `requires` entry,
    `OCA\Scholiq\Service\WalletRevocationPropagationService`, so revoking a credential propagates to any
    outstanding wallet offer. Unlike `offerToWallet`, this hook is **fail-soft by design**: revoking a
    credential is the compliance action of record and MUST NOT be blocked by the wallet rail being
    unavailable. It only fires when `walletOfferStatus` is `offered` or `claimed` (nothing to revoke
    otherwise), dispatches `WalletRevocationRequestedEvent` best-effort, catches `Throwable`, logs, and
    always returns `true` so `revoke` proceeds; on success it also sets `walletOfferStatus=revoked`.
- **New cross-app event contract (scholiq side only)** — `lib/Event/WalletOfferRequestedEvent.php` and
  `lib/Event/WalletRevocationRequestedEvent.php` (scholiq is the producer; openconnector implements the
  listener), `WalletOfferConcludedEvent` (openconnector is the producer; scholiq implements the
  listener) — shaped like `DecisionRequestedEvent`/`DecisionConcludedEvent`
  (`decidesk/lib/Event/DecisionRequestedEvent.php`): provenance (`sourceApp='scholiq'`,
  `subjectRegister`, `subjectSchema='Credential'`, `subjectId`), a `payload` (credential `kind`,
  `openbadges3Payload`, `edciPayload`, `learnerId`, `issuerDid`, `expiresAt`), `externalReference`
  (the Credential UUID, for idempotency), `correlationId`, and a synchronous result slot
  (`walletAttestationRef` + `handled`) on the request event.
- **Cross-repo companion change (not part of this change, prose reference only):** an openconnector-side
  change, working title `eudi-wallet-credential-issuance`, implements the actual OpenID4VCI
  issuance/sealing/revocation wire protocol and the listeners for
  `WalletOfferRequestedEvent`/`WalletRevocationRequestedEvent`, dispatching `WalletOfferConcludedEvent`
  back. Scope split follows the `data-exchange` precedent exactly
  (`openspec/specs/data-exchange/spec.md:19,55`). This scholiq change is fully specified without it —
  the event classes' `class_exists` guards fail closed until the companion change ships.
- **No new manifest.json pages/widgets.** The `offerToWallet` action surfaces via the existing
  `lifecycleActions` mechanism on the `Credential` detail page (`src/manifest.json:1805-1885`); the
  wallet-offer status/timestamps render as ordinary fields in the existing `cred-data` widget.
- **No new PHP controllers.** `WalletOfferDelegationService`, `WalletClaimSyncService`, and
  `WalletRevocationPropagationService` are lifecycle `requires` hooks (ADR-031 exception 1: "OR's
  extension is missing or insufficient" — no declarative expression exists for dispatching a
  cross-app event) and `WalletOfferConcludedListener` is an `IEventListener`, matching every existing
  ADR-031-exception bridge already registered in `lib/AppInfo/Application.php:173-250`
  (`XapiCompletionHandler`, `CredentialIssuanceHandler`, `GradeRollupHandler`, etc.) — no
  `x-openregister-notifications` change is proposed in this S-sized change (the existing
  `issuedToLearner`/`expiringSoon`/`revoked` notifications on `Credential`, lines 601-687, already
  cover the credential's own lifecycle; wallet-offer notifications are left as a follow-up so this
  change stays scoped to the contract/seam).

## Impact

- **Affected spec:** `openspec/specs/certification/spec.md` — ADDED requirements only; no existing
  requirement is modified (the existing "Issue EDCI/Europass and Open Badges 3.0 credentials," "Detect
  expiries," and "Auto-enrol on renewal" requirements are untouched).
- **Affected code (this change):** `lib/Settings/scholiq_register.json` (`Credential` schema — 5 new
  properties, 2 new transitions, 1 modified transition's `requires`), 3 new PHP service classes under
  `lib/Service/`, 3 new PHP event classes under `lib/Event/`, 1 new PHP listener under `lib/Listener/`,
  `lib/AppInfo/Application.php` (1 new `registerEventListener` block).
- **Not affected:** `src/manifest.json` (no change needed — see above), no PHP controllers, no new
  routes in `appinfo/routes.php`.
- **Cross-repo dependency (informational, not a build blocker):** end-to-end wallet delivery requires
  the openconnector-side `eudi-wallet-credential-issuance` companion change; until it ships,
  `offerToWallet` fails closed with a clear `walletOfferError` and no state corruption.
- **Risk carried forward, not resolved here:** the append-only/`TransitionEngine` interaction flagged
  in Why — `openregister/lib/Service/ObjectService.php:1082-1089` — affects `Credential`'s existing
  `revoke`/`expire` transitions as much as this change's new ones. `tasks.md` includes a verification
  task; if it reproduces, the fix belongs in a separate OpenRegister-tracked change, not here.

## DEFERRED_QUESTIONS

- `recordWalletClaim` is documented as "system-triggered only," but this schema's default RBAC (no
  `x-openregister-authorization` override on `Credential` today) may still let an admin trigger it
  manually via the generic transition UI/endpoint, same as any other declared transition — I did not
  find a "hidden/system-only transition" primitive in this codebase to suppress it from
  `CnLifecycleActions`. Provisional decision: accept admin-manual-override as low-risk (admins are
  already trusted to force other states) rather than inventing a new OR primitive in an S-sized change;
  revisit if this proves confusing in practice.
- `walletOfferStatus` values are exactly `offered | claimed | revoked` per the given scope, with `null`
  as the implicit "never offered" state rather than an explicit `not-offered` enum value (keeps the
  enum tight; `null` is idiomatic for "not yet issued" elsewhere in this schema, e.g. `expiresAt`).
- No `x-openregister-notifications` entries are added for wallet-offer state changes in this change
  (kept out to stay S-sized / contract-only); left as an explicit follow-up rather than silently
  bundled in.
