<?php

/**
 * Scholiq Lesson Progress Handler
 *
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects — the SAME
 * event XapiCompletionHandler already consumes — and, for every resolvable
 * completed/passed statement, upserts a per-(learnerId, lessonId)
 * LessonCompletion row. Deliberately a sibling listener, NOT an edit to
 * XapiCompletionHandler:
 *   1. Single responsibility per ADR-031 — XapiCompletionHandler's job is
 *      "decide whether an Enrolment completes"; this handler's job is
 *      "record that a Lesson was completed" — different questions with
 *      different guards.
 *   2. XapiCompletionHandler's mandatoryTraining/last-lesson gates are
 *      deliberate compliance-attestation logic (feeds Attestation.
 *      xapiStatementId) that must NOT loosen just because progress-tracking
 *      wants a broader trigger.
 *
 * This handler applies NO mandatoryTraining filter and NO last-lesson
 * filter — every resolvable completed/passed xAPI statement for a Lesson
 * produces or updates a LessonCompletion row, independent of whether that
 * same statement also happens to trigger an Enrolment completion via
 * XapiCompletionHandler.
 *
 * ADR-031 legitimate exception: single-method lifecycle-guard-equivalent
 * bridge from an OR ObjectCreatedEvent to a LessonCompletion object write.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Upserts a LessonCompletion for every resolvable completed/passed xAPI
 * statement — no mandatoryTraining or last-lesson gate.
 *
 * @implements IEventListener<Event>
 */
class LessonProgressHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const XAPI_SCHEMA      = 'xapi-statement';
    private const LESSON_SCHEMA    = 'lesson';
    private const LESSON_COMPLETION_SCHEMA = 'lesson-completion';
    private const ENROLMENT_SCHEMA         = 'enrolment';

    /**
     * XAPI verb IRIs that indicate successful completion.
     *
     * Deliberately duplicated from XapiCompletionHandler::COMPLETION_VERBS
     * (a private constant, not accessible cross-class in PHP) rather than
     * loosening that class's visibility — XapiCompletionHandler itself is
     * not modified by this change. Keep both lists in sync if the xAPI
     * completion-verb vocabulary ever changes.
     *
     * @var string[]
     */
    private const COMPLETION_VERBS = [
        'http://adlnet.gov/expapi/verbs/completed',
        'http://adlnet.gov/expapi/verbs/passed',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service used to query/write objects.
     * @param ITimeFactory    $timeFactory   NC time source (injectable "now" for tests).
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an incoming ObjectCreatedEvent.
     *
     * @param Event $event The dispatched event from OR.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
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

        $lesson = $this->resolveLesson(payload: $payload, tenantId: $tenantId);
        if ($lesson === null) {
            // No resolvable Lesson — skipped without error, exactly like
            // XapiCompletionHandler skips an unresolvable object id.
            return;
        }

        $lessonId = $lesson['id'] ?? ($lesson['uuid'] ?? null);
        $courseId = $lesson['courseId'] ?? null;
        if ($lessonId === null || $courseId === null) {
            return;
        }

        // C6: resolve learner identity ONLY from the server-trusted
        // verified_actor_id field — the same trust boundary
        // XapiCompletionHandler enforces. NEVER read payload.actor.*.
        $learnerId = $payload['verified_actor_id'] ?? null;
        if ($learnerId === null || $learnerId === '') {
            $this->logger->warning(
                '[LessonProgressHandler] xAPI statement missing verified_actor_id; skipping. '
                .'Ensure the xAPI ingest controller stamps this field on authenticated saves.'
            );
            return;
        }

        $enrolmentId = $this->resolveActiveEnrolmentId(
            learnerId: $learnerId,
            courseId: $courseId,
            tenantId: $tenantId
        );

        $score = $payload['result']['score']['scaled'] ?? null;

        $this->upsertLessonCompletion(
            learnerId: $learnerId,
            lessonId: $lessonId,
            courseId: $courseId,
            enrolmentId: $enrolmentId,
            verb: $verbId,
            score: $score,
            tenantId: $tenantId
        );

    }//end handle()

    /**
     * Resolve the Lesson referenced by the xAPI statement's object id — the
     * same xapiObjectId lookup XapiCompletionHandler already uses.
     *
     * @param array<string, mixed> $payload  The XapiStatement payload.
     * @param string               $tenantId Tenant scope for the lookup.
     *
     * @return array<string, mixed>|null
     */
    private function resolveLesson(array $payload, string $tenantId): ?array
    {
        $lessonObjectId = $payload['object']['id'] ?? null;
        if ($lessonObjectId === null) {
            return null;
        }

        $lessonFilters = ['xapiObjectId' => $lessonObjectId];
        if ($tenantId !== '') {
            $lessonFilters['tenant_id'] = $tenantId;
        }

        $lessons = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LESSON_SCHEMA,
                'filters'  => $lessonFilters,
                'limit'    => 1,
            ]
        );

        if (empty($lessons) === true) {
            return null;
        }

        $lesson = $lessons[0];
        if (is_array($lesson) === false) {
            $lesson = $lesson->jsonSerialize();
        }

        return $lesson;

    }//end resolveLesson()

    /**
     * Resolve the learner's active Enrolment id for this Course, if any.
     *
     * @param string $learnerId NC user ID of the learner.
     * @param string $courseId  UUID of the Course.
     * @param string $tenantId  Tenant scope for the lookup.
     *
     * @return string|null
     */
    private function resolveActiveEnrolmentId(string $learnerId, string $courseId, string $tenantId): ?string
    {
        $filters = [
            'learnerId' => $learnerId,
            'courseId'  => $courseId,
            'lifecycle' => 'active',
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $enrolments = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENROLMENT_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($enrolments) === true) {
            return null;
        }

        $enrolment = $enrolments[0];
        if (is_array($enrolment) === false) {
            $enrolment = $enrolment->jsonSerialize();
        }

        return $enrolment['id'] ?? ($enrolment['uuid'] ?? null);

    }//end resolveActiveEnrolmentId()

    /**
     * Create or update the LessonCompletion for (learnerId, lessonId).
     *
     * A duplicate completion statement for the same learner+lesson updates
     * the existing row's completedAt/verb/score rather than duplicating it —
     * LessonCompletion is an upsert target, not an append-only log.
     *
     * @param string      $learnerId   NC user ID of the learner.
     * @param string      $lessonId    UUID of the completed Lesson.
     * @param string      $courseId    UUID of the Lesson's parent Course.
     * @param string|null $enrolmentId UUID of the learner's active Enrolment, if any.
     * @param string      $verb        The xAPI verb IRI.
     * @param float|null  $score       Optional result.score.scaled value.
     * @param string      $tenantId    Tenant identifier.
     *
     * @return void
     *
     * @spec openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-a-duplicate-completion-statement-for-the-same-lesson-updates-not-duplicates
     */
    private function upsertLessonCompletion(
        string $learnerId,
        string $lessonId,
        string $courseId,
        ?string $enrolmentId,
        string $verb,
        ?float $score,
        string $tenantId,
    ): void {
        $existingFilters = [
            'learnerId' => $learnerId,
            'lessonId'  => $lessonId,
        ];

        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LESSON_COMPLETION_SCHEMA,
                'filters'  => $existingFilters,
                'limit'    => 1,
            ]
        );

        $existingData = null;
        if (empty($existing) === false) {
            $existingData = $existing[0];
            if (is_array($existingData) === false) {
                $existingData = $existingData->jsonSerialize();
            }
        }

        $completedAt = $this->timeFactory->getDateTime()->format(\DATE_ATOM);

        $data = array_merge(
            $existingData ?? [],
            [
                'learnerId'   => $learnerId,
                'lessonId'    => $lessonId,
                'courseId'    => $courseId,
                'enrolmentId' => $enrolmentId,
                'source'      => 'xapi',
                'verb'        => $verb,
                'score'       => $score,
                'completedAt' => $completedAt,
                'tenant_id'   => $tenantId,
            ]
        );

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::LESSON_COMPLETION_SCHEMA,
            object: $data
        );

    }//end upsertLessonCompletion()
}//end class
