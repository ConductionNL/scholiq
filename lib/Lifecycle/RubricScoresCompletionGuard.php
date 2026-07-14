<?php

/**
 * Scholiq Rubric Scores Completion Guard
 *
 * Shared lifecycle guard for PeerReview's `submit` (assigned -> submitted) and
 * SelfAssessment's `submit` (draft -> submitted) transitions. Blocks the transition
 * unless `rubricScores` covers every `criterionId` declared on the linked
 * Assignment's Rubric. For SelfAssessment, additionally blocks the transition
 * unless `learnerId` is one of the linked Submission's own `learnerIds` — a
 * self-assessment can only ever be authored by a participant in the work it
 * assesses.
 *
 * One shared class rather than two near-duplicates: PeerReview.submit and
 * SelfAssessment.submit need the identical rubric-coverage check (design.md
 * "Rejected Alternatives" — two near-duplicate completion guards). The two
 * schemas are distinguished structurally: SelfAssessment rows carry a
 * `learnerId` field PeerReview rows never carry (PeerReview instead carries
 * `reviewerId`) — the additional Submission-membership check only runs when
 * `learnerId` is present on the transitioning object.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration"; JSON Schema
 * alone cannot express "every criterionId in a cross-object Rubric is present in
 * this array" nor "this scalar field is a member of another object's array field".
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards PeerReview.submit and SelfAssessment.submit.
 *
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-peerreview-captures-one-reviewers-rubric-based-assessment-with-its-own-lifecycle
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric
 */
class RubricScoresCompletionGuard
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const ASSIGNMENT_SCHEMA = 'assignment';
    private const RUBRIC_SCHEMA     = 'rubric';
    private const SUBMISSION_SCHEMA = 'submission';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
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
     * Allow the `submit` transition unless rubric coverage is incomplete (or, for a
     * SelfAssessment, `learnerId` is not a member of the linked Submission's
     * `learnerIds`).
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the PeerReview/SelfAssessment data array
     *                                               - 'transition' : 'submit'
     *
     * @return bool True if the transition is allowed; false blocks it (HTTP 422).
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
     */
    public function check(array &$transitionContext): bool
    {
        $object       = $transitionContext['object'] ?? [];
        $objectId     = $object['id'] ?? ($object['uuid'] ?? '');
        $assignmentId = $object['assignmentId'] ?? null;
        $rubricScores = $object['rubricScores'] ?? [];

        if (is_string($assignmentId) === false || $assignmentId === '') {
            $this->logger->info(
                '[RubricScoresCompletionGuard] {id} has no assignmentId — denying submit (fail closed).',
                ['id' => $objectId]
            );
            return false;
        }

        $criterionIds = $this->fetchRequiredCriterionIds(assignmentId: $assignmentId);

        if ($this->coversAllCriteria(rubricScores: $rubricScores, requiredCriterionIds: $criterionIds) === false) {
            $this->logger->info(
                '[RubricScoresCompletionGuard] {id} rubricScores does not cover every criterionId of the linked Rubric — blocking submit.',
                ['id' => $objectId]
            );
            return false;
        }

        // SelfAssessment rows carry a `learnerId`; PeerReview rows never do (they
        // carry `reviewerId` instead) — the discriminator is structural, per this
        // class's docblock.
        if (array_key_exists('learnerId', $object) === true) {
            if ($this->isLearnerOnSubmission(object: $object) === false) {
                $this->logger->info(
                    '[RubricScoresCompletionGuard] {id} learnerId is not a member of the linked Submission.learnerIds — blocking submit.',
                    ['id' => $objectId]
                );
                return false;
            }
        }

        return true;

    }//end check()

    /**
     * Resolve the set of criterionId values the linked Assignment's Rubric declares.
     *
     * @param string $assignmentId UUID of the Assignment.
     *
     * @return array<int,string> Required criterionIds. Empty when the Assignment has
     *                           no rubricId or the Rubric cannot be resolved — an
     *                           empty requirement set is trivially satisfied by any
     *                           rubricScores array, including an empty one.
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
     */
    private function fetchRequiredCriterionIds(string $assignmentId): array
    {
        $assignment = $this->fetchObject(id: $assignmentId, schema: self::ASSIGNMENT_SCHEMA);
        if ($assignment === null) {
            return [];
        }

        $rubricId = $assignment['rubricId'] ?? null;
        if (is_string($rubricId) === false || $rubricId === '') {
            return [];
        }

        $rubric = $this->fetchObject(id: $rubricId, schema: self::RUBRIC_SCHEMA);
        if ($rubric === null) {
            return [];
        }

        $criterionIds = [];
        foreach (($rubric['criteria'] ?? []) as $criterion) {
            if (is_array($criterion) === true && isset($criterion['criterionId']) === true) {
                $criterionIds[] = (string) $criterion['criterionId'];
            }
        }

        return $criterionIds;

    }//end fetchRequiredCriterionIds()

    /**
     * Check that rubricScores covers every required criterionId.
     *
     * @param array<int,mixed>  $rubricScores         The transitioning object's rubricScores array.
     * @param array<int,string> $requiredCriterionIds Every criterionId the linked Rubric declares.
     *
     * @return bool
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#scenario-submit-is-blocked-when-rubric-coverage-is-incomplete
     */
    private function coversAllCriteria(array $rubricScores, array $requiredCriterionIds): bool
    {
        if (empty($requiredCriterionIds) === true) {
            return true;
        }

        $scoredCriterionIds = [];
        foreach ($rubricScores as $score) {
            if (is_array($score) === true && isset($score['criterionId']) === true) {
                $scoredCriterionIds[] = (string) $score['criterionId'];
            }
        }

        foreach ($requiredCriterionIds as $criterionId) {
            if (in_array($criterionId, $scoredCriterionIds, true) === false) {
                return false;
            }
        }

        return true;

    }//end coversAllCriteria()

    /**
     * Check that a SelfAssessment's `learnerId` is a member of the linked
     * Submission's `learnerIds`.
     *
     * @param array<string,mixed> $object The SelfAssessment data array.
     *
     * @return bool True when learnerId is a member (or submissionId/learnerId is
     *              missing and there is nothing to check against — fails closed
     *              instead: an unresolvable Submission denies the transition).
     *
     * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-self-assessment-lets-a-learner-score-their-own-submission-against-the-assignments-rubric
     */
    private function isLearnerOnSubmission(array $object): bool
    {
        $submissionId = $object['submissionId'] ?? null;
        $learnerId    = $object['learnerId'] ?? null;

        if (is_string($submissionId) === false || $submissionId === ''
            || is_string($learnerId) === false || $learnerId === ''
        ) {
            return false;
        }

        $submission = $this->fetchObject(id: $submissionId, schema: self::SUBMISSION_SCHEMA);
        if ($submission === null) {
            return false;
        }

        $learnerIds = $submission['learnerIds'] ?? [];
        if (is_array($learnerIds) === false) {
            return false;
        }

        return in_array($learnerId, $learnerIds, true) === true;

    }//end isLearnerOnSubmission()

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

        if (is_array($obj) === true) {
            return $obj;
        }

        return $obj->jsonSerialize();

    }//end fetchObject()
}//end class
