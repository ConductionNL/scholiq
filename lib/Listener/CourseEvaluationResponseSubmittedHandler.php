<?php

/**
 * Scholiq Course Evaluation Response Submitted Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to the
 * CourseEvaluationResponse schema's `submit` transition. Re-resolves the
 * SAME session-caller identity CourseEvaluationEligibilityGuard used
 * (IUserSession — never read from the event payload, because it was never
 * written there) and flips that learner's own EvaluationInvitation to
 * hasResponded:true / respondedAt:now.
 *
 * This is the second half of design.md Decision 2's "crux mechanism": the
 * identity is used transiently, at request time, exactly twice (once by the
 * guard to authorise, once here to flip the invitation) and is never
 * persisted onto CourseEvaluationResponse or into EvaluationInvitation in a
 * form that points back to a specific response — this handler writes ONLY
 * hasResponded/respondedAt onto the invitation, never a field referencing
 * the submitted response's identity or content.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration." Mirrors
 * GradeRollupHandler's find-and-update shape.
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-successful-submission-flips-the-invitation-without-linking-to-the-response
 */

declare(strict_types=1);

namespace OCA\Scholiq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Bridges CourseEvaluationResponse `submit` → the caller's own EvaluationInvitation flip.
 *
 * @implements IEventListener<Event>
 */
class CourseEvaluationResponseSubmittedHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const COURSE_EVALUATION_RESPONSE_SCHEMA = 'course-evaluation-response';
    private const EVALUATION_INVITATION_SCHEMA      = 'evaluation-invitation';

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession   Current NC user session (server-resolved caller identity).
     * @param ObjectService   $objectService OpenRegister object access.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectTransitionedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-successful-submission-flips-the-invitation-without-linking-to-the-response
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER
            || $event->getSchema() !== self::COURSE_EVALUATION_RESPONSE_SCHEMA
            || $event->getTo() !== 'submitted'
        ) {
            return;
        }

        // The response itself carries no identity field to read — the caller
        // is re-resolved from the session, exactly as CourseEvaluationEligibilityGuard
        // did moments earlier for the same request.
        $user = $this->userSession->getUser();
        if ($user === null) {
            $this->logger->warning(
                '[CourseEvaluationResponseSubmittedHandler] No authenticated user in session after a '
                .'CourseEvaluationResponse submit; cannot flip any EvaluationInvitation.'
            );
            return;
        }

        $callerUid = $user->getUID();

        $response   = $event->getObject()->jsonSerialize();
        $campaignId = $response['campaignId'] ?? '';

        if ($campaignId === '') {
            $this->logger->warning(
                '[CourseEvaluationResponseSubmittedHandler] Submitted CourseEvaluationResponse has no '
                .'campaignId; cannot resolve which EvaluationInvitation to flip.'
            );
            return;
        }

        $tenantId = $response['tenant_id'] ?? '';

        $filters = [
            'campaignId' => $campaignId,
            'learnerId'  => $callerUid,
        ];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $invitations = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::EVALUATION_INVITATION_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        if (empty($invitations) === true) {
            // The guard already required this invitation to exist; a miss here
            // means it was removed between the guard check and this listener
            // firing — log and no-op rather than fabricate one.
            $this->logger->warning(
                '[CourseEvaluationResponseSubmittedHandler] No EvaluationInvitation found for caller '
                .'{caller} / campaign {campaignId} at submit-handling time.',
                ['caller' => $callerUid, 'campaignId' => $campaignId]
            );
            return;
        }

        $invitation = $invitations[0];
        if (is_array($invitation) === false) {
            $invitation = $invitation->jsonSerialize();
        }

        // Only hasResponded/respondedAt change — no field referencing the
        // response's identity or content is ever added.
        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::EVALUATION_INVITATION_SCHEMA,
            object: array_merge(
                $invitation,
                [
                    'hasResponded' => true,
                    'respondedAt'  => (new DateTimeImmutable())->format(\DATE_ATOM),
                ]
            )
        );

    }//end handle()
}//end class
