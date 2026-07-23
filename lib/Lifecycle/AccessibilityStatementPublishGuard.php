<?php

/**
 * Scholiq Accessibility Statement Publish Guard
 *
 * Lifecycle guard for the AccessibilityStatement schema's `draft -> published`
 * transition. Structurally enforces the "no unverifiable conformance claim"
 * posture required by the Tijdelijk besluit digitale toegankelijkheid
 * overheid (BDTO): a toegankelijkheidsverklaring cannot go live without
 * recorded evaluation evidence, and it cannot claim `fully-compliant` while
 * a known accessibility issue is still open.
 *
 * Two independent checks, both must pass:
 *   1. `status`, `evaluationMethod`, `evaluationDate`, and a non-empty
 *      `feedbackContact` must all be set (evidence pre-condition).
 *   2. When `status` is `fully-compliant`, no `open` or `mitigated`
 *      AccessibilityLimitation may reference this statement — a cross-object
 *      invariant no JSON-logic expression on a single schema can check,
 *      mirroring BsaDecisionGuard's "no negative BSA without a logged
 *      warning" cross-object query shape. Legitimate PHP per ADR-031
 *      §"Lifecycle guards".
 *
 * Mirrors AttestationSigningGuard/CoursePublishGuard's `requires` pattern.
 * Referenced from AccessibilityStatement.x-openregister-lifecycle.transitions.
 * publish.requires in scholiq_register.json.
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
 * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AccessibilityStatement `draft -> published` lifecycle transition.
 */
class AccessibilityStatementPublishGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for the AccessibilityLimitation register.
     */
    private const LIMITATION_SCHEMA = 'accessibility-limitation';

    /**
     * Valid AccessibilityStatement.status values.
     *
     * @var string[]
     */
    private const VALID_STATUSES = [
        'fully-compliant',
        'partially-compliant',
        'non-compliant',
    ];

    /**
     * Valid AccessibilityStatement.evaluationMethod values.
     *
     * @var string[]
     */
    private const VALID_EVALUATION_METHODS = [
        'self-assessment',
        'expert-review',
        'user-testing',
        'automated-scan',
    ];

    /**
     * AccessibilityLimitation lifecycle states that block a fully-compliant claim.
     *
     * @var string[]
     */
    private const BLOCKING_LIMITATION_STATES = [
        'open',
        'mitigated',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query service for
     *                                       AccessibilityLimitation lookup.
     * @param LoggerInterface $logger        PSR logger for guard rejections.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `draft -> published` transition on an AccessibilityStatement object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine:
     *                                               - 'object'     : the
     *                                               AccessibilityStatement
     *                                               data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : 'draft'
     *                                               - 'to'         : 'published'
     *
     * @return bool True when evaluation evidence is complete and (for a
     *              fully-compliant status) no open/mitigated limitation
     *              references this statement; false blocks the transition.
     *
     * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
     * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $status   = $object['status'] ?? null;
        $tenantId = $object['tenant_id'] ?? '';

        if ($this->hasCompleteEvaluationEvidence(object: $object) === false) {
            $this->logger->info(
                'AccessibilityStatementPublishGuard: missing evaluation evidence '
                .'(status/evaluationMethod/evaluationDate/feedbackContact) — blocking publish.'
            );
            return false;
        }

        if ($status === 'fully-compliant') {
            $statementId = $object['uuid'] ?? $object['id'] ?? null;

            if ($statementId !== null
                && $this->hasBlockingLimitation(statementId: $statementId, tenantId: $tenantId) === true
            ) {
                $this->logger->info(
                    'AccessibilityStatementPublishGuard: an open/mitigated AccessibilityLimitation '
                    .'references statement {id} — blocking fully-compliant publish.',
                    ['id' => $statementId]
                );
                return false;
            }
        }

        return true;

    }//end check()

    /**
     * Assert status, evaluationMethod, evaluationDate, and feedbackContact
     * are all set on the record.
     *
     * @param array<string,mixed> $object The AccessibilityStatement property array.
     *
     * @return bool True when all four evidence fields are present and valid.
     *
     * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
     */
    private function hasCompleteEvaluationEvidence(array $object): bool
    {
        $status           = $object['status'] ?? null;
        $evaluationMethod = $object['evaluationMethod'] ?? null;
        $evaluationDate   = $object['evaluationDate'] ?? null;
        $feedbackContact  = $object['feedbackContact'] ?? null;

        if (in_array($status, self::VALID_STATUSES, true) === false) {
            return false;
        }

        if (in_array($evaluationMethod, self::VALID_EVALUATION_METHODS, true) === false) {
            return false;
        }

        if (is_string($evaluationDate) === false || trim($evaluationDate) === '') {
            return false;
        }

        if (is_string($feedbackContact) === false || trim($feedbackContact) === '') {
            return false;
        }

        return true;

    }//end hasCompleteEvaluationEvidence()

    /**
     * Query OR for an `open` or `mitigated` AccessibilityLimitation referencing
     * the given AccessibilityStatement.
     *
     * @param string $statementId UUID of the AccessibilityStatement being published.
     * @param string $tenantId    Tenant ID to scope the query.
     *
     * @return bool True when at least one blocking limitation exists.
     *
     * @spec openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
     */
    private function hasBlockingLimitation(string $statementId, string $tenantId): bool
    {
        $filters = ['accessibilityStatementId' => $statementId];

        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::LIMITATION_SCHEMA,
                'filters'  => $filters,
            ]
        );

        foreach ($results as $limitation) {
            $lifecycle = $limitation['lifecycle'] ?? null;
            if (in_array($lifecycle, self::BLOCKING_LIMITATION_STATES, true) === true) {
                return true;
            }
        }

        return false;

    }//end hasBlockingLimitation()
}//end class
