<?php

/**
 * Scholiq RejectionMappingHandler unit tests.
 *
 * Covers the `duo-afkeurmelding-correction` change: mapping a finished
 * DataExchangeJob's result.validationReport onto ExchangeRejection rows
 * (first-pass creation, idempotency, sourceKind resolution, errorCodeRef
 * best-effort matching) and the resubmission-outcome accept/reopen paths.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\RejectionMappingHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for RejectionMappingHandler::handle() on DataExchangeJob →
 * succeeded/partial/failed.
 */
class RejectionMappingHandlerTest extends TestCase
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
     * @param array<int,array<string,mixed>> $existingRejections  ExchangeRejection rows findAll() returns for
     *                                                            schema=exchange-rejection queries filtered by
     *                                                            dataExchangeJobId.
     * @param array<int,array<string,mixed>> $errorCodes          ExchangeErrorCode rows findAll() returns for
     *                                                            schema=exchange-error-code queries.
     * @param array<int,array<string,mixed>> $resubmissionRejections ExchangeRejection rows findAll() returns for
     *                                                            queries filtered by resubmittedJobId.
     *
     * @return RejectionMappingHandler
     */
    private function makeHandler(
        array $existingRejections=[],
        array $errorCodes=[],
        array $resubmissionRejections=[],
    ): RejectionMappingHandler {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($existingRejections, $errorCodes, $resubmissionRejections): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'exchange-rejection') {
                    if (isset($filters['resubmittedJobId']) === true) {
                        return $resubmissionRejections;
                    }

                    if (isset($filters['id']) === true) {
                        // saveRejectionFields() lookup — find in either capture set.
                        foreach (array_merge($existingRejections, $resubmissionRejections) as $row) {
                            if (($row['id'] ?? null) === $filters['id']) {
                                return [$row];
                            }
                        }
                        return [];
                    }

                    return $existingRejections;
                }

                if ($schema === 'exchange-error-code') {
                    return $errorCodes;
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return array_merge(['id' => 'saved-'.count($this->savedObjects)], $object);
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) {
                $this->transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new RejectionMappingHandler($objectService, $transitionEngine, new NullLogger());

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a DataExchangeJob → $to transition.
     *
     * @param array<string,mixed> $jobData The DataExchangeJob's jsonSerialize() payload.
     * @param string              $to      Target lifecycle state.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $jobData, string $to='succeeded'): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($jobData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('data-exchange-job');
        $event->method('getTo')->willReturn($to);

        return $event;

    }//end makeEvent()

    /**
     * A validationReport entry with a recordId matching an exported
     * LearnerProfile creates an ExchangeRejection with sourceKind:
     * learner-profile, learnerProfileId set, status open, errorCode/errorMessage
     * copied verbatim.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-rejected-record-is-persisted-with-its-source-object-reference
     */
    public function testCreatesRejectionWithSourceKind(): void
    {
        $handler = $this->makeHandler();

        $job = [
            'id'        => 'job-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'lp-1', 'errorCode' => 'BRON-101', 'errorMessage' => 'Ongeldig BSN-formaat.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(1, $rejectionSaves);

        $rejection = $rejectionSaves[0]['object'];
        self::assertSame('job-1', $rejection['dataExchangeJobId']);
        self::assertSame('learner-profile', $rejection['sourceKind']);
        self::assertSame('lp-1', $rejection['learnerProfileId']);
        self::assertSame('BRON-101', $rejection['errorCode']);
        self::assertSame('Ongeldig BSN-formaat.', $rejection['errorMessage']);
        self::assertSame('open', $rejection['status']);
        self::assertSame('tenant-a', $rejection['tenant_id']);

    }//end testCreatesRejectionWithSourceKind()

    /**
     * A redelivered event for a job already fully mapped creates no
     * duplicate ExchangeRejection rows for a (dataExchangeJobId, recordId)
     * pair already mapped.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-idempotent-mapping-on-repeated-handler-invocation
     */
    public function testDoesNotDuplicateOnRedelivery(): void
    {
        $handler = $this->makeHandler(
            existingRejections: [
                ['id' => 'rej-1', 'dataExchangeJobId' => 'job-1', 'sourceKind' => 'learner-profile', 'learnerProfileId' => 'lp-1'],
            ]
        );

        $job = [
            'id'        => 'job-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'lp-1', 'errorCode' => 'BRON-101', 'errorMessage' => 'Ongeldig BSN-formaat.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(0, $rejectionSaves);

    }//end testDoesNotDuplicateOnRedelivery()

    /**
     * Two distinct recordIds in the same validationReport each create their
     * own row — only an exact repeat is deduplicated.
     *
     * @return void
     */
    public function testDistinctRecordIdsBothCreateRows(): void
    {
        $handler = $this->makeHandler();

        $job = [
            'id'        => 'job-2',
            'target'    => 'leerplicht',
            'scope'     => ['schema' => 'attendance-flag'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'flag-1', 'errorCode' => 'LP-401', 'errorMessage' => 'Ontbrekend BRIN-nummer.'],
                    ['recordId' => 'flag-2', 'errorCode' => 'LP-401', 'errorMessage' => 'Ontbrekend BRIN-nummer.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(2, $rejectionSaves);
        self::assertSame('attendance-flag', $rejectionSaves[0]['object']['sourceKind']);
        self::assertSame('flag-1', $rejectionSaves[0]['object']['attendanceFlagId']);
        self::assertSame('flag-2', $rejectionSaves[1]['object']['attendanceFlagId']);

    }//end testDistinctRecordIdsBothCreateRows()

    /**
     * A resubmission job whose recordId no longer appears in the new
     * validationReport transitions the referencing ExchangeRejection to
     * accepted.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-that-duo-now-accepts-closes-its-rejection
     */
    public function testResubmittedRecordAcceptedClosesRejection(): void
    {
        $handler = $this->makeHandler(
            resubmissionRejections: [
                [
                    'id'                => 'rej-1',
                    'sourceKind'        => 'learner-profile',
                    'learnerProfileId'  => 'lp-1',
                    'resubmittedJobId'  => 'job-resubmit-1',
                ],
            ]
        );

        $job = [
            'id'        => 'job-resubmit-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'succeeded'));

        self::assertCount(1, $this->transitions);
        self::assertSame('rej-1', $this->transitions[0]['objectId']);
        self::assertSame('accept', $this->transitions[0]['action']);

        // No new ExchangeRejection rows created on the resubmission-outcome path.
        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(0, $rejectionSaves);

    }//end testResubmittedRecordAcceptedClosesRejection()

    /**
     * A resubmission job whose recordId still appears in the new
     * validationReport reopens the referencing ExchangeRejection with
     * refreshed errorCode/errorMessage.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-resubmitted-record-duo-rejects-again-reopens-its-rejection
     */
    public function testResubmittedRecordStillRejectedReopens(): void
    {
        $handler = $this->makeHandler(
            resubmissionRejections: [
                [
                    'id'               => 'rej-1',
                    'sourceKind'       => 'learner-profile',
                    'learnerProfileId' => 'lp-1',
                    'resubmittedJobId' => 'job-resubmit-1',
                    'errorCode'        => 'BRON-101',
                    'errorMessage'     => 'Ongeldig BSN-formaat.',
                ],
            ]
        );

        $job = [
            'id'        => 'job-resubmit-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'lp-1', 'errorCode' => 'BRON-102', 'errorMessage' => 'Ontbrekende geboortedatum.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'succeeded'));

        self::assertCount(1, $this->transitions);
        self::assertSame('rej-1', $this->transitions[0]['objectId']);
        self::assertSame('reopen', $this->transitions[0]['action']);

        $rejectionFieldSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(1, $rejectionFieldSaves);
        self::assertSame('BRON-102', $rejectionFieldSaves[0]['object']['errorCode']);
        self::assertSame('Ontbrekende geboortedatum.', $rejectionFieldSaves[0]['object']['errorMessage']);

    }//end testResubmittedRecordStillRejectedReopens()

    /**
     * A validationReport error code matching the ExchangeErrorCode catalogue
     * by (code, target) resolves errorCodeRef to that entry's id.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-a-known-error-code-resolves-to-its-catalogue-entry
     */
    public function testResolvesKnownErrorCode(): void
    {
        $handler = $this->makeHandler(
            errorCodes: [
                ['id' => 'code-1', 'code' => 'BRON-101', 'target' => 'bron-rod'],
            ]
        );

        $job = [
            'id'        => 'job-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'lp-1', 'errorCode' => 'BRON-101', 'errorMessage' => 'Ongeldig BSN-formaat.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertSame('code-1', $rejectionSaves[0]['object']['errorCodeRef']);

    }//end testResolvesKnownErrorCode()

    /**
     * An error code with no matching ExchangeErrorCode entry does not block
     * rejection creation — errorCodeRef is left null.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-an-unknown-error-code-does-not-block-rejection-creation
     */
    public function testUnknownErrorCodeLeavesRefNull(): void
    {
        $handler = $this->makeHandler(errorCodes: []);

        $job = [
            'id'        => 'job-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'lp-1', 'errorCode' => 'BRON-999', 'errorMessage' => 'Unknown.'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        $rejectionSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'exchange-rejection'));
        self::assertCount(1, $rejectionSaves);
        self::assertNull($rejectionSaves[0]['object']['errorCodeRef']);

    }//end testUnknownErrorCodeLeavesRefNull()

    /**
     * A job scope.schema that is not one of the five supported sourceKind
     * schemas is skipped entirely — no rejections created, no exception.
     *
     * @return void
     */
    public function testUnsupportedSourceKindSkipsMapping(): void
    {
        $handler = $this->makeHandler();

        $job = [
            'id'        => 'job-1',
            'target'    => 'oso',
            'scope'     => ['schema' => 'cohort'],
            'tenant_id' => 'tenant-a',
            'result'    => [
                'validationReport' => [
                    ['recordId' => 'c-1', 'errorCode' => 'X', 'errorMessage' => 'Y'],
                ],
            ],
        ];

        $handler->handle($this->makeEvent($job, 'partial'));

        self::assertCount(0, $this->savedObjects);

    }//end testUnsupportedSourceKindSkipsMapping()

    /**
     * An empty validationReport is a no-op — no rejections created.
     *
     * @return void
     */
    public function testEmptyValidationReportCreatesNothing(): void
    {
        $handler = $this->makeHandler();

        $job = [
            'id'        => 'job-1',
            'target'    => 'bron-rod',
            'scope'     => ['schema' => 'learner-profile'],
            'tenant_id' => 'tenant-a',
            'result'    => ['validationReport' => []],
        ];

        $handler->handle($this->makeEvent($job, 'succeeded'));

        self::assertCount(0, $this->savedObjects);
        self::assertCount(0, $this->transitions);

    }//end testEmptyValidationReportCreatesNothing()

    /**
     * A wrong-schema event is ignored.
     *
     * @return void
     */
    public function testWrongSchemaIgnored(): void
    {
        $handler = $this->makeHandler();

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('support-request');
        $event->method('getTo')->willReturn('succeeded');

        $handler->handle($event);

        self::assertCount(0, $this->savedObjects);

    }//end testWrongSchemaIgnored()

    /**
     * A transition to a non-terminal state (e.g. running) is ignored.
     *
     * @return void
     */
    public function testNonTerminalStateIgnored(): void
    {
        $handler = $this->makeHandler();

        $job = ['id' => 'job-1', 'scope' => ['schema' => 'learner-profile'], 'tenant_id' => 'tenant-a'];

        $handler->handle($this->makeEvent($job, 'running'));

        self::assertCount(0, $this->savedObjects);

    }//end testNonTerminalStateIgnored()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testNonMatchingEventTypeIgnored(): void
    {
        $handler = $this->makeHandler();

        $handler->handle($this->createMock(Event::class));

        self::assertCount(0, $this->savedObjects);

    }//end testNonMatchingEventTypeIgnored()
}//end class
