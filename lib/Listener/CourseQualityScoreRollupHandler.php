<?php

/**
 * Scholiq Course Quality Score Rollup Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to the
 * CourseEvaluationResponse schema's `submit` transition. Find-or-creates the
 * matching CourseQualityScore row for (courseId, teacherId, academicYear,
 * period), invokes CourseQualityScoreEvaluator, and persists the recomputed
 * responseCount/invitationCount/averageOverallScore/responseRate/
 * lastRecomputedAt.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration." Mirrors
 * GradeRollupHandler's find-or-create-and-recompute shape exactly
 * (FinalGrade / GradeFormulaEvaluator). NOT a TimedJob (ADR-022).
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-course-teacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\CourseEvaluation\CourseQualityScoreEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Bridges CourseEvaluationResponse `submit` → CourseQualityScore find-or-create + recompute.
 *
 * @implements IEventListener<Event>
 */
class CourseQualityScoreRollupHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const COURSE_EVALUATION_RESPONSE_SCHEMA = 'course-evaluation-response';
    private const COURSE_QUALITY_SCORE_SCHEMA       = 'course-quality-score';

    /**
     * Constructor.
     *
     * @param ObjectService               $objectService OpenRegister object access.
     * @param CourseQualityScoreEvaluator $evaluator     Calculation engine.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly CourseQualityScoreEvaluator $evaluator,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER
            || $event->getSchema() !== self::COURSE_EVALUATION_RESPONSE_SCHEMA
            || $event->getTo() !== 'submitted'
        ) {
            return;
        }

        $response = $event->getObject()->jsonSerialize();

        $courseId     = $response['courseId'] ?? '';
        $academicYear = $response['academicYear'] ?? '';
        $period       = $response['period'] ?? '';

        if ($courseId === '' || $academicYear === '' || $period === '') {
            return;
        }

        $teacherId = $response['teacherId'] ?? null;
        if ($teacherId === '') {
            $teacherId = null;
        }

        $tenantId = $response['tenant_id'] ?? '';

        $result = $this->evaluator->evaluate(
            courseId: $courseId,
            teacherId: $teacherId,
            academicYear: $academicYear,
            period: $period
        );

        $existing = $this->findExisting(
            courseId: $courseId,
            teacherId: $teacherId,
            academicYear: $academicYear,
            period: $period
        );

        $data = array_merge(
            $existing ?? [],
            [
                'courseId'            => $courseId,
                'teacherId'           => $teacherId,
                'academicYear'        => $academicYear,
                'period'              => $period,
                'responseCount'       => $result['responseCount'],
                'invitationCount'     => $result['invitationCount'],
                'averageOverallScore' => $result['averageOverallScore'],
                'responseRate'        => $result['responseRate'],
                'lastRecomputedAt'    => $result['lastRecomputedAt'],
                'tenant_id'           => $tenantId,
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::COURSE_QUALITY_SCORE_SCHEMA,
            object: $data
        );

    }//end handle()

    /**
     * Find the existing CourseQualityScore row for this scope, if any.
     *
     * @param string      $courseId     Course UUID.
     * @param string|null $teacherId    Teacher NC user id, or null.
     * @param string      $academicYear Academic year.
     * @param string      $period       Period label.
     *
     * @return array|null
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#scenario-a-new-response-recomputes-the-course-s-quality-score
     */
    private function findExisting(
        string $courseId,
        ?string $teacherId,
        string $academicYear,
        string $period,
    ): ?array {
        $filters = [
            'courseId'     => $courseId,
            'academicYear' => $academicYear,
            'period'       => $period,
        ];

        // OR's filter matching treats a missing key as "any" rather than
        // "null" for some drivers; an explicit null is still passed through
        // so a course-level (teacherId:null) row is not confused with a
        // teacher-scoped one.
        $filters['teacherId'] = $teacherId;

        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::COURSE_QUALITY_SCORE_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            return null;
        }

        $existingObj = $existing[0];
        if (is_array($existingObj) === false) {
            $existingObj = $existingObj->jsonSerialize();
        }

        return $existingObj;

    }//end findExisting()
}//end class
