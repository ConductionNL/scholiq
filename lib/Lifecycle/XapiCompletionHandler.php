<?php

/**
 * XapiCompletionHandler
 *
 * ADR-031 legitimate PHP exception: single-method lifecycle guard that bridges
 * an OR ObjectCreatedEvent (for XapiStatement objects) to an Enrolment lifecycle
 * transition. All other Enrolment behaviour is declared in lib/Settings/scholiq_register.json
 * via x-openregister-lifecycle / x-openregister-notifications / x-openregister-calculations.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects and, when the
 * statement represents the final mandatory lesson completion of a course,
 * dispatches the `complete` transition on the matching active Enrolment.
 *
 * ADR-031 §"Lifecycle guards": single-method handler, no state machine logic,
 * no notification dispatch, no audit writing — all delegated to OR via transition.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 *
 * @implements IEventListener<Event>
 */
class XapiCompletionHandler implements IEventListener
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for xAPI statement objects.
     *
     * C5 fix: use the real kebab-case slug from scholiq_register.json.
     */
    private const XAPI_SCHEMA = 'xapi-statement';

    /**
     * XAPI verb IRIs that indicate successful completion.
     */
    private const COMPLETION_VERBS = [
        'http://adlnet.gov/expapi/verbs/completed',
        'http://adlnet.gov/expapi/verbs/passed',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object service used to query Lessons and Enrolments.
     * @param TransitionEngine $transitionEngine OR lifecycle engine used to dispatch the `complete` transition.
     * @param LoggerInterface  $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an incoming ObjectCreatedEvent.
     *
     * Only acts on XapiStatement objects in the scholiq register.
     * Fires the `complete` transition on the learner's active Enrolment when:
     *   1. verb.id is `completed` or `passed`
     *   2. The related Lesson has mandatoryTraining=true
     *   3. The Lesson is the final published Lesson of its Course
     *
     * @param Event $event The dispatched event from OR.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        $objectEntity = $event->getObject();

        // Filter to XapiStatement objects in the scholiq register only.
        if ($objectEntity->getRegister() !== self::SCHOLIQ_REGISTER
            || $objectEntity->getSchema() !== self::XAPI_SCHEMA
        ) {
            return;
        }

        $payload  = $objectEntity->jsonSerialize();
        $tenantId = $payload['tenant_id'] ?? '';

        // Guard 1: verb must be completed/passed.
        $verbId = $payload['verb']['id'] ?? '';
        if (in_array($verbId, self::COMPLETION_VERBS, true) === false) {
            return;
        }

        // Guard 2: resolve the Lesson object from the xAPI object IRI/id.
        $lessonId = $payload['object']['id'] ?? null;
        if ($lessonId === null) {
            return;
        }

        // H1: scope Lesson lookup to the same tenant.
        $lessonFilters = ['xapiObjectId' => $lessonId];
        if ($tenantId !== '') {
            $lessonFilters['tenant_id'] = $tenantId;
        }

        $lessons = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'lesson',
                'filters'  => $lessonFilters,
                'limit'    => 1,
            ]
        );

        if (empty($lessons) === true) {
            return;
        }

        $lesson = $lessons[0];

        // Guard 3: lesson must be mandatory training.
        if (($lesson['mandatoryTraining'] ?? false) !== true) {
            return;
        }

        $courseId = $lesson['courseId'] ?? null;
        if ($courseId === null) {
            return;
        }

        // Guard 4: lesson must be the final published lesson of the course.
        // #200: sort by `order` field (not insertion order) to find the true last lesson.
        // H1: scope to the same tenant.
        $publishedLessonFilters = ['courseId' => $courseId, 'lifecycle' => 'published'];
        if ($tenantId !== '') {
            $publishedLessonFilters['tenant_id'] = $tenantId;
        }

        $publishedLessons = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'lesson',
                'filters'  => $publishedLessonFilters,
                'sort'     => ['order' => 'ASC'],
            ]
        );

        if (empty($publishedLessons) === true) {
            return;
        }

        // Find the lesson with the highest `order` value — that is the final lesson.
        $maxOrder   = -1;
        $lastLesson = null;
        foreach ($publishedLessons as $pl) {
            if (is_array($pl) === true) {
                $plData = $pl;
            } else {
                $plData = $pl->jsonSerialize();
            }

            $order = (int) ($plData['order'] ?? 0);
            if ($order > $maxOrder) {
                $maxOrder   = $order;
                $lastLesson = $plData;
            }
        }//end foreach

        if (is_array($lesson) === true) {
            $lessonData = $lesson;
        } else {
            $lessonData = $lesson->jsonSerialize();
        }

        if ($lastLesson === null || ($lastLesson['uuid'] ?? null) !== ($lessonData['uuid'] ?? null)) {
            return;
        }

        // C6 fix: resolve learner identity ONLY from the server-trusted `verified_actor_id`
        // field, which is stamped by the authenticated xAPI ingest controller before OR
        // writes the statement. NEVER read from payload.actor.* — those values are
        // user-controlled and allow credential forgery (attacker sets actor.account.name
        // to victim UUID → handler fires → victim enrolment auto-completes → signed OB3
        // credential minted under victim learnerId).
        $learnerId = $payload['verified_actor_id'] ?? null;

        if ($learnerId === null || $learnerId === '') {
            $this->logger->warning(
                '[XapiCompletionHandler] xAPI statement missing verified_actor_id; skipping. '
                .'Ensure the xAPI ingest controller stamps this field on authenticated saves.'
            );
            return;
        }

        // H1: scope Enrolment lookup to the same tenant.
        $enrolmentFilters = ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => 'active'];
        if ($tenantId !== '') {
            $enrolmentFilters['tenant_id'] = $tenantId;
        }

        $enrolments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'enrolment',
                'filters'  => $enrolmentFilters,
                'limit'    => 1,
            ]
        );

        if (empty($enrolments) === true) {
            $this->logger->info(
                '[XapiCompletionHandler] No active Enrolment found for learner {learner} course {course}; skipping.',
                ['learner' => $learnerId, 'course' => $courseId]
            );
            return;
        }

        if (is_array($enrolments[0]) === true) {
            $enrolmentData = $enrolments[0];
        } else {
            $enrolmentData = $enrolments[0]->jsonSerialize();
        }

        // #179: secondary integrity check — the enrolment's own learnerId must
        // match the actor claim to prevent a statement for learner A inadvertently
        // completing learner B's enrolment if there is a lookup collision.
        if (($enrolmentData['learnerId'] ?? '') !== $learnerId) {
            $this->logger->warning(
                '[XapiCompletionHandler] Enrolment learnerId mismatch — actor claim rejected.',
                ['claimed' => $learnerId, 'enrolled' => $enrolmentData['learnerId'] ?? '']
            );
            return;
        }

        $enrolmentId = $enrolmentData['uuid'];

        // Dispatch the `complete` transition. OR's lifecycle engine emits the
        // enrolment.completed audit entry and the completionOnComplete notification
        // automatically — no additional PHP code needed here.
        $this->transitionEngine->transition($enrolmentId, 'complete');

        $this->logger->info(
            '[XapiCompletionHandler] Enrolment {id} transitioned to completed via xAPI statement.',
            ['id' => $enrolmentId]
        );

    }//end handle()
}//end class
