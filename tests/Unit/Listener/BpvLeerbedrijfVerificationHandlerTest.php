<?php

/**
 * Scholiq BpvLeerbedrijfVerificationHandler unit tests.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Bpv\ProvidesLeerbedrijfVerification;
use OCA\Scholiq\Listener\BpvLeerbedrijfVerificationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BpvLeerbedrijfVerificationHandler::handle() on
 * BpvPlacement → sbb-verification-pending.
 */
class BpvLeerbedrijfVerificationHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls, captured by the ObjectService stub used per test.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Reset the capture buffer before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];

    }//end setUp()

    /**
     * Build a handler with a stubbed ObjectService and the given container.
     *
     * @param ContainerInterface $container DI container stub.
     *
     * @return BpvLeerbedrijfVerificationHandler
     */
    private function makeHandler(ContainerInterface $container): BpvLeerbedrijfVerificationHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new BpvLeerbedrijfVerificationHandler($objectService, $container, $this->createMock(LoggerInterface::class));

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a BpvPlacement → sbb-verification-pending
     * transition.
     *
     * @param array<string, mixed> $placementData The BpvPlacement's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $placementData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($placementData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('bpv-placement');
        $event->method('getTo')->willReturn('sbb-verification-pending');
        $event->method('getFrom')->willReturn('proposed');

        return $event;

    }//end makeEvent()

    /**
     * A configured, resolvable, `verified`-returning provider writes the full result back onto
     * the BpvPlacement.
     *
     * @return void
     */
    public function testConfiguredProviderWritesVerifiedResultBack(): void
    {
        $fakeProvider = new class implements ProvidesLeerbedrijfVerification {
            public function verify(string $kvkOrErkenningNumber): array
            {
                return [
                    'status'          => 'verified',
                    'erkenningNumber' => 'SBB-999',
                    'expiresAt'       => '2027-01-01T00:00:00+00:00',
                    'raw'             => ['source' => 'fake'],
                ];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('FakeSbbAdapter')->willReturn($fakeProvider);

        $handler   = $this->makeHandler($container);
        $placement = [
            'id'                      => 'placement-1',
            'leerbedrijfKvkNumber'    => '12345678',
            'leerbedrijfVerification' => ['provider' => 'FakeSbbAdapter', 'status' => 'unverified'],
        ];

        $handler->handle($this->makeEvent($placement));

        $this->assertCount(1, $this->savedObjects);
        $saved = $this->savedObjects[0]['object']['leerbedrijfVerification'];
        $this->assertSame('verified', $saved['status']);
        $this->assertSame('SBB-999', $saved['erkenningNumber']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $saved['expiresAt']);
        $this->assertSame(['source' => 'fake'], $saved['raw']);
        $this->assertNotEmpty($saved['verifiedAt']);

    }//end testConfiguredProviderWritesVerifiedResultBack()

    /**
     * An unconfigured provider (empty/null `provider` config) is a no-op — the placement stays
     * in sbb-verification-pending, no exception thrown, nothing saved.
     *
     * @return void
     */
    public function testUnconfiguredProviderIsNoOp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        $handler   = $this->makeHandler($container);
        $placement = [
            'id'                      => 'placement-1',
            'leerbedrijfKvkNumber'    => '12345678',
            'leerbedrijfVerification' => ['provider' => null, 'status' => 'unverified'],
        ];

        $handler->handle($this->makeEvent($placement));

        $this->assertCount(0, $this->savedObjects);

    }//end testUnconfiguredProviderIsNoOp()

    /**
     * A configured provider FQCN the container cannot resolve is also a no-op — the handler
     * never throws.
     *
     * @return void
     */
    public function testUnresolvableProviderIsNoOp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(
            $this->createMock(NotFoundExceptionInterface::class)
        );

        $handler   = $this->makeHandler($container);
        $placement = [
            'id'                      => 'placement-1',
            'leerbedrijfKvkNumber'    => '12345678',
            'leerbedrijfVerification' => ['provider' => 'MissingClass', 'status' => 'unverified'],
        ];

        $handler->handle($this->makeEvent($placement));

        $this->assertCount(0, $this->savedObjects);

    }//end testUnresolvableProviderIsNoOp()

    /**
     * A resolved service that does NOT implement ProvidesLeerbedrijfVerification is treated as
     * unconfigured (no-op) — never called, never crashes.
     *
     * @return void
     */
    public function testResolvedServiceNotImplementingInterfaceIsNoOp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(new \stdClass());

        $handler   = $this->makeHandler($container);
        $placement = [
            'id'                      => 'placement-1',
            'leerbedrijfKvkNumber'    => '12345678',
            'leerbedrijfVerification' => ['provider' => 'NotAnAdapter', 'status' => 'unverified'],
        ];

        $handler->handle($this->makeEvent($placement));

        $this->assertCount(0, $this->savedObjects);

    }//end testResolvedServiceNotImplementingInterfaceIsNoOp()

    /**
     * A `rejected`/`pending` result is written back without advancing the lifecycle (the
     * handler only ever writes the leerbedrijfVerification field — it never calls the
     * transition engine).
     *
     * @return void
     */
    public function testRejectedResultIsWrittenBackWithoutTransition(): void
    {
        $fakeProvider = new class implements ProvidesLeerbedrijfVerification {
            public function verify(string $kvkOrErkenningNumber): array
            {
                return ['status' => 'rejected', 'erkenningNumber' => null, 'expiresAt' => null, 'raw' => []];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($fakeProvider);

        $handler   = $this->makeHandler($container);
        $placement = [
            'id'                      => 'placement-1',
            'leerbedrijfKvkNumber'    => '12345678',
            'leerbedrijfVerification' => ['provider' => 'FakeSbbAdapter', 'status' => 'unverified'],
        ];

        $handler->handle($this->makeEvent($placement));

        $this->assertCount(1, $this->savedObjects);
        $this->assertSame('rejected', $this->savedObjects[0]['object']['leerbedrijfVerification']['status']);
        // No lifecycle field write attempted — the saved object carries no 'lifecycle' change
        // beyond what was already on the placement (none was present here).
        $this->assertArrayNotHasKey('lifecycle', $this->savedObjects[0]['object']);

    }//end testRejectedResultIsWrittenBackWithoutTransition()

    /**
     * Events for other schemas/states are ignored entirely.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvents(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');
        $handler = $this->makeHandler($container);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $event        = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('praktijkovereenkomst');
        $event->method('getTo')->willReturn('sbb-verification-pending');

        $handler->handle($event);

        $this->assertCount(0, $this->savedObjects);

    }//end testIgnoresUnrelatedEvents()
}//end class
