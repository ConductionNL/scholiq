<?php

/**
 * XapiCompletionHandler
 *
 * ADR-031 legitimate PHP exception: single-method lifecycle guard that bridges
 * an OR audit event (xapi.statement.received) to an Enrolment lifecycle transition.
 * All other Enrolment behaviour is declared in lib/Settings/scholiq_register.json
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for OR's xapi.statement.received audit event and, when the statement
 * represents the final mandatory lesson completion of a course, dispatches the
 * `complete` transition on the matching active Enrolment.
 *
 * ADR-031 §"Lifecycle guards": single-method handler, no state machine logic,
 * no notification dispatch, no audit writing — all delegated to OR via transition.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 */
class XapiCompletionHandler implements IEventListener
{

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
     * Handle an incoming xapi.statement.received event.
     *
     * Fires the `complete` transition on the learner's active Enrolment when:
     *   1. verb.id is `completed` or `passed`
     *   2. The related Lesson has mandatoryTraining=true
     *   3. The Lesson is the final published Lesson of its Course
     *
     * @param Event $event The dispatched event; payload is the xAPI statement JSON-decoded as array.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function handle(Event $event): void
    {
        // OR dispatches audit events carrying the raw payload via getData().
        if (method_exists($event, 'getData') === false) {
            return;
        }

        $payload = $event->getData();

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

        $lessons = $this->objectService->findAll(
            [
                'register' => 'scholiq',
                'schema'   => 'Lesson',
                'filters'  => ['xapiObjectId' => $lessonId],
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
        $publishedLessons = $this->objectService->findAll(
            [
                'register' => 'scholiq',
                'schema'   => 'Lesson',
                'filters'  => ['courseId' => $courseId, 'lifecycle' => 'published'],
            ]
        );

        $lessonIds    = array_column($publishedLessons, 'uuid');
        $lastLessonId = end($lessonIds);

        if ($lastLessonId !== ($lesson['uuid'] ?? null)) {
            return;
        }

        // Resolve the learner ID from the xAPI actor.
        $learnerId = $payload['actor']['account']['name'] ?? $payload['actor']['mbox'] ?? null;

        if ($learnerId === null) {
            $this->logger->warning('[XapiCompletionHandler] No actor identifier in xAPI statement; skipping.');
            return;
        }

        // Find the active Enrolment for this learner + course.
        $enrolments = $this->objectService->findAll(
            [
                'register' => 'scholiq',
                'schema'   => 'Enrolment',
                'filters'  => [
                    'learnerId' => $learnerId,
                    'courseId'  => $courseId,
                    'lifecycle' => 'active',
                ],
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

        $enrolmentId = $enrolments[0]['uuid'];

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
