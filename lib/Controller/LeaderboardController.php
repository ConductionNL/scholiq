<?php

/**
 * Scholiq Leaderboard Controller
 *
 * One read endpoint: `getRankings`. This exists because the RBAC gap
 * documented in design.md ("no cross-object 'cohort-mate' RBAC primitive" --
 * the same gap SupportRequest/TlvApplication/PupilVoice already document)
 * means the raw OpenRegister object API cannot serve a peer-visible ranking:
 * LearnerEngagement's x-property-rbac only allows admin + self-match reads.
 *
 * Four gates compose in this one controller method:
 *   1. An `active` Leaderboard row exists for the requested cohort (the
 *      opt-in gate -- no seed data, no tenant-wide switch, see design.md
 *      "Pedagogical posture").
 *   2. The caller is a member of that cohort (admin, or the caller's NC user
 *      id is in Cohort.learnerIds/teacherIds) -- the authorization gate
 *      x-property-rbac cannot express.
 *   3. Each candidate learner's `pref_leaderboardoptout` preference (read
 *      directly via IConfig, the same underlying store the existing
 *      preferences-api's GenericPreferencesController writes to -- its
 *      sanitizeKey() lowercases every key, so the on-disk key for a
 *      frontend-supplied `leaderboardOptOut` is `pref_leaderboardoptout`;
 *      this class uses the already-lowercase key directly) is not set (the
 *      per-learner opt-out gate).
 *   4. The response is a minimal `{learnerId, totalPoints, level, rank}`
 *      projection, never the raw LearnerEngagement object.
 *
 * NOT a pass-through CRUD wrapper (ADR-031 / hydra-gate-redundant-
 * controller): OR's object API already serves plain CRUD/list/filter
 * directly to the frontend for PointRule, EngagementLevel, PointAward, and a
 * learner's own LearnerEngagement row. This controller exists only for the
 * one read that genuinely cannot be expressed as a declarative RBAC rule
 * today -- the same class of exception RolloverController/PeerReviewController
 * already are in this codebase.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Serves the ranked, peer-visible leaderboard for a cohort, opt-in and
 * opt-out gated.
 *
 * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#requirement-a-ranked-leaderboard-is-opt-in-per-cohort-course-default-off-and-respects-a-per-learner-opt-out
 */
class LeaderboardController extends Controller
{

    private const SCHOLIQ_REGISTER   = 'scholiq';
    private const COHORT_SCHEMA      = 'cohort';
    private const LEADERBOARD_SCHEMA = 'leaderboard';
    private const LEARNER_ENGAGEMENT_SCHEMA = 'learner-engagement';
    private const ENGAGEMENT_LEVEL_SCHEMA   = 'engagement-level';

    /**
     * IConfig key the leaderboard opt-out preference is stored under. Already
     * lowercase -- GenericPreferencesController::sanitizeKey() lowercases
     * every key before storing, so an already-lowercase key avoids any
     * case-mapping ambiguity between the frontend's PUT and this read.
     */
    private const OPT_OUT_CONFIG_KEY = 'pref_leaderboardoptout';

    /**
     * Constructor.
     *
     * @param IRequest      $request       HTTP request.
     * @param IUserSession  $userSession   Current user session.
     * @param IGroupManager $groupManager  Group manager (admin check).
     * @param ObjectService $objectService OR object query/persistence.
     * @param IConfig       $config        Nextcloud config (opt-out preference reads).
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ObjectService $objectService,
        private readonly IConfig $config,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the ranked leaderboard for a cohort.
     *
     * @param string $cohortId UUID of the Cohort.
     *
     * @return JSONResponse `{results: {learnerId, totalPoints, level, rank}[]}`, or an error/denial response.
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-no-ranking-is-served-without-an-active-leaderboard
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-opted-out-learner-is-excluded-from-the-ranking-but-keeps-their-own-view
     */
    #[NoAdminRequired]
    public function getRankings(string $cohortId=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if ($cohortId === '') {
            return new JSONResponse(data: ['error' => 'cohortId is required'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $cohort = $this->fetchObject(id: $cohortId, schema: self::COHORT_SCHEMA);
        if ($cohort === null) {
            return new JSONResponse(data: ['error' => 'Cohort not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        if ($this->isCohortMember(user: $user, cohort: $cohort) === false) {
            return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
        }

        if ($this->hasActiveLeaderboard(cohortId: $cohortId) === false) {
            return new JSONResponse(data: ['error' => 'No active leaderboard for this cohort'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $learnerIds = $cohort['learnerIds'] ?? [];
        if (is_array($learnerIds) === false) {
            $learnerIds = [];
        }

        $tenantId   = $cohort['tenant_id'] ?? '';
        $levelNames = $this->fetchLevelNames(tenantId: $tenantId);
        $rankings   = $this->buildRankings(learnerIds: $learnerIds, tenantId: $tenantId, levelNames: $levelNames);

        $topN = $this->fetchLeaderboardTopN(cohortId: $cohortId);
        if ($topN !== null) {
            $rankings = array_slice($rankings, 0, $topN);
        }

        return new JSONResponse(data: ['results' => $rankings]);
    }//end getRankings()

    /**
     * True when the caller is admin, or their NC user id is in
     * Cohort.learnerIds/teacherIds.
     *
     * @param IUser               $user   The authenticated caller.
     * @param array<string,mixed> $cohort The Cohort data array.
     *
     * @return bool
     */
    private function isCohortMember(IUser $user, array $cohort): bool
    {
        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return true;
        }

        $uid        = $user->getUID();
        $learnerIds = $cohort['learnerIds'] ?? [];
        $teacherIds = $cohort['teacherIds'] ?? [];

        if (is_array($learnerIds) === true && in_array($uid, $learnerIds, true) === true) {
            return true;
        }

        if (is_array($teacherIds) === true && in_array($uid, $teacherIds, true) === true) {
            return true;
        }

        return false;
    }//end isCohortMember()

    /**
     * True when an `active` Leaderboard row exists scoped to this cohort.
     *
     * @param string $cohortId UUID of the Cohort.
     *
     * @return bool
     */
    private function hasActiveLeaderboard(string $cohortId): bool
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEADERBOARD_SCHEMA,
                'filters'  => [
                    'cohortId'  => $cohortId,
                    'lifecycle' => 'active',
                ],
                'limit'    => 1,
            ]
        );

        return empty($existing) === false;
    }//end hasActiveLeaderboard()

    /**
     * Fetch the active Leaderboard's topN display-limit hint, if any.
     *
     * @param string $cohortId UUID of the Cohort.
     *
     * @return int|null
     */
    private function fetchLeaderboardTopN(string $cohortId): ?int
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEADERBOARD_SCHEMA,
                'filters'  => [
                    'cohortId'  => $cohortId,
                    'lifecycle' => 'active',
                ],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            return null;
        }

        $row = $existing[0];
        if (is_array($row) === false) {
            $row = $row->jsonSerialize();
        }

        $topN = $row['topN'] ?? null;
        if ($topN === null) {
            return null;
        }

        return (int) $topN;
    }//end fetchLeaderboardTopN()

    /**
     * Build the levelId -> name map for a tenant.
     *
     * @param string $tenantId Tenant identifier.
     *
     * @return array<string,string>
     */
    private function fetchLevelNames(string $tenantId): array
    {
        $levels = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENGAGEMENT_LEVEL_SCHEMA,
                'filters'  => ['tenant_id' => $tenantId],
            ]
        );

        $names = [];
        foreach ($levels as $level) {
            if (is_array($level) === false) {
                $level = $level->jsonSerialize();
            }

            $id = $level['id'] ?? ($level['uuid'] ?? null);
            if ($id === null) {
                continue;
            }

            $names[$id] = $level['name'] ?? '';
        }

        return $names;
    }//end fetchLevelNames()

    /**
     * Build the sorted, opt-out-filtered ranking for a cohort's learners.
     *
     * @param array<int,mixed>     $learnerIds Cohort.learnerIds (not strictly typed at this
     *                                         boundary -- defensively re-checked below).
     * @param string               $tenantId   Tenant identifier.
     * @param array<string,string> $levelNames levelId -> name map.
     *
     * @return array<int, array{learnerId: string, totalPoints: float, level: string|null, rank: int}>
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-opted-out-learner-is-excluded-from-the-ranking-but-keeps-their-own-view
     */
    private function buildRankings(array $learnerIds, string $tenantId, array $levelNames): array
    {
        $engagementRows = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LEARNER_ENGAGEMENT_SCHEMA,
                'filters'  => ['tenant_id' => $tenantId],
            ]
        );

        $byLearnerId = [];
        foreach ($engagementRows as $row) {
            if (is_array($row) === false) {
                $row = $row->jsonSerialize();
            }

            $learnerId = $row['learnerId'] ?? null;
            if ($learnerId === null) {
                continue;
            }

            $byLearnerId[$learnerId] = $row;
        }

        $entries = [];
        foreach ($learnerIds as $learnerId) {
            if (is_string($learnerId) === false || $learnerId === '') {
                continue;
            }

            if ($this->isOptedOut(learnerId: $learnerId) === true) {
                continue;
            }

            $row         = $byLearnerId[$learnerId] ?? null;
            $totalPoints = (float) ($row['totalPoints'] ?? 0);
            $levelId     = $row['levelId'] ?? null;

            $levelName = null;
            if ($levelId !== null) {
                $levelName = $levelNames[$levelId] ?? null;
            }

            $entries[] = [
                'learnerId'   => $learnerId,
                'totalPoints' => $totalPoints,
                'level'       => $levelName,
            ];
        }//end foreach

        usort($entries, static fn (array $a, array $b) => $b['totalPoints'] <=> $a['totalPoints']);

        $rank = 0;
        return array_map(
            static function (array $entry) use (&$rank) {
                $rank++;
                $entry['rank'] = $rank;
                return $entry;
            },
            $entries
        );
    }//end buildRankings()

    /**
     * True when the learner has set the standing leaderboard opt-out preference.
     *
     * @param string $learnerId NC user ID.
     *
     * @return bool
     *
     * @spec openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-an-opted-out-learner-is-excluded-from-the-ranking-but-keeps-their-own-view
     */
    private function isOptedOut(string $learnerId): bool
    {
        $value = $this->config->getUserValue(
            userId: $learnerId,
            appName: Application::APP_ID,
            key: self::OPT_OUT_CONFIG_KEY,
            default: ''
        );

        return $value !== '';
    }//end isOptedOut()

    /**
     * Fetch an object by id/schema, normalising to an array whether OR returns
     * an array or an object exposing jsonSerialize().
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string,mixed>|null
     */
    private function fetchObject(string $id, string $schema): ?array
    {
        $obj = $this->objectService->find(
            id: $id,
            register: self::SCHOLIQ_REGISTER,
            schema: $schema
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $serialized = $obj->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end fetchObject()
}//end class
