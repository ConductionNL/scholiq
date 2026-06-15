<?php

/**
 * Scholiq RolloverService unit tests.
 *
 * Covers the default-mapping proposal (leerjaar increment + unparseable block),
 * the side-effect-free preview (counts, blocked state, preview-matches-mappings),
 * and the idempotent execution (cohort creation, archival, enrolment carry-over,
 * per-mapping resume).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\RolloverService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RolloverService.
 */
class RolloverServiceTest extends TestCase
{
    /**
     * Build a service with a configurable ObjectService.
     *
     * @param ObjectService|null $objectService Optional pre-built object service mock.
     *
     * @return RolloverService
     */
    private function makeService(?ObjectService $objectService=null): RolloverService
    {
        return new RolloverService(
            $objectService ?? $this->createMock(ObjectService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end makeService()

    /**
     * Default mapping increments the leerjaar and preserves the suffix.
     *
     * @return void
     */
    public function testProposeDefaultMappingIncrementsLeerjaar(): void
    {
        $svc = $this->makeService();

        $mappings = $svc->proposeDefaultMapping([
            ['id' => 'c1', 'name' => '2A', 'programmeId' => 'p1'],
            ['id' => 'c2', 'name' => '4-VWO'],
        ]);

        $this->assertSame('promote', $mappings[0]['action']);
        $this->assertSame('3A', $mappings[0]['toCohortName']);
        $this->assertSame('p1', $mappings[0]['toProgrammeId']);
        $this->assertSame('promote', $mappings[1]['action']);
        $this->assertSame('5-VWO', $mappings[1]['toCohortName']);
    }//end testProposeDefaultMappingIncrementsLeerjaar()

    /**
     * An unparseable cohort name yields a null action (blocks preview).
     *
     * @return void
     */
    public function testProposeDefaultMappingBlocksUnparseableName(): void
    {
        $svc = $this->makeService();

        $mappings = $svc->proposeDefaultMapping([['id' => 'c1', 'name' => 'Examenklas']]);

        $this->assertNull($mappings[0]['action']);
        $this->assertNull($mappings[0]['toCohortName']);
    }//end testProposeDefaultMappingBlocksUnparseableName()

    /**
     * A null mapping action makes the preview blocked.
     *
     * @return void
     */
    public function testPreviewBlockedOnNullAction(): void
    {
        $svc = $this->makeService();

        $plan = [
            'fromAcademicYear' => '2025/2026',
            'toAcademicYear'   => '2026/2027',
            'mappings'         => [['fromCohortId' => 'c1', 'action' => null]],
        ];

        $report = $svc->preview($plan);
        $this->assertTrue($report['blocked']);
        $this->assertContains('c1', $report['blockingCohorts']);
    }//end testPreviewBlockedOnNullAction()

    /**
     * Preview counts promote/graduate per member and lists cohorts to create.
     *
     * @return void
     */
    public function testPreviewCountsAndCohortsToCreate(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->cohortEntity(['id' => 'c1', 'name' => '2A', 'learnerIds' => ['l1', 'l2', 'l3']]));
        // No enrolments returned (carry count 0).
        $objectService->method('findAll')->willReturn([]);

        $svc = $this->makeService($objectService);

        $plan = [
            'fromAcademicYear' => '2025/2026',
            'toAcademicYear'   => '2026/2027',
            'mappings'         => [['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => '3A']],
            'learnerOverrides' => [['learnerId' => 'l2', 'action' => 'graduate']],
        ];

        $report = $svc->preview($plan);

        $this->assertFalse($report['blocked']);
        $this->assertSame(2, $report['counts']['promote'], 'l1 + l3 promote');
        $this->assertSame(1, $report['counts']['graduate'], 'l2 graduates');
        $this->assertContains('3A', $report['cohortsToCreate']);
        $this->assertContains('scholiq-cohort-2026-2027-3a', $report['ncGroupsToSync']);
    }//end testPreviewCountsAndCohortsToCreate()

    /**
     * previewMatchesMappings is false when no report is stored, true when it matches.
     *
     * @return void
     */
    public function testPreviewMatchesMappings(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->cohortEntity(['id' => 'c1', 'name' => '2A', 'learnerIds' => ['l1']]));
        $objectService->method('findAll')->willReturn([]);

        $svc = $this->makeService($objectService);

        $plan = [
            'fromAcademicYear' => '2025/2026',
            'toAcademicYear'   => '2026/2027',
            'mappings'         => [['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => '3A']],
        ];

        $this->assertFalse($svc->previewMatchesMappings($plan), 'no stored report');

        $plan['dryRunReport'] = $svc->preview($plan);
        $this->assertTrue($svc->previewMatchesMappings($plan), 'stored report matches current mappings');

        // Editing the mappings invalidates the stored preview.
        $plan['mappings'][0]['toCohortName'] = '3B';
        // counts unchanged but cohortsToCreate differs.
        $this->assertFalse($svc->previewMatchesMappings($plan), 'edited mappings invalidate preview');
    }//end testPreviewMatchesMappings()

    /**
     * Execution creates the to-year cohort, archives the from-year cohort, and
     * records per-mapping progress.
     *
     * @return void
     */
    public function testExecuteCreatesCohortArchivesAndRecordsProgress(): void
    {
        $saved = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->cohortEntity(['id' => 'c1', 'name' => '2A', 'learnerIds' => ['l1', 'l2'], 'lifecycle' => 'active']));
        // No existing to-year cohort, no enrolments.
        $objectService->method('findAll')->willReturn([]);
        $objectService->method('saveObject')->willReturnCallback(
            static function (string $register, string $schema, array $object) use (&$saved): array {
                $saved[] = ['schema' => $schema, 'object' => $object];
                return $object;
            }
        );

        $svc = $this->makeService($objectService);

        $plan = [
            'fromAcademicYear' => '2025/2026',
            'toAcademicYear'   => '2026/2027',
            'tenant_id'        => 'tenant-a',
            'mappings'         => [['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => '3A']],
        ];

        $progress = $svc->execute($plan);

        $this->assertSame('done', $progress['c1']);

        $schemasWritten = array_column($saved, 'schema');
        $this->assertContains('cohort', $schemasWritten, 'to-year cohort created + from-year archived');

        // The from-year cohort archival must be present.
        $archived = array_filter($saved, static fn ($s): bool => $s['schema'] === 'cohort' && ($s['object']['lifecycle'] ?? '') === 'archived');
        $this->assertNotEmpty($archived, 'from-year cohort archived');
    }//end testExecuteCreatesCohortArchivesAndRecordsProgress()

    /**
     * A mapping already marked done is skipped on re-run (idempotency).
     *
     * @return void
     */
    public function testExecuteSkipsCompletedMappings(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        // find/saveObject must never be called for an already-done mapping.
        $objectService->expects($this->never())->method('find');
        $objectService->expects($this->never())->method('saveObject');

        $svc = $this->makeService($objectService);

        $plan = [
            'fromAcademicYear'   => '2025/2026',
            'toAcademicYear'     => '2026/2027',
            'tenant_id'          => 'tenant-a',
            'mappings'           => [['fromCohortId' => 'c1', 'action' => 'promote', 'toCohortName' => '3A']],
            'perMappingProgress' => ['c1' => 'done'],
        ];

        $progress = $svc->execute($plan);
        $this->assertSame('done', $progress['c1']);
    }//end testExecuteSkipsCompletedMappings()

    /**
     * groupName produces a stable, slugified identifier.
     *
     * @return void
     */
    public function testGroupNameIsDeterministic(): void
    {
        $svc = $this->makeService();
        $this->assertSame('scholiq-cohort-2026-2027-3a', $svc->groupName('2026/2027', '3A'));
        $this->assertSame(
            $svc->groupName('2026/2027', '3A'),
            $svc->groupName('2026/2027', '3A'),
            'deterministic across calls'
        );
    }//end testGroupNameIsDeterministic()

    /**
     * Build a cohort entity stub exposing jsonSerialize().
     *
     * @param array<string,mixed> $data The cohort data.
     *
     * @return object
     */
    private function cohortEntity(array $data): object
    {
        return new class($data) {
            /**
             * @param array<string,mixed> $data The data payload.
             */
            public function __construct(private array $data)
            {
            }

            /**
             * @return array<string,mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->data;
            }
        };
    }//end cohortEntity()
}//end class
