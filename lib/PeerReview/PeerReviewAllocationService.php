<?php

/**
 * Scholiq Peer Review Allocation Service
 *
 * Batch-matching engine that allocates `PeerReview` rows for an Assignment's
 * Submissions. Draws its reviewer pool from the Assignment's own submitters
 * (mutual peer review among people who actually did the work, not the whole
 * cohort roster), excludes every learner listed in a Submission's own
 * `learnerIds` from reviewing that Submission (covers group submissions — no
 * group member reviews their own group's work), and supports `round-robin`
 * (deterministic cyclic assignment), `random` (shuffled assignment, same
 * exclusion rule), and `manual` (a no-op — the teacher creates `PeerReview`
 * rows by hand through the ordinary manifest create form).
 *
 * Idempotent: re-running allocate() only tops up Submissions short of
 * `peerReviewersPerSubmission` reviewers and never duplicates an existing
 * (reviewer, submission) pair.
 *
 * ADR-031 legitimate exception: batch-matching problem over a *set* of objects
 * that OR's per-object calculation/aggregation engine cannot express — the
 * same rationale GradeFormulaEvaluator/BsaProgressEvaluator already establish
 * for "cross-object logic JSON-logic can't express." Invoked (admin-only, or
 * a caller holding write access to the Assignment's Course/Cohort) by
 * PeerReviewController::allocate().
 *
 * @category PeerReview
 * @package  OCA\Scholiq\PeerReview
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */

declare(strict_types=1);

namespace OCA\Scholiq\PeerReview;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Allocates PeerReview rows for an Assignment's Submissions.
 *
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */
class PeerReviewAllocationService
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const ASSIGNMENT_SCHEMA  = 'assignment';
    private const SUBMISSION_SCHEMA  = 'submission';
    private const PEER_REVIEW_SCHEMA = 'peer-review';

    private const DEFAULT_STRATEGY  = 'round-robin';
    private const DEFAULT_REVIEWERS = 2;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query/persistence.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Allocate PeerReview rows for every Submission of an Assignment.
     *
     * @param string $assignmentId UUID of the Assignment to allocate reviewers for.
     *
     * @return array{strategy: string, submissionsProcessed: int, createdCount: int}
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-manual-strategy-performs-no-automatic-allocation
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-re-running-allocation-is-idempotent
     */
    public function allocate(string $assignmentId): array
    {
        $assignment = $this->fetchObject(id: $assignmentId, schema: self::ASSIGNMENT_SCHEMA);
        if ($assignment === null) {
            $this->logger->warning(
                '[PeerReviewAllocationService] Assignment {id} not found — nothing to allocate.',
                ['id' => $assignmentId]
            );
            return $this->result(strategy: self::DEFAULT_STRATEGY, submissionsProcessed: 0, createdCount: 0);
        }

        $strategy = $this->resolveStrategy(assignment: $assignment);
        if ($strategy === 'manual') {
            // No-op — the teacher creates PeerReview rows by hand.
            return $this->result(strategy: $strategy, submissionsProcessed: 0, createdCount: 0);
        }

        $submissions = $this->fetchOrderedSubmissions(assignmentId: $assignmentId);
        if (empty($submissions) === true) {
            return $this->result(strategy: $strategy, submissionsProcessed: 0, createdCount: 0);
        }

        $pool = $this->buildReviewerPool(submissions: $submissions);
        if ($strategy === 'random') {
            shuffle($pool);
        }

        if (empty($pool) === true) {
            return $this->result(strategy: $strategy, submissionsProcessed: count($submissions), createdCount: 0);
        }

        $createdCount = $this->allocateAcrossSubmissions(
            assignmentId: $assignmentId,
            submissions: $submissions,
            pool: $pool,
            reviewersPerSub: $this->resolveReviewersPerSub(assignment: $assignment)
        );

        return $this->result(strategy: $strategy, submissionsProcessed: count($submissions), createdCount: $createdCount);
    }//end allocate()

    /**
     * Walk every Submission in order, topping up short-of-quota reviewer counts.
     * The reviewer-pool cursor is shared and advances across submissions
     * (round-robin cycling), never resetting per submission.
     *
     * @param string                         $assignmentId    UUID of the Assignment.
     * @param array<int,array<string,mixed>> $submissions     Ordered Submissions.
     * @param array<int,string>              $pool            The reviewer pool.
     * @param int                            $reviewersPerSub Target reviewer count per Submission.
     *
     * @return int Total PeerReview rows created.
     */
    private function allocateAcrossSubmissions(
        string $assignmentId,
        array $submissions,
        array $pool,
        int $reviewersPerSub,
    ): int {
        $existingBySubmission = $this->indexExistingBySubmission(
            existingReviews: $this->fetchExistingReviews(assignmentId: $assignmentId)
        );

        $cursor       = 0;
        $createdCount = 0;

        foreach ($submissions as $submission) {
            $submissionId = (string) ($submission['id'] ?? '');
            if ($submissionId === '') {
                continue;
            }

            $alreadyAssigned = $existingBySubmission[$submissionId] ?? [];
            $needed          = $reviewersPerSub - count($alreadyAssigned);
            if ($needed <= 0) {
                continue;
            }

            $excluded = array_merge(
                $this->normalizeLearnerIds(submission: $submission),
                $alreadyAssigned
            );

            $outcome = $this->assignReviewersToSubmission(
                assignmentId: $assignmentId,
                submission: $submission,
                submissionId: $submissionId,
                needed: $needed,
                excluded: $excluded,
                pool: $pool,
                cursor: $cursor
            );

            $cursor        = $outcome['cursor'];
            $createdCount += $outcome['created'];
        }//end foreach

        return $createdCount;
    }//end allocateAcrossSubmissions()

    /**
     * Assign up to `$needed` reviewers to one Submission, cycling through the pool
     * from `$cursor`, skipping excluded and already-picked-this-round candidates.
     *
     * @param string              $assignmentId UUID of the Assignment.
     * @param array<string,mixed> $submission   The Submission data array.
     * @param string              $submissionId UUID of the Submission.
     * @param int                 $needed       Reviewers still needed.
     * @param array<int,string>   $excluded     Reviewer ids that must not be picked.
     * @param array<int,string>   $pool         The reviewer pool.
     * @param int                 $cursor       Starting pool cursor (shared across submissions).
     *
     * @return array{created: int, cursor: int}
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
     */
    private function assignReviewersToSubmission(
        string $assignmentId,
        array $submission,
        string $submissionId,
        int $needed,
        array $excluded,
        array $pool,
        int $cursor,
    ): array {
        $poolSize      = count($pool);
        $maxAttempts   = $poolSize * 2;
        $assignedIds   = [];
        $assignedCount = 0;
        $attempts      = 0;

        while ($assignedCount < $needed && $attempts < $maxAttempts) {
            $candidate = $pool[$cursor % $poolSize];
            $cursor++;
            $attempts++;

            $isExcluded  = in_array($candidate, $excluded, true) === true;
            $isDuplicate = in_array($candidate, $assignedIds, true) === true;
            if ($isExcluded === true || $isDuplicate === true) {
                continue;
            }

            $assignedIds[] = $candidate;
            $assignedCount++;

            $this->objectService->saveObject(
                register: self::SCHOLIQ_REGISTER,
                schema: self::PEER_REVIEW_SCHEMA,
                object: [
                    'assignmentId' => $assignmentId,
                    'submissionId' => $submissionId,
                    'reviewerId'   => $candidate,
                    'rubricScores' => [],
                    'lifecycle'    => 'assigned',
                    'tenant_id'    => $submission['tenant_id'] ?? '',
                ]
            );
        }//end while

        return ['created' => $assignedCount, 'cursor' => $cursor];
    }//end assignReviewersToSubmission()

    /**
     * Resolve the Assignment's allocation strategy, defaulting to round-robin.
     *
     * @param array<string,mixed> $assignment The Assignment data array.
     *
     * @return string
     */
    private function resolveStrategy(array $assignment): string
    {
        $strategy = $assignment['peerReviewAllocationStrategy'] ?? self::DEFAULT_STRATEGY;
        if (is_string($strategy) === false || $strategy === '') {
            return self::DEFAULT_STRATEGY;
        }

        return $strategy;
    }//end resolveStrategy()

    /**
     * Resolve the Assignment's reviewers-per-submission target, defaulting to 2.
     *
     * @param array<string,mixed> $assignment The Assignment data array.
     *
     * @return int
     */
    private function resolveReviewersPerSub(array $assignment): int
    {
        $count = (int) ($assignment['peerReviewersPerSubmission'] ?? self::DEFAULT_REVIEWERS);
        if ($count < 1) {
            return self::DEFAULT_REVIEWERS;
        }

        return $count;
    }//end resolveReviewersPerSub()

    /**
     * Build the allocate() return shape.
     *
     * @param string $strategy             Strategy applied.
     * @param int    $submissionsProcessed Number of Submissions considered.
     * @param int    $createdCount         Number of PeerReview rows created.
     *
     * @return array{strategy: string, submissionsProcessed: int, createdCount: int}
     */
    private function result(string $strategy, int $submissionsProcessed, int $createdCount): array
    {
        return [
            'strategy'             => $strategy,
            'submissionsProcessed' => $submissionsProcessed,
            'createdCount'         => $createdCount,
        ];
    }//end result()

    /**
     * Fetch every Submission for the Assignment, ordered by submittedAt then id
     * for deterministic round-robin cycling.
     *
     * @param string $assignmentId UUID of the Assignment.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
     */
    private function fetchOrderedSubmissions(string $assignmentId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::SUBMISSION_SCHEMA,
                'filters'  => ['assignmentId' => $assignmentId],
            ]
        );

        $submissions = [];
        foreach ($results as $result) {
            $submissions[] = $this->toArray(value: $result);
        }

        usort(
            $submissions,
            static function (array $a, array $b): int {
                $aSubmittedAt = (string) ($a['submittedAt'] ?? '');
                $bSubmittedAt = (string) ($b['submittedAt'] ?? '');
                if ($aSubmittedAt !== $bSubmittedAt) {
                    return strcmp($aSubmittedAt, $bSubmittedAt);
                }

                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }
        );

        return $submissions;

    }//end fetchOrderedSubmissions()

    /**
     * Build the reviewer pool: the union of every Submission's learnerIds,
     * de-duplicated and sorted for deterministic cycling.
     *
     * @param array<int,array<string,mixed>> $submissions Ordered Submissions.
     *
     * @return array<int,string>
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
     */
    private function buildReviewerPool(array $submissions): array
    {
        $pool = [];
        foreach ($submissions as $submission) {
            foreach ($this->normalizeLearnerIds(submission: $submission) as $learnerId) {
                if (in_array($learnerId, $pool, true) === false) {
                    $pool[] = $learnerId;
                }
            }
        }

        sort($pool);

        return $pool;

    }//end buildReviewerPool()

    /**
     * Normalize a Submission's learnerIds into a clean string array.
     *
     * @param array<string,mixed> $submission The Submission data array.
     *
     * @return array<int,string>
     */
    private function normalizeLearnerIds(array $submission): array
    {
        $learnerIds = $submission['learnerIds'] ?? [];
        if (is_array($learnerIds) === false) {
            return [];
        }

        $normalized = [];
        foreach ($learnerIds as $learnerId) {
            if (is_string($learnerId) === true && $learnerId !== '') {
                $normalized[] = $learnerId;
            }
        }

        return $normalized;

    }//end normalizeLearnerIds()

    /**
     * Fetch every existing PeerReview for the Assignment (any lifecycle state —
     * there is no `cancelled` state, so `assigned`/`submitted`/`released` all
     * count toward the per-Submission quota and must never be duplicated).
     *
     * @param string $assignmentId UUID of the Assignment.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-re-running-allocation-is-idempotent
     */
    private function fetchExistingReviews(string $assignmentId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::PEER_REVIEW_SCHEMA,
                'filters'  => ['assignmentId' => $assignmentId],
            ]
        );

        $reviews = [];
        foreach ($results as $result) {
            $reviews[] = $this->toArray(value: $result);
        }

        return $reviews;

    }//end fetchExistingReviews()

    /**
     * Index existing PeerReviews by submissionId -> list of reviewerId already
     * assigned to that submission.
     *
     * @param array<int,array<string,mixed>> $existingReviews Every existing PeerReview for the Assignment.
     *
     * @return array<string,array<int,string>>
     */
    private function indexExistingBySubmission(array $existingReviews): array
    {
        $index = [];
        foreach ($existingReviews as $review) {
            $submissionId = (string) ($review['submissionId'] ?? '');
            $reviewerId   = (string) ($review['reviewerId'] ?? '');
            if ($submissionId === '' || $reviewerId === '') {
                continue;
            }

            $index[$submissionId][] = $reviewerId;
        }

        return $index;

    }//end indexExistingBySubmission()

    /**
     * Fetch an object by id/schema, normalising to an array whether OR returns an
     * array or an object exposing jsonSerialize().
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        $obj = $this->objectService->find(
            id: $id,
            register: self::SCHOLIQ_REGISTER,
            schema: $schema
        );

        if ($obj === null) {
            return null;
        }

        return $this->toArray(value: $obj);

    }//end fetchObject()

    /**
     * Normalise a value that may be an array or an object exposing jsonSerialize()
     * into a plain array.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];

    }//end toArray()
}//end class
