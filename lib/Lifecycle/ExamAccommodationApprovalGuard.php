<?php

/**
 * Scholiq Exam Accommodation Approval Guard
 *
 * Lifecycle guard for the ExamAccommodation schema's `approve` transition
 * (`requested → approved`). Restricted to admin/compliance-officer/mentor —
 * a learner MUST NOT be able to self-approve their own accommodation.
 *
 * DEVIATION FROM THE LITERAL "4 PHP classes" ENUMERATION in the timetabling
 * spec's "Frontend is declarative with named custom views" requirement text:
 * this is a 5th PHP class. No `x-openregister-authorization` key expresses a
 * per-transition role gate in this register — verified: every existing
 * `x-openregister-authorization` block in `scholiq_register.json` uses only
 * `create`/`update`/`read`/`delete` keys, never a transition action name — so
 * a small PHP lifecycle guard is the only ADR-031-legitimate seam, mirroring
 * `MunicipalityFeedbackGuard`'s identical "no declarative field/transition-
 * scoped write-authorization extension exists" rationale. The deviation is
 * documented here and on the schema's `approve` transition itself.
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-exam-accommodations-are-recorded-as-approved-evidence-backed-entitlements
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-cannot-self-approve-their-own-accommodation
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExamAccommodation `approve` transition.
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-exam-accommodations-are-recorded-as-approved-evidence-backed-entitlements
 */
class ExamAccommodationApprovalGuard
{

    /**
     * NC groups whose members may approve an ExamAccommodation.
     *
     * @var string[]
     */
    private const AUTHORISED_GROUPS = ['admin', 'compliance-officer', 'mentor'];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager OR/NC group manager to resolve the acting user's role groups.
     * @param IUserManager    $userManager  User manager to resolve the acting user object for membership checks.
     * @param LoggerInterface $logger       PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'  : the ExamAccommodation data array
     *                                               - 'actor'   : NC user ID of the requester
     *                                               - 'payload' : mutable array; approvedBy is stamped here
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-requests-an-accommodation-and-a-mentor-approves-it
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-learner-cannot-self-approve-their-own-accommodation
     */
    public function check(array &$transitionContext): bool
    {
        $actor = (string) ($transitionContext['actor'] ?? '');

        if ($actor === '') {
            $this->logger->info('[ExamAccommodationApprovalGuard] No actor in transitionContext — denying approve.');
            return false;
        }

        if ($this->actorIsAuthorised(actor: $actor) === false) {
            $this->logger->info(
                '[ExamAccommodationApprovalGuard] Actor {a} is not admin/compliance-officer/mentor — denying approve.',
                ['a' => $actor]
            );
            return false;
        }

        // Stamp approvedBy server-side — never trust a caller-supplied value,
        // mirroring MunicipalityFeedbackGuard's recordedBy stamping.
        $transitionContext['payload']['approvedBy'] = $actor;

        return true;

    }//end check()

    /**
     * Whether the acting user is a member of one of AUTHORISED_GROUPS.
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in admin/compliance-officer/mentor.
     */
    private function actorIsAuthorised(string $actor): bool
    {
        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::AUTHORISED_GROUPS)) > 0;

    }//end actorIsAuthorised()
}//end class
