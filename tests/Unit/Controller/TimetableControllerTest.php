<?php

/**
 * Unit tests for TimetableController.
 *
 * Verify the personal-timetable read surface: cohort-membership resolution
 * (teacher via Cohort.teacherIds, learner via Cohort.learnerIds and
 * Enrolment.cohortId), window filtering, ordering, the empty-not-error
 * contract, no cross-cohort leakage, and the read-only invariant (no writes).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\TimetableController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TimetableController::mine().
 *
 * The window is passed explicitly to every test so the assertions do not depend
 * on the wall-clock "current week" default.
 */
class TimetableControllerTest extends TestCase
{
    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * User-session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Window start used by every windowed test (Monday).
     *
     * @var string
     */
    private string $from = '2026-01-05T00:00:00+00:00';

    /**
     * Window end used by every windowed test (next Monday, exclusive).
     *
     * @var string
     */
    private string $to = '2026-01-12T00:00:00+00:00';

    /**
     * Build the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return TimetableController The controller.
     */
    private function controller(): TimetableController
    {
        return new TimetableController(
            request: $this->createMock(IRequest::class),
            userSession: $this->userSession,
            objectService: $this->objectService,
            logger: $this->logger,
        );
    }//end controller()

    /**
     * Make IUserSession return a user with the given uid.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signInAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signInAs()

    /**
     * Wire ObjectService::findAll to serve fixture data keyed by schema.
     *
     * The `session` schema is served per-cohort: the callback honours the
     * `cohortId` equality filter so the test proves the controller never loads
     * a session for a cohort the caller does not belong to.
     *
     * @param array<int,array<string,mixed>> $cohorts    Cohort fixtures.
     * @param array<int,array<string,mixed>> $enrolments Enrolment fixtures (already scoped to the caller).
     * @param array<int,array<string,mixed>> $sessions   Session fixtures (all cohorts).
     * @param array<int,array<string,mixed>> $rooms      Room fixtures.
     *
     * @return void
     */
    private function wireFindAll(array $cohorts, array $enrolments, array $sessions, array $rooms = []): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($cohorts, $enrolments, $sessions, $rooms): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'cohort') {
                    return $cohorts;
                }

                if ($schema === 'enrolment') {
                    $learner = $filters['learnerId'] ?? null;
                    return array_values(
                        array_filter(
                            $enrolments,
                            static fn (array $e): bool => $learner === null || ($e['learnerId'] ?? null) === $learner
                        )
                    );
                }

                if ($schema === 'session') {
                    $cohortId = $filters['cohortId'] ?? null;
                    return array_values(
                        array_filter(
                            $sessions,
                            static fn (array $s): bool => $cohortId === null || ($s['cohortId'] ?? null) === $cohortId
                        )
                    );
                }

                if ($schema === 'room') {
                    $id = $filters['id'] ?? null;
                    return array_values(array_filter($rooms, static fn (array $r): bool => ($r['id'] ?? null) === $id));
                }

                return [];
            }
        );
    }//end wireFindAll()

    /**
     * Decode a JSONResponse body to an array.
     *
     * @param JSONResponse $response The response.
     *
     * @return array<string,mixed> The decoded body.
     */
    private function body(JSONResponse $response): array
    {
        return (array) $response->getData();
    }//end body()

    /**
     * A learner sees this week's sessions for their enrolled cohorts, ordered,
     * and never a session of a cohort they are not in.
     *
     * @return void
     */
    public function testLearnerSeesEnrolledCohortSessions(): void
    {
        $this->signInAs('alice');

        // alice is a listed learner in cohort-1 and enrolled (via Enrolment) in cohort-2.
        // cohort-3 is someone else's — she must never see its session.
        $cohorts = [
            ['id' => 'cohort-1', 'learnerIds' => ['alice', 'bob'], 'teacherIds' => ['tom']],
            ['id' => 'cohort-2', 'learnerIds' => [], 'teacherIds' => ['tom']],
            ['id' => 'cohort-3', 'learnerIds' => ['carol'], 'teacherIds' => ['tom']],
        ];
        $enrolments = [
            ['learnerId' => 'alice', 'cohortId' => 'cohort-2'],
        ];
        $sessions = [
            ['id' => 's-b', 'cohortId' => 'cohort-1', 'title' => 'Biology', 'startsAt' => '2026-01-07T10:00:00+00:00', 'endsAt' => '2026-01-07T11:00:00+00:00', 'location' => 'Room 1'],
            ['id' => 's-a', 'cohortId' => 'cohort-2', 'title' => 'Algebra', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00', 'location' => 'Room 2'],
            ['id' => 's-x', 'cohortId' => 'cohort-3', 'title' => 'Secret',  'startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00', 'location' => 'Room 9'],
        ];
        $this->wireFindAll($cohorts, $enrolments, $sessions);

        $response = $this->controller()->mine(from: $this->from, to: $this->to);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $out = $this->body($response);
        $ids = array_column($out['sessions'], 'id');

        // Ordered by startsAt: Algebra (Jan 6) before Biology (Jan 7).
        $this->assertSame(['s-a', 's-b'], $ids);
        // No cross-cohort leakage.
        $this->assertNotContains('s-x', $ids);
        // Projection carries the required fields.
        $this->assertSame('Algebra', $out['sessions'][0]['title']);
        $this->assertSame('Room 2', $out['sessions'][0]['location']);
    }//end testLearnerSeesEnrolledCohortSessions()

    /**
     * A teacher sees the sessions of the cohorts they teach.
     *
     * @return void
     */
    public function testTeacherSeesTaughtCohortSessions(): void
    {
        $this->signInAs('tom');

        $cohorts = [
            ['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
            ['id' => 'cohort-9', 'learnerIds' => ['zoe'], 'teacherIds' => ['other']],
        ];
        $sessions = [
            ['id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Lecture', 'startsAt' => '2026-01-07T13:00:00+00:00', 'endsAt' => '2026-01-07T14:00:00+00:00'],
            ['id' => 's-9', 'cohortId' => 'cohort-9', 'title' => 'Other',   'startsAt' => '2026-01-07T15:00:00+00:00', 'endsAt' => '2026-01-07T16:00:00+00:00'],
        ];
        $this->wireFindAll($cohorts, [], $sessions);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
        $ids = array_column($out['sessions'], 'id');

        $this->assertSame(['s-1'], $ids);
    }//end testTeacherSeesTaughtCohortSessions()

    /**
     * A user with no cohorts gets an empty list (HTTP 200), never an error.
     *
     * @return void
     */
    public function testNoCohortsReturnsEmptyOk(): void
    {
        $this->signInAs('nobody');

        $cohorts = [
            ['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => ['tom']],
        ];
        // findAll must never be asked for sessions when there are no cohorts.
        $this->objectService->expects($this->exactly(2))
            ->method('findAll')
            ->willReturnCallback(
                function (array $config) use ($cohorts): array {
                    if (($config['schema'] ?? '') === 'session') {
                        $this->fail('Sessions must not be queried when the caller has no cohorts');
                    }
                    if (($config['schema'] ?? '') === 'cohort') {
                        return $cohorts;
                    }
                    return [];
                }
            );

        $response = $this->controller()->mine(from: $this->from, to: $this->to);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $this->body($response);
        $this->assertSame([], $body['sessions']);
        $this->assertSame([], $body['changes']);
    }//end testNoCohortsReturnsEmptyOk()

    /**
     * Each session projects roomId (with resolved Room detail when set),
     * lifecycle, substituteTeacherId, changeReasonKind, and changeReason.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/personal-timetable/spec.md#requirement-a-signed-in-user-can-see-their-own-upcoming-sessions
     */
    public function testProjectsRoomAndSubstitutionFields(): void
    {
        $this->signInAs('alice');

        $cohorts = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
        $sessions = [
            [
                'id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Bio',
                'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00',
                'roomId' => 'room-1', 'lifecycle' => 'scheduled',
                'substituteTeacherId' => 'sub-1', 'changeReasonKind' => 'teacher-absence', 'changeReason' => 'Ziek',
            ],
        ];
        $rooms = [['id' => 'room-1', 'name' => 'Lokaal A-203', 'capacity' => 30, 'facilities' => ['projector']]];
        $this->wireFindAll($cohorts, [], $sessions, $rooms);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
        $session = $out['sessions'][0];

        $this->assertSame('room-1', $session['roomId']);
        $this->assertSame('Lokaal A-203', $session['room']['name']);
        $this->assertSame(30, $session['room']['capacity']);
        $this->assertSame('sub-1', $session['substituteTeacherId']);
        $this->assertSame('teacher-absence', $session['changeReasonKind']);
        $this->assertSame('Ziek', $session['changeReason']);
        $this->assertSame('scheduled', $session['lifecycle']);
    }//end testProjectsRoomAndSubstitutionFields()

    /**
     * A Session with no roomId projects a null room, never an error.
     *
     * @return void
     */
    public function testNoRoomProjectsNull(): void
    {
        $this->signInAs('alice');

        $cohorts  = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
        $sessions = [['id' => 's-1', 'cohortId' => 'cohort-1', 'title' => 'Bio', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00']];
        $this->wireFindAll($cohorts, [], $sessions);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

        $this->assertNull($out['sessions'][0]['roomId']);
        $this->assertNull($out['sessions'][0]['room']);
    }//end testNoRoomProjectsNull()

    /**
     * Today's cancellation surfaces in the dagrooster `changes` list even for
     * a Session scheduled outside the requested window (a future Session).
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/personal-timetable/spec.md#scenario-today-s-cancellation-surfaces-in-the-dagrooster-changes-list-even-for-a-future-session
     */
    public function testTodaysCancellationSurfacesInChangesRegardlessOfWindow(): void
    {
        $this->signInAs('alice');

        $today = gmdate('Y-m-d\TH:i:s\+00:00');
        $cohorts  = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
        $sessions = [
            [
                // Scheduled well outside the requested window (2026-01-05..12).
                'id' => 's-future', 'cohortId' => 'cohort-1', 'title' => 'Future lesson',
                'startsAt' => '2099-01-01T09:00:00+00:00', 'endsAt' => '2099-01-01T10:00:00+00:00',
                'lifecycle' => 'cancelled', 'changedAt' => $today,
            ],
        ];
        $this->wireFindAll($cohorts, [], $sessions);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

        $this->assertSame([], $out['sessions']);
        $this->assertCount(1, $out['changes']);
        $this->assertSame('s-future', $out['changes'][0]['id']);
    }//end testTodaysCancellationSurfacesInChangesRegardlessOfWindow()

    /**
     * A Session changed on a prior day does not appear in today's changes list.
     *
     * @return void
     */
    public function testStaleChangeDoesNotAppearInTodaysChanges(): void
    {
        $this->signInAs('alice');

        $cohorts  = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
        $sessions = [
            [
                'id' => 's-old-change', 'cohortId' => 'cohort-1', 'title' => 'Old change',
                'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00',
                'lifecycle' => 'cancelled', 'changedAt' => '2020-01-01T09:00:00+00:00',
            ],
        ];
        $this->wireFindAll($cohorts, [], $sessions);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));

        $this->assertSame([], $out['changes']);
    }//end testStaleChangeDoesNotAppearInTodaysChanges()

    /**
     * Sessions outside the requested window are excluded.
     *
     * @return void
     */
    public function testWindowingExcludesOutOfWindowSessions(): void
    {
        $this->signInAs('alice');

        $cohorts    = [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]];
        $sessions   = [
            // In window.
            ['id' => 'in', 'cohortId' => 'cohort-1', 'title' => 'In', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00'],
            // Before the window.
            ['id' => 'before', 'cohortId' => 'cohort-1', 'title' => 'Before', 'startsAt' => '2026-01-01T09:00:00+00:00', 'endsAt' => '2026-01-01T10:00:00+00:00'],
            // After the window (starts on the exclusive end boundary).
            ['id' => 'after', 'cohortId' => 'cohort-1', 'title' => 'After', 'startsAt' => '2026-01-12T09:00:00+00:00', 'endsAt' => '2026-01-12T10:00:00+00:00'],
        ];
        $this->wireFindAll($cohorts, [], $sessions);

        $out = $this->body($this->controller()->mine(from: $this->from, to: $this->to));
        $ids = array_column($out['sessions'], 'id');

        $this->assertSame(['in'], $ids);
    }//end testWindowingExcludesOutOfWindowSessions()

    /**
     * An unauthenticated caller gets HTTP 401.
     *
     * @return void
     */
    public function testUnauthenticatedReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->objectService->expects($this->never())->method('findAll');

        $response = $this->controller()->mine();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testUnauthenticatedReturns401()

    /**
     * The timetable never writes — saveObject is never called.
     *
     * @return void
     */
    public function testReadOnlyNeverWrites(): void
    {
        $this->signInAs('alice');
        $this->wireFindAll(
            [['id' => 'cohort-1', 'learnerIds' => ['alice'], 'teacherIds' => []]],
            [],
            [['id' => 's', 'cohortId' => 'cohort-1', 'title' => 'X', 'startsAt' => '2026-01-06T09:00:00+00:00', 'endsAt' => '2026-01-06T10:00:00+00:00']]
        );
        $this->objectService->expects($this->never())->method('saveObject');

        $response = $this->controller()->mine(from: $this->from, to: $this->to);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testReadOnlyNeverWrites()

    /**
     * The window defaults to the current ISO week when from/to are omitted.
     *
     * @return void
     */
    public function testDefaultWindowIsCurrentWeek(): void
    {
        $this->signInAs('nobody');
        $this->objectService->method('findAll')->willReturn([]);

        $out = $this->body($this->controller()->mine());

        // The server echoes a resolved 7-day window even for the empty result.
        $this->assertNotSame('', $out['from']);
        $this->assertNotSame('', $out['to']);
        $fromTs = strtotime($out['from']);
        $toTs   = strtotime($out['to']);
        $this->assertSame(7 * 24 * 3600, ($toTs - $fromTs));
    }//end testDefaultWindowIsCurrentWeek()
}//end class
