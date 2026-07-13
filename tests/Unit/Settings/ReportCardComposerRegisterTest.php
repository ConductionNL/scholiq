<?php

/**
 * Unit tests for the `report-card-composer` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` is evaluated by
 * OpenRegister core at runtime, which does not live in this repository. This
 * test verifies the declared SHAPE is correct — schemas exist, lifecycle
 * transitions/guards are wired, the isLocked calculation expression matches
 * ConferenceRound.isBookingClosed's lt/now idiom, and GradeEntry.publish/
 * republish's `requires` was swapped to ReportPeriodLockGuard — mirroring
 * VerzuimReportComposerRegisterTest's established pattern.
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
 * @spec openspec/changes/report-card-composer/tasks.md#task-10.4
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ReportPeriod/ReportCard/ReportCardParentNotification schema
 * declarations plus the GradeEntry.publish/republish ReportPeriodLockGuard wiring.
 */
class ReportCardComposerRegisterTest extends TestCase
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
     * ReportPeriod, ReportCard, and ReportCardParentNotification are all
     * registered schemas.
     *
     * @return void
     */
    public function testAllThreeSchemasAreRegistered(): void
    {
        $schemas = $this->config['components']['schemas'];

        self::assertArrayHasKey('ReportPeriod', $schemas);
        self::assertArrayHasKey('ReportCard', $schemas);
        self::assertArrayHasKey('ReportCardParentNotification', $schemas);

    }//end testAllThreeSchemasAreRegistered()

    /**
     * ReportPeriod's isLocked calculation is materialised and matches the
     * `lockDate` set AND passed `@now` shape — mirroring
     * ConferenceRound.isBookingClosed's lt/now idiom.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-lock-date-is-enforced-by-a-materialised-calculation-and-guards-not-an-automatic-transition
     */
    public function testReportPeriodIsLockedCalculationShape(): void
    {
        $period = $this->config['components']['schemas']['ReportPeriod'];

        $isLocked = $period['x-openregister-calculations']['isLocked'];
        self::assertTrue($isLocked['materialise']);
        self::assertSame('boolean', $isLocked['type']);

        $expression = $isLocked['expression'];
        self::assertArrayHasKey('and', $expression);
        // { ne: [ {prop: lockDate}, null ] }
        self::assertSame('lockDate', $expression['and'][0]['ne'][0]['prop']);
        self::assertNull($expression['and'][0]['ne'][1]);
        // { lt: [ {prop: lockDate}, {now: []} ] }
        self::assertSame('lockDate', $expression['and'][1]['lt'][0]['prop']);
        self::assertArrayHasKey('now', $expression['and'][1]['lt'][1]);

    }//end testReportPeriodIsLockedCalculationShape()

    /**
     * ReportPeriod declares NO automatic lifecycle transition driven by the
     * lockDatePassed scheduled trigger — the trigger fires a notification
     * only, `lifecycle` stays `open`.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-reminder-notification-fires-once-the-lock-date-passes-without-auto-transitioning
     */
    public function testReportPeriodLockDatePassedIsScheduledNotificationOnly(): void
    {
        $period = $this->config['components']['schemas']['ReportPeriod'];

        $trigger = $period['x-openregister-notifications']['lockDatePassed']['trigger'];
        self::assertSame('scheduled', $trigger['type']);
        self::assertSame(86400, $trigger['intervalSec']);
        self::assertSame('open', $trigger['filter']['lifecycle']);

        $transitions = $period['x-openregister-lifecycle']['transitions'];
        // No transition targets a "locked" state driven by this trigger —
        // only compose (open->composed, requires ReportPeriodComposeGuard) and
        // archive (composed->archived) exist.
        self::assertSame(['compose', 'archive'], array_keys($transitions));
        self::assertSame('open', $transitions['compose']['from']);
        self::assertSame('composed', $transitions['compose']['to']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportPeriodComposeGuard', $transitions['compose']['requires']);

    }//end testReportPeriodLockDatePassedIsScheduledNotificationOnly()

    /**
     * ReportCard's full lifecycle transition table: pullIntoReview (no
     * guard), finalise/reopen/publishToParents (each guarded),
     * recompose/renderToPdf/rerenderToPdf self-loops.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-the-rapportvergadering-review-lifecycle-gates-parent-visibility-behind-a-finalise-step
     */
    public function testReportCardLifecycleTransitions(): void
    {
        $transitions = $this->config['components']['schemas']['ReportCard']['x-openregister-lifecycle']['transitions'];

        self::assertSame(['draft', 'rapportvergadering-review'], [$transitions['pullIntoReview']['from'], $transitions['pullIntoReview']['to']]);
        self::assertArrayNotHasKey('requires', $transitions['pullIntoReview']);

        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportCardFinaliseGuard', $transitions['finalise']['requires']);
        self::assertSame(['rapportvergadering-review', 'finalised'], [$transitions['finalise']['from'], $transitions['finalise']['to']]);

        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportCardReopenGuard', $transitions['reopen']['requires']);
        self::assertSame(['finalised', 'rapportvergadering-review'], [$transitions['reopen']['from'], $transitions['reopen']['to']]);

        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportCardVisibilityGuard', $transitions['publishToParents']['requires']);
        self::assertSame(['finalised', 'published-to-parents'], [$transitions['publishToParents']['from'], $transitions['publishToParents']['to']]);
        self::assertSame(['reportCardPublished'], $transitions['publishToParents']['notifications']);

        self::assertSame(['draft', 'draft'], [$transitions['recompose']['from'], $transitions['recompose']['to']]);

        self::assertSame('OCA\\Scholiq\\Service\\ReportCardPdfDelegationService', $transitions['renderToPdf']['requires']);
        self::assertSame(['finalised', 'finalised'], [$transitions['renderToPdf']['from'], $transitions['renderToPdf']['to']]);

        self::assertSame('OCA\\Scholiq\\Service\\ReportCardPdfDelegationService', $transitions['rerenderToPdf']['requires']);
        self::assertSame(
            ['published-to-parents', 'published-to-parents'],
            [$transitions['rerenderToPdf']['from'], $transitions['rerenderToPdf']['to']]
        );

    }//end testReportCardLifecycleTransitions()

    /**
     * ReportCardParentNotification is appendOnly and mirrors GradeNotification's
     * scheduled/visibleFrom/olderThan-PT0S dispatch mechanism.
     *
     * @return void
     */
    public function testReportCardParentNotificationShape(): void
    {
        $schema = $this->config['components']['schemas']['ReportCardParentNotification'];

        self::assertTrue($schema['appendOnly']);
        self::assertSame(['reportCardPublished'], $schema['properties']['event']['enum']);

        $trigger = $schema['x-openregister-notifications']['reportCardPublished']['trigger'];
        self::assertSame('scheduled', $trigger['type']);
        self::assertSame(300, $trigger['intervalSec']);
        self::assertSame('olderThan', $trigger['filter']['visibleFrom']['operator']);
        self::assertSame('PT0S', $trigger['filter']['visibleFrom']['value']);

    }//end testReportCardParentNotificationShape()

    /**
     * GradeEntry.publish and .republish `requires` were swapped from
     * FraudCaseBlockGuard to ReportPeriodLockGuard — the latter composes the
     * former internally (verified in ReportPeriodLockGuardTest), since OR's
     * `requires` is a single DI-tag string, not an array (verified against
     * LifecycleAnnotationValidator::validate()).
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
     */
    public function testGradeEntryPublishRepublishRequireReportPeriodLockGuard(): void
    {
        $gradeEntry  = $this->config['components']['schemas']['GradeEntry'];
        $transitions = $gradeEntry['x-openregister-lifecycle']['transitions'];

        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportPeriodLockGuard', $transitions['publish']['requires']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\ReportPeriodLockGuard', $transitions['republish']['requires']);

        // `requires` is a plain string on both — confirms the single-guard
        // composition shape (not an unsupported array of guards).
        self::assertIsString($transitions['publish']['requires']);
        self::assertIsString($transitions['republish']['requires']);

    }//end testGradeEntryPublishRepublishRequireReportPeriodLockGuard()

    /**
     * The register's info.version was bumped for this change.
     *
     * @return void
     */
    public function testRegisterVersionBumped(): void
    {
        self::assertSame('0.11.0', $this->config['info']['version']);

    }//end testRegisterVersionBumped()
}//end class
