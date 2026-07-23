<?php

/**
 * Scholiq Enrolment Prerequisite Listener
 *
 * Closes the "prerequisite hole": openspec/specs/enrolment/spec.md has required
 * "Validate prerequisites before persistence" since 2026-05-11, but no code ever
 * implemented it. This listener is that enforcement — a creation-time veto on
 * OpenRegister's `ObjectCreatingEvent` for the `enrolment` schema.
 *
 * WHY NOT A `Lifecycle/*Guard.php` (the obvious first instinct, since every
 * other guard in this codebase — CohortMembershipGuard, CoursePublishGuard,
 * AssessmentPublishGuard — wires via an `x-openregister-lifecycle.transitions.
 * *.requires` clause): OpenRegister's `LifecycleValidationListener` only
 * resolves a `requires` guard on `ObjectUpdatingEvent`, between two
 * already-persisted states, and bails immediately when there is no prior
 * object (`if ($oldObject === null) { return; }`). `Enrolment`'s lifecycle
 * (`pending -> active -> completed -> withdrawn|failed`) has no transition
 * *into* its initial `pending` state — the initial value is stamped by the
 * separate, non-blocking `LifecycleInitialStateListener` on
 * `ObjectCreatingEvent`, which never resolves a `requires` guard. A
 * `requires`-style guard can therefore never fire before an Enrolment's
 * first persist, no matter what transition name is invented. The only real
 * creation-time hook is the raw `ObjectCreatingEvent`
 * (`implements StoppableEventInterface`); `MagicMapper::insertObjectEntity()`
 * throws `HookStoppedException` and aborts the insert when a listener calls
 * `stopPropagation()`. This is precedented elsewhere in the fleet —
 * `apps-extra/decidesk/lib/Listener/SubmissionDeadlineListener.php` and
 * `apps-extra/larpingapp/lib/Listener/CharacterRequirementListener.php` both
 * already veto object creation this exact way — this listener follows that
 * shape rather than the unworkable `Lifecycle/*Guard.php` one.
 *
 * Enforcement posture (mirrors SubmissionDeadlineListener's own documented
 * split): fail CLOSED on an actually-unmet, successfully-checked
 * prerequisite (block the enrolment); fail OPEN on an infrastructure error
 * during the lookup (log a warning and allow) — a transient OpenRegister
 * read failure must never brick all enrolment.
 *
 * @category Listener
 * @package  OCA\Scholiq\Listener
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
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Blocks `Enrolment` creation when the target `Course`'s
 * `prerequisiteCourseIds` are not all satisfied by a `completed` `Enrolment`
 * the learner already holds.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence
 */
class EnrolmentPrerequisiteListener implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const ENROLMENT_SCHEMA = 'enrolment';
    private const COURSE_SCHEMA    = 'course';
    private const COMPLETED_STATE  = 'completed';

    /**
     * The spec rejection message template — `%s` is the failing prerequisite
     * course's display name (falling back to its UUID when unresolvable).
     *
     * @var string
     */
    public const REJECTION_MESSAGE_TEMPLATE = 'You must complete the prerequisite course "%s" before enrolling in this course.';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an OR object-creating event, filtering to the `enrolment` schema.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatingEvent === false) {
            return;
        }

        try {
            $this->evaluate(event: $event);
        } catch (Throwable $exception) {
            // Fail soft on infrastructure errors: the prerequisite rule must
            // never break the OR write path for unrelated objects (deliberate
            // — see class docblock).
            $this->logger->warning(
                '[EnrolmentPrerequisiteListener] Prerequisite check failed: {msg}',
                ['msg' => $exception->getMessage()]
            );
        }

    }//end handle()

    /**
     * Filter to `enrolment` creations, resolve the target Course, and reject
     * on the first unmet prerequisite (if any). Split out of handle() to
     * keep both methods under the cyclomatic-complexity threshold.
     *
     * @param ObjectCreatingEvent $event The event to evaluate.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#requirement-validate-prerequisites-before-persistence
     */
    private function evaluate(ObjectCreatingEvent $event): void
    {
        $entity = $event->getObject();
        if ($entity->getRegister() !== self::SCHOLIQ_REGISTER || $entity->getSchema() !== self::ENROLMENT_SCHEMA) {
            return;
        }

        $payload   = $entity->jsonSerialize();
        $courseId  = (string) ($payload['courseId'] ?? '');
        $learnerId = (string) ($payload['learnerId'] ?? '');
        if ($courseId === '' || $learnerId === '') {
            // No course/learner reference — nothing this listener can check;
            // OR's own `required` validation handles a missing field.
            return;
        }

        $tenantId = (string) ($payload['tenant_id'] ?? '');

        $course = $this->resolveObject(id: $courseId, schema: self::COURSE_SCHEMA);
        if ($course === null) {
            // Unresolvable course — an infrastructure/data-integrity
            // condition, not an unmet prerequisite. Fail open (OR's own
            // $ref/required validation is responsible for a genuinely
            // missing courseId).
            return;
        }

        $prereqIds = ($course['prerequisiteCourseIds'] ?? []);
        if (is_array($prereqIds) === false || $prereqIds === []) {
            return;
        }

        $unmet = $this->findUnmetPrerequisite(prereqIds: $prereqIds, learnerId: $learnerId, tenantId: $tenantId);
        if ($unmet !== null) {
            $this->rejectForUnmetPrerequisite(event: $event, prereqCourseId: $unmet);
        }

    }//end evaluate()

    /**
     * Return the first prerequisite course UUID the learner has NOT
     * completed, or null when every listed prerequisite is met.
     *
     * @param array<int, mixed> $prereqIds Candidate prerequisite Course UUIDs.
     * @param string            $learnerId NC user ID of the enrolling learner.
     * @param string            $tenantId  Tenant scope for the lookup ('' when unknown).
     *
     * @return string|null
     */
    private function findUnmetPrerequisite(array $prereqIds, string $learnerId, string $tenantId): ?string
    {
        foreach ($prereqIds as $prereqCourseId) {
            if (is_string($prereqCourseId) === false || $prereqCourseId === '') {
                continue;
            }

            if ($this->learnerHasCompleted(learnerId: $learnerId, courseId: $prereqCourseId, tenantId: $tenantId) === false) {
                return $prereqCourseId;
            }
        }

        return null;

    }//end findUnmetPrerequisite()

    /**
     * Whether the learner already holds a `completed` Enrolment for the
     * given course.
     *
     * @param string $learnerId NC user ID of the enrolling learner.
     * @param string $courseId  UUID of the prerequisite Course.
     * @param string $tenantId  Tenant scope for the lookup ('' when unknown).
     *
     * @return bool
     */
    private function learnerHasCompleted(string $learnerId, string $courseId, string $tenantId): bool
    {
        $filters = [
            'learnerId' => $learnerId,
            'courseId'  => $courseId,
            'lifecycle' => self::COMPLETED_STATE,
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $completed = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENROLMENT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        return empty($completed) === false;

    }//end learnerHasCompleted()

    /**
     * Reject the creating Enrolment for an unmet prerequisite, naming the
     * failing course.
     *
     * @param ObjectCreatingEvent $event          The event to stop.
     * @param string              $prereqCourseId UUID of the unmet prerequisite Course.
     *
     * @return void
     *
     * @spec openspec/changes/adaptive-release-and-prerequisites/specs/enrolment/spec.md#scenario-block-enrolment-when-prerequisites-are-unmet
     */
    private function rejectForUnmetPrerequisite(ObjectCreatingEvent $event, string $prereqCourseId): void
    {
        $prereqCourseName = $prereqCourseId;
        $prereqCourse     = $this->resolveObject(id: $prereqCourseId, schema: self::COURSE_SCHEMA);
        if ($prereqCourse !== null && is_string($prereqCourse['name'] ?? null) === true && $prereqCourse['name'] !== '') {
            $prereqCourseName = $prereqCourse['name'];
        }

        $event->setErrors(
            [
                'message'              => sprintf(self::REJECTION_MESSAGE_TEMPLATE, $prereqCourseName),
                'prerequisiteCourseId' => $prereqCourseId,
            ]
        );
        $event->stopPropagation();

        $this->logger->info(
            '[EnrolmentPrerequisiteListener] Blocked enrolment — unmet prerequisite course {course}',
            ['course' => $prereqCourseName]
        );

    }//end rejectForUnmetPrerequisite()

    /**
     * Resolve a scholiq-register object by id, returning it as a plain array.
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

        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end resolveObject()
}//end class
