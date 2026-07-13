<?php

/**
 * Scholiq Wallet Claim Sync Service
 *
 * Lifecycle guard for the Credential schema's `recordWalletClaim` transition.
 * System-triggered only (never a user-facing action) — invoked by
 * {@see \OCA\Scholiq\Listener\WalletOfferConcludedListener} when openconnector
 * reports the wallet holder claimed an outstanding offer. Writes the claim
 * timestamp onto the Credential.
 *
 * TRIGGER GAP (flag to a human): as documented on
 * `WalletOfferConcludedListener`, openconnector's merged
 * `eudi-wallet-credential-issuance` adapter has no mechanism that reports a
 * claim back to the offering app — no event, no webhook, no poll endpoint.
 * This guard's own logic (write `walletOfferStatus=claimed` +
 * `walletClaimedAt`) is correct and tested, but nothing in the real system
 * invokes the `recordWalletClaim` transition today.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.recordWalletClaim.requires in
 * scholiq_register.json. Built to the `check(array &$transitionContext): bool`
 * contract `CredentialSigningService` establishes.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

/**
 * Guards the Credential `recordWalletClaim` transition.
 *
 * Always allows the transition; its purpose is the side effect of writing
 * `walletOfferStatus=claimed` and `walletClaimedAt` into the transition
 * context, mirroring `AssessmentScoringHandler`'s "always-true handler that
 * mutates the object" shape.
 */
class WalletClaimSyncService
{
    /**
     * OR lifecycle guard entry-point.
     *
     * Called before executing the `recordWalletClaim` transition on a
     * Credential object. Writes `walletOfferStatus=claimed` and
     * `walletClaimedAt=now` into the context.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Credential data array (mutated)
     *                                               - 'transition' : 'recordWalletClaim'
     *                                               - 'from'       : 'issued'
     *                                               - 'to'         : 'issued'
     *
     * @return bool Always true — this transition has no failure mode.
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
     */
    public function check(array &$transitionContext): bool
    {
        $object = &$transitionContext['object'];

        $object['walletOfferStatus'] = 'claimed';
        $object['walletClaimedAt']   = gmdate('c');

        return true;
    }//end check()
}//end class
