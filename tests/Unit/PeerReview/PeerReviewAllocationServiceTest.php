<?php

/**
 * Scholiq PeerReviewAllocationService unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\PeerReview
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

namespace OCA\Scholiq\Tests\Unit\PeerReview;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\PeerReview\PeerReviewAllocationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PeerReviewAllocationService::allocate().
 */
class PeerReviewAllocationServiceTest extends TestCase
{

    private const ASSIGNMENT_ID = 'assignment-1';

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
     * Build a service wired to the given Assignment/Submissions/existing-PeerReviews.
     *
     * @param array<string,mixed>             $assignment      Assignment fixture.
     * @param array<int,array<string,mixed>>  $submissions     Submission fixtures.
     * @param array<int,array<string,mixed>>  $existingReviews Existing PeerReview fixtures.
     *
     * @return PeerReviewAllocationService
     */
    private function makeService(array $assignment, array $submissions, array $existingReviews = []): PeerReviewAllocationService
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($assignment) {
                if ($schema === 'assignment' && $id === self::ASSIGNMENT_ID) {
                    return $assignment;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($submissions, $existingReviews) {
                if (($config['schema'] ?? '') === 'submission') {
                    return $submissions;
                }

                if (($config['schema'] ?? '') === 'peer-review') {
                    return $existingReviews;
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

        return new PeerReviewAllocationService($objectService, $this->createMock(LoggerInterface::class));
    }//end makeService()

    /**
     * Five solo Submissions, one per learner.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fiveSoloSubmissions(): array
    {
        return [
            ['id' => 'sub-1', 'learnerIds' => ['learner-a'], 'submittedAt' => '2026-01-01T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-2', 'learnerIds' => ['learner-b'], 'submittedAt' => '2026-01-02T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-3', 'learnerIds' => ['learner-c'], 'submittedAt' => '2026-01-03T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-4', 'learnerIds' => ['learner-d'], 'submittedAt' => '2026-01-04T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-5', 'learnerIds' => ['learner-e'], 'submittedAt' => '2026-01-05T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
        ];
    }//end fiveSoloSubmissions()

    /**
     * Round-robin allocates exactly peerReviewersPerSubmission reviewers per
     * Submission and never assigns a learner to review their own Submission.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-round-robin-allocates-the-configured-reviewer-count-while-excluding-self
     */
    public function testRoundRobinAssignsExactCountExcludingSelf(): void
    {
        $assignment = [
            'id'                           => self::ASSIGNMENT_ID,
            'peerReviewAllocationStrategy' => 'round-robin',
            'peerReviewersPerSubmission'   => 2,
        ];
        $submissions = $this->fiveSoloSubmissions();

        $service = $this->makeService($assignment, $submissions);
        $result  = $service->allocate(self::ASSIGNMENT_ID);

        self::assertSame(10, $result['createdCount']);

        $bySubmission = [];
        foreach ($this->savedObjects as $saved) {
            self::assertSame('peer-review', $saved['schema']);
            $bySubmission[$saved['object']['submissionId']][] = $saved['object']['reviewerId'];
        }

        self::assertCount(5, $bySubmission);

        $submissionsById = [];
        foreach ($submissions as $submission) {
            $submissionsById[$submission['id']] = $submission;
        }

        foreach ($bySubmission as $submissionId => $reviewerIds) {
            self::assertCount(2, $reviewerIds, "submission {$submissionId} should have exactly 2 reviewers");
            self::assertCount(2, array_unique($reviewerIds), "submission {$submissionId} should not get duplicate reviewers");

            $ownLearnerIds = $submissionsById[$submissionId]['learnerIds'];
            foreach ($reviewerIds as $reviewerId) {
                self::assertNotContains(
                    $reviewerId,
                    $ownLearnerIds,
                    "reviewer {$reviewerId} must not review their own submission {$submissionId}"
                );
            }
        }
    }//end testRoundRobinAssignsExactCountExcludingSelf()

    /**
     * Random strategy applies the same self-exclusion rule as round-robin.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
     */
    public function testRandomExcludesSelf(): void
    {
        $assignment = [
            'id'                           => self::ASSIGNMENT_ID,
            'peerReviewAllocationStrategy' => 'random',
            'peerReviewersPerSubmission'   => 2,
        ];
        $submissions = $this->fiveSoloSubmissions();

        $service = $this->makeService($assignment, $submissions);
        $result  = $service->allocate(self::ASSIGNMENT_ID);

        self::assertSame('random', $result['strategy']);
        self::assertGreaterThan(0, $result['createdCount']);

        $submissionsById = [];
        foreach ($submissions as $submission) {
            $submissionsById[$submission['id']] = $submission;
        }

        foreach ($this->savedObjects as $saved) {
            $submissionId  = $saved['object']['submissionId'];
            $reviewerId    = $saved['object']['reviewerId'];
            $ownLearnerIds = $submissionsById[$submissionId]['learnerIds'];

            self::assertNotContains($reviewerId, $ownLearnerIds);
        }
    }//end testRandomExcludesSelf()

    /**
     * Manual strategy is a no-op: no PeerReview rows are created.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-manual-strategy-performs-no-automatic-allocation
     */
    public function testManualStrategyCreatesNothing(): void
    {
        $assignment = [
            'id'                           => self::ASSIGNMENT_ID,
            'peerReviewAllocationStrategy' => 'manual',
            'peerReviewersPerSubmission'   => 2,
        ];

        $service = $this->makeService($assignment, $this->fiveSoloSubmissions());
        $result  = $service->allocate(self::ASSIGNMENT_ID);

        self::assertSame('manual', $result['strategy']);
        self::assertSame(0, $result['createdCount']);
        self::assertEmpty($this->savedObjects);
    }//end testManualStrategyCreatesNothing()

    /**
     * Re-running allocate() when every Submission already has its full complement
     * of PeerReviews is a no-op — no new rows, no duplicate pairs.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-re-running-allocation-is-idempotent
     */
    public function testReRunningAllocationIsIdempotentOnceFull(): void
    {
        $assignment = [
            'id'                           => self::ASSIGNMENT_ID,
            'peerReviewAllocationStrategy' => 'round-robin',
            'peerReviewersPerSubmission'   => 2,
        ];
        $submissions = $this->fiveSoloSubmissions();

        // Every submission already has 2 existing PeerReviews (any lifecycle state
        // counts toward the quota — there is no `cancelled` state).
        $existingReviews = [];
        foreach ($submissions as $index => $submission) {
            $existingReviews[] = ['submissionId' => $submission['id'], 'reviewerId' => "existing-reviewer-{$index}-1"];
            $existingReviews[] = ['submissionId' => $submission['id'], 'reviewerId' => "existing-reviewer-{$index}-2"];
        }

        $service = $this->makeService($assignment, $submissions, $existingReviews);
        $result  = $service->allocate(self::ASSIGNMENT_ID);

        self::assertSame(0, $result['createdCount']);
        self::assertEmpty($this->savedObjects);
    }//end testReRunningAllocationIsIdempotentOnceFull()

    /**
     * A group Submission excludes every group member from reviewing it.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
     */
    public function testGroupSubmissionExcludesEveryGroupMember(): void
    {
        $assignment = [
            'id'                           => self::ASSIGNMENT_ID,
            'peerReviewAllocationStrategy' => 'round-robin',
            'peerReviewersPerSubmission'   => 2,
        ];
        $submissions = [
            ['id' => 'sub-group', 'learnerIds' => ['learner-a', 'learner-b'], 'submittedAt' => '2026-01-01T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-c', 'learnerIds' => ['learner-c'], 'submittedAt' => '2026-01-02T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
            ['id' => 'sub-d', 'learnerIds' => ['learner-d'], 'submittedAt' => '2026-01-03T00:00:00+00:00', 'tenant_id' => 'tenant-1'],
        ];

        $service = $this->makeService($assignment, $submissions);
        $service->allocate(self::ASSIGNMENT_ID);

        $groupReviewers = [];
        foreach ($this->savedObjects as $saved) {
            if ($saved['object']['submissionId'] === 'sub-group') {
                $groupReviewers[] = $saved['object']['reviewerId'];
            }
        }

        self::assertCount(2, $groupReviewers);
        self::assertNotContains('learner-a', $groupReviewers);
        self::assertNotContains('learner-b', $groupReviewers);
    }//end testGroupSubmissionExcludesEveryGroupMember()
}//end class
