<?php

/**
 * Scholiq Lesson Release Controller
 *
 * Computes, per request, whether a Lesson or Assessment is available to the
 * calling learner right now — a genuine per-(item, learner) gate decision
 * (adaptive release / drip scheduling), not a pass-through CRUD read.
 * Delegates all evaluation logic to {@see \OCA\Scholiq\Release\LessonReleaseEvaluator}.
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
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Release\LessonReleaseEvaluator;
use OCA\Scholiq\Service\DashboardRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * `GET /api/lessons/{lessonId}/release-status` and
 * `GET /api/assessments/{assessmentId}/release-status`.
 *
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 */
class LessonReleaseController extends Controller
{

    private const SCHOLIQ_REGISTER  = 'scholiq';
    private const LESSON_SCHEMA     = 'lesson';
    private const ASSESSMENT_SCHEMA = 'assessment';
    private const ENROLMENT_SCHEMA  = 'enrolment';

    /**
     * Scholiq roles (DashboardRoleService::resolveViews) that may view a
     * Lesson/Assessment's release status without holding a personal
     * Enrolment — staff previewing content, not the module's rank-and-file
     * learner path.
     *
     * @var string[]
     */
    private const STAFF_VIEWS = ['admin', 'teacher'];

    /**
     * Constructor.
     *
     * @param IRequest               $request              HTTP request.
     * @param IUserSession           $userSession          NC user session.
     * @param ObjectService          $objectService        OR object access service.
     * @param LessonReleaseEvaluator $releaseEvaluator     Stateless release-gate evaluator.
     * @param DashboardRoleService   $dashboardRoleService Resolves the caller's Scholiq role/views.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ObjectService $objectService,
        private readonly LessonReleaseEvaluator $releaseEvaluator,
        private readonly DashboardRoleService $dashboardRoleService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Release status for a Lesson.
     *
     * @param string $lessonId UUID of the Lesson.
     *
     * @return JSONResponse `{available, reason, availableAt}`, or an error.
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(string $lessonId): JSONResponse
    {
        return $this->resolveStatus(itemId: $lessonId, itemSchema: self::LESSON_SCHEMA);

    }//end status()

    /**
     * Release status for an Assessment.
     *
     * @param string $assessmentId UUID of the Assessment.
     *
     * @return JSONResponse `{available, reason, availableAt}`, or an error.
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/assessment/spec.md#requirement-assessment-declares-per-learner-release-conditions
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function assessmentStatus(string $assessmentId): JSONResponse
    {
        return $this->resolveStatus(itemId: $assessmentId, itemSchema: self::ASSESSMENT_SCHEMA);

    }//end assessmentStatus()

    /**
     * Shared resolution: authenticate, resolve the item, authorize the
     * caller, resolve their own Enrolment (if any), and evaluate.
     *
     * @param string $itemId     UUID of the Lesson or Assessment.
     * @param string $itemSchema 'lesson' or 'assessment'.
     *
     * @return JSONResponse
     */
    private function resolveStatus(string $itemId, string $itemSchema): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $item = $this->resolveObject(id: $itemId, schema: $itemSchema);
        } catch (Throwable $exception) {
            return new JSONResponse(
                data: ['error' => 'Failed to resolve the requested item'],
                statusCode: Http::STATUS_BAD_GATEWAY
            );
        }

        if ($item === null) {
            $notFoundLabel = 'Lesson not found';
            if ($itemSchema === self::ASSESSMENT_SCHEMA) {
                $notFoundLabel = 'Assessment not found';
            }

            return new JSONResponse(data: ['error' => $notFoundLabel], statusCode: Http::STATUS_NOT_FOUND);
        }

        $courseId = (string) ($item['courseId'] ?? '');
        $isStaff  = $this->callerIsStaff(user: $user);

        $enrolment = [];
        if ($courseId !== '') {
            $enrolment = $this->resolveEnrolment(learnerId: $user->getUID(), courseId: $courseId);
        }

        // Any authenticated learner holding an Enrolment for the item's
        // course may see its own release status; staff (admin/teacher-
        // equivalent) may always preview it, even without a personal
        // Enrolment. Everyone else is denied — this endpoint computes new
        // information (a lock/unlock decision), not a pass-through read.
        if ($enrolment === [] && $isStaff === false) {
            return new JSONResponse(
                data: ['error' => 'Not enrolled in the course this item belongs to'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $result = $this->releaseEvaluator->evaluate(
            item: $item,
            itemSchema: $itemSchema,
            learnerId: $user->getUID(),
            enrolment: $enrolment
        );

        return new JSONResponse(
            data: [
                'available'   => $result['available'],
                'reason'      => $result['reason'],
                'availableAt' => $result['availableAt'],
            ]
        );

    }//end resolveStatus()

    /**
     * Whether the caller holds a Scholiq staff (admin/teacher-equivalent)
     * view, per DashboardRoleService::resolveViews().
     *
     * @param IUser $user The authenticated caller.
     *
     * @return bool
     */
    private function callerIsStaff(IUser $user): bool
    {
        $views = $this->dashboardRoleService->resolveViews(user: $user);

        return count(array_intersect($views, self::STAFF_VIEWS)) > 0;

    }//end callerIsStaff()

    /**
     * Resolve the caller's own Enrolment for a course, or `[]` when none.
     *
     * @param string $learnerId NC user ID.
     * @param string $courseId  UUID of the Course.
     *
     * @return array<string, mixed>
     */
    private function resolveEnrolment(string $learnerId, string $courseId): array
    {
        $enrolments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENROLMENT_SCHEMA,
                'filters'  => [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                ],
                'limit'    => 1,
            ]
        );

        if (empty($enrolments) === true) {
            return [];
        }

        return $this->toArray(object: $enrolments[0]);

    }//end resolveEnrolment()

    /**
     * Resolve a Lesson/Assessment by UUID.
     *
     * @param string $id     UUID of the object.
     * @param string $schema Schema slug.
     *
     * @return array<string, mixed>|null
     */
    private function resolveObject(string $id, string $schema): ?array
    {
        $object = $this->objectService->find(id: $id, register: self::SCHOLIQ_REGISTER, schema: $schema);
        if ($object === null) {
            return null;
        }

        return $this->toArray(object: $object);

    }//end resolveObject()

    /**
     * Normalise an ObjectService result (array or ObjectEntity) to a plain array.
     *
     * @param mixed $object The result row.
     *
     * @return array<string, mixed>
     */
    private function toArray($object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end toArray()
}//end class
