<?php

/**
 * Scholiq SupportRequestSubmitHandler unit tests.
 *
 * Covers the `zorgvraag-swv-tlv-chain` change: submitting a SupportRequest
 * auto-queues a DataExchangeJob (target: swv, scope.schema: support-request),
 * stamps the job id back onto the SupportRequest, and advances the job into
 * pending-parent-review — the same gate the existing OSO overstapdossier flow
 * uses (learning-plan spec "SWV routing reuses DataExchangeJob and the
 * existing pending-parent-review gate").
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
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-6.1
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-swv-routing-reuses-dataexchangejob-and-the-existing-pending-parent-review-gate
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\SupportRequestSubmitHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SupportRequestSubmitHandler::handle() on SupportRequest → submitted.
 */
class SupportRequestSubmitHandlerTest extends TestCase
{

    /**
     * Recorded saveObject() calls.
     *
     * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Recorded transition() calls.
     *
     * @var array<int, array{objectId: string, action: string}>
     */
    private array $transitions = [];

    /**
     * Reset capture buffers before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedObjects = [];
        $this->transitions  = [];

    }//end setUp()

    /**
     * Build a handler with stubbed collaborators.
     *
     * @param string|null              $savedJobId             UUID to return for the DataExchangeJob save, or null
     *                                                         to simulate a save failure.
     * @param array<string,mixed>|null $mappingProfile         DataMappingProfile row to resolve for target=swv, or
     *                                                         null when none is configured.
     * @param array<string,mixed>|null $existingSupportRequest The SupportRequest row findAll() returns when the
     *                                                         handler looks it up to stamp dataExchangeJobId back on.
     *
     * @return SupportRequestSubmitHandler
     */
    private function makeHandler(
        ?string $savedJobId,
        ?array $mappingProfile=null,
        ?array $existingSupportRequest=null,
    ): SupportRequestSubmitHandler {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($mappingProfile, $existingSupportRequest): array {
                $schema = $config['schema'] ?? '';

                if ($schema === 'data-mapping-profile') {
                    return $mappingProfile === null ? [] : [$mappingProfile];
                }

                if ($schema === 'support-request') {
                    return $existingSupportRequest === null ? [] : [$existingSupportRequest];
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) use ($savedJobId) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

                if ($schema === 'data-exchange-job') {
                    if ($savedJobId === null) {
                        return null;
                    }

                    return array_merge($object, ['id' => $savedJobId]);
                }

                return $object;
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) {
                $this->transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new SupportRequestSubmitHandler($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a SupportRequest → submitted transition.
     *
     * @param array<string,mixed> $requestData The SupportRequest's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $requestData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($requestData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('support-request');
        $event->method('getTo')->willReturn('submitted');
        $event->method('getFrom')->willReturn('draft');

        return $event;

    }//end makeEvent()

    /**
     * Submitting a SupportRequest queues a DataExchangeJob (target: swv,
     * scope.schema: support-request), stamps dataExchangeJobId back onto the
     * request, and advances the job into pending-parent-review.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#scenario-submitting-a-supportrequest-queues-a-gated-swv-dossier-job
     */
    public function testSubmittedRequestQueuesSwvJobAndAdvancesToPendingParentReview(): void
    {
        $handler = $this->makeHandler(
            savedJobId: 'job-1',
            mappingProfile: ['id' => 'profile-1', 'target' => 'swv'],
            existingSupportRequest: ['id' => 'sr-1', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-a']
        );

        $supportRequest = [
            'id'        => 'sr-1',
            'learnerId' => 'learner-1',
            'raisedBy'  => 'coordinator-1',
            'tenant_id' => 'tenant-a',
            'lifecycle' => 'submitted',
        ];

        $handler->handle($this->makeEvent($supportRequest));

        $jobSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'data-exchange-job'));
        self::assertCount(1, $jobSaves);
        self::assertSame('swv', $jobSaves[0]['object']['target']);
        self::assertSame('support-request', $jobSaves[0]['object']['scope']['schema']);
        self::assertSame('learner-1', $jobSaves[0]['object']['scope']['filters']['learnerId']);
        self::assertSame('sr-1', $jobSaves[0]['object']['scope']['filters']['supportRequestId']);
        self::assertSame('profile-1', $jobSaves[0]['object']['mappingProfileId']);
        self::assertSame('coordinator-1', $jobSaves[0]['object']['requestedBy']);
        self::assertSame('queued', $jobSaves[0]['object']['lifecycle']);

        $requestSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'support-request'));
        self::assertCount(1, $requestSaves);
        self::assertSame('job-1', $requestSaves[0]['object']['dataExchangeJobId']);

        self::assertCount(1, $this->transitions);
        self::assertSame('job-1', $this->transitions[0]['objectId']);
        self::assertSame('pendingParentReview', $this->transitions[0]['action']);

    }//end testSubmittedRequestQueuesSwvJobAndAdvancesToPendingParentReview()

    /**
     * No active swv DataMappingProfile is configured — the job is still queued
     * (mappingProfileId null), fail-closed enforcement happens later at
     * buildPayload() time via MANDATORY_PROFILE_TARGETS, not here.
     *
     * @return void
     */
    public function testQueuesJobWithNullMappingProfileIdWhenNoneConfigured(): void
    {
        $handler = $this->makeHandler(
            savedJobId: 'job-2',
            mappingProfile: null,
            existingSupportRequest: ['id' => 'sr-2', 'learnerId' => 'learner-2', 'tenant_id' => 'tenant-a']
        );

        $supportRequest = [
            'id'        => 'sr-2',
            'learnerId' => 'learner-2',
            'raisedBy'  => 'coordinator-2',
            'tenant_id' => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($supportRequest));

        $jobSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'data-exchange-job'));
        self::assertCount(1, $jobSaves);
        self::assertNull($jobSaves[0]['object']['mappingProfileId']);

    }//end testQueuesJobWithNullMappingProfileIdWhenNoneConfigured()

    /**
     * A SupportRequest with no learnerId is skipped — no DataExchangeJob queued.
     *
     * @return void
     */
    public function testMissingLearnerIdSkips(): void
    {
        $handler = $this->makeHandler(savedJobId: 'job-3');

        $supportRequest = ['id' => 'sr-3', 'raisedBy' => 'coordinator-1', 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($supportRequest));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testMissingLearnerIdSkips()

    /**
     * A non-SupportRequest event (wrong schema) is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler(savedJobId: 'job-4');

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('learning-plan');
        $event->method('getTo')->willReturn('submitted');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testWrongSchemaIgnored()

    /**
     * A transition to a state other than `submitted` is ignored.
     *
     * @return void
     */
    public function testWrongTargetStateIgnored(): void
    {
        $handler = $this->makeHandler(savedJobId: 'job-5');

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('support-request');
        $event->method('getTo')->willReturn('closed');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testWrongTargetStateIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler(savedJobId: 'job-6');

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testNonMatchingEventTypeIgnored()
}//end class
