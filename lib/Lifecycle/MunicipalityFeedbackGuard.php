<?php

/**
 * Scholiq Municipality Feedback Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `recordMunicipalityFeedback`
 * transition — a self-loop (`succeeded` → `succeeded`) used solely to attach a
 * PHP authorisation check to a plain field write. This register has no
 * declarative field-scoped write-authorization extension (`x-property-rbac`
 * only expresses whole-object `read` gates, and `x-openregister-authorization`
 * only expresses whole-operation `create` gates — verified at HEAD, see
 * design.md "Security Considerations"). Mirrors the pattern already used by
 * ExternalTrainingVerificationGuard (role-group check + server-side stamping
 * of identity/timestamp fields, never trusting caller-supplied values).
 *
 * ADR-031 legitimate exception: no `x-openregister-*` extension expresses a
 * field-scoped write-authorization gate on a non-transition update.
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
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
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
 * Guards the DataExchangeJob `recordMunicipalityFeedback` self-loop transition.
 *
 * The transition proceeds only when ALL of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinator`).
 *   2. The job's `target` is `leerplicht` — municipalityFeedback (the MAS-route)
 *      only makes sense for a verzuimloket report to a municipality.
 *
 * On success it stamps `municipalityFeedback.recordedBy` (always the acting
 * user, never a caller-supplied value) and `municipalityFeedback.receivedAt`
 * (only when not already supplied) into the transition payload.
 *
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
 */
class MunicipalityFeedbackGuard
{

    /**
     * The only DataExchangeJob target municipalityFeedback applies to.
     */
    private const LEERPLICHT_TARGET = 'leerplicht';

    /**
     * Groups whose members may record municipality feedback.
     *
     * @var string[]
     */
    private const AUTHORISED_GROUPS = [
        'admin',
        'coordinator',
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager OR/NC group manager to resolve the
     *                                      acting user's role groups.
     * @param IUserManager    $userManager  User manager to resolve the acting
     *                                      user object for membership checks.
     * @param LoggerInterface $logger       PSR logger for guard rejections.
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
     * Assert the recording preconditions and stamp the recorder.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `succeeded → succeeded` recordMunicipalityFeedback transition. Returns
     * true to allow the transition (and writes `municipalityFeedback.recordedBy`
     * / `.receivedAt` into the payload), false to block it.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine. Expected
     *                                               keys:
     *                                               - 'object'     : the
     *                                               DataExchangeJob data array
     *                                               - 'actor'      : NC user ID
     *                                               of the requester
     *                                               - 'transition' :
     *                                               'recordMunicipalityFeedback'
     *                                               - 'payload'    : mutable
     *                                               array; municipalityFeedback
     *                                               fields are written here
     *
     * @return bool True when the transition is allowed; false blocks it.
     *
     * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
     */
    public function check(array &$transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $actor  = (string) ($transitionContext['actor'] ?? '');
        $target = (string) ($object['target'] ?? '');

        if ($actor === '') {
            $this->logger->warning(
                '[MunicipalityFeedbackGuard] No actor in transitionContext — denying recordMunicipalityFeedback.'
            );
            return false;
        }

        if ($target !== self::LEERPLICHT_TARGET) {
            $this->logger->info(
                '[MunicipalityFeedbackGuard] Job {id} target is {t}, not leerplicht — denying recordMunicipalityFeedback.',
                ['id' => $object['id'] ?? '?', 't' => $target]
            );
            return false;
        }

        if ($this->actorIsAuthorised(actor: $actor) === false) {
            $this->logger->info(
                '[MunicipalityFeedbackGuard] Actor {a} is not in an authorised group — denying recordMunicipalityFeedback.',
                ['a' => $actor]
            );
            return false;
        }

        // Stamp recordedBy/receivedAt server-side — never trust a caller-supplied
        // identity/timestamp for this compliance-sensitive field (mirrors
        // ExternalTrainingVerificationGuard's verifiedBy/verifiedAt stamping).
        $payload = $transitionContext['payload']['municipalityFeedback'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        $payload['recordedBy'] = $actor;
        if (empty($payload['receivedAt']) === true) {
            $payload['receivedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        }

        $transitionContext['payload']['municipalityFeedback'] = $payload;

        return true;

    }//end check()

    /**
     * Whether the acting user is in one of the authorised groups.
     *
     * @param string $actor NC user ID of the requester.
     *
     * @return bool True when the user is in admin / coordinator.
     *
     * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
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
