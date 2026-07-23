<?php

/**
 * Scholiq ProgrammePublishGuard unit tests.
 *
 * Regression coverage for delegate-ooapi-to-opencatalogi tasks.md#task-4.1: the
 * OOAPI publication-contract spec sync does not alter Programme's existing
 * `publish` transition guard behavior — it only changes what downstream
 * consumers (opencatalogi/openconnector) are told to expect once a Programme
 * is published. This test asserts ProgrammePublishGuard::check() still
 * behaves exactly as before this change: a Programme may only publish once
 * it has an assigned, published CurriculumPlan with at least one required
 * course.
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
 * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\ProgrammePublishGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProgrammePublishGuard::check() — the Programme `draft -> published` transition.
 */
class ProgrammePublishGuardTest extends TestCase
{

    /**
     * A Programme with a published CurriculumPlan carrying required courses
     * is allowed to publish — unchanged by the OOAPI publication-contract
     * spec sync.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
     */
    public function testProgrammeWithPublishedPlanAndRequiredCoursesIsAllowedToPublish(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn(
            [
                [
                    'id'                 => 'plan-1',
                    'lifecycle'          => 'published',
                    'requiredCourseIds'  => ['course-1', 'course-2'],
                ],
            ]
        );

        $guard   = new ProgrammePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'programme-1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testProgrammeWithPublishedPlanAndRequiredCoursesIsAllowedToPublish()

    /**
     * A Programme with no CurriculumPlan assigned is blocked from publishing.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
     */
    public function testProgrammeWithoutCurriculumPlanIsBlocked(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $guard   = new ProgrammePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'programme-2', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testProgrammeWithoutCurriculumPlanIsBlocked()

    /**
     * A Programme whose CurriculumPlan is not (yet) published is blocked.
     *
     * @return void
     */
    public function testProgrammeWithUnpublishedPlanIsBlocked(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $guard   = new ProgrammePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'programme-3', 'curriculumPlanId' => 'plan-3', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testProgrammeWithUnpublishedPlanIsBlocked()

    /**
     * A published CurriculumPlan with zero required courses still blocks
     * publish — unchanged by the OOAPI publication-contract spec sync.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
     */
    public function testProgrammeWithPublishedPlanButNoRequiredCoursesIsBlocked(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn(
            [['id' => 'plan-4', 'lifecycle' => 'published', 'requiredCourseIds' => []]]
        );

        $guard   = new ProgrammePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'programme-4', 'curriculumPlanId' => 'plan-4', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testProgrammeWithPublishedPlanButNoRequiredCoursesIsBlocked()
}//end class
