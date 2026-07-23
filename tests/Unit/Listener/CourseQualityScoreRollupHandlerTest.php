<?php

/**
 * Scholiq CourseQualityScoreRollupHandler unit tests.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\CourseEvaluation\CourseQualityScoreEvaluator;
use OCA\Scholiq\Listener\CourseQualityScoreRollupHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CourseQualityScoreRollupHandler::handle() on CourseEvaluationResponse → submitted.
 */
class CourseQualityScoreRollupHandlerTest extends TestCase
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
     * Build a handler with a real CourseQualityScoreEvaluator (stubbed ObjectService)
     * so evaluate() actually runs over the given existing score / response set.
     *
     * @param array|null                        $existingScore Existing CourseQualityScore row, if any.
     * @param array<int, array<string, mixed>> $responses     Responses fetched by the evaluator.
     * @param array<int, array<string, mixed>> $invitations   Invitations fetched by the evaluator.
     *
     * @return CourseQualityScoreRollupHandler
     */
    private function makeHandler(?array $existingScore, array $responses, array $invitations): CourseQualityScoreRollupHandler
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($existingScore, $responses, $invitations) {
                if ($config['schema'] === 'course-evaluation-response') {
                    return $responses;
                }

                if ($config['schema'] === 'evaluation-invitation') {
                    return $invitations;
                }

                if ($config['schema'] === 'course-quality-score') {
                    return $existingScore === null ? [] : [$existingScore];
                }

                return [];
            }
        );

        $objectService->method('saveObject')->willReturnCallback(
            function (string $register, string $schema, array $object) {
                $this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        return new CourseQualityScoreRollupHandler($objectService, new CourseQualityScoreEvaluator($objectService));

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
     * The first response for a course/period creates the CourseQualityScore row.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    public function testFirstResponseCreatesQualityScoreRow(): void
    {
        $responses   = [['overallScore' => 4]];
        $invitations = array_fill(0, 4, ['hasResponded' => false]);

        $handler = $this->makeHandler(existingScore: null, responses: $responses, invitations: $invitations);

        $response = [
            'courseId'     => 'course-1',
            'teacherId'    => null,
            'academicYear' => '2025-2026',
            'period'       => 'Q1',
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($response));

        $scoreSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'course-quality-score'));
        self::assertCount(1, $scoreSaves);
        self::assertSame('course-1', $scoreSaves[0]['object']['courseId']);
        self::assertSame(1, $scoreSaves[0]['object']['responseCount']);
        self::assertSame(4, $scoreSaves[0]['object']['invitationCount']);
        self::assertSame(4.0, $scoreSaves[0]['object']['averageOverallScore']);
        self::assertNotNull($scoreSaves[0]['object']['lastRecomputedAt']);

    }//end testFirstResponseCreatesQualityScoreRow()

    /**
     * A subsequent response updates the existing CourseQualityScore row
     * (same id preserved) rather than creating a duplicate.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    public function testSubsequentResponseUpdatesExistingRow(): void
    {
        $existing = [
            'id'                  => 'score-1',
            'courseId'            => 'course-1',
            'teacherId'           => null,
            'academicYear'        => '2025-2026',
            'period'              => 'Q1',
            'responseCount'       => 2,
            'invitationCount'     => 4,
            'averageOverallScore' => 4.5,
            'responseRate'        => 0.5,
            'lastRecomputedAt'    => '2026-07-01T00:00:00+02:00',
            'tenant_id'           => 'tenant-a',
        ];

        $responses   = [['overallScore' => 4], ['overallScore' => 5], ['overallScore' => 3]];
        $invitations = array_fill(0, 4, ['hasResponded' => false]);

        $handler = $this->makeHandler(existingScore: $existing, responses: $responses, invitations: $invitations);

        $response = [
            'courseId'     => 'course-1',
            'teacherId'    => null,
            'academicYear' => '2025-2026',
            'period'       => 'Q1',
            'tenant_id'    => 'tenant-a',
        ];

        $handler->handle($this->makeEvent($response));

        $scoreSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'course-quality-score'));
        self::assertCount(1, $scoreSaves, 'Exactly one save — a find-or-create update, not a second row');
        self::assertSame('score-1', $scoreSaves[0]['object']['id'], 'The existing row id is preserved (update, not create)');
        self::assertSame(3, $scoreSaves[0]['object']['responseCount']);
        self::assertSame(4.0, $scoreSaves[0]['object']['averageOverallScore']);

    }//end testSubsequentResponseUpdatesExistingRow()
}//end class
