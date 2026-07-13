<?php

/**
 * Scholiq CoursePublishGuard unit tests.
 *
 * Regression coverage for delegate-ooapi-to-opencatalogi tasks.md#task-4.1: the
 * OOAPI publication-contract spec sync does not alter Course's existing
 * `publish` transition guard behavior — it only changes what downstream
 * consumers (opencatalogi/openconnector) are told to expect once a Course is
 * published. This test asserts CoursePublishGuard::check() still behaves
 * exactly as before this change: a Course may only publish once it has at
 * least one published Lesson.
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
use OCA\Scholiq\Lifecycle\CoursePublishGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CoursePublishGuard::check() — the Course `draft -> published` transition.
 */
class CoursePublishGuardTest extends TestCase
{

    /**
     * A Course with at least one published Lesson is allowed to publish —
     * unchanged by the OOAPI publication-contract spec sync.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
     */
    public function testCourseWithPublishedLessonIsAllowedToPublish(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([['id' => 'lesson-1', 'lifecycle' => 'published']]);

        $guard   = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'course-1', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testCourseWithPublishedLessonIsAllowedToPublish()

    /**
     * A Course with no published Lesson is blocked from publishing —
     * unchanged by the OOAPI publication-contract spec sync.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.1
     */
    public function testCourseWithoutPublishedLessonIsBlocked(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn([]);

        $guard   = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'course-2', 'tenant_id' => 'tenant-a'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertFalse($guard->check($context));

    }//end testCourseWithoutPublishedLessonIsBlocked()

    /**
     * A transition context with no course id blocks the publish outright.
     *
     * @return void
     */
    public function testMissingCourseIdBlocksPublish(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $guard   = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = ['object' => [], 'transition' => 'publish', 'from' => 'draft', 'to' => 'published'];

        self::assertFalse($guard->check($context));

    }//end testMissingCourseIdBlocksPublish()

    /**
     * The Lesson lookup is scoped to the Course's own tenant — H1 isolation,
     * unaffected by the OOAPI contract.
     *
     * @return void
     */
    public function testLessonLookupIsScopedToTenant(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('findAll')
            ->with(
                self::callback(
                    function (array $params): bool {
                        return ($params['filters']['tenant_id'] ?? null) === 'tenant-b'
                            && ($params['filters']['courseId'] ?? null) === 'course-3'
                            && ($params['schema'] ?? null) === 'lesson';
                    }
                )
            )
            ->willReturn([['id' => 'lesson-9', 'lifecycle' => 'published']]);

        $guard   = new CoursePublishGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = [
            'object'     => ['id' => 'course-3', 'tenant_id' => 'tenant-b'],
            'transition' => 'publish',
            'from'       => 'draft',
            'to'         => 'published',
        ];

        self::assertTrue($guard->check($context));

    }//end testLessonLookupIsScopedToTenant()
}//end class
