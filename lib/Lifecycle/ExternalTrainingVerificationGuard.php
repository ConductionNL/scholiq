<?php

/**
 * Scholiq External Training Verification Guard
 *
 * Lifecycle guard for the ExternalTrainingRecord schema's
 * `submitted → verified` transition. Called by OpenRegister's lifecycle
 * engine when an officer/HR/admin verifies an externally-completed training.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards": the
 * verification gate combines an actor-role check, an evidence-attachment
 * precondition, and a self-verification guard that cannot be expressed
 * declaratively. Per ADR-008 OR emits the audit-trail entry automatically when
 * the transition completes — this guard records nothing itself.
 *
 * Per ADR-022: evidence files are OpenRegister file attachments on the object;
 * this guard reads the attachment list via OR's ObjectService and never stores
 * bytes locally.
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
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExternalTrainingRecord `submitted → verified` transition.
 *
 * The transition proceeds only when ALL of the following hold:
 *   1. The acting user is in one of the privileged groups
 *      (`compliance-officer`, `hr`, `admin`).
 *   2. At least one OpenRegister file attachment (evidence) is present on the
 *      record.
 *   3. The verifier is not the same person who submitted the record when the
 *      record was self-submitted by the learner (`verifiedBy != submittedBy`).
 *
 * On success it stamps `verifiedBy` and `verifiedAt` into the transition
 * payload so OR persists them on the verified record.
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingVerificationGuard
{
    /**
     * Groups whose members may verify an external-training record.
     *
     * @var string[]
     */
    private const VERIFIER_GROUPS = [
        'admin',
        'compliance-officer',
        'hr',
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager OR/NC group manager to resolve the
     *                                       acting user's role groups.
     * @param IUserManager    $userManager  User manager to resolve the acting
     *                                       user object for membership checks.
     * @param LoggerInterface $logger       PSR logger for guard rejections.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the verification preconditions and stamp the verifier.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `submitted → verified` transition. Returns true to allow the transition
     * (and writes `verifiedBy`/`verifiedAt` into the payload), false to block
     * it with HTTP 422.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'     : the record
     *                                               property array
     *                                               - 'actor'      : NC user ID
     *                                               of the verifier
     *                                               - 'transition' : 'verify'
     *                                               - 'payload'    : mutable
     *                                               array; verifier fields are
     *                                               written here
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/external-training-recording/tasks.md
     */
    public function check(array &$transitionContext): bool
    {
        $object      = $transitionContext['object'] ?? [];
        $actor       = (string) ($transitionContext['actor'] ?? '');
        $submittedBy = (string) ($object['submittedBy'] ?? '');

        if ($actor === '') {
            $this->logger->warning('[ExternalTrainingVerificationGuard] No actor in transitionContext — denying verify.');
            return false;
        }

        // Step 1 — actor must be in a privileged verifier group.
        if ($this->actorIsVerifier(actor: $actor) === false) {
            $this->logger->info(
                '[ExternalTrainingVerificationGuard] Actor is not in a verifier group — denying verify.',
                ['actor' => $actor]
            );
            return false;
        }

        // Step 2 — at least one evidence file attachment must be present.
        if ($this->hasEvidenceAttachment(object: $object) === false) {
            $this->logger->info(
                '[ExternalTrainingVerificationGuard] No evidence attachment present — denying verify.',
                ['record' => ($object['id'] ?? '')]
            );
            return false;
        }

        // Step 3 — a learner self-submission may not be self-verified.
        if ($submittedBy !== '' && $submittedBy === $actor) {
            $this->logger->info(
                '[ExternalTrainingVerificationGuard] Verifier equals submitter (self-verification) — denying verify.',
                ['actor' => $actor]
            );
            return false;
        }

        // Stamp the verifier on the payload so OR persists it on the verified record.
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $transitionContext['payload']['verifiedBy'] = $actor;
        $transitionContext['payload']['verifiedAt'] = $now;

        return true;
    }//end check()

    /**
     * Whether the acting user is in one of the verifier groups.
     *
     * @param string $actor NC user ID of the verifier.
     *
     * @return bool True when the user is in admin / compliance-officer / hr.
     */
    private function actorIsVerifier(string $actor): bool
    {
        $user = $this->userManager->get($actor);
        if ($user === null) {
            return false;
        }

        $actorGroups = $this->groupManager->getUserGroupIds($user);

        return count(array_intersect($actorGroups, self::VERIFIER_GROUPS)) > 0;
    }//end actorIsVerifier()

    /**
     * Whether the record carries at least one OpenRegister file attachment.
     *
     * OR exposes attachments on the serialised object under `@self.files` (the
     * canonical attachment list) or a legacy `files` array. A non-empty list of
     * either satisfies the evidence precondition.
     *
     * @param array<string,mixed> $object The record property array.
     *
     * @return bool True when one or more evidence attachments are present.
     */
    private function hasEvidenceAttachment(array $object): bool
    {
        $self = $object['@self'] ?? [];
        if (is_array($self) === true && empty($self['files'] ?? []) === false) {
            return true;
        }

        if (empty($object['files'] ?? []) === false && is_array($object['files']) === true) {
            return true;
        }

        return false;
    }//end hasEvidenceAttachment()
}//end class
