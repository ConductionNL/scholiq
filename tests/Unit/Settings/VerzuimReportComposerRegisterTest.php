<?php

/**
 * Unit tests for the `verzuim-report-composer` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` and
 * `x-openregister-aggregations` are evaluated by OpenRegister core at
 * runtime, which does not live in this repository (only test stubs for its
 * PHP service classes do — see composer.json's `autoload-dev`). Scholiq
 * cannot unit-test the numeric OUTPUT of a declared calculation/aggregation
 * (no existing test in this suite does — see e.g. Course.isPublished,
 * Regulation.ragStatus, which carry zero runtime-value tests). What Scholiq
 * CAN and MUST verify is that the declared SHAPE is correct: the right
 * fields exist, reference the right source schema/metric, and the
 * two-step calc chain (aggregation -> calc -> calc) is wired the way
 * design.md specifies. This mirrors the established pattern in
 * ProcessingActivityCatalogueTest.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-5.2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the AttendanceFlag.interventions/schoolDaysSinceFlag/
 * reportDeadlineAt/reportOverdue and DataExchangeJob.municipalityFeedback
 * declarations, plus the recordMunicipalityFeedback guarded transition.
 */
class VerzuimReportComposerRegisterTest extends TestCase
{

    /**
     * Decoded register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Load the register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path         = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $this->config = json_decode((string) file_get_contents($path), true);

    }//end setUp()

    /**
     * AttendanceFlag.interventions is an append-only-friendly array of
     * attributed note entries, defaulting to empty.
     *
     * @return void
     */
    public function testAttendanceFlagInterventionsShape(): void
    {
        $flag = $this->config['components']['schemas']['AttendanceFlag'];

        self::assertTrue($flag['appendOnly']);

        $interventions = $flag['properties']['interventions'];
        self::assertSame('array', $interventions['type']);
        self::assertSame([], $interventions['default']);

        $itemProps = $interventions['items']['properties'];
        self::assertArrayHasKey('recordedBy', $itemProps);
        self::assertArrayHasKey('recordedAt', $itemProps);
        self::assertArrayHasKey('note', $itemProps);
        self::assertArrayHasKey('lifecycleAtRecording', $itemProps);
        self::assertSame(['recordedBy', 'recordedAt', 'note'], $interventions['items']['required']);

    }//end testAttendanceFlagInterventionsShape()

    /**
     * AttendanceFlag.schoolDaysSinceFlag is a count_distinct aggregation over
     * Session.sessionDayBucket, tenant-scoped and windowEnd-bounded.
     *
     * @return void
     */
    public function testSchoolDaysSinceFlagAggregationShape(): void
    {
        $flag = $this->config['components']['schemas']['AttendanceFlag'];
        $agg  = $flag['x-openregister-aggregations']['schoolDaysSinceFlag'];

        self::assertSame('session', $agg['from']);
        self::assertSame('count_distinct', $agg['metric']);
        self::assertSame('sessionDayBucket', $agg['field']);
        self::assertSame('@self.tenant_id', $agg['where']['tenant_id']);
        self::assertSame('@self.windowEnd', $agg['where']['startsAt']['gte']);

    }//end testSchoolDaysSinceFlagAggregationShape()

    /**
     * Session.sessionDayBucket is a materialised, deterministic day-bucket
     * derived from startsAt (the date-truncation workaround per task 1.2).
     *
     * @return void
     */
    public function testSessionDayBucketCalculationShape(): void
    {
        $session = $this->config['components']['schemas']['Session'];
        $calc    = $session['x-openregister-calculations']['sessionDayBucket'];

        self::assertTrue($calc['materialise']);
        self::assertSame('integer', $calc['type']);
        self::assertSame('startsAt', $calc['expression']['dateDiff']['to']['prop']);
        self::assertSame('days', $calc['expression']['dateDiff']['unit']);

    }//end testSessionDayBucketCalculationShape()

    /**
     * AttendanceFlag.reportOverdue is a two-step calc referencing
     * schoolDaysSinceFlag (by plain prop, mirroring Regulation.ragStatus
     * referencing coveragePercent) and gates on lifecycle NOT IN
     * (reported, resolved) — never on the AttendanceFlagReportGuard's own
     * transition, so it cannot alter the report guard.
     *
     * @return void
     */
    public function testReportOverdueCalculationShape(): void
    {
        $flag = $this->config['components']['schemas']['AttendanceFlag'];
        $calc = $flag['x-openregister-calculations']['reportOverdue'];

        self::assertTrue($calc['materialise']);
        self::assertSame('boolean', $calc['type']);

        $terms = $calc['expression']['and'];
        self::assertSame('schoolDaysSinceFlag', $terms[0]['gte'][0]['prop']);
        self::assertSame(5, $terms[0]['gte'][1]);
        self::assertSame('lifecycle', $terms[1]['ne'][0]['prop']);
        self::assertSame('reported', $terms[1]['ne'][1]);
        self::assertSame('lifecycle', $terms[2]['ne'][0]['prop']);
        self::assertSame('resolved', $terms[2]['ne'][1]);

    }//end testReportOverdueCalculationShape()

    /**
     * AttendanceFlag.reportDeadlineAt is a materialised nullable date derived
     * from windowEnd.
     *
     * @return void
     */
    public function testReportDeadlineAtCalculationShape(): void
    {
        $flag = $this->config['components']['schemas']['AttendanceFlag'];
        $calc = $flag['x-openregister-calculations']['reportDeadlineAt'];

        self::assertTrue($calc['materialise']);
        self::assertTrue($calc['nullable']);
        self::assertSame('date', $calc['format']);
        self::assertSame('windowEnd', $calc['expression']['dateAdd']['date']['prop']);

    }//end testReportDeadlineAtCalculationShape()

    /**
     * AttendanceFlag.reportDeadlineOverdue notification is a calculatedChange
     * trigger on reportOverdue flipping false -> true (idempotency via the
     * calculatedChange previously/condition mechanism, the same shape as
     * AttendanceThreshold.thresholdCrossed), additive to flagRaised.
     *
     * @return void
     */
    public function testReportDeadlineOverdueNotificationShape(): void
    {
        $flag          = $this->config['components']['schemas']['AttendanceFlag'];
        $notifications = $flag['x-openregister-notifications'];

        self::assertArrayHasKey('flagRaised', $notifications);

        $overdue = $notifications['reportDeadlineOverdue'];
        self::assertSame('calculatedChange', $overdue['trigger']['type']);
        self::assertSame('reportOverdue', $overdue['trigger']['field']);
        self::assertTrue($overdue['trigger']['condition']['eq']);
        self::assertFalse($overdue['trigger']['previously']['eq']);

    }//end testReportDeadlineOverdueNotificationShape()

    /**
     * DataExchangeJob.municipalityFeedback is a nullable object, defaulting
     * to null, carrying masRoute/receivedAt/note/recordedBy.
     *
     * @return void
     */
    public function testMunicipalityFeedbackPropertyShape(): void
    {
        $job      = $this->config['components']['schemas']['DataExchangeJob'];
        $feedback = $job['properties']['municipalityFeedback'];

        self::assertTrue($feedback['nullable']);
        self::assertNull($feedback['default']);

        $props = $feedback['properties'];
        self::assertArrayHasKey('masRoute', $props);
        self::assertArrayHasKey('receivedAt', $props);
        self::assertArrayHasKey('note', $props);
        self::assertArrayHasKey('recordedBy', $props);

    }//end testMunicipalityFeedbackPropertyShape()

    /**
     * DataExchangeJob's recordMunicipalityFeedback transition is a
     * succeeded -> succeeded self-loop guarded by MunicipalityFeedbackGuard,
     * and the existing run/approveDossier/succeed transitions are unchanged.
     *
     * @return void
     */
    public function testRecordMunicipalityFeedbackTransitionShape(): void
    {
        $job         = $this->config['components']['schemas']['DataExchangeJob'];
        $transitions = $job['x-openregister-lifecycle']['transitions'];

        $recordFeedback = $transitions['recordMunicipalityFeedback'];
        self::assertSame('succeeded', $recordFeedback['from']);
        self::assertSame('succeeded', $recordFeedback['to']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\MunicipalityFeedbackGuard', $recordFeedback['requires']);

        // Existing chain untouched.
        self::assertSame('OCA\\Scholiq\\Lifecycle\\DataExchangeRunGuard', $transitions['run']['requires']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\OsoDossierReviewGuard', $transitions['approveDossier']['requires']);
        self::assertArrayNotHasKey('requires', $transitions['succeed']);

    }//end testRecordMunicipalityFeedbackTransitionShape()
}//end class
