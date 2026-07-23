<?php

/**
 * Scholiq BPV Leerbedrijf Verification Handler
 *
 * IEventListener for BpvPlacement lifecycle → `sbb-verification-pending`
 * (the OR ObjectTransitionedEvent with register=scholiq, schema=bpv-placement,
 * to=sbb-verification-pending — the coordinator-triggered `checkLeerbedrijf`
 * self-transition action).
 *
 * Algorithm:
 * 1. Resolve the configured ProvidesLeerbedrijfVerification adapter from
 *    BpvPlacement.leerbedrijfVerification.provider via the DI container.
 *    No provider configured (null/empty, class missing, or the resolved
 *    service does not implement ProvidesLeerbedrijfVerification) → no-op.
 *    The placement simply stays in `sbb-verification-pending`; no exception
 *    is thrown (Scholiq works standalone without an SBB adapter — the SBB
 *    OpenConnector adapter itself is explicit cross-repo follow-up work).
 * 2. Call verify() with the placement's leerbedrijfKvkNumber.
 * 3. Write the result (`status`, `erkenningNumber`, `verifiedAt`, `expiresAt`,
 *    `raw`) back onto BpvPlacement.leerbedrijfVerification via
 *    ObjectService::saveObject. This is a field write only — it does NOT
 *    drive a lifecycle transition itself (BpvConfirmationGuard reads this
 *    stored result later, when a coordinator attempts `confirm`).
 *
 * This is the ADR-031 "external-system bridge" exception: single
 * responsibility — orchestrate the provider call and persist its result. No
 * SBB wire protocol lives here (that is the out-of-repo openconnector
 * adapter's job).
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Bpv\ProvidesLeerbedrijfVerification;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles BpvPlacement lifecycle → sbb-verification-pending.
 *
 * Resolves the configured leerbedrijf-verification provider (if any), calls
 * it, and writes the result back onto the placement. Implements no SBB wire
 * protocol itself.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */
class BpvLeerbedrijfVerificationHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const PLACEMENT_SCHEMA = 'bpv-placement';
    private const TARGET_STATE     = 'sbb-verification-pending';

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService OR object access service.
     * @param ContainerInterface $container     DI container used to resolve the configured
     *                                          ProvidesLeerbedrijfVerification adapter by FQCN.
     * @param LoggerInterface    $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::PLACEMENT_SCHEMA) {
            return;
        }

        if ($event->getTo() !== self::TARGET_STATE) {
            return;
        }

        $this->runVerification(event: $event);

    }//end handle()

    /**
     * Resolve the configured provider (if any), verify, and persist the result.
     *
     * @param ObjectTransitionedEvent $event The sbb-verification-pending transition event.
     *
     * @return void
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
     */
    private function runVerification(ObjectTransitionedEvent $event): void
    {
        $placement   = $event->getObject()->jsonSerialize();
        $placementId = $placement['id'] ?? ($placement['uuid'] ?? '');

        if ($placementId === '') {
            $this->logger->error('[BpvLeerbedrijfVerificationHandler] BpvPlacement has no id — cannot verify.');
            return;
        }

        $verification = $placement['leerbedrijfVerification'] ?? [];
        if (is_array($verification) === false) {
            $verification = [];
        }

        $providerClass = $verification['provider'] ?? null;
        $kvkNumber     = $placement['leerbedrijfKvkNumber'] ?? '';

        $provider = $this->resolveProvider(providerClass: $providerClass);

        if ($provider === null) {
            $this->logger->info(
                '[BpvLeerbedrijfVerificationHandler] No leerbedrijfVerification.provider configured for '
                .'BpvPlacement {id} — leaving verification unresolved; the placement cannot confirm until '
                .'a provider is configured. Scholiq ships no bundled SBB adapter by design.',
                ['id' => $placementId]
            );
            return;
        }

        if ($kvkNumber === '') {
            $this->logger->warning(
                '[BpvLeerbedrijfVerificationHandler] BpvPlacement {id} has no leerbedrijfKvkNumber — cannot verify.',
                ['id' => $placementId]
            );
            return;
        }

        try {
            $result = $provider->verify($kvkNumber);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[BpvLeerbedrijfVerificationHandler] Provider call failed for BpvPlacement {id}: {msg}',
                ['id' => $placementId, 'msg' => $e->getMessage()]
            );
            return;
        }

        $updatedVerification = array_merge(
            $verification,
            [
                'status'          => $result['status'],
                'erkenningNumber' => $result['erkenningNumber'],
                'verifiedAt'      => date('c'),
                'expiresAt'       => $result['expiresAt'],
                'raw'             => $result['raw'],
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::PLACEMENT_SCHEMA,
            object: array_merge($placement, ['leerbedrijfVerification' => $updatedVerification])
        );

        $this->logger->info(
            '[BpvLeerbedrijfVerificationHandler] BpvPlacement {id} leerbedrijfVerification.status → {status}.',
            ['id' => $placementId, 'status' => $updatedVerification['status']]
        );

    }//end runVerification()

    /**
     * Resolve the configured provider FQCN through the DI container.
     *
     * Fails closed (returns null, never throws) when no provider is configured, the class
     * does not exist, the container cannot build it, or the resolved service does not
     * implement ProvidesLeerbedrijfVerification.
     *
     * @param mixed $providerClass The `leerbedrijfVerification.provider` config value.
     *
     * @return ProvidesLeerbedrijfVerification|null The resolved adapter, or null.
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
     */
    private function resolveProvider(mixed $providerClass): ?ProvidesLeerbedrijfVerification
    {
        if (is_string($providerClass) === false || $providerClass === '') {
            return null;
        }

        try {
            $service = $this->container->get($providerClass);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" could not be resolved: {msg}',
                ['class' => $providerClass, 'msg' => $e->getMessage()]
            );
            return null;
        }

        if (($service instanceof ProvidesLeerbedrijfVerification) === false) {
            $this->logger->warning(
                '[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" does not implement '
                .'ProvidesLeerbedrijfVerification.',
                ['class' => $providerClass]
            );
            return null;
        }

        return $service;

    }//end resolveProvider()
}//end class
