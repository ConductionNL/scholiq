<?php

/**
 * Scholiq Course Evaluation Eligibility Guard
 *
 * Lifecycle guard for the CourseEvaluationResponse schema's `submit` transition
 * (`draft → submitted`). Enforces that the caller holds an eligible, not-yet-
 * responded EvaluationInvitation for the response's campaignId — the same
 * check structurally prevents both an uninvited submission and a second
 * submission from the same learner for the same campaign.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Mirrors ConferenceSignupGuardianGuard's shape exactly: resolves the
 * caller's identity server-side via IUserSession (never a client-supplied
 * claim), looks it up against a *different* schema (EvaluationInvitation)
 * via ObjectService::findAll(), and never reads or writes any identity
 * field onto the CourseEvaluationResponse object itself — the object this
 * guard runs against carries no learnerId/submittedBy property to read from
 * or write to in the first place (design.md Decision 2, "anonymity vs.
 * targeted reminders — the crux mechanism").
 *
 * Referenced from CourseEvaluationResponse's
 * x-openregister-lifecycle.transitions.submit.requires in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guards the CourseEvaluationResponse `submit` transition.
 *
 * Passes only when the caller (resolved via IUserSession) holds exactly one
 * EvaluationInvitation for the response's campaignId with
 * hasResponded:false. Fails closed on any lookup miss — no session, no
 * matching invitation, or an already-responded invitation all block the
 * transition.
 */
class CourseEvaluationEligibilityGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for EvaluationInvitation.
     */
    private const EVALUATION_INVITATION_SCHEMA = 'evaluation-invitation';

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession   Current NC user session (server-resolved caller identity).
     * @param ObjectService   $objectService OR object access service.
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
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `submit`
     * transition on a CourseEvaluationResponse object. Resolves the caller's
     * NC user id from the session (never from the request payload, and never
     * from the CourseEvaluationResponse object itself — it has no identity
     * field to read from) and passes only when that user holds an eligible,
     * not-yet-responded EvaluationInvitation for the response's campaignId.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the CourseEvaluationResponse data array
     *                                               - 'transition' : 'submit'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'submitted'
     *
     * @return bool True if the caller may submit this response; false blocks the transition.
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
     */
    public function check(array &$transitionContext): bool
    {
        $object     = $transitionContext['object'] ?? [];
        $campaignId = $object['campaignId'] ?? '';

        if ($campaignId === '') {
            $this->logger->warning(
                '[CourseEvaluationEligibilityGuard] CourseEvaluationResponse has no campaignId; blocking submit.'
            );
            return false;
        }

        $user = $this->userSession->getUser();

        if ($user === null) {
            $this->logger->info(
                '[CourseEvaluationEligibilityGuard] No authenticated user in session; blocking submit.'
            );
            return false;
        }

        $callerUid = $user->getUID();
        $tenantId  = $object['tenant_id'] ?? '';

        $filters = [
            'campaignId'   => $campaignId,
            'learnerId'    => $callerUid,
            'hasResponded' => false,
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
            $this->logger->info(
                '[CourseEvaluationEligibilityGuard] Caller {caller} has no eligible EvaluationInvitation for '
                .'campaign {campaignId} (no invitation, or already responded); blocking submit (fail closed).',
                ['caller' => $callerUid, 'campaignId' => $campaignId]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
