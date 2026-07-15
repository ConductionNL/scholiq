<?php

/**
 * Scholiq CourseEvaluationResponseSubmittedHandler unit tests.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-successful-submission-flips-the-invitation-without-linking-to-the-response
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\CourseEvaluationResponseSubmittedHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CourseEvaluationResponseSubmittedHandler::handle() on CourseEvaluationResponse → submitted.
 */
class CourseEvaluationResponseSubmittedHandlerTest extends TestCase
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
     * Build a handler with a signed-in caller and stubbed invitation rows.
     *
     * @param string                            $callerUid   NC user id the session resolves to.
     * @param array<int, array<string, mixed>> $invitations EvaluationInvitation rows returned by findAll().
     *
     * @return CourseEvaluationResponseSubmittedHandler
     */
    private function makeHandler(string $callerUid, array $invitations): CourseEvaluationResponseSubmittedHandler
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($callerUid);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($invitations, $callerUid) {
                if ($config['schema'] !== 'evaluation-invitation') {
                    return [];
                }

                $filters = $config['filters'] ?? [];
                return array_values(
                    array_filter(
                        $invitations,
                        static fn ($inv) => ($inv['campaignId'] ?? null) === ($filters['campaignId'] ?? null)
                            && ($inv['learnerId'] ?? null) === ($filters['learnerId'] ?? null)
                    )
                );
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new CourseEvaluationResponseSubmittedHandler($userSession, $objectService, $this->createMock(LoggerInterface::class));

    }//end makeHandler()

    /**
     * Build a mocked ObjectTransitionedEvent for a CourseEvaluationResponse → submitted transition.
     *
     * @param array<string, mixed> $responseData The response's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $responseData): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($responseData);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('course-evaluation-response');
        $event->method('getTo')->willReturn('submitted');
        $event->method('getFrom')->willReturn('draft');

        return $event;

    }//end makeEvent()

    /**
     * Submitting flips only the caller's own EvaluationInvitation — a second
     * learner's invitation for the same campaign is left untouched.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-submitting-flips-the-caller-s-own-invitation-not-anyone-else-s
     */
    public function testFlipsCallersOwnInvitationOnly(): void
    {
        $invitations = [
            ['id' => 'inv-1', 'campaignId' => 'campaign-1', 'learnerId' => 'learner-1', 'hasResponded' => false],
            ['id' => 'inv-2', 'campaignId' => 'campaign-1', 'learnerId' => 'learner-2', 'hasResponded' => false],
        ];

        $handler = $this->makeHandler(callerUid: 'learner-1', invitations: $invitations);

        $response = [
            'campaignId' => 'campaign-1',
            'courseId'   => 'course-1',
            'answers'    => [],
            'tenant_id'  => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($response));

        self::assertCount(1, $this->savedObjects, 'Only one invitation is written');
        $saved = $this->savedObjects[0];
        self::assertSame('evaluation-invitation', $saved['schema']);
        self::assertSame('inv-1', $saved['object']['id']);
        self::assertSame('learner-1', $saved['object']['learnerId']);
        self::assertTrue($saved['object']['hasResponded']);
        self::assertNotNull($saved['object']['respondedAt']);

    }//end testFlipsCallersOwnInvitationOnly()

    /**
     * The updated EvaluationInvitation gains no field referencing the
     * submitted response's identity or content — only hasResponded/
     * respondedAt change relative to the pre-existing row.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-submitting-flips-the-caller-s-own-invitation-not-anyone-else-s
     */
    public function testUpdatedInvitationGainsNoResponseReference(): void
    {
        $invitations = [
            [
                'id'               => 'inv-1',
                'campaignId'       => 'campaign-1',
                'courseId'         => 'course-1',
                'learnerId'        => 'learner-1',
                'hasResponded'     => false,
                'respondedAt'      => null,
                'campaignClosesAt' => '2026-08-01T00:00:00+02:00',
                'tenant_id'        => 'tenant-a',
            ],
        ];

        $handler = $this->makeHandler(callerUid: 'learner-1', invitations: $invitations);

        $response = [
            'id'         => 'response-1',
            'campaignId' => 'campaign-1',
            'courseId'   => 'course-1',
            'answers'    => [['questionId' => 'q1', 'ratingValue' => 5]],
            'tenant_id'  => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($response));

        $saved = $this->savedObjects[0]['object'];
        self::assertArrayNotHasKey('responseId', $saved);
        self::assertArrayNotHasKey('response', $saved);
        self::assertArrayNotHasKey('answers', $saved, 'The invitation never absorbs response content');
        // Every other pre-existing field on the invitation is preserved unchanged.
        self::assertSame('course-1', $saved['courseId']);
        self::assertSame('2026-08-01T00:00:00+02:00', $saved['campaignClosesAt']);

    }//end testUpdatedInvitationGainsNoResponseReference()

    /**
     * A transition to a state other than `submitted` is ignored entirely.
     *
     * @return void
     */
    public function testIgnoresNonSubmittedTransitions(): void
    {
        $handler = $this->makeHandler(callerUid: 'learner-1', invitations: []);

        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn(['campaignId' => 'campaign-1']);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('course-evaluation-response');
        $event->method('getTo')->willReturn('draft');

        $handler->handle($event);

        self::assertEmpty($this->savedObjects);

    }//end testIgnoresNonSubmittedTransitions()

    /**
     * A missing session (no authenticated user) is a safe no-op, not a fatal error.
     *
     * @return void
     */
    public function testNoAuthenticatedUserIsSafeNoOp(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects(self::never())->method('findAll');
        $objectService->expects(self::never())->method('saveObject');

        $handler = new CourseEvaluationResponseSubmittedHandler($userSession, $objectService, $this->createMock(LoggerInterface::class));

        $response = ['campaignId' => 'campaign-1', 'tenant_id' => 'tenant-a'];
        $handler->handle($this->makeEvent($response));

        self::assertTrue(true, 'No exception thrown for a missing session');

    }//end testNoAuthenticatedUserIsSafeNoOp()
}//end class
