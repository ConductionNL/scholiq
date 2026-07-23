<?php

/**
 * Scholiq Wallet Offer Concluded Listener
 *
 * Consumes an inbound `\OCA\OpenConnector\Event\WalletOfferConcludedEvent`
 * reporting that an EUDI wallet holder claimed an outstanding credential
 * offer, and drives the matching Credential's `recordWalletClaim`
 * transition. `class_exists`-guarded and registered only when openconnector
 * exposes that event class, mirroring the ADR-041 consumer-listener shape
 * (`procest\Listener\DecisionConcludedListener`).
 *
 * TRIGGER GAP (flag to a human) — this listener is currently DEAD CODE end
 * to end. openconnector's merged `eudi-wallet-credential-issuance` adapter
 * (verified against `openconnector-dev` HEAD) defines no
 * `OCA\OpenConnector\Event\WalletOfferConcludedEvent` class, dispatches no
 * such event, and exposes no webhook/callback/poll surface for "the wallet
 * holder claimed the offer" — `EudiCredentialOfferService::fireStatusCallback()`
 * is invoked ONLY from `revoke()`, never from `exchangeToken()` or
 * `issueCredential()` (the points where a claim actually happens). The
 * `class_exists` guard below will therefore always evaluate false against
 * the real, merged openconnector code as of this writing, and
 * {@see \OCA\Scholiq\AppInfo\Application}'s registration of this listener is
 * consequently a no-op. This class is kept per tasks.md (the guard/service
 * side, {@see \OCA\Scholiq\Service\WalletClaimSyncService}, has real,
 * independently-correct logic) so wiring is ready the moment openconnector
 * ships a real claim-notification mechanism — that mechanism is a companion
 * openconnector-side gap this change cannot close (scholiq stays
 * wallet-wire-protocol-free).
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives `recordWalletClaim` from an inbound openconnector claim report.
 *
 * @implements IEventListener<Event>
 */
class WalletOfferConcludedListener implements IEventListener
{
    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const CREDENTIAL_SCHEMA = 'credential';
    private const CLAIM_ACTION      = 'recordWalletClaim';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object service used to resolve the matching Credential.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to apply `recordWalletClaim`.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an inbound `WalletOfferConcludedEvent`.
     *
     * Defensive duck-typing: the event class is openconnector's and is
     * optional at runtime (see class docblock — it does not exist today),
     * so calls on `$event` are guarded by `method_exists()` rather than a
     * hard type import.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
     */
    public function handle(Event $event): void
    {
        if (method_exists($event, 'getExternalReference') === false
            || method_exists($event, 'getStatus') === false
        ) {
            return;
        }

        try {
            $status = strtolower((string) $event->getStatus());
            if ($status !== 'claimed') {
                return;
            }

            $externalReference = (string) $event->getExternalReference();
            if ($externalReference === '') {
                return;
            }

            $credential = $this->resolveCredentialByAttestationRef(attestationRef: $externalReference);
            if ($credential === null) {
                $this->logger->warning(
                    '[WalletOfferConcludedListener] No Credential found for wallet attestation ref {ref}',
                    ['ref' => $externalReference]
                );
                return;
            }

            $credentialId = (string) ($credential['id'] ?? ($credential['uuid'] ?? ''));
            if ($credentialId === '') {
                return;
            }

            $this->transitionEngine->transition(objectId: $credentialId, action: self::CLAIM_ACTION);

            $this->logger->info(
                '[WalletOfferConcludedListener] Recorded wallet claim for Credential {id}',
                ['id' => $credentialId]
            );
        } catch (Throwable $exception) {
            // Never block event delivery on our own derivation failure.
            $this->logger->warning(
                '[WalletOfferConcludedListener] Could not record wallet claim: {msg}',
                ['msg' => $exception->getMessage()]
            );
        }//end try
    }//end handle()

    /**
     * Resolve the Credential whose `walletAttestationRef` matches the given
     * reference.
     *
     * @param string $attestationRef The opaque wallet-offer correlation key.
     *
     * @return array<string,mixed>|null The Credential data array, or null when not found.
     */
    private function resolveCredentialByAttestationRef(string $attestationRef): ?array
    {
        $matches = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::CREDENTIAL_SCHEMA,
                'filters'  => ['walletAttestationRef' => $attestationRef],
                'limit'    => 1,
            ]
        );

        $result = ($matches[0] ?? null);
        if ($result === null) {
            return null;
        }

        if (is_array($result) === true) {
            return $result;
        }

        return $result->jsonSerialize();
    }//end resolveCredentialByAttestationRef()
}//end class
