<?php

/**
 * Scholiq LeaderboardController unit tests.
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
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohort-course-default-off-and-respects-a-per-learner-opt-out
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\LeaderboardController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LeaderboardController::getRankings().
 */
class LeaderboardControllerTest extends TestCase
{

    private const COHORT_ID = 'cohort-1';

    /**
     * Per-user opt-out flags, keyed by learnerId. True = opted out.
     *
     * @var array<string,bool>
     */
    private array $optedOut = [];

    /**
     * Build a controller with the given fixtures.
     *
     * @param array<string,mixed>|null $cohort         Cohort fixture, or null (not found).
     * @param bool                     $leaderboardActive Whether an active Leaderboard row exists for the cohort.
     * @param array<int,array>         $engagementRows LearnerEngagement rows in the tenant.
     * @param array<int,array>         $levels         EngagementLevel rows in the tenant.
     * @param bool                     $isAdmin        Whether the caller is a Nextcloud admin.
     * @param string                   $uid            The caller's uid.
     * @param int|null                 $topN           Leaderboard.topN, if any.
     *
     * @return LeaderboardController
     */
    private function makeController(
        ?array $cohort,
        bool $leaderboardActive,
        array $engagementRows,
        array $levels,
        bool $isAdmin,
        string $uid = 'learner-1',
        ?int $topN = null,
    ): LeaderboardController {
        $objectService = $this->createMock(ObjectService::class);

        $objectService->method('find')->willReturnCallback(
            function (string $id, string $register, string $schema) use ($cohort) {
                if ($schema === 'cohort') {
                    return $cohort;
                }

                return null;
            }
        );

        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($leaderboardActive, $engagementRows, $levels, $topN) {
                if ($config['schema'] === 'leaderboard') {
                    if ($leaderboardActive === false) {
                        return [];
                    }

                    return [['id' => 'lb-1', 'cohortId' => self::COHORT_ID, 'lifecycle' => 'active', 'topN' => $topN]];
                }

                if ($config['schema'] === 'learner-engagement') {
                    return $engagementRows;
                }

                if ($config['schema'] === 'engagement-level') {
                    return $levels;
                }

                return [];
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            function (string $userId) {
                return ($this->optedOut[$userId] ?? false) === true ? '1' : '';
            }
        );

        return new LeaderboardController(
            request: $this->createMock(IRequest::class),
            userSession: $userSession,
            groupManager: $groupManager,
            objectService: $objectService,
            config: $config,
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
     * No Leaderboard row at all for the cohort is refused.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-no-ranking-is-served-without-an-active-leaderboard
     */
    public function testNoLeaderboardRowRefused(): void
    {
        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => ['learner-1'], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: false,
            engagementRows: [],
            levels: [],
            isAdmin: false,
            uid: 'learner-1',
        );

        $response = $controller->getRankings(self::COHORT_ID);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testNoLeaderboardRowRefused()

    /**
     * A cohort member sees the ranking when an active Leaderboard exists.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-opted-out-learner-is-excluded-from-the-ranking-but-keeps-their-own-view
     */
    public function testActiveLeaderboardReturnsSortedRanking(): void
    {
        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => ['learner-1', 'learner-2'], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: true,
            engagementRows: [
                ['learnerId' => 'learner-1', 'totalPoints' => 10, 'levelId' => 'level-bronze'],
                ['learnerId' => 'learner-2', 'totalPoints' => 40, 'levelId' => 'level-silver'],
            ],
            levels: [
                ['id' => 'level-bronze', 'name' => 'Bronze'],
                ['id' => 'level-silver', 'name' => 'Silver'],
            ],
            isAdmin: false,
            uid: 'learner-1',
        );

        $response = $controller->getRankings(self::COHORT_ID);
        $results  = $this->body($response)['results'];

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertCount(2, $results);
        self::assertSame('learner-2', $results[0]['learnerId']);
        self::assertSame(1, $results[0]['rank']);
        self::assertSame('Silver', $results[0]['level']);
        self::assertSame('learner-1', $results[1]['learnerId']);
        self::assertSame(2, $results[1]['rank']);
    }//end testActiveLeaderboardReturnsSortedRanking()

    /**
     * An opted-out learner does not appear in the ranking another cohort
     * member requests.
     *
     * @return void
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-opted-out-learner-is-excluded-from-the-ranking-but-keeps-their-own-view
     */
    public function testOptedOutLearnerExcludedFromRanking(): void
    {
        $this->optedOut['learner-2'] = true;

        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => ['learner-1', 'learner-2'], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: true,
            engagementRows: [
                ['learnerId' => 'learner-1', 'totalPoints' => 10, 'levelId' => null],
                ['learnerId' => 'learner-2', 'totalPoints' => 99, 'levelId' => null],
            ],
            levels: [],
            isAdmin: false,
            uid: 'learner-1',
        );

        $response = $controller->getRankings(self::COHORT_ID);
        $results  = $this->body($response)['results'];

        self::assertCount(1, $results);
        self::assertSame('learner-1', $results[0]['learnerId']);
    }//end testOptedOutLearnerExcludedFromRanking()

    /**
     * A caller who is neither admin nor a listed cohort learner/teacher
     * receives a 403.
     *
     * @return void
     */
    public function testNonMemberReceives403(): void
    {
        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => ['learner-1'], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: true,
            engagementRows: [],
            levels: [],
            isAdmin: false,
            uid: 'random-user',
        );

        $response = $controller->getRankings(self::COHORT_ID);

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testNonMemberReceives403()

    /**
     * An admin caller is always authorized regardless of Cohort membership.
     *
     * @return void
     */
    public function testAdminIsAuthorized(): void
    {
        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => [], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: true,
            engagementRows: [],
            levels: [],
            isAdmin: true,
            uid: 'admin-user',
        );

        $response = $controller->getRankings(self::COHORT_ID);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testAdminIsAuthorized()

    /**
     * A missing Cohort returns 404.
     *
     * @return void
     */
    public function testMissingCohortReturns404(): void
    {
        $controller = $this->makeController(cohort: null, leaderboardActive: true, engagementRows: [], levels: [], isAdmin: true);

        $response = $controller->getRankings(self::COHORT_ID);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testMissingCohortReturns404()

    /**
     * A missing cohortId parameter returns 400.
     *
     * @return void
     */
    public function testMissingCohortIdReturns400(): void
    {
        $controller = $this->makeController(cohort: null, leaderboardActive: true, engagementRows: [], levels: [], isAdmin: true);

        $response = $controller->getRankings('');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testMissingCohortIdReturns400()

    /**
     * topN limits the returned ranking.
     *
     * @return void
     */
    public function testTopNLimitsResults(): void
    {
        $controller = $this->makeController(
            cohort: ['id' => self::COHORT_ID, 'learnerIds' => ['learner-1', 'learner-2', 'learner-3'], 'teacherIds' => [], 'tenant_id' => 'tenant-a'],
            leaderboardActive: true,
            engagementRows: [
                ['learnerId' => 'learner-1', 'totalPoints' => 10, 'levelId' => null],
                ['learnerId' => 'learner-2', 'totalPoints' => 20, 'levelId' => null],
                ['learnerId' => 'learner-3', 'totalPoints' => 30, 'levelId' => null],
            ],
            levels: [],
            isAdmin: false,
            uid: 'learner-1',
            topN: 2,
        );

        $response = $controller->getRankings(self::COHORT_ID);
        $results  = $this->body($response)['results'];

        self::assertCount(2, $results);
        self::assertSame('learner-3', $results[0]['learnerId']);
        self::assertSame('learner-2', $results[1]['learnerId']);
    }//end testTopNLimitsResults()
}//end class
