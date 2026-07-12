# Certification — EUDI Wallet Credential Push Delta

**Spec refs**: `certification`, ADR-041

## ADDED Requirements

### Requirement: Credential schema carries wallet-offer state

The `Credential` schema (`lib/Settings/scholiq_register.json`) MUST carry five nullable properties
describing EUDI-wallet offer delivery, orthogonal to the existing `lifecycle` enum
(`issued`/`revoked`/`expired`), which continues to describe the credential's own validity and not wallet
delivery: `walletOfferStatus` (enum `offered | claimed | revoked`, `null` until first pushed),
`walletOfferedAt` (date-time, set when the offer is dispatched), `walletClaimedAt` (date-time, set when
openconnector reports the wallet holder claimed the offer), `walletAttestationRef` (string, the opaque
correlation key openconnector's OpenID4VCI adapter returns for the credential-offer/issuance session), and
`walletOfferError` (string, the last push/propagation failure message, cleared on the next successful
offer).

#### Scenario: A Credential without a wallet offer has null wallet-offer fields

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface, verified by schema fixtures. -->

- **GIVEN** a `Credential` that has never been pushed to a wallet
- **WHEN** the object is read
- **THEN** `walletOfferStatus`, `walletOfferedAt`, `walletClaimedAt`, `walletAttestationRef`, and
  `walletOfferError` are all `null`
- **AND** the existing `lifecycle` value (`issued`/`revoked`/`expired`) is unaffected by any of these
  fields

### Requirement: offerToWallet transition pushes an issued Credential to the EUDI wallet

The `Credential` schema MUST declare an `offerToWallet` `x-openregister-lifecycle` transition, a self-loop
on the existing `lifecycle` field (`from: ["issued"], to: "issued"`) so it never collides with the
credential's own issued/revoked/expired meaning. The transition `requires`
`OCA\Scholiq\Service\WalletOfferDelegationService`, built to the `check(array &$transitionContext): bool`
hook contract `CredentialSigningService` already establishes. On invocation it dispatches a
`WalletOfferRequestedEvent` (`class_exists`-guarded per ADR-041, failing closed when openconnector or the
event class is absent). On a handled result it writes `walletOfferStatus=offered`,
`walletOfferedAt=now`, `walletAttestationRef=<returned reference>`, and clears `walletOfferError`, into
the transition context. On failure — including the guard failing closed — it sets `walletOfferError` and
returns `false`, blocking the transition.

#### Scenario: Pushing an issued credential to the wallet records the offer

<!-- @e2e exclude Surfaces via the existing generic lifecycleActions button on the Credential detail page (src/manifest.json:1812-1814); no new scholiq DOM. Covered by WalletOfferDelegationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"` and `walletOfferStatus: null`
- **WHEN** an authorized user triggers the `offerToWallet` transition and openconnector's OpenID4VCI
  adapter accepts the offer
- **THEN** `walletOfferStatus` becomes `"offered"`, `walletOfferedAt` is set to the transition time,
  `walletAttestationRef` is set to the reference openconnector returned, `walletOfferError` is cleared,
  and `lifecycle` remains `"issued"`

#### Scenario: openconnector unreachable blocks the offer and records the error

<!-- @e2e exclude Failure-path of a backend lifecycle hook; no scholiq DOM surface beyond the generic transition-error toast. Covered by WalletOfferDelegationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"`
- **WHEN** an authorized user triggers the `offerToWallet` transition and openconnector is absent or the
  dispatched `WalletOfferRequestedEvent` is not handled
- **THEN** the transition is blocked (`lifecycle` stays `"issued"` and `walletOfferStatus` stays
  unchanged), `walletOfferError` is set to a description of the failure, and no partial wallet-offer state
  is written

### Requirement: recordWalletClaim transition syncs wallet-claim status back onto the Credential

The `Credential` schema MUST declare a `recordWalletClaim` `x-openregister-lifecycle` transition, a
self-loop on `lifecycle` (`from: ["issued"], to: "issued"`), invoked only by a new
`WalletOfferConcludedListener` (`class_exists`-guarded on
`\OCA\OpenConnector\Event\WalletOfferConcludedEvent`, registered in `lib/AppInfo/Application.php`) when
openconnector reports the wallet holder claimed the offer — never a user-facing action. The transition
`requires` `OCA\Scholiq\Service\WalletClaimSyncService`, which writes `walletOfferStatus=claimed` and
`walletClaimedAt=now`.

#### Scenario: A claimed wallet offer updates the Credential's wallet-offer status

<!-- @e2e exclude System-triggered by an inbound cross-app event; no scholiq DOM interaction to drive. Covered by WalletClaimSyncService + WalletOfferConcludedListener PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `walletOfferStatus: "offered"` and a `walletAttestationRef` matching an
  outstanding wallet-offer session
- **WHEN** openconnector dispatches a `WalletOfferConcludedEvent` reporting that session as claimed
- **THEN** the matching `Credential`'s `walletOfferStatus` becomes `"claimed"` and `walletClaimedAt` is set
  to the claim time

### Requirement: Revoking a Credential propagates to any outstanding wallet offer, fail-soft

The existing `revoke` transition (`from: "issued", to: "revoked"`) MUST gain a second `requires` entry,
`OCA\Scholiq\Service\WalletRevocationPropagationService`, so revoking a credential propagates to any
outstanding wallet offer. This hook MUST be fail-soft by design: revoking a credential is the compliance
action of record and MUST NOT be blocked by the wallet rail being unavailable. It fires only when
`walletOfferStatus` is `"offered"` or `"claimed"` (nothing to revoke otherwise), dispatches a
`WalletRevocationRequestedEvent` best-effort, catches `Throwable`, logs the failure, and always returns
`true` so `revoke` proceeds regardless of wallet-rail outcome; on a successful propagation it also sets
`walletOfferStatus=revoked`.

#### Scenario: Revoking a credential with an outstanding wallet offer propagates the revocation

<!-- @e2e exclude Backend lifecycle hook triggered from the existing revoke action; no new scholiq DOM. Covered by WalletRevocationPropagationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"` and `walletOfferStatus: "offered"`
- **WHEN** an authorized user triggers the existing `revoke` transition and openconnector's OpenID4VCI
  adapter successfully revokes the wallet attestation
- **THEN** `lifecycle` becomes `"revoked"` and `walletOfferStatus` becomes `"revoked"`

#### Scenario: Revoking a credential proceeds even when the wallet rail is unavailable

<!-- @e2e exclude Fail-soft backend path; no scholiq DOM surface — the revoke button behaves identically to today regardless of this hook's outcome. Covered by WalletRevocationPropagationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"` and `walletOfferStatus: "claimed"`
- **WHEN** an authorized user triggers the `revoke` transition and openconnector is absent or the
  `WalletRevocationRequestedEvent` dispatch throws
- **THEN** `lifecycle` still becomes `"revoked"` (the compliance action of record is never blocked)
- **AND** the failure is logged rather than surfaced as a transition error
- **AND** `walletOfferStatus` is left unchanged (not set to `"revoked"`) since propagation did not
  succeed

### Requirement: Cross-app wallet-offer event contract, scholiq side

scholiq MUST define, as the producer, `WalletOfferRequestedEvent` and `WalletRevocationRequestedEvent`
(`lib/Event/`), and, as the consumer, handle `WalletOfferConcludedEvent`, per the ADR-041 typed-event
recipe — the sanctioned cross-app mechanism, not a REST call or an OpenRegister integration-registry leaf.
Each event carries provenance (`sourceApp='scholiq'`, `subjectRegister`, `subjectSchema='Credential'`,
`subjectId`), a `payload` (credential `kind`, `openbadges3Payload`, `edciPayload`, `learnerId`,
`issuerDid`, `expiresAt`), an `externalReference` (the Credential UUID, for idempotency), a
`correlationId`, and, on the request events, a synchronous result slot (`walletAttestationRef` +
`handled`). This change defines the scholiq-side event classes and dispatch/listener wiring only; the
OpenID4VCI wire protocol and issuance/sealing/revocation logic are out of scope and belong to a companion
openconnector-side change.

#### Scenario: WalletOfferRequestedEvent carries enough payload for openconnector to build a wallet offer

<!-- @e2e exclude Cross-app event contract; no scholiq DOM surface. Covered by unit tests asserting the event's constructed shape per tasks.md. -->

- **GIVEN** the `offerToWallet` transition dispatches a `WalletOfferRequestedEvent` for a `Credential`
- **WHEN** the event is constructed
- **THEN** it carries `sourceApp='scholiq'`, `subjectSchema='Credential'`, `subjectId`, a `payload`
  containing `kind`, `openbadges3Payload`, `edciPayload`, `learnerId`, `issuerDid`, and `expiresAt`, an
  `externalReference` equal to the Credential's UUID, and a `correlationId`

#### Scenario: Absent openconnector fails closed rather than silently no-opping

<!-- @e2e exclude Cross-app event contract failure path; no scholiq DOM surface. Covered by unit tests per tasks.md. -->

- **GIVEN** openconnector's listener class for `WalletOfferRequestedEvent` does not exist on the running
  instance
- **WHEN** `WalletOfferDelegationService` attempts to dispatch the event
- **THEN** the `class_exists` guard fails closed, the `offerToWallet` transition is blocked, and
  `walletOfferError` is set to a message indicating the wallet integration is unavailable
