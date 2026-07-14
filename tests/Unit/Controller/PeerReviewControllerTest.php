<?php

/**
 * Scholiq PeerReviewController unit tests.
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
 * @spec openspec/changes/peer-and-self-assessment/specs/assignments/spec.md#requirement-reviewer-allocation-runs-as-a-dedicated-service-supporting-round-robin-random-and-manual-strategies
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\PeerReviewController;
use OCA\Scholiq\PeerReview\PeerReviewAllocationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PeerReviewController::allocate().
 */
class PeerReviewControllerTest extends TestCase
{

    private const ASSIGNMENT_ID = 'assignment-1';

    /**
     * Build a controller with the given fixtures + admin flag + delegated allocate() result.
     *
     * @param array<string,mixed>|null $assignment  Assignment fixture, or null (not found).
     * @param array<string,mixed>|null $cohort      Cohort fixture, or null (not found).
     * @param array<string,mixed>|null $session     Session fixture, or null (not found).
     * @param bool                     $isAdmin     Whether the caller is a Nextcloud admin.
     * @param string                   $uid         The caller's uid.
     *
     * @return PeerReviewController
     */
    private function makeController(
        ?array $assignment,
        ?array $cohort,
        ?array $session,
        bool $isAdmin,
        string $uid = 'teacher-1',
    ): PeerReviewController {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($assignment, $cohort, $session) {
                if ($schema === 'assignment') {
                    return $assignment;
                }

                if ($schema === 'cohort') {
                    return $cohort;
                }

                if ($schema === 'session') {
                    return $session;
                }

                return null;
            }
        );

        $allocationService = $this->createMock(PeerReviewAllocationService::class);
        $allocationService->method('allocate')->willReturn(
            ['strategy' => 'round-robin', 'submissionsProcessed' => 1, 'createdCount' => 2]
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new PeerReviewController(
            request: $this->createMock(IRequest::class),
            userSession: $userSession,
            groupManager: $groupManager,
            objectService: $objectService,
            allocationService: $allocationService,
        );
    }//end makeController()

    /**
     * Decode a JSONResponse body.
     *
     * @param JSONResponse $response The response.
     *
     * @return array<string,mixed>
     */
    private function body(JSONResponse $response): array
    {
        return (array) $response->getData();
    }//end body()

    /**
     * An admin caller is always authorized, regardless of Cohort membership.
     *
     * @return void
     */
    public function testAdminIsAuthorized(): void
    {
        $controller = $this->makeController(
            assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
            cohort: ['id' => 'cohort-1', 'teacherIds' => []],
            session: null,
            isAdmin: true,
        );

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(2, $this->body($response)['result']['createdCount']);
    }//end testAdminIsAuthorized()

    /**
     * A teacher listed on the Assignment's own Cohort (direct cohortId) is authorized.
     *
     * @return void
     */
    public function testCohortTeacherIsAuthorizedViaDirectCohortId(): void
    {
        $controller = $this->makeController(
            assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
            cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
            session: null,
            isAdmin: false,
            uid: 'teacher-1',
        );

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testCohortTeacherIsAuthorizedViaDirectCohortId()

    /**
     * A teacher listed on the Cohort resolved via Assignment.sessionId -> Session.cohortId
     * is authorized (Assignment has no direct cohortId).
     *
     * @return void
     */
    public function testCohortTeacherIsAuthorizedViaSession(): void
    {
        $controller = $this->makeController(
            assignment: ['id' => self::ASSIGNMENT_ID, 'sessionId' => 'session-1'],
            cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
            session: ['id' => 'session-1', 'cohortId' => 'cohort-1'],
            isAdmin: false,
            uid: 'teacher-1',
        );

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testCohortTeacherIsAuthorizedViaSession()

    /**
     * A caller who is neither admin nor a listed Cohort teacher receives a 403.
     *
     * @return void
     */
    public function testUnauthorizedCallerReceives403(): void
    {
        $controller = $this->makeController(
            assignment: ['id' => self::ASSIGNMENT_ID, 'cohortId' => 'cohort-1'],
            cohort: ['id' => 'cohort-1', 'teacherIds' => ['teacher-1']],
            session: null,
            isAdmin: false,
            uid: 'random-user',
        );

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testUnauthorizedCallerReceives403()

    /**
     * A caller with no resolvable Cohort at all (no cohortId, no sessionId) is denied.
     *
     * @return void
     */
    public function testNoResolvableCohortDenies(): void
    {
        $controller = $this->makeController(
            assignment: ['id' => self::ASSIGNMENT_ID],
            cohort: null,
            session: null,
            isAdmin: false,
            uid: 'teacher-1',
        );

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testNoResolvableCohortDenies()

    /**
     * A missing Assignment returns 404.
     *
     * @return void
     */
    public function testMissingAssignmentReturns404(): void
    {
        $controller = $this->makeController(assignment: null, cohort: null, session: null, isAdmin: true);

        $response = $controller->allocate(self::ASSIGNMENT_ID);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testMissingAssignmentReturns404()

    /**
     * A missing assignmentId parameter returns 400.
     *
     * @return void
     */
    public function testMissingAssignmentIdReturns400(): void
    {
        $controller = $this->makeController(assignment: null, cohort: null, session: null, isAdmin: true);

        $response = $controller->allocate('');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testMissingAssignmentIdReturns400()
}//end class
