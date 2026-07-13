---
slug: certification
title: Certification & Digital Credentials
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-003]   # TODO until ADRs land
created: 2026-05-11
---

# Certification & Digital Credentials

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes and scheduled expiry jobs — no `#### Scenario:` headings exist in this spec.

## Purpose
"Certification management" and "Credential management" are both top-10 canonical features (153 demand). Insight #10: "EDCI / Europass digital credentials open the diploma + microcredential market." Insight #11: "Dutch government spends €250M+ annually on employee training" — every euro requires a defensible record. Five `compliance-training` and `government-training` stories pivot on issuing, expiring, and renewing credentials.

## What
Certificate templates (visual + metadata); issuance triggered by course/exam completion; cryptographically verifiable EDCI / Europass issuance; Open Badges 3.0 issuance with verifiable URL/QR; expiry detection with tiered notifications (90/60/30 days); auto-enrol into renewal modules; delta-module enrol when content version changes; Bologna-compliant Diploma Supplement on degree award; portable transcript/edubadge for civil-service mobility.

## User Stories
- As a registrar, I want a Bologna-compliant Diploma Supplement to be generated automatically when a degree is awarded so the institution complies with EU rules.
- As a student, I want an edubadge for each completed module so I can share verifiable credentials with employers.
- As a foreign university registrar, I want a verification link on each transcript so I can confirm authenticity without contacting the issuing institution.
- As a compliance officer, I want any certification recorded with an expiry date to trigger tiered notifications at 90/60/30 days so renewals never lapse silently.
- As a learner who completed a recognised RADIO certification, I want it stored in a portable transcript with verifiable URL/QR so any ministry can validate it when I move.

## Acceptance Criteria
- GIVEN a learner completes a course with a defined certificate template, WHEN the final attempt passes, THEN the certificate is issued, signed, and pushed to the learner's wallet within 30 seconds.
- GIVEN a certification has an expiry date, WHEN the daily job runs, THEN learners + managers + compliance officers get tiered notifications at 90/60/30 days.
- GIVEN a regulation changes and the related course is marked as a new content version, WHEN the change is saved, THEN every previously certified learner is auto-enrolled in the delta module.
- GIVEN a degree is awarded, WHEN the registrar confirms, THEN a Bologna Diploma Supplement is generated and an EDCI credential is issued.
## Requirements
### Requirement: Issue EDCI/Europass and Open Badges 3.0 credentials
The system MUST issue EDCI / Europass credentials and Open Badges 3.0 with verifiable URLs.

#### Scenario: Credential issued with verifiable URL
- **GIVEN** a learner who completes a course or exam with a defined certificate template
- **WHEN** the credential is issued
- **THEN** an EDCI / Europass credential and an Open Badges 3.0 badge are produced, each with a verifiable URL

### Requirement: Detect expiries on a daily schedule
The system MUST detect expiries on a daily schedule and dispatch tiered notifications.

#### Scenario: Daily expiry detection dispatches tiered notifications
- **GIVEN** certifications carrying expiry dates
- **WHEN** the daily expiry-detection schedule runs
- **THEN** expiries are detected and tiered notifications are dispatched at 90/60/30 days to learners, managers, and compliance officers

### Requirement: Auto-enrol on renewal or content-version change
The system MUST auto-enrol learners in renewal or delta modules when triggered by expiry or content-version change.

#### Scenario: Auto-enrol on renewal or content-version change
- **GIVEN** a previously certified learner whose certification expires or whose related course gets a new content version
- **WHEN** the expiry or content-version change is triggered
- **THEN** the learner is auto-enrolled in the corresponding renewal or delta module

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
hook contract `CredentialSigningService` already establishes. On invocation it POSTs the credential
payload to openconnector's merged OpenID4VCI adapter endpoint `POST /api/eudi/credential-offers`
(`EudiWalletController::createOffer`), over the same REST seam scholiq already uses for cross-app calls
(`DataExchangeRunHandler::callOpenConnector()` — the `IClientService` + `IURLGenerator` + `IAppConfig`
bearer shape, reusing the `scholiq.openconnector_api_token` config value), rather than an OpenRegister
integration-registry leaf. It MUST fail closed: when openconnector is absent, returns a non-2xx, or the
call throws, it sets `walletOfferError` and returns `false`, blocking the transition. On a 2xx response it
writes `walletOfferStatus=offered`, `walletOfferedAt=now`, `walletAttestationRef=<returned reference>`, and
clears `walletOfferError`.

#### Scenario: Pushing an issued credential to the wallet records the offer

<!-- @e2e exclude Surfaces via the existing generic lifecycleActions button on the Credential detail page (src/manifest.json:1812-1814); no new scholiq DOM. Covered by WalletOfferDelegationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"` and `walletOfferStatus: null`
- **WHEN** an authorized user triggers the `offerToWallet` transition and openconnector's OpenID4VCI
  adapter accepts the offer (2xx)
- **THEN** `walletOfferStatus` becomes `"offered"`, `walletOfferedAt` is set to the transition time,
  `walletAttestationRef` is set to the reference openconnector returned, `walletOfferError` is cleared,
  and `lifecycle` remains `"issued"`

#### Scenario: openconnector unreachable blocks the offer and records the error

<!-- @e2e exclude Failure-path of a backend lifecycle hook; no scholiq DOM surface beyond the generic transition-error toast. Covered by WalletOfferDelegationService PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `lifecycle: "issued"`
- **WHEN** an authorized user triggers the `offerToWallet` transition and openconnector is absent or the
  `POST /api/eudi/credential-offers` call returns a non-2xx or throws
- **THEN** the transition is blocked (`lifecycle` stays `"issued"` and `walletOfferStatus` stays
  unchanged), `walletOfferError` is set to a description of the failure, and no partial wallet-offer state
  is written

### Requirement: recordWalletClaim transition syncs wallet-claim status back onto the Credential

The `Credential` schema MUST declare a `recordWalletClaim` `x-openregister-lifecycle` transition, a
self-loop on `lifecycle` (`from: ["issued"], to: "issued"`), invoked only by the system when openconnector
reports the wallet holder claimed the offer — never a user-facing action. The transition `requires`
`OCA\Scholiq\Service\WalletClaimSyncService`, which writes `walletOfferStatus=claimed` and
`walletClaimedAt=now`. The inbound trigger is `WalletOfferConcludedListener` (`lib/Listener/`), registered
in `lib/AppInfo/Application.php` under a `class_exists` guard on
`\OCA\OpenConnector\Event\WalletOfferConcludedEvent`. openconnector's merged adapter does not emit that
event today (it fires a status callback only on `revoke`), so the listener is a fail-closed
forward-compatibility shim that stays inert until a wallet-claim notification path is added on the
openconnector side; the transition and `WalletClaimSyncService` are complete and unit-tested so the sync
works the moment that trigger exists.

#### Scenario: A claimed wallet offer updates the Credential's wallet-offer status

<!-- @e2e exclude System-triggered by an inbound cross-app notification; no scholiq DOM interaction to drive. Covered by WalletClaimSyncService + WalletOfferConcludedListener PHPUnit per tasks.md. -->

- **GIVEN** a `Credential` with `walletOfferStatus: "offered"` and a `walletAttestationRef` matching an
  outstanding wallet-offer session
- **WHEN** openconnector notifies that the session was claimed and `WalletOfferConcludedListener` runs the
  `recordWalletClaim` transition
- **THEN** the matching `Credential`'s `walletOfferStatus` becomes `"claimed"` and `walletClaimedAt` is set
  to the claim time

### Requirement: Revoking a Credential propagates to any outstanding wallet offer, fail-soft

The existing `revoke` transition (`from: "issued", to: "revoked"`) MUST gain a second `requires` entry,
`OCA\Scholiq\Service\WalletRevocationPropagationService`, so revoking a credential propagates to any
outstanding wallet offer. This hook MUST be fail-soft by design: revoking a credential is the compliance
action of record and MUST NOT be blocked by the wallet rail being unavailable. It fires only when
`walletOfferStatus` is `"offered"` or `"claimed"` (nothing to revoke otherwise), calls openconnector's
`POST /api/eudi/credential-offers/{id}/revoke` (`EudiWalletController::revoke`) best-effort over the same
REST seam and bearer-token convention as the offer path, catches `Throwable`, logs the failure, and always
returns `true` so `revoke` proceeds regardless of wallet-rail outcome; on a successful propagation it also
sets `walletOfferStatus=revoked`.

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
  `POST /api/eudi/credential-offers/{id}/revoke` call throws
- **THEN** `lifecycle` still becomes `"revoked"` (the compliance action of record is never blocked)
- **AND** the failure is logged rather than surfaced as a transition error
- **AND** `walletOfferStatus` is left unchanged (not set to `"revoked"`) since propagation did not
  succeed

### Requirement: Cross-app wallet delegation over openconnector's REST adapter

scholiq MUST delegate all EUDI-wallet wire operations to openconnector's merged OpenID4VCI adapter over
REST, and MUST NOT implement any OpenID4VCI wire protocol, credential signing/sealing, or status-list
logic itself. `WalletOfferDelegationService` and `WalletRevocationPropagationService` are the only
egress points; both call `EudiWalletController` endpoints (`POST /api/eudi/credential-offers`,
`POST /api/eudi/credential-offers/{id}/revoke`) using the `IClientService` + `IURLGenerator` + `IAppConfig`
seam and the `scholiq.openconnector_api_token` bearer credential established by
`DataExchangeRunHandler::callOpenConnector()`. The request payload MUST carry enough for openconnector to
build a wallet offer: the credential `kind`, its `openbadges3Payload`/`edciPayload`, `learnerId`,
`issuerDid`, `expiresAt`, and the Credential UUID as the idempotency `externalReference`. Where a first-
class app-to-app auth handshake (consumer-JWT registration) or an inbound wallet-claim notification is
required for full round-trip operation, those are documented follow-ups on the openconnector side, not
scholiq responsibilities.

#### Scenario: The offer delegation call carries enough payload for openconnector to build a wallet offer

<!-- @e2e exclude Cross-app REST contract; no scholiq DOM surface. Covered by unit tests asserting the request body shape per tasks.md. -->

- **GIVEN** the `offerToWallet` transition invokes `WalletOfferDelegationService` for a `Credential`
- **WHEN** the outbound `POST /api/eudi/credential-offers` request body is constructed
- **THEN** it carries `subjectSchema='Credential'`, the credential UUID as `externalReference`, and a
  payload containing `kind`, `openbadges3Payload`, `edciPayload`, `learnerId`, `issuerDid`, and
  `expiresAt`

#### Scenario: Absent openconnector fails closed rather than silently no-opping

<!-- @e2e exclude Cross-app REST contract failure path; no scholiq DOM surface. Covered by unit tests per tasks.md. -->

- **GIVEN** openconnector is not reachable on the running instance
- **WHEN** `WalletOfferDelegationService` attempts the `POST /api/eudi/credential-offers` call
- **THEN** the call fails closed, the `offerToWallet` transition is blocked, and `walletOfferError` is set
  to a message indicating the wallet integration is unavailable

## Standards
EDCI (Europass), Open Badges 3.0, E-Portfolio NL, Bologna Diploma Supplement, Schema.org `EducationalOccupationalCredential`.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Certificate`, `CertificateTemplate`, `CredentialIssuance`, `RenewalRule`, `ContentVersion`. All in OpenRegister.

## Out of Scope
- Blockchain-anchored credential storage (V2; verifiable URL + signed JSON is enough at MVP).
- Manual paper certificate printing (handed to docudesk if needed).
- Cross-institution badge wallet (handled by edubadges.nl federation).
