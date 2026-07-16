<?php

/**
 * Scholiq Learning Record Aggregation Service
 *
 * Composes a learner's portable-learning-record trajectory LIVE, on read,
 * scoped by `learnerRef` — no materialized "LearningRecord" rollup schema is
 * persisted (design.md "Why no materialized rollup schema"). Reads across
 * nine already-`learnerRef`-scoped (or `learnerRef`-reachable) schemas:
 * Enrolment, FinalGrade, CompetencyAttainment, Credential, Portfolio +
 * PortfolioEntry, ExternalTrainingRecord (verified only), BpvPlacement +
 * WerkprocesAssessment, LessonCompletion (summarized per-course), and
 * ReportCard (published-to-parents only).
 *
 * Deliberately excludes DossierNote, BehaviourIncident, WellbeingCheckIn
 * (staff professional-judgment records about the learner, not evidence of
 * the learner's own learning) and AttendanceRecord (leerplicht/BRON
 * compliance spine, not portable "what I learned" evidence) — see
 * design.md "What the learner controls vs. what stays institutional".
 *
 * Legitimate PHP per ADR-031 "cross-schema read composition" — the same
 * exception category `data-exchange`'s OSO dossier composer already
 * exercises, learner-initiated instead of institution-initiated.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads a learner's trajectory live, across nine schemas, scoped by
 * `learnerRef`. No persistence — a pure read composition.
 *
 * @spec openspec/changes/portable-learning-record/tasks.md#task-2-1
 */
class LearningRecordAggregationService
{

    private const REGISTER = 'scholiq';

    private const SCHEMA_LEARNER_PROFILE = 'learner-profile';
    private const SCHEMA_ENROLMENT       = 'enrolment';
    private const SCHEMA_FINAL_GRADE     = 'final-grade';
    private const SCHEMA_COMPETENCY_ATTAINMENT = 'competency-attainment';
    private const SCHEMA_CREDENTIAL            = 'credential';
    private const SCHEMA_PORTFOLIO       = 'portfolio';
    private const SCHEMA_PORTFOLIO_ENTRY = 'portfolio-entry';
    private const SCHEMA_EXTERNAL_TRAINING_RECORD = 'external-training-record';
    private const SCHEMA_BPV_PLACEMENT            = 'bpv-placement';
    private const SCHEMA_WERKPROCES_ASSESSMENT    = 'werkproces-assessment';
    private const SCHEMA_LESSON_COMPLETION        = 'lesson-completion';
    private const SCHEMA_REPORT_CARD = 'report-card';

    /**
     * ReportCard's real x-openregister-lifecycle enum value for "visible in
     * the parent portal" — verified at HEAD (scholiq_register.json's
     * ReportCard.lifecycle enum). NOT the literal string "published" the
     * design/spec prose uses as shorthand.
     */
    private const REPORT_CARD_PUBLISHED_LIFECYCLE = 'published-to-parents';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OR object query service.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Resolve the LearnerProfile UUID (learnerRef) for a Nextcloud user id.
     *
     * @param string $ncUserId Nextcloud user id of the caller.
     *
     * @return string|null The LearnerProfile UUID, or null when no LearnerProfile is bound to this user.
     *
     * @spec openspec/changes/portable-learning-record/tasks.md#task-2-2
     */
    public function resolveLearnerRefForUser(string $ncUserId): ?string
    {
        if ($ncUserId === '') {
            return null;
        }

        $rows = $this->objectService->findAll(
            [
                'register' => self::REGISTER,
                'schema'   => self::SCHEMA_LEARNER_PROFILE,
                'filters'  => ['ncUserId' => $ncUserId],
                'limit'    => 1,
            ]
        );

        if (empty($rows) === true) {
            return null;
        }

        $profile = $this->toArray(row: $rows[0]);

        $id = $profile['id'] ?? ($profile['uuid'] ?? null);
        if (is_string($id) === false || $id === '') {
            return null;
        }

        return $id;
    }//end resolveLearnerRefForUser()

    /**
     * Compose a learner's whole trajectory, live, scoped by `learnerRef`.
     *
     * @param string $learnerRef UUID of the LearnerProfile whose trajectory to compose.
     *
     * @return array<string,mixed> Keyed by collection name — see the class docblock for the nine
     *                              schemas composed. Every value is a list of plain associative arrays.
     *
     * @spec openspec/changes/portable-learning-record/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
     */
    public function compose(string $learnerRef): array
    {
        $enrolments            = $this->findAllByLearnerRef(schema: self::SCHEMA_ENROLMENT, learnerRef: $learnerRef);
        $finalGrades           = $this->findAllByLearnerRef(schema: self::SCHEMA_FINAL_GRADE, learnerRef: $learnerRef);
        $competencyAttainments = $this->findAllByLearnerRef(schema: self::SCHEMA_COMPETENCY_ATTAINMENT, learnerRef: $learnerRef);

        // Credential has no learnerRef field of its own — its existing
        // `learnerId` property is already typed as a LearnerProfile UUID
        // ($ref: LearnerProfile), a pre-existing naming quirk verified at
        // HEAD (scholiq_register.json:223 region). Filter by that field.
        $credentials = $this->findAll(schema: self::SCHEMA_CREDENTIAL, filters: ['learnerId' => $learnerRef]);

        $portfolios       = $this->findAllByLearnerRef(schema: self::SCHEMA_PORTFOLIO, learnerRef: $learnerRef);
        $portfolioEntries = $this->resolvePortfolioEntries(portfolios: $portfolios);

        $externalTrainingRecords = $this->findVerifiedExternalTrainingRecords(learnerRef: $learnerRef);

        $bpvPlacements         = $this->findAllByLearnerRef(schema: self::SCHEMA_BPV_PLACEMENT, learnerRef: $learnerRef);
        $werkprocesAssessments = $this->resolveWerkprocesAssessments(bpvPlacements: $bpvPlacements);

        $lessonCompletions       = $this->findAllByLearnerRef(schema: self::SCHEMA_LESSON_COMPLETION, learnerRef: $learnerRef);
        $lessonCompletionSummary = $this->summariseLessonCompletions(lessonCompletions: $lessonCompletions, enrolments: $enrolments);

        $reportCards = $this->findPublishedReportCards(learnerRef: $learnerRef);

        return [
            'enrolments'              => $enrolments,
            'finalGrades'             => $finalGrades,
            'competencyAttainments'   => $competencyAttainments,
            'credentials'             => $credentials,
            'portfolios'              => $portfolios,
            'portfolioEntries'        => $portfolioEntries,
            'externalTrainingRecords' => $externalTrainingRecords,
            'bpvPlacements'           => $bpvPlacements,
            'werkprocesAssessments'   => $werkprocesAssessments,
            'lessonCompletions'       => $lessonCompletionSummary,
            'reportCards'             => $reportCards,
        ];
    }//end compose()

    /**
     * Find every row of a `learnerRef`-scoped schema for one learner.
     *
     * @param string $schema     Schema slug.
     * @param string $learnerRef LearnerProfile UUID.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findAllByLearnerRef(string $schema, string $learnerRef): array
    {
        return $this->findAll(schema: $schema, filters: ['learnerRef' => $learnerRef]);
    }//end findAllByLearnerRef()

    /**
     * Find every row of a schema matching arbitrary filters, normalised to plain arrays.
     *
     * @param string              $schema  Schema slug.
     * @param array<string,mixed> $filters OR findAll() filter map.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findAll(string $schema, array $filters): array
    {
        $rows = $this->objectService->findAll(
            [
                'register' => self::REGISTER,
                'schema'   => $schema,
                'filters'  => $filters,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->toArray(row: $row);
        }

        return $result;
    }//end findAll()

    /**
     * `ExternalTrainingRecord` rows for this learner, `verified: true` only —
     * unverified claims are never exported as fact.
     *
     * @param string $learnerRef LearnerProfile UUID.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findVerifiedExternalTrainingRecords(string $learnerRef): array
    {
        return $this->findAll(
            schema: self::SCHEMA_EXTERNAL_TRAINING_RECORD,
            filters: [
                'learnerRef' => $learnerRef,
                'lifecycle'  => 'verified',
            ]
        );
    }//end findVerifiedExternalTrainingRecords()

    /**
     * `ReportCard` rows for this learner, `lifecycle: published-to-parents`
     * only — respects the existing visibleFrom/publication gating rather
     * than exposing a draft/under-review report.
     *
     * @param string $learnerRef LearnerProfile UUID.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findPublishedReportCards(string $learnerRef): array
    {
        return $this->findAll(
            schema: self::SCHEMA_REPORT_CARD,
            filters: [
                'learnerRef' => $learnerRef,
                'lifecycle'  => self::REPORT_CARD_PUBLISHED_LIFECYCLE,
            ]
        );
    }//end findPublishedReportCards()

    /**
     * `PortfolioEntry` rows for every resolved Portfolio — PortfolioEntry
     * carries no `learnerRef`/`learnerId`-as-LearnerProfile field of its
     * own, only a denormalized NC-user-id `learnerId` and its parent
     * `portfolioId`, so entries are resolved per already-resolved Portfolio.
     *
     * @param array<int,array<string,mixed>> $portfolios Already-resolved Portfolio rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolvePortfolioEntries(array $portfolios): array
    {
        $entries = [];
        foreach ($portfolios as $portfolio) {
            $portfolioId = $portfolio['id'] ?? ($portfolio['uuid'] ?? null);
            if (is_string($portfolioId) === false || $portfolioId === '') {
                continue;
            }

            foreach ($this->findAll(schema: self::SCHEMA_PORTFOLIO_ENTRY, filters: ['portfolioId' => $portfolioId]) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }//end resolvePortfolioEntries()

    /**
     * `WerkprocesAssessment` rows for every resolved BpvPlacement —
     * WerkprocesAssessment carries no learner field of its own, only its
     * parent `bpvPlacementId`.
     *
     * @param array<int,array<string,mixed>> $bpvPlacements Already-resolved BpvPlacement rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolveWerkprocesAssessments(array $bpvPlacements): array
    {
        $assessments = [];
        foreach ($bpvPlacements as $placement) {
            $placementId = $placement['id'] ?? ($placement['uuid'] ?? null);
            if (is_string($placementId) === false || $placementId === '') {
                continue;
            }

            foreach ($this->findAll(schema: self::SCHEMA_WERKPROCES_ASSESSMENT, filters: ['bpvPlacementId' => $placementId]) as $assessment) {
                $assessments[] = $assessment;
            }
        }

        return $assessments;
    }//end resolveWerkprocesAssessments()

    /**
     * Summarise `LessonCompletion` rows per-course (count + percentage),
     * never the raw per-lesson log — the export/aggregate view exposes "89%
     * of Course X completed", not every individual lesson timestamp.
     * `percentage` reuses `Enrolment.progressPercent` (already computed by
     * `EnrolmentProgressRollupHandler`) for the matching course when an
     * Enrolment is present, rather than re-deriving it.
     *
     * @param array<int,array<string,mixed>> $lessonCompletions Raw LessonCompletion rows for this learner.
     * @param array<int,array<string,mixed>> $enrolments        Already-resolved Enrolment rows for this learner.
     *
     * @return array<int,array{courseId:string|null,completedCount:int,percentage:float|null}>
     */
    private function summariseLessonCompletions(array $lessonCompletions, array $enrolments): array
    {
        $percentageByCourseId = [];
        foreach ($enrolments as $enrolment) {
            $courseId = $enrolment['courseId'] ?? null;
            if (is_string($courseId) === false || $courseId === '') {
                continue;
            }

            $percentageByCourseId[$courseId] = $enrolment['progressPercent'] ?? null;
        }

        $countByCourseId = [];
        foreach ($lessonCompletions as $completion) {
            $courseId = $completion['courseId'] ?? null;
            if (is_string($courseId) === false || $courseId === '') {
                $courseId = null;
            }

            $key = $courseId ?? '';
            if (isset($countByCourseId[$key]) === false) {
                $countByCourseId[$key] = ['courseId' => $courseId, 'completedCount' => 0];
            }

            $countByCourseId[$key]['completedCount']++;
        }

        $summary = [];
        foreach ($countByCourseId as $row) {
            $courseId   = $row['courseId'];
            $percentage = null;
            if ($courseId !== null && isset($percentageByCourseId[$courseId]) === true) {
                $percentage = $percentageByCourseId[$courseId];
            }

            $summary[] = [
                'courseId'       => $courseId,
                'completedCount' => $row['completedCount'],
                'percentage'     => $percentage,
            ];
        }

        return $summary;
    }//end summariseLessonCompletions()

    /**
     * Normalise an OR `findAll()`/`find()` result row (a raw array or an
     * ObjectEntity-like object) to a plain associative array.
     *
     * @param mixed $row Raw row from ObjectService.
     *
     * @return array<string,mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialized = $row->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()
}//end class
