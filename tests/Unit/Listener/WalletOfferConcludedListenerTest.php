<?php

/**
 * Unit tests for WalletOfferConcludedListener.
 *
 * Covers the claim-sync-back projection contract per
 * `specs/certification/spec.md`: resolves the Credential whose
 * `walletAttestationRef` matches the event's `externalReference` and drives
 * `recordWalletClaim`; ignores non-claimed statuses; ignores events it
 * cannot resolve a Credential for; never throws out to the dispatcher.
 *
 * These tests exercise the listener's own resolution/dispatch logic using a
 * duck-typed stand-in event (mirroring `procest\Listener\
 * DecisionConcludedListenerTest`'s approach for an optional cross-app event
 * class) — see the class docblock for why openconnector never actually
 * dispatches a real `WalletOfferConcludedEvent` today.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
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

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\WalletOfferConcludedListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal stand-in for openconnector's (non-existent, see class docblock)
 * `WalletOfferConcludedEvent`, shaped only with the two getters the
 * listener duck-types against.
 */
class FakeWalletOfferConcludedEvent extends Event
{
    /**
     * @param string $externalReference The Credential UUID to correlate against.
     * @param string $status            The concluded status (e.g. 'claimed').
     */
    public function __construct(
        private readonly string $externalReference,
        private readonly string $status,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * @return string
     */
    public function getExternalReference(): string
    {
        return $this->externalReference;
    }//end getExternalReference()

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }//end getStatus()
}//end class

/**
 * Tests for WalletOfferConcludedListener::handle().
 */
class WalletOfferConcludedListenerTest extends TestCase
{

    /**
     * A claimed event resolves the matching Credential by
     * `walletAttestationRef` and drives `recordWalletClaim`.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-a-claimed-wallet-offer-updates-the-credentials-wallet-offer-status
     */
    public function testClaimedEventTriggersRecordWalletClaim(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                if (($config['filters']['walletAttestationRef'] ?? null) === 'offer-uuid-1') {
                    return [['id' => 'credential-1', 'walletAttestationRef' => 'offer-uuid-1']];
                }

                return [];
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->once())
            ->method('transition')
            ->with('credential-1', 'recordWalletClaim');

        $listener = new WalletOfferConcludedListener($objectService, $transitionEngine, new NullLogger());
        $listener->handle(new FakeWalletOfferConcludedEvent('offer-uuid-1', 'claimed'));
    }//end testClaimedEventTriggersRecordWalletClaim()

    /**
     * A non-claimed status (e.g. an intermediate/unknown status) is ignored
     * — no transition is triggered.
     *
     * @return void
     */
    public function testNonClaimedStatusIsIgnored(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $listener = new WalletOfferConcludedListener($objectService, $transitionEngine, new NullLogger());
        $listener->handle(new FakeWalletOfferConcludedEvent('offer-uuid-1', 'pending'));
    }//end testNonClaimedStatusIsIgnored()

    /**
     * No matching Credential: the listener logs and returns without
     * throwing.
     *
     * @return void
     */
    public function testNoMatchingCredentialDoesNotThrow(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $listener = new WalletOfferConcludedListener($objectService, $transitionEngine, new NullLogger());
        $listener->handle(new FakeWalletOfferConcludedEvent('unknown-ref', 'claimed'));

        // Reaching this line without an uncaught exception is the assertion.
        self::assertTrue(true);
    }//end testNoMatchingCredentialDoesNotThrow()

    /**
     * A base Event without the expected getters is ignored (defensive
     * duck-typing) rather than fataling.
     *
     * @return void
     */
    public function testUnrelatedEventIsIgnored(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->expects($this->never())->method('transition');

        $listener = new WalletOfferConcludedListener($objectService, $transitionEngine, new NullLogger());
        $listener->handle(new Event());

        self::assertTrue(true);
    }//end testUnrelatedEventIsIgnored()

    /**
     * A transitionEngine failure is caught and swallowed — the listener
     * never lets its own derivation failure escape to the dispatcher.
     *
     * @return void
     */
    public function testTransitionEngineFailureIsCaught(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['id' => 'credential-2', 'walletAttestationRef' => 'offer-uuid-2']]);

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willThrowException(new \RuntimeException('boom'));

        $listener = new WalletOfferConcludedListener($objectService, $transitionEngine, new NullLogger());
        $listener->handle(new FakeWalletOfferConcludedEvent('offer-uuid-2', 'claimed'));

        self::assertTrue(true);
    }//end testTransitionEngineFailureIsCaught()
}//end class
