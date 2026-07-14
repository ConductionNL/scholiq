<?php

/**
 * Scholiq Peer Feedback Aggregator
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to PeerReview
 * objects transitioning to `released`. Recomputes the linked Submission's
 * `PeerFeedbackSummary` — `reviewCount`, `averageScore` (mean of released
 * PeerReview.totalScore), and `feedbackItems` (one per released PeerReview:
 * `comments`, `rubricScores`, and `reviewerId` set to null when the governing
 * Assignment's `peerReviewAnonymity` is `blind`/`double-blind`, or the
 * reviewer's identity when `open`).
 *
 * This is the server-side anonymity mechanism (design.md "Anonymity
 * Enforcement"): `PeerFeedbackSummary` structurally omits `reviewerId` — the
 * field is computed as null, not merely hidden — so a submission's author who
 * reads this projection (their own `PeerReview` rows are denied by
 * `x-property-rbac.read`) can never learn a blind/double-blind reviewer's
 * identity through it.
 *
 * ADR-031 legitimate exception: this register's `x-openregister-aggregations`
 * vocabulary is `count`/`count_distinct` only (no `avg`, verified — a
 * full-file grep for `"metric":` finds no other value) and cannot
 * conditionally redact a field per matching row; `averageScore` and
 * `feedbackItems` require both, so they are computed here, in PHP, mirroring
 * GradeRollupHandler's "recompute on publish" shape exactly.
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-open-anonymity-reveals-reviewer-identity-in-the-feedback-summary
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges PeerReview.released -> PeerFeedbackSummary recompute.
 *
 * @implements IEventListener<Event>
 * @spec       openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-identity-is-hidden-from-the-submission-author-via-a-server-enforced-feedback-projection
 */
class PeerFeedbackAggregator implements IEventListener
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const PEER_REVIEW_SCHEMA = 'peer-review';
    private const PEER_FEEDBACK_SUMMARY_SCHEMA = 'peer-feedback-summary';
    private const ASSIGNMENT_SCHEMA            = 'assignment';

    private const OPEN_ANONYMITY = 'open';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object access.
     * @param ITimeFactory  $timeFactory   NC time source (injectable "now" for tests).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ITimeFactory $timeFactory,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-a-teacher-releases-a-submitted-peerreview
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER) {
            return;
        }

        if ($event->getSchema() !== self::PEER_REVIEW_SCHEMA || $event->getTo() !== 'released') {
            return;
        }

        $peerReview   = $event->getObject()->jsonSerialize();
        $submissionId = $peerReview['submissionId'] ?? '';
        $assignmentId = $peerReview['assignmentId'] ?? '';

        if (is_string($submissionId) === false || $submissionId === ''
            || is_string($assignmentId) === false || $assignmentId === ''
        ) {
            return;
        }

        $this->recomputeSummary(submissionId: $submissionId, assignmentId: $assignmentId);

    }//end handle()

    /**
     * Recompute and persist the PeerFeedbackSummary for a Submission.
     *
     * @param string $submissionId UUID of the Submission.
     * @param string $assignmentId UUID of the governing Assignment.
     *
     * @return void
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-open-anonymity-reveals-reviewer-identity-in-the-feedback-summary
     */
    private function recomputeSummary(string $submissionId, string $assignmentId): void
    {
        $anonymity = $this->fetchAnonymityMode(assignmentId: $assignmentId);
        $released  = $this->fetchReleasedReviews(submissionId: $submissionId);

        $feedbackItems = [];
        $scores        = [];

        foreach ($released as $review) {
            $totalScore = $review['totalScore'] ?? null;
            if (is_numeric($totalScore) === true) {
                $scores[] = (float) $totalScore;
            }

            $reviewerId = null;
            if ($anonymity === self::OPEN_ANONYMITY) {
                $reviewerId = $review['reviewerId'] ?? null;
            }

            $feedbackItems[] = [
                'comments'     => $review['comments'] ?? null,
                'rubricScores' => $review['rubricScores'] ?? [],
                'reviewerId'   => $reviewerId,
            ];
        }

        $averageScore = null;
        if (empty($scores) === false) {
            $averageScore = array_sum($scores) / count($scores);
        }

        $existing = $this->fetchExistingSummary(submissionId: $submissionId);

        $data = array_merge(
            $existing ?? [],
            [
                'submissionId'   => $submissionId,
                'assignmentId'   => $assignmentId,
                'reviewCount'    => count($released),
                'averageScore'   => $averageScore,
                'feedbackItems'  => $feedbackItems,
                'lastComputedAt' => DateTimeImmutable::createFromMutable($this->timeFactory->getDateTime())->format(\DATE_ATOM),
                'tenant_id'      => $existing['tenant_id'] ?? ($this->fetchTenantId(submissionId: $submissionId) ?? ''),
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::PEER_FEEDBACK_SUMMARY_SCHEMA,
            object: $data
        );

    }//end recomputeSummary()

    /**
     * Resolve the governing Assignment's `peerReviewAnonymity`.
     *
     * @param string $assignmentId UUID of the Assignment.
     *
     * @return string Defaults to `blind` (the schema default) when unresolvable —
     *                fail closed on anonymity, never fail open.
     */
    private function fetchAnonymityMode(string $assignmentId): string
    {
        $assignment = $this->fetchObject(id: $assignmentId, schema: self::ASSIGNMENT_SCHEMA);
        if ($assignment === null) {
            return 'blind';
        }

        $anonymity = $assignment['peerReviewAnonymity'] ?? 'blind';
        if (is_string($anonymity) === false || $anonymity === '') {
            return 'blind';
        }

        return $anonymity;

    }//end fetchAnonymityMode()

    /**
     * Fetch every `released` PeerReview for a Submission.
     *
     * @param string $submissionId UUID of the Submission.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchReleasedReviews(string $submissionId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::PEER_REVIEW_SCHEMA,
                'filters'  => [
                    'submissionId' => $submissionId,
                    'lifecycle'    => 'released',
                ],
            ]
        );

        $reviews = [];
        foreach ($results as $result) {
            $reviews[] = $this->toArray(value: $result);
        }

        return $reviews;

    }//end fetchReleasedReviews()

    /**
     * Fetch the existing PeerFeedbackSummary for a Submission, if any.
     *
     * @param string $submissionId UUID of the Submission.
     *
     * @return array<string,mixed>|null
     */
    private function fetchExistingSummary(string $submissionId): ?array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::PEER_FEEDBACK_SUMMARY_SCHEMA,
                'filters'  => ['submissionId' => $submissionId],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        return $this->toArray(value: $results[0]);

    }//end fetchExistingSummary()

    /**
     * Best-effort resolve a Submission's tenant_id for a brand-new PeerFeedbackSummary.
     *
     * @param string $submissionId UUID of the Submission.
     *
     * @return string|null
     */
    private function fetchTenantId(string $submissionId): ?string
    {
        $submission = $this->fetchObject(id: $submissionId, schema: 'submission');
        if ($submission === null) {
            return null;
        }

        $tenantId = $submission['tenant_id'] ?? null;
        if (is_string($tenantId) === false || $tenantId === '') {
            return null;
        }

        return $tenantId;

    }//end fetchTenantId()

    /**
     * Fetch an object by id/schema, normalising to an array.
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
