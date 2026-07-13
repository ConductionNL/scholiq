<?php

/**
 * Scholiq LessonReleaseController unit tests.
 *
 * Covers: an enrolled learner gets a real evaluator decision; a
 * non-enrolled, non-staff caller gets 403; a staff (admin/teacher-view)
 * caller gets a real decision regardless of their own enrolment; an unknown
 * item id returns 404; and the response never leaks the raw
 * releaseConditions configuration or another learner's data — only
 * `{available, reason, availableAt}`.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\LessonReleaseController;
use OCA\Scholiq\Release\LessonReleaseEvaluator;
use OCA\Scholiq\Service\DashboardRoleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LessonReleaseController::status() / assessmentStatus().
 */
class LessonReleaseControllerTest extends TestCase
{

    /**
     * In-memory fake OR datastore, keyed by schema slug.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $db = [];

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var DashboardRoleService&MockObject
     */
    private DashboardRoleService&MockObject $dashboardRoleService;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->db                  = [];
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->dashboardRoleService = $this->createMock(DashboardRoleService::class);

    }//end setUp()

    /**
     * Seed a record into the fake datastore.
     *
     * @param string               $schema Schema slug.
     * @param array<string, mixed> $record Record data.
     *
     * @return void
     */
    private function seed(string $schema, array $record): void
    {
        $this->db[$schema][] = $record;

    }//end seed()

    /**
     * Sign the caller in as the given uid with the given Scholiq views.
     *
     * @param string   $uid   NC user id.
     * @param string[] $views DashboardRoleService::resolveViews() result.
     *
     * @return void
     */
    private function signInAs(string $uid, array $views=['student']): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->dashboardRoleService->method('resolveViews')->willReturn($views);

    }//end signInAs()

    /**
     * Build the controller under test, with a real ObjectService double and
     * an evaluator that always returns a fixed, recognisable decision.
     *
     * @param array{available: bool, reason: string|null, availableAt: string|null}|null $evaluatorResult
     *
     * @return LessonReleaseController
     */
    private function controller(?array $evaluatorResult=null): LessonReleaseController
    {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, $register=null, $schema=null) {
                foreach (($this->db[$schema] ?? []) as $rec) {
                    if (($rec['id'] ?? null) === $id) {
                        return $rec;
                    }
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema  = $config['schema'];
                $filters = ($config['filters'] ?? []);

                return array_values(
                    array_filter(
                        ($this->db[$schema] ?? []),
                        static function (array $rec) use ($filters) {
                            foreach ($filters as $key => $value) {
                                if (($rec[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }
        );

        $evaluator = $this->createMock(LessonReleaseEvaluator::class);
        $evaluator->method('evaluate')->willReturn(
            $evaluatorResult ?? ['available' => true, 'reason' => null, 'availableAt' => null]
        );

        return new LessonReleaseController(
            request: $this->createMock(IRequest::class),
            userSession: $this->userSession,
            objectService: $objectService,
            releaseEvaluator: $evaluator,
            dashboardRoleService: $this->dashboardRoleService,
        );

    }//end controller()

    /**
     * An enrolled learner receives the evaluator's real decision.
     *
     * @return void
     */
    public function testEnrolledLearnerGetsRealDecision(): void
    {
        $this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
        $this->seed('enrolment', ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']);
        $this->signInAs('learner-1');

        $controller = $this->controller(['available' => false, 'reason' => 'Complete "Lesson A" first.', 'availableAt' => null]);
        $response   = $controller->status('lesson-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertFalse($response->getData()['available']);
        self::assertSame('Complete "Lesson A" first.', $response->getData()['reason']);

    }//end testEnrolledLearnerGetsRealDecision()

    /**
     * A non-enrolled, non-staff caller is denied with 403.
     *
     * @return void
     */
    public function testNonEnrolledNonStaffCallerIsDenied(): void
    {
        $this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
        $this->signInAs('learner-2', views: ['student']);

        $controller = $this->controller();
        $response   = $controller->status('lesson-1');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testNonEnrolledNonStaffCallerIsDenied()

    /**
     * A staff (admin/teacher view) caller gets a real decision even without
     * holding a personal Enrolment for the course.
     *
     * @return void
     */
    public function testStaffCallerGetsDecisionWithoutOwnEnrolment(): void
    {
        $this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
        $this->signInAs('teacher-1', views: ['teacher', 'student']);

        $controller = $this->controller(['available' => true, 'reason' => null, 'availableAt' => null]);
        $response   = $controller->status('lesson-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertTrue($response->getData()['available']);

    }//end testStaffCallerGetsDecisionWithoutOwnEnrolment()

    /**
     * An admin-view caller is also treated as staff.
     *
     * @return void
     */
    public function testAdminViewCallerIsTreatedAsStaff(): void
    {
        $this->seed('assessment', ['id' => 'assessment-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
        $this->signInAs('admin-1', views: ['admin', 'teacher', 'student']);

        $controller = $this->controller();
        $response   = $controller->assessmentStatus('assessment-1');

        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testAdminViewCallerIsTreatedAsStaff()

    /**
     * An unauthenticated caller is rejected with 401.
     *
     * @return void
     */
    public function testUnauthenticatedCallerRejected(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $controller = $this->controller();
        $response   = $controller->status('lesson-1');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedCallerRejected()

    /**
     * An unknown Lesson id returns 404.
     *
     * @return void
     */
    public function testUnknownLessonReturnsNotFound(): void
    {
        $this->signInAs('learner-1');

        $controller = $this->controller();
        $response   = $controller->status('does-not-exist');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUnknownLessonReturnsNotFound()

    /**
     * The response carries only {available, reason, availableAt} — no raw
     * releaseConditions configuration or other data.
     *
     * @return void
     */
    public function testResponseShapeIsMinimal(): void
    {
        $this->seed('lesson', ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 'tenant-a']);
        $this->seed('enrolment', ['id' => 'enrolment-1', 'learnerId' => 'learner-1', 'courseId' => 'course-1']);
        $this->signInAs('learner-1');

        $controller = $this->controller(['available' => true, 'reason' => null, 'availableAt' => null]);
        $response   = $controller->status('lesson-1');

        self::assertSame(['available', 'reason', 'availableAt'], array_keys($response->getData()));

    }//end testResponseShapeIsMinimal()
}//end class
