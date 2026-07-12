<?php

/**
 * Scholiq BSA Progress Evaluator
 *
 * Stateless calculation engine that sums a learner's cumulative ECTS credits
 * earned within a Programme's scope: resolves each of the learner's
 * `passed: true` FinalGrades to its Course.ectsCredits (treating a null
 * ectsCredits as 0) and sums them.
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * Resolving FinalGrade -> Course.ectsCredits is a cross-schema join the pure
 * JSON-logic `sum` operator cannot express (it needs to resolve each entry's
 * courseId against a second schema) — the same rationale already accepted for
 * GradeFormulaEvaluator on FinalGrade.value. Single responsibility: sum and
 * return; no state, no audit writes.
 *
 * BsaTrajectory is a shared per-(programmeId, academicYear) rule row, not a
 * per-learner record (mirrors AttendanceThreshold), so a learner's ectsEarned
 * cannot be materialised as a scalar property on that row — it is computed
 * here, per learner, and consumed by BsaProgressFlagHandler.
 *
 * Consumed by:
 *   - BsaProgressFlagHandler (via ObjectTransitionedEvent on GradeEntry.published)
 *
 * @category StudyProgress
 * @package  OCA\Scholiq\StudyProgress
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\StudyProgress;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Sums a learner's earned ECTS credits within a Programme's course scope.
 */
class BsaProgressEvaluator
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const FINAL_GRADE_SCHEMA = 'final-grade';
    private const COURSE_SCHEMA      = 'course';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object access.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Compute a learner's ectsEarned within a Programme's course scope.
     *
     * Fetches every Course belonging to the Programme (via
     * Course.programmeIds), then every `passed: true` FinalGrade for the
     * learner, and sums the ectsCredits of the courses referenced by those
     * FinalGrades. A FinalGrade whose courseId is not in the Programme's
     * course list (e.g. a programme-level FinalGrade with no courseId) does
     * not contribute. A null Course.ectsCredits contributes 0, never an
     * error.
     *
     * @param string $programmeId UUID of the Programme to scope courses to.
     * @param string $learnerId   Nextcloud user ID of the learner.
     *
     * @return array{ectsEarned: float}
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    public function evaluate(string $programmeId, string $learnerId): array
    {
        if ($programmeId === '' || $learnerId === '') {
            return ['ectsEarned' => 0.0];
        }

        $ectsByCourseId = $this->fetchCourseCredits(programmeId: $programmeId);

        if (empty($ectsByCourseId) === true) {
            return ['ectsEarned' => 0.0];
        }

        $passedGrades = $this->fetchPassedFinalGrades(learnerId: $learnerId);

        $total = 0.0;
        foreach ($passedGrades as $grade) {
            $courseId = $grade['courseId'] ?? null;
            if ($courseId === null || array_key_exists($courseId, $ectsByCourseId) === false) {
                continue;
            }

            $total += $ectsByCourseId[$courseId];
        }

        return ['ectsEarned' => $total];

    }//end evaluate()

    /**
     * Fetch every Course in the Programme's scope, keyed by id -> ectsCredits
     * (null treated as 0).
     *
     * @param string $programmeId UUID of the Programme.
     *
     * @return array<string, float>
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    private function fetchCourseCredits(string $programmeId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COURSE_SCHEMA,
                'filters'  => ['programmeIds' => $programmeId],
            ]
        );

        $byId = [];
        foreach ($results as $course) {
            if (is_array($course) === false) {
                $course = $course->jsonSerialize();
            }

            $id = $course['id'] ?? ($course['uuid'] ?? null);
            if ($id === null) {
                continue;
            }

            $ectsCredits = $course['ectsCredits'] ?? null;
            $byId[$id]   = $ectsCredits === null ? 0.0 : (float) $ectsCredits;
        }

        return $byId;

    }//end fetchCourseCredits()

    /**
     * Fetch every `passed: true` FinalGrade for the learner.
     *
     * @param string $learnerId Nextcloud user ID of the learner.
     *
     * @return array<int, array>
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-credit-earned-and-at-risk-detection-are-declared-calculations-not-a-timedjob
     */
    private function fetchPassedFinalGrades(string $learnerId): array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::FINAL_GRADE_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'passed'    => true,
                ],
            ]
        );

        return array_map(
            static function ($grade) {
                if (is_array($grade) === true) {
                    return $grade;
                }

                return $grade->jsonSerialize();
            },
            $results
        );

    }//end fetchPassedFinalGrades()
}//end class
