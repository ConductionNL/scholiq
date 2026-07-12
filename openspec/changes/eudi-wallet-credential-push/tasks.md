## 1. Register schema — Credential wallet-offer fields + transitions

- [ ] 1.1 In `lib/Settings/scholiq_register.json`, add five nullable properties to the `Credential`
      schema (near the existing `signature`/`issuerDid`/`verificationUrl` fields, `lib/Settings/scholiq_register.json:175-708`):
      `walletOfferStatus` (`enum: ["offered", "claimed", "revoked"]`, default `null`),
      `walletOfferedAt` (`format: date-time`), `walletClaimedAt` (`format: date-time`),
      `walletAttestationRef` (string), `walletOfferError` (string). None are `required`; all default
      `null` so every existing `Credential` row is unaffected.
- [ ] 1.2 Add an `offerToWallet` `x-openregister-lifecycle` transition (`from: ["issued"], to: "issued"`)
      with `requires: ["OCA\\Scholiq\\Service\\WalletOfferDelegationService"]`, matching the existing
      `issue` transition's `requires` shape (`lib/Settings/scholiq_register.json:363-369`).
- [ ] 1.3 Add a `recordWalletClaim` `x-openregister-lifecycle` transition (`from: ["issued"], to: "issued"`)
      with `requires: ["OCA\\Scholiq\\Service\\WalletClaimSyncService"]`.
- [ ] 1.4 Extend the existing `revoke` transition's `requires` array (`from: "issued", to: "revoked"`,
      `lib/Settings/scholiq_register.json:355-358`) with a second entry,
      `OCA\Scholiq\Service\WalletRevocationPropagationService`, appended after the existing hook so current
      revoke behaviour runs first.
- [ ] 1.5 Confirm `x-property-rbac` (`lib/Settings/scholiq_register.json:689-705`) already covers the five
      new properties under its existing credential-holder-and-admin read scope; add them explicitly to the
      RBAC block only if OR does not apply the schema-level default to new properties automatically.

## 2. Backend — cross-app event contract (scholiq side)

- [ ] 2.1 Add `lib/Event/WalletOfferRequestedEvent.php` (SPDX docblock), shaped like
      `decidesk/lib/Event/DecisionRequestedEvent.php`: provenance (`sourceApp='scholiq'`,
      `subjectRegister`, `subjectSchema='Credential'`, `subjectId`), `payload` (`kind`,
      `openbadges3Payload`, `edciPayload`, `learnerId`, `issuerDid`, `expiresAt`), `externalReference`
      (Credential UUID), `correlationId`, and a synchronous result slot (`walletAttestationRef`,
      `handled`) with getters/setters matching `DecisionRequestedEvent`'s pattern.
- [ ] 2.2 Add `lib/Event/WalletRevocationRequestedEvent.php`, same provenance/payload shape as 2.1, plus a
      `handled` result slot (no `walletAttestationRef` needed on revocation).
- [ ] 2.3 Add `lib/Event/WalletOfferConcludedEvent.php` — the event scholiq *consumes* (openconnector is
      the producer): carries `subjectId`/`externalReference` (Credential UUID) to correlate back, plus the
      concluded status (`claimed`) and claim timestamp.
- [ ] 2.4 Unit tests asserting each event's constructed shape (fields present, getters return constructor
      values) per `specs/certification/spec.md` "WalletOfferRequestedEvent carries enough payload"
      scenario.

## 3. Backend — lifecycle hook services + listener

- [ ] 3.1 Add `lib/Service/WalletOfferDelegationService.php` (SPDX docblock; ADR-031-exception docblock
      naming this change, mirroring `CredentialSigningService`'s docblock style,
      `lib/Service/CredentialSigningService.php:9-13`), implementing
      `check(array &$transitionContext): bool`. It `class_exists`-guards openconnector's
      `WalletOfferRequestedEvent` listener target, fails closed (sets `walletOfferError`, returns `false`)
      when absent, otherwise dispatches `WalletOfferRequestedEvent` via `IEventDispatcher`, reads the
      synchronous result slot, and on `handled === true` writes `walletOfferStatus=offered`,
      `walletOfferedAt=now`, `walletAttestationRef`, clears `walletOfferError`, into `$transitionContext`;
      on `handled === false` sets `walletOfferError` and returns `false`.
- [ ] 3.2 Add `lib/Service/WalletClaimSyncService.php`, `check(array &$transitionContext): bool`, writing
      `walletOfferStatus=claimed`, `walletClaimedAt=now` into `$transitionContext`.
- [ ] 3.3 Add `lib/Service/WalletRevocationPropagationService.php`, `check(array &$transitionContext): bool`,
      fail-soft per `specs/certification/spec.md`: no-op (`return true`) unless the current
      `walletOfferStatus` is `offered` or `claimed`; otherwise dispatches
      `WalletRevocationRequestedEvent` inside a `try`/`catch (\Throwable $e)`, logs on catch, and always
      `return true`; on a successful, handled dispatch also writes `walletOfferStatus=revoked` into
      `$transitionContext`.
- [ ] 3.4 Add `lib/Listener/WalletOfferConcludedListener.php` implementing `IEventListener`,
      `class_exists`-guarded on `\OCA\OpenConnector\Event\WalletOfferConcludedEvent`, resolving the
      matching `Credential` by `externalReference` and invoking the `recordWalletClaim` transition via
      OpenRegister's `TransitionEngine`/`ObjectService`, mirroring the existing listener wiring style at
      `lib/AppInfo/Application.php:177-250`.
- [ ] 3.5 Register the new listener in `lib/AppInfo/Application.php` with a `class_exists`-guarded
      `registerEventListener` block (same guard pattern as the other entries in the existing
      `lib/AppInfo/Application.php:173-250` block).
- [ ] 3.6 Unit tests: `WalletOfferDelegationService` (handled success writes offer fields; absent
      openconnector fails closed and sets `walletOfferError`; unhandled result fails closed);
      `WalletClaimSyncService` (writes claimed fields); `WalletRevocationPropagationService` (no-op when
      `walletOfferStatus` is `null`; propagates and sets `revoked` on success; catches `Throwable` and
      still returns `true` on failure, leaving `walletOfferStatus` unchanged);
      `WalletOfferConcludedListener` (resolves the correct `Credential` by `externalReference` and
      triggers `recordWalletClaim`).

## 4. Verify pre-existing append-only risk (not introduced by this change)

- [ ] 4.1 Write a focused test (or reuse an existing `Credential` `revoke`/`expire` transition test) that
      exercises `TransitionEngine::transition()` on an append-only `Credential` object and confirms whether
      `OpenRegister\Service\ObjectService::saveObject()`'s append-only `uuid` guard
      (`openregister/lib/Service/ObjectService.php:1082-1089`) actually blocks the write, as flagged in
      `proposal.md`'s "Why" section.
- [ ] 4.2 If task 4.1 reproduces the guard blocking the write: do NOT attempt to fix `ObjectService`/
      `TransitionEngine` inside this S-sized scholiq change (out of scope, OpenRegister foundation work).
      Instead, file a separate OpenRegister-tracked issue describing the reproduction, link it from this
      change's notes, and flag to a human that `offerToWallet`/`recordWalletClaim`/`revoke`-with-wallet-
      propagation will not persist until that issue is fixed.
- [ ] 4.3 If task 4.1 shows the write succeeds (e.g. the guard only applies to a different write path),
      record that finding in this change's notes so the risk callout in `proposal.md` can be resolved
      before archiving.

## 5. Verify + docs

- [ ] 5.1 Run `composer check:strict` on all new/touched PHP files (`WalletOfferDelegationService.php`,
      `WalletClaimSyncService.php`, `WalletRevocationPropagationService.php`,
      `WalletOfferRequestedEvent.php`, `WalletRevocationRequestedEvent.php`, `WalletOfferConcludedEvent.php`,
      `WalletOfferConcludedListener.php`, and the touched `Application.php`) and fix any pre-existing
      warnings encountered in them, per CLAUDE.md.
- [ ] 5.2 Add `@spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-...`
      docblock tags to each new/touched method (the three hook services' `check()`, the listener's
      `handle()`, and the `revoke` transition's config change note in `Application.php` if applicable).
- [ ] 5.3 Confirm no `src/manifest.json` change is needed — `offerToWallet` surfaces via the existing
      `lifecycleActions` config on the `Credential` detail page (`src/manifest.json:1812-1814`); the new
      wallet-offer fields render as ordinary fields in the existing `cred-data` widget without a widget
      change. If either assumption proves false while implementing, update this task list before
      continuing.
- [ ] 5.4 File a follow-up issue for the openconnector-side companion change (`eudi-wallet-credential-issuance`)
      implementing the actual OpenID4VCI wire protocol and the listeners for
      `WalletOfferRequestedEvent`/`WalletRevocationRequestedEvent`, per `proposal.md`'s cross-repo note.
- [ ] 5.5 Run `openspec validate eudi-wallet-credential-push --strict` and resolve any errors.
