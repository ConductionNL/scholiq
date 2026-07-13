<?php

/**
 * Scholiq ReportCardVisibilityGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use DateTime;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\ReportCardVisibilityGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ReportCardVisibilityGuard (finalised -> published-to-parents).
 */
class ReportCardVisibilityGuardTest extends TestCase
{

    /**
     * Build a guard whose ObjectService::findAll(schema=grade-entry) resolves
     * from a fixed id => visibleFrom map, and whose "now" is fixed.
     *
     * @param array<string,string|null> $visibleFromById GradeEntry id => visibleFrom (or missing = unresolvable).
     * @param DateTime                  $now             The current moment.
     *
     * @return ReportCardVisibilityGuard
     */
    private function makeGuard(array $visibleFromById, DateTime $now): ReportCardVisibilityGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($visibleFromById) {
                if ($config['schema'] !== 'grade-entry') {
                    return [];
                }

                $id = $config['filters']['id'] ?? null;
                if ($id === null || array_key_exists($id, $visibleFromById) === false) {
                    return [];
                }

                return [['id' => $id, 'visibleFrom' => $visibleFromById[$id]]];
            }
        );

        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getDateTime')->willReturn($now);

        return new ReportCardVisibilityGuard($objectService, $timeFactory, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * Every contributing GradeEntry's visibleFrom has already passed -> allowed.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-succeeds-once-every-contributing-grades-window-has-opened
     */
    public function testAllVisibleFromPassedAllowsPublish(): void
    {
        $now   = new DateTime('2026-07-13T12:00:00+00:00');
        $guard = $this->makeGuard(
            visibleFromById: ['entry-1' => '2026-07-01T00:00:00+00:00', 'entry-2' => '2026-07-10T00:00:00+00:00'],
            now: $now
        );

        $context = [
            'object' => [
                'id'            => 'card-1',
                'subjectGrades' => [
                    ['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']],
                    ['curriculumPlanId' => 'plan-2', 'sourceGradeEntryIds' => ['entry-2']],
                ],
            ],
        ];

        self::assertTrue($guard->check($context));

    }//end testAllVisibleFromPassedAllowsPublish()

    /**
     * One contributing GradeEntry's visibleFrom is still in the future -> blocked.
     *
     * @return void
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-publish-is-blocked-while-a-contributing-grades-visibility-window-has-not-opened
     */
    public function testFutureVisibleFromBlocksPublish(): void
    {
        $now   = new DateTime('2026-07-13T12:00:00+00:00');
        $guard = $this->makeGuard(
            visibleFromById: ['entry-1' => '2026-07-01T00:00:00+00:00', 'entry-2' => '2099-01-01T00:00:00+00:00'],
            now: $now
        );

        $context = [
            'object' => [
                'id'            => 'card-1',
                'subjectGrades' => [
                    ['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']],
                    ['curriculumPlanId' => 'biologie', 'sourceGradeEntryIds' => ['entry-2']],
                ],
            ],
        ];

        self::assertFalse($guard->check($context));

    }//end testFutureVisibleFromBlocksPublish()

    /**
     * A null visibleFrom on a source GradeEntry blocks (fail closed).
     *
     * @return void
     */
    public function testNullVisibleFromBlocksPublish(): void
    {
        $now   = new DateTime('2026-07-13T12:00:00+00:00');
        $guard = $this->makeGuard(visibleFromById: ['entry-1' => null], now: $now);

        $context = [
            'object' => [
                'id'            => 'card-1',
                'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-1']]],
            ],
        ];

        self::assertFalse($guard->check($context));

    }//end testNullVisibleFromBlocksPublish()

    /**
     * An unresolvable (deleted/missing) source GradeEntry blocks (fail closed).
     *
     * @return void
     */
    public function testUnresolvableSourceGradeEntryBlocksPublish(): void
    {
        $now   = new DateTime('2026-07-13T12:00:00+00:00');
        $guard = $this->makeGuard(visibleFromById: [], now: $now);

        $context = [
            'object' => [
                'id'            => 'card-1',
                'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => ['entry-missing']]],
            ],
        ];

        self::assertFalse($guard->check($context));

    }//end testUnresolvableSourceGradeEntryBlocksPublish()

    /**
     * A subject row with no sourceGradeEntryIds contributes nothing to block on.
     *
     * @return void
     */
    public function testEmptySourceGradeEntryIdsDoesNotBlock(): void
    {
        $now     = new DateTime('2026-07-13T12:00:00+00:00');
        $guard   = $this->makeGuard(visibleFromById: [], now: $now);
        $context = [
            'object' => [
                'id'            => 'card-1',
                'subjectGrades' => [['curriculumPlanId' => 'plan-1', 'sourceGradeEntryIds' => []]],
            ],
        ];

        self::assertTrue($guard->check($context));

    }//end testEmptySourceGradeEntryIdsDoesNotBlock()
}//end class
