<?php

/**
 * Unit tests for LearningRecordAggregationService.
 *
 * Verifies the nine-schema live composition scoped by learnerRef, the
 * LessonCompletion per-course summarisation (never raw rows), and that
 * DossierNote/BehaviourIncident/WellbeingCheckIn/AttendanceRecord/raw
 * GradeEntry never appear in the composed result.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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
 * @spec openspec/changes/portable-learning-record/tasks.md#task-6-1
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\LearningRecordAggregationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearningRecordAggregationService::compose()/resolveLearnerRefForUser().
 */
class LearningRecordAggregationServiceTest extends TestCase
{

    private const LEARNER_REF = 'learner-ref-uuid-1';

    /**
     * ObjectService mock, dispatching findAll() by schema.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The service under test.
     *
     * @var LearningRecordAggregationService
     */
    private LearningRecordAggregationService $service;

    /**
     * Per-schema fixture rows, keyed by schema slug, consumed by the
     * ObjectService::findAll() mock's willReturnCallback().
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $rowsBySchema = [];

    /**
     * Set up the service under test with a schema-dispatching findAll() mock.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->rowsBySchema = [];

        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];
                $rows    = $this->rowsBySchema[$schema] ?? [];

                foreach ($filters as $field => $value) {
                    $rows = array_values(
                        array_filter(
                            $rows,
                            static fn (array $row) => ($row[$field] ?? null) === $value
                        )
                    );
                }

                return $rows;
            }
        );

        $this->service = new LearningRecordAggregationService(objectService: $this->objectService);
    }//end setUp()

    /**
     * All nine in-scope collections compose correctly per learner, including
     * the cross-schema joins (PortfolioEntry via portfolioId,
     * WerkprocesAssessment via bpvPlacementId) and the Credential
     * learnerId-as-LearnerProfile-UUID quirk.
     *
     * @return void
     */
    public function testComposesAllNineInScopeSchemasForOneLearner(): void
    {
        $this->rowsBySchema = [
            'enrolment'               => [['id' => 'e1', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-1', 'progressPercent' => 80.0]],
            'final-grade'              => [['id' => 'fg1', 'learnerRef' => self::LEARNER_REF]],
            'competency-attainment'    => [['id' => 'ca1', 'learnerRef' => self::LEARNER_REF]],
            'credential'               => [['id' => 'c1', 'learnerId' => self::LEARNER_REF, 'kind' => 'diploma']],
            'portfolio'                => [['id' => 'p1', 'learnerRef' => self::LEARNER_REF, 'title' => 'My portfolio']],
            'portfolio-entry'          => [['id' => 'pe1', 'portfolioId' => 'p1', 'title' => 'Entry 1']],
            'external-training-record' => [['id' => 'et1', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'verified', 'title' => 'NIS2 training']],
            'bpv-placement'            => [['id' => 'bpv1', 'learnerRef' => self::LEARNER_REF, 'leerbedrijfName' => 'Bakkerij De Vries']],
            'werkproces-assessment'    => [['id' => 'wpa1', 'bpvPlacementId' => 'bpv1', 'werkprocesLabel' => 'Bakken']],
            'lesson-completion'        => [['id' => 'lc1', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-1']],
            'report-card'              => [['id' => 'rc1', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'published-to-parents']],
        ];

        $composition = $this->service->compose(learnerRef: self::LEARNER_REF);

        self::assertCount(1, $composition['enrolments']);
        self::assertCount(1, $composition['finalGrades']);
        self::assertCount(1, $composition['competencyAttainments']);
        self::assertCount(1, $composition['credentials']);
        self::assertSame('c1', $composition['credentials'][0]['id']);
        self::assertCount(1, $composition['portfolios']);
        self::assertCount(1, $composition['portfolioEntries']);
        self::assertSame('pe1', $composition['portfolioEntries'][0]['id']);
        self::assertCount(1, $composition['externalTrainingRecords']);
        self::assertCount(1, $composition['bpvPlacements']);
        self::assertCount(1, $composition['werkprocesAssessments']);
        self::assertSame('wpa1', $composition['werkprocesAssessments'][0]['id']);
        self::assertCount(1, $composition['reportCards']);
    }//end testComposesAllNineInScopeSchemasForOneLearner()

    /**
     * LessonCompletion rows summarise to per-course counts (+ percentage
     * from the matching Enrolment.progressPercent), never raw rows.
     *
     * @return void
     */
    public function testLessonCompletionSummarisesToPerCourseCounts(): void
    {
        $this->rowsBySchema = [
            'enrolment'         => [['id' => 'e1', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-1', 'progressPercent' => 66.7]],
            'lesson-completion' => [
                ['id' => 'lc1', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-1'],
                ['id' => 'lc2', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-1'],
                ['id' => 'lc3', 'learnerRef' => self::LEARNER_REF, 'courseId' => 'course-2'],
            ],
        ];

        $composition = $this->service->compose(learnerRef: self::LEARNER_REF);

        $summary = $composition['lessonCompletions'];
        self::assertCount(2, $summary, 'Exactly one summary row per distinct course, never raw per-lesson rows.');

        $byCourse = [];
        foreach ($summary as $row) {
            $byCourse[$row['courseId']] = $row;
        }

        self::assertSame(2, $byCourse['course-1']['completedCount']);
        self::assertSame(66.7, $byCourse['course-1']['percentage']);
        self::assertSame(1, $byCourse['course-2']['completedCount']);
        self::assertNull($byCourse['course-2']['percentage'], 'No matching Enrolment for course-2 — percentage stays null, not guessed.');
    }//end testLessonCompletionSummarisesToPerCourseCounts()

    /**
     * ExternalTrainingRecord only surfaces `verified: true` rows — an
     * unverified claim is never exported as fact.
     *
     * @return void
     */
    public function testOnlyVerifiedExternalTrainingRecordsAreIncluded(): void
    {
        $this->rowsBySchema = [
            'external-training-record' => [
                ['id' => 'et-verified', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'verified'],
                ['id' => 'et-submitted', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'submitted'],
            ],
        ];

        $composition = $this->service->compose(learnerRef: self::LEARNER_REF);

        self::assertCount(1, $composition['externalTrainingRecords']);
        self::assertSame('et-verified', $composition['externalTrainingRecords'][0]['id']);
    }//end testOnlyVerifiedExternalTrainingRecordsAreIncluded()

    /**
     * ReportCard only surfaces `lifecycle: published-to-parents` rows.
     *
     * @return void
     */
    public function testOnlyPublishedReportCardsAreIncluded(): void
    {
        $this->rowsBySchema = [
            'report-card' => [
                ['id' => 'rc-draft', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'draft'],
                ['id' => 'rc-published', 'learnerRef' => self::LEARNER_REF, 'lifecycle' => 'published-to-parents'],
            ],
        ];

        $composition = $this->service->compose(learnerRef: self::LEARNER_REF);

        self::assertCount(1, $composition['reportCards']);
        self::assertSame('rc-published', $composition['reportCards'][0]['id']);
    }//end testOnlyPublishedReportCardsAreIncluded()

    /**
     * Staff-judgment/compliance-only schemas (DossierNote, BehaviourIncident,
     * WellbeingCheckIn, AttendanceRecord) and raw GradeEntry never appear in
     * the composed result — they are simply never queried by
     * LearningRecordAggregationService.
     *
     * @return void
     */
    public function testExcludesStaffJudgmentAndAttendanceRecords(): void
    {
        $this->rowsBySchema = [
            'dossier-note'        => [['id' => 'dn1', 'learnerId' => 'nc-uid-1']],
            'behaviour-incident'  => [['id' => 'bi1', 'learnerId' => 'nc-uid-1']],
            'wellbeing-check-in'  => [['id' => 'wc1', 'learnerId' => 'nc-uid-1']],
            'attendance-record'   => [['id' => 'ar1', 'learnerId' => 'nc-uid-1']],
            'grade-entry'         => [['id' => 'ge1', 'learnerId' => 'nc-uid-1']],
        ];

        $composition = $this->service->compose(learnerRef: self::LEARNER_REF);

        $encoded = json_encode($composition);
        self::assertStringNotContainsString('dn1', (string) $encoded);
        self::assertStringNotContainsString('bi1', (string) $encoded);
        self::assertStringNotContainsString('wc1', (string) $encoded);
        self::assertStringNotContainsString('ar1', (string) $encoded);
        self::assertStringNotContainsString('ge1', (string) $encoded);
    }//end testExcludesStaffJudgmentAndAttendanceRecords()

    /**
     * resolveLearnerRefForUser() resolves the LearnerProfile whose
     * ncUserId matches the caller — the RBAC-gap-closing self-scoping key.
     *
     * @return void
     */
    public function testResolveLearnerRefForUserResolvesOwnLearnerProfile(): void
    {
        $this->rowsBySchema = [
            'learner-profile' => [['id' => self::LEARNER_REF, 'ncUserId' => 'anna']],
        ];

        $learnerRef = $this->service->resolveLearnerRefForUser(ncUserId: 'anna');

        self::assertSame(self::LEARNER_REF, $learnerRef);
    }//end testResolveLearnerRefForUserResolvesOwnLearnerProfile()

    /**
     * resolveLearnerRefForUser() returns null when no LearnerProfile is bound.
     *
     * @return void
     */
    public function testResolveLearnerRefForUserReturnsNullWhenUnbound(): void
    {
        $this->rowsBySchema = ['learner-profile' => []];

        self::assertNull($this->service->resolveLearnerRefForUser(ncUserId: 'nobody'));
    }//end testResolveLearnerRefForUserReturnsNullWhenUnbound()
}//end class
