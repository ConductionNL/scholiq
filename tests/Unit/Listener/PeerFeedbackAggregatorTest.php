<?php

/**
 * Scholiq PeerFeedbackAggregator unit tests.
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\PeerFeedbackAggregator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PeerFeedbackAggregator::handle() on PeerReview -> released.
 */
class PeerFeedbackAggregatorTest extends TestCase
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
     * Build an aggregator wired to the given Assignment + released-PeerReview set.
     *
     * @param array<string,mixed>            $assignment      Assignment fixture (peerReviewAnonymity).
     * @param array<int,array<string,mixed>> $releasedReviews Released PeerReview fixtures for the submission.
     * @param array<string,mixed>|null       $existingSummary  Existing PeerFeedbackSummary, or null.
     *
     * @return PeerFeedbackAggregator
     */
    private function makeAggregator(array $assignment, array $releasedReviews, ?array $existingSummary = null): PeerFeedbackAggregator
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($assignment) {
                if ($schema === 'assignment') {
                    return $assignment;
                }

                if ($schema === 'submission') {
                    return ['id' => $id, 'tenant_id' => 'tenant-1'];
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($releasedReviews, $existingSummary) {
                if (($config['schema'] ?? '') === 'peer-review') {
                    return $releasedReviews;
                }

                if (($config['schema'] ?? '') === 'peer-feedback-summary') {
                    return $existingSummary === null ? [] : [$existingSummary];
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

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn(new DateTime('2026-07-14T10:00:00+00:00'));

        return new PeerFeedbackAggregator($objectService, $timeFactory);
    }//end makeAggregator()

    /**
     * Build a mocked ObjectTransitionedEvent for a PeerReview -> released transition.
     *
     * @param array<string,mixed> $peerReview The PeerReview's jsonSerialize() payload.
     *
     * @return ObjectTransitionedEvent
     */
    private function makeEvent(array $peerReview): ObjectTransitionedEvent
    {
        $objectEntity = $this->createMock(ObjectEntity::class);
        $objectEntity->method('jsonSerialize')->willReturn($peerReview);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getObject')->willReturn($objectEntity);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('peer-review');
        $event->method('getTo')->willReturn('released');
        $event->method('getFrom')->willReturn('submitted');

        return $event;
    }//end makeEvent()

    /**
     * Blind anonymity: feedbackItems[].reviewerId is computed as null.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
     */
    public function testBlindAnonymityNullsReviewerId(): void
    {
        $assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => 'blind'];
        $reviews    = [
            ['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => []],
        ];

        $aggregator = $this->makeAggregator($assignment, $reviews);
        $aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

        self::assertCount(1, $this->savedObjects);
        $summary = $this->savedObjects[0]['object'];
        self::assertSame('peer-feedback-summary', $this->savedObjects[0]['schema']);
        self::assertNull($summary['feedbackItems'][0]['reviewerId']);
    }//end testBlindAnonymityNullsReviewerId()

    /**
     * Double-blind anonymity: feedbackItems[].reviewerId is also computed as null.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
     */
    public function testDoubleBlindAnonymityNullsReviewerId(): void
    {
        $assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => 'double-blind'];
        $reviews    = [
            ['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => []],
        ];

        $aggregator = $this->makeAggregator($assignment, $reviews);
        $aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

        $summary = $this->savedObjects[0]['object'];
        self::assertNull($summary['feedbackItems'][0]['reviewerId']);
    }//end testDoubleBlindAnonymityNullsReviewerId()

    /**
     * Open anonymity: feedbackItems[].reviewerId is populated with the reviewer's identity.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-open-anonymity-reveals-reviewer-identity-in-the-feedback-summary
     */
    public function testOpenAnonymityPopulatesReviewerId(): void
    {
        $assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'];
        $reviews    = [
            ['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 8, 'comments' => 'Good', 'rubricScores' => []],
        ];

        $aggregator = $this->makeAggregator($assignment, $reviews);
        $aggregator->handle($this->makeEvent(['id' => 'pr-1', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

        $summary = $this->savedObjects[0]['object'];
        self::assertSame('reviewer-a', $summary['feedbackItems'][0]['reviewerId']);
    }//end testOpenAnonymityPopulatesReviewerId()

    /**
     * reviewCount and averageScore recompute correctly across multiple released reviews.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-teacher-releases-a-submitted-peerreview
     */
    public function testReviewCountAndAverageScoreRecomputeCorrectly(): void
    {
        $assignment = ['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'];
        $reviews    = [
            ['id' => 'pr-1', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-a', 'totalScore' => 6, 'comments' => null, 'rubricScores' => []],
            ['id' => 'pr-2', 'submissionId' => 'sub-1', 'reviewerId' => 'reviewer-b', 'totalScore' => 8, 'comments' => null, 'rubricScores' => []],
        ];

        $aggregator = $this->makeAggregator($assignment, $reviews);
        $aggregator->handle($this->makeEvent(['id' => 'pr-2', 'submissionId' => 'sub-1', 'assignmentId' => 'assignment-1']));

        $summary = $this->savedObjects[0]['object'];
        self::assertSame(2, $summary['reviewCount']);
        self::assertSame(7.0, $summary['averageScore']);
        self::assertCount(2, $summary['feedbackItems']);
    }//end testReviewCountAndAverageScoreRecomputeCorrectly()

    /**
     * A transition to a state other than `released` is ignored.
     *
     * @return void
     */
    public function testIgnoresNonReleasedTransitions(): void
    {
        $aggregator = $this->makeAggregator(['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'], []);

        $event = $this->createMock(ObjectTransitionedEvent::class);
        $event->method('getRegister')->willReturn('scholiq');
        $event->method('getSchema')->willReturn('peer-review');
        $event->method('getTo')->willReturn('submitted');

        $aggregator->handle($event);

        self::assertEmpty($this->savedObjects);
    }//end testIgnoresNonReleasedTransitions()

    /**
     * A non-ObjectTransitionedEvent is ignored.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvent(): void
    {
        $aggregator = $this->makeAggregator(['id' => 'assignment-1', 'peerReviewAnonymity' => 'open'], []);
        $aggregator->handle($this->createMock(Event::class));

        self::assertEmpty($this->savedObjects);
    }//end testIgnoresUnrelatedEvent()
}//end class
