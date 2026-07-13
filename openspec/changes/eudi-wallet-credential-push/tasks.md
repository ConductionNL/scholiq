## IMPLEMENTATION NOTE — mechanism deviation (read first)

This change's `proposal.md` and the task list below were drafted assuming
openconnector's companion adapter (`eudi-wallet-credential-issuance`) would
expose an ADR-041 typed-event contract (`WalletOfferRequestedEvent`/
`WalletRevocationRequestedEvent` dispatched via `IEventDispatcher`, consumed
by an openconnector listener). **Verified against `openconnector-dev` HEAD
during implementation: that adapter is already merged, and it does not use
events.** It ships a dedicated REST controller
(`lib/Controller/EudiWalletController.php`) with routes registered in
`appinfo/routes.php` (`eudiWallet#createOffer`, `eudiWallet#revoke`, etc.),
consumer-JWT-gated per `authorization-jwt` REQ-001. openconnector's own
proposal for that change states outright: scholiq's `eudi-wallet-credential-push`
"is the caller of `POST /api/eudi/credential-offers`" — a REST call, not an
event. A repo-wide grep of `openconnector-dev` for
`OCA\OpenConnector\Event\Wallet*` returns zero hits.

Tasks 2.1–2.4 (the ADR-041 event classes) were therefore **not implemented as
originally specified** — building them would ship dead code nothing
dispatches or consumes. Tasks 3.1 and 3.3 (`WalletOfferDelegationService`,
`WalletRevocationPropagationService`) were implemented as REST callers
instead of event dispatchers, reusing scholiq's already-established
OpenConnector REST-delegation pattern (`LtiToolPlacementController`,
`DataExchangeRunHandler` — same `IClientService`/`IURLGenerator`/`IAppConfig`
bearer-token shape, same `scholiq.openconnector_api_token` config key). See
each new class's docblock for the full rationale and the residual gaps this
carries (auth-shape mismatch; no real claim-notification trigger). Details
in the implementing agent's final report.

## 1. Register schema — Credential wallet-offer fields + transitions

- [x] 1.1 In `lib/Settings/scholiq_register.json`, add five nullable properties to the `Credential`
      schema (near the existing `signature`/`issuerDid`/`verificationUrl` fields, `lib/Settings/scholiq_register.json:175-708`):
      `walletOfferStatus` (`enum: ["offered", "claimed", "revoked"]`, default `null`),
      `walletOfferedAt` (`format: date-time`), `walletClaimedAt` (`format: date-time`),
      `walletAttestationRef` (string), `walletOfferError` (string). None are `required`; all default
      `null` so every existing `Credential` row is unaffected. DONE — inserted after `verificationUrl`,
      before `lifecycle`.
- [x] 1.2 Add an `offerToWallet` `x-openregister-lifecycle` transition (`from: ["issued"], to: "issued"`)
      with `requires: ["OCA\\Scholiq\\Service\\WalletOfferDelegationService"]`, matching the existing
      `issue` transition's `requires` shape (`lib/Settings/scholiq_register.json:363-369`).
      DEVIATION: `requires` is written as a **single string**, not an array. Verified against
      `openregister-dev/lib/Listener/LifecycleValidationListener.php:215-231`: the real guard-resolution
      code does `$requires = ($spec['requires'] ?? null); if (is_string($requires) === true) { ... }` —
      an array value is silently skipped (`is_string` fails), so the guard would never even be attempted.
      Every existing `requires` entry in this file (25+ instances) is already a single string; no
      pre-existing "issue" transition with `requires` actually exists in the schema today (the proposal's
      citation of lines 363-369 does not match HEAD — see the additional finding under task 4 below).
- [x] 1.3 Add a `recordWalletClaim` `x-openregister-lifecycle` transition (`from: ["issued"], to: "issued"`)
      with `requires: ["OCA\\Scholiq\\Service\\WalletClaimSyncService"]`. DONE, same single-string
      correction as 1.2.
- [x] 1.4 Extend the existing `revoke` transition's `requires` array (`from: "issued", to: "revoked"`,
      `lib/Settings/scholiq_register.json:355-358`) with a second entry,
      `OCA\Scholiq\Service\WalletRevocationPropagationService`, appended after the existing hook so current
      revoke behaviour runs first. DEVIATION: `revoke` has **no existing `requires` hook at HEAD** — the
      proposal's premise of "the existing hook runs first" does not match reality (grepped; only
      `from`/`to` were declared). OR's guard model supports exactly one `requires` string per transition,
      not a chain, so there is nothing to append to regardless. Added the single new `requires` string
      directly.
- [x] 1.5 Confirm `x-property-rbac` (`lib/Settings/scholiq_register.json:689-705`) already covers the five
      new properties under its existing credential-holder-and-admin read scope. CONFIRMED: verified
      `OpenRegister\Db\Schema::getPropertyAuthorization()` — the actual per-property RBAC reader looks for
      an `authorization` key nested inside an *individual* property's own definition
      (`properties.<name>.authorization`), which is a **different** mechanism than the schema-level
      `x-property-rbac` block already on `Credential` (no property in this schema — old or new — carries
      its own per-property `authorization` key). The schema-level block is read by a different, object-wide
      gate and applies uniformly; no per-property listing was needed or added.

## 2. Backend — cross-app event contract (scholiq side)

- [ ] 2.1 ~~Add `lib/Event/WalletOfferRequestedEvent.php`~~ — NOT IMPLEMENTED. See the deviation note at
      the top of this file: openconnector defines and dispatches no such event; nothing would ever
      construct or consume this class. Building it would be dead code.
- [ ] 2.2 ~~Add `lib/Event/WalletRevocationRequestedEvent.php`~~ — NOT IMPLEMENTED, same reason as 2.1.
- [ ] 2.3 ~~Add `lib/Event/WalletOfferConcludedEvent.php`~~ — NOT IMPLEMENTED, same reason as 2.1. (Also
      note: per the ADR-041 shape decidesk/procest actually use, an event scholiq *consumes* from
      openconnector should live in **openconnector's** own `OCA\OpenConnector\Event\` namespace if it
      existed, not scholiq's `lib/Event/` — the original task description had the ownership backwards
      relative to the working precedent, in addition to the class not existing at all.)
- [ ] 2.4 ~~Unit tests asserting each event's constructed shape~~ — N/A, no event classes were built.

## 3. Backend — lifecycle hook services + listener

- [x] 3.1 Add `lib/Service/WalletOfferDelegationService.php` (SPDX docblock; ADR-031-exception docblock
      naming this change, mirroring `CredentialSigningService`'s docblock style), implementing
      `check(array &$transitionContext): bool`. IMPLEMENTED AS A REST CALLER (see deviation note): builds
      an offer request from `edciPayload` (preferred, currently always null — "Phase 3") or
      `openbadges3Payload` (fallback, always populated), POSTs to openconnector's real, routed
      `POST /apps/openconnector/api/eudi/credential-offers` (consumer-JWT-gated), extracts the offer uuid
      from the returned `credentialOfferUri`'s final path segment as `walletAttestationRef`, and on
      success writes `walletOfferStatus=offered`, `walletOfferedAt`, `walletAttestationRef`, clears
      `walletOfferError`. Fails closed (missing token, HTTP error, unresolvable response) with
      `walletOfferError` set and `false` returned.
- [x] 3.2 Add `lib/Service/WalletClaimSyncService.php`, `check(array &$transitionContext): bool`, writing
      `walletOfferStatus=claimed`, `walletClaimedAt=now` into `$transitionContext`. DONE as specified.
- [x] 3.3 Add `lib/Service/WalletRevocationPropagationService.php`, `check(array &$transitionContext): bool`,
      fail-soft. IMPLEMENTED AS A REST CALLER (see deviation note): no-op (`return true`) unless
      `walletOfferStatus` is `offered`/`claimed`; otherwise calls openconnector's real, routed
      `POST /apps/openconnector/api/eudi/credential-offers/{ref}/revoke` inside a `try`/`catch (\Throwable)`,
      logs on catch, and always `return true`; on a confirmed `status: "revoked"` response also writes
      `walletOfferStatus=revoked`.
- [x] 3.4 Add `lib/Listener/WalletOfferConcludedListener.php` implementing `IEventListener`,
      `class_exists`-guarded on `\OCA\OpenConnector\Event\WalletOfferConcludedEvent`, resolving the
      matching `Credential` by `walletAttestationRef` (as `externalReference`) and invoking the
      `recordWalletClaim` transition via `TransitionEngine`. IMPLEMENTED, but flagged as currently
      unreachable end-to-end: openconnector defines no such event class today and has no other
      claim-notification mechanism (no webhook, no poll endpoint — `fireStatusCallback()` fires only from
      `revoke()`, never from the token-exchange/credential-issuance paths where a "claim" actually
      happens). The `class_exists` guard evaluates false, so registration is a no-op until openconnector
      ships a real trigger.
- [x] 3.5 Register the new listener in `lib/AppInfo/Application.php` with a `class_exists`-guarded
      `registerEventListener` block. DONE (`registerWalletOfferConcludedListener()`, mirrors
      `procest\AppInfo\Application::registerDecisionListeners()`).
- [x] 3.6 Unit tests: `WalletOfferDelegationService` (handled success writes offer fields; missing token
      fails closed; unreachable openconnector fails closed; unresolvable response fails closed; no-payload
      fails closed before any HTTP call; badge/microcredential uses the `open-badges-3` configuration id) —
      6 tests. `WalletClaimSyncService` (writes claimed fields) — 1 test.
      `WalletRevocationPropagationService` (no-op when `walletOfferStatus` is `null`; propagates and sets
      `revoked` on success for both `offered` and `claimed`; catches `Throwable` and still returns `true`
      on failure, leaving `walletOfferStatus` unchanged; missing token still proceeds fail-soft) — 5 tests.
      `WalletOfferConcludedListener` (resolves the correct `Credential` by `walletAttestationRef` and
      triggers `recordWalletClaim`; ignores non-claimed status; ignores unresolved Credential; ignores an
      unrelated event shape; catches a TransitionEngine failure) — 5 tests. 17 new tests total, all green
      (baseline 297 → 314).

## 4. Verify pre-existing append-only risk (not introduced by this change)

- [x] 4.1 VERIFIED BY STATIC READ OF HEAD CODE (not a runtime stub test — scholiq's PHPUnit suite mocks
      `ObjectService`/`TransitionEngine` directly, so a stub-based test cannot exercise OpenRegister's real
      write path; reading the real code is the only way to confirm this). Confirmed:
      `openregister-dev/lib/Service/ObjectService.php:1082-1089` —
      `if ($uuid !== null && $this->currentSchema->isAppendOnly() === true) { throw new AppendOnlyException(...) }`
      — is **unconditional**, runs before any validation/event dispatch, and has no bypass parameter.
      `openregister-dev/lib/Service/Lifecycle/TransitionEngine.php:187-193` calls `saveObject()` with the
      object's existing `uuid` set for every named transition. Since `Credential.appendOnly === true`, this
      means: **every** transition on an existing `Credential` (the pre-existing `revoke`/`expire`, and this
      change's new `offerToWallet`/`recordWalletClaim`) throws `AppendOnlyException` before it can persist.
      This reproduces exactly as `proposal.md`'s "Why" section predicted.
- [x] ADDITIONAL FINDING (beyond what 4.1 was scoped to check, discovered during implementation, not
      fixed — same "flag to a human, don't fix in an S-sized leaf" posture): the append-only guard is not
      the only blocker. (a) `LifecycleValidationListener::handle()` (the code path that actually resolves
      and calls a transition's `requires` guard) explicitly returns early when the lifecycle field's old
      and new values are equal (`if ($oldValue === $newValue) { return; }`,
      `openregister-dev/lib/Listener/LifecycleValidationListener.php:135-138`). `offerToWallet` and
      `recordWalletClaim` are declared as **self-loop** transitions (`from: issued, to: issued`) per this
      change's own spec — meaning, independent of the append-only guard, their `requires` hook would never
      even be invoked via this path, since old===new for every self-loop write. (b) The real, current
      `LifecycleGuardInterface` contract
      (`openregister-dev/lib/Lifecycle/LifecycleGuardInterface.php`) is
      `check(array $object, string $action, string $userId): GuardResult` — read-only, no object mutation,
      returns a `GuardResult` object — which does **not** match the `check(array &$transitionContext): bool`
      contract every existing Lifecycle guard/handler in this app (25+ classes, including
      `CredentialSigningService` and `CoursePublishGuard`, both cited by this change's own proposal as the
      pattern to follow) actually implements. This mismatch pre-dates this change and applies fleet-wide,
      not just to the new wallet guards. Given both (a) and (b) are pre-existing, app-wide OpenRegister-
      integration issues far outside an S-sized scholiq leaf's scope, this change's new guard classes were
      built strictly consistent with the established (if currently non-functional-via-the-real-pipeline)
      scholiq convention — no better, no worse than `revoke`/`expire`/`CoursePublishGuard` today — and the
      finding is documented here rather than "fixed" in isolation, per `proposal.md`'s own precedent for
      the append-only risk.
- [ ] 4.2 File a separate OpenRegister-tracked issue. NOT DONE by this agent — no repo/issue-tracker write
      access in this task's scope. Flagged in the final implementation report instead, per the task's own
      "flag to a human" instruction, covering both 4.1's confirmed append-only block and the additional (a)/(b)
      findings above.
- [x] 4.3 N/A — 4.1 confirmed the guard reproduces (does not need this branch).

## 5. Verify + docs

- [x] 5.1 Ran `phpcs --standard=phpcs.xml`, `phpstan analyse`, and `php -l` on all new/touched PHP files.
      0 errors, 0 warnings after fixes (alignment issues auto-fixed via `phpcbf`; one non-named-parameter
      call fixed by hand). Full `composer check:strict` was not run (needs a full NC install per
      apply-common.md guidance) — `phpunit-unit.xml` (314/314 green), `phpcs`, and `phpstan` were used as
      the standalone-capable substitute.
- [x] 5.2 Added `@spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-...`
      docblock tags to each new method (the three hook services' `check()`, the listener's `handle()`).
      Class-level `@spec .../tasks.md#task-N` tags were tried and then reverted: they don't resolve against
      hydra gate-46's checker (pre-existing, repo-wide limitation — the same pattern already fails for
      `LtiToolPlacementController`/`AssessmentScoringHandler`/etc., 335 pre-existing hits) and only silenced
      a non-blocking phpcs warning that `CredentialSigningService` itself also carries unfixed.
- [x] 5.3 Confirmed no `src/manifest.json` change is needed. Not touched.
- [ ] 5.4 File a follow-up issue for the openconnector-side companion change. SUPERSEDED BY REALITY: the
      companion change (`eudi-wallet-credential-issuance`) is **already merged** in `openconnector-dev`
      with a working REST surface — no follow-up needed for offer-creation/revocation. The REAL remaining
      gaps to flag instead: (a) `EudiWalletController::authenticateConsumer()` requires a consumer JWT
      (`authorization-jwt` REQ-001), not a static bearer token — scholiq has no JWT-minting or
      consumer-registration flow; (b) no claim-notification mechanism exists on the openconnector side (see
      task 3.4's note) — both are openconnector-side/cross-app-provisioning gaps outside this scholiq
      leaf's scope. Flagged in the final implementation report; not filed as a tracked issue by this agent
      (no issue-tracker write access in scope).
- [x] 5.5 Ran `openspec validate eudi-wallet-credential-push --strict` — passes ("Change
      'eudi-wallet-credential-push' is valid").
