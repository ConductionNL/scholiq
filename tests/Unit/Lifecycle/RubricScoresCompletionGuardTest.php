<?php

/**
 * Scholiq RubricScoresCompletionGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\RubricScoresCompletionGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the RubricScoresCompletionGuard (PeerReview.submit / SelfAssessment.submit).
 */
class RubricScoresCompletionGuardTest extends TestCase
{

    private const ASSIGNMENT_ID = 'assignment-1';
    private const RUBRIC_ID     = 'rubric-1';
    private const SUBMISSION_ID = 'submission-1';

    /**
     * A three-criterion Rubric fixture.
     *
     * @var array<string,mixed>
     */
    private array $rubric = [
        'id'       => self::RUBRIC_ID,
        'criteria' => [
            ['criterionId' => 'structure', 'levels' => []],
            ['criterionId' => 'analysis', 'levels' => []],
            ['criterionId' => 'conclusion', 'levels' => []],
        ],
    ];

    /**
     * Build a guard wired to the given Assignment/Rubric/Submission fixtures.
     *
     * @param array<string,mixed>|null $assignment Assignment fixture, or null (not found).
     * @param array<string,mixed>|null $rubric     Rubric fixture, or null (not found).
     * @param array<string,mixed>|null $submission Submission fixture, or null (not found).
     *
     * @return RubricScoresCompletionGuard
     */
    private function makeGuard(?array $assignment, ?array $rubric, ?array $submission): RubricScoresCompletionGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($assignment, $rubric, $submission) {
                if ($schema === 'assignment') {
                    return $assignment;
                }

                if ($schema === 'rubric') {
                    return $rubric;
                }

                if ($schema === 'submission') {
                    return $submission;
                }

                return null;
            }
        );

        return new RubricScoresCompletionGuard($objectService, $this->createMock(LoggerInterface::class));
    }//end makeGuard()

    /**
     * Complete rubric coverage (all three criteria) allows submit — PeerReview shape.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-reviewer-completes-an-assigned-peerreview
     */
    public function testCompleteCoverageAllowsSubmitForPeerReview(): void
    {
        $guard   = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric, null);
        $context = [
            'object' => [
                'id'            => 'pr-1',
                'assignmentId'  => self::ASSIGNMENT_ID,
                'reviewerId'    => 'teacher-uid',
                'rubricScores'  => [
                    ['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
                ],
            ],
        ];

        self::assertTrue($guard->check($context));
    }//end testCompleteCoverageAllowsSubmitForPeerReview()

    /**
     * Incomplete rubric coverage (2 of 3 criteria) blocks submit — PeerReview shape.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
     */
    public function testIncompleteCoverageBlocksSubmitForPeerReview(): void
    {
        $guard   = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID], $this->rubric, null);
        $context = [
            'object' => [
                'id'            => 'pr-1',
                'assignmentId'  => self::ASSIGNMENT_ID,
                'reviewerId'    => 'teacher-uid',
                'rubricScores'  => [
                    ['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
                ],
            ],
        ];

        self::assertFalse($guard->check($context));
    }//end testIncompleteCoverageBlocksSubmitForPeerReview()

    /**
     * Complete rubric coverage + learnerId on the linked Submission allows submit —
     * SelfAssessment shape.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-learner-completes-a-self-assessment-after-submitting
     */
    public function testCompleteCoverageAndValidLearnerAllowsSubmitForSelfAssessment(): void
    {
        $guard = $this->makeGuard(
            ['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID],
            $this->rubric,
            ['id' => self::SUBMISSION_ID, 'learnerIds' => ['learner-a', 'learner-b']]
        );
        $context = [
            'object' => [
                'id'           => 'sa-1',
                'assignmentId' => self::ASSIGNMENT_ID,
                'submissionId' => self::SUBMISSION_ID,
                'learnerId'    => 'learner-a',
                'rubricScores' => [
                    ['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
                ],
            ],
        ];

        self::assertTrue($guard->check($context));
    }//end testCompleteCoverageAndValidLearnerAllowsSubmitForSelfAssessment()

    /**
     * SelfAssessment with learnerId NOT in Submission.learnerIds is blocked, even
     * with complete rubric coverage.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric
     */
    public function testLearnerNotOnSubmissionBlocksSubmitForSelfAssessment(): void
    {
        $guard = $this->makeGuard(
            ['id' => self::ASSIGNMENT_ID, 'rubricId' => self::RUBRIC_ID],
            $this->rubric,
            ['id' => self::SUBMISSION_ID, 'learnerIds' => ['learner-a', 'learner-b']]
        );
        $context = [
            'object' => [
                'id'           => 'sa-1',
                'assignmentId' => self::ASSIGNMENT_ID,
                'submissionId' => self::SUBMISSION_ID,
                'learnerId'    => 'learner-outsider',
                'rubricScores' => [
                    ['criterionId' => 'structure', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'analysis', 'levelId' => 'good', 'points' => 3],
                    ['criterionId' => 'conclusion', 'levelId' => 'good', 'points' => 3],
                ],
            ],
        ];

        self::assertFalse($guard->check($context));
    }//end testLearnerNotOnSubmissionBlocksSubmitForSelfAssessment()

    /**
     * An Assignment with no rubricId has no required criteria — an empty
     * rubricScores array is trivially "complete".
     *
     * @return void
     */
    public function testNoRubricOnAssignmentAllowsSubmitWithEmptyScores(): void
    {
        $guard   = $this->makeGuard(['id' => self::ASSIGNMENT_ID, 'rubricId' => null], null, null);
        $context = [
            'object' => [
                'id'           => 'pr-1',
                'assignmentId' => self::ASSIGNMENT_ID,
                'reviewerId'   => 'teacher-uid',
                'rubricScores' => [],
            ],
        ];

        self::assertTrue($guard->check($context));
    }//end testNoRubricOnAssignmentAllowsSubmitWithEmptyScores()

    /**
     * A missing assignmentId fails closed.
     *
     * @return void
     */
    public function testMissingAssignmentIdFailsClosed(): void
    {
        $guard   = $this->makeGuard(null, null, null);
        $context = ['object' => ['id' => 'pr-1', 'reviewerId' => 'teacher-uid', 'rubricScores' => []]];

        self::assertFalse($guard->check($context));
    }//end testMissingAssignmentIdFailsClosed()
}//end class
