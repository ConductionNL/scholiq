<?php

/**
 * Scholiq BSA Decision Guard
 *
 * Lifecycle guard for the BsaDecision schema's `drafted -> decided` transition.
 * Called by OpenRegister's lifecycle engine when a study-advisor / exam-board
 * member records a year-end bindend studieadvies (BSA) decision.
 *
 * This is the ONE requirement in the study-progress capability that MUST be
 * enforced in code rather than declared config: "no negative BSA without a
 * logged warning" is a cross-object invariant no JSON-logic expression on a
 * single schema can check (it needs to query the BsaWarning collection).
 * Legitimate PHP per ADR-031 §"Lifecycle guards".
 *
 * Rule: when `decisionType` is `negative` or `negative-with-recommendation`,
 * at least one `BsaWarning` with `lifecycle: issued` MUST exist for the same
 * (learnerId, programmeId, academicYear), AND `rationale` must be non-empty.
 * `positive` and `postponed` decisions are not subject to either check.
 *
 * Mirrors AttestationSigningGuard / ProgrammePublishGuard's `requires` pattern:
 * on success this guard also stamps the HMAC `signature`/`signingKeyId` pair,
 * matching BsaWarningSigningGuard's cryptographic-exception rationale.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the BsaDecision `drafted -> decided` lifecycle transition.
 */
class BsaDecisionGuard
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const BSA_WARNING_SCHEMA = 'bsa-warning';

    /**
     * decisionType values that trigger the warning + rationale requirement.
     *
     * @var string[]
     */
    private const NEGATIVE_DECISION_TYPES = [
        'negative',
        'negative-with-recommendation',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object query service for
     *                                           BsaWarning lookup.
     * @param TenantKeyService $tenantKeyService OR tenant-key abstraction that
     *                                           exposes the current HMAC
     *                                           signing key.
     * @param LoggerInterface  $logger           PSR logger for guard rejections.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TenantKeyService $tenantKeyService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the negative-decision pre-conditions and compute the HMAC signature.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `drafted -> decided` transition on a BsaDecision object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine:
     *                                               - 'object'     : BsaDecision
     *                                               property array
     *                                               - 'transition' : 'decide'
     *                                               - 'from'       : 'drafted'
     *                                               - 'to'         : 'decided'
     *                                               - 'payload'    : mutable array;
     *                                               write signature
     *                                               fields here
     *
     * @return bool True when pre-conditions are satisfied and signature has
     *              been computed; false blocks the transition with HTTP 422,
     *              naming the missing requirement in the log entry.
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
     */
    public function check(array &$transitionContext): bool
    {
        $object       = $transitionContext['object'] ?? [];
        $decisionType = $object['decisionType'] ?? '';
        $tenantId     = $object['tenant_id'] ?? '';

        $isNegative = in_array($decisionType, self::NEGATIVE_DECISION_TYPES, true);

        if ($isNegative === true) {
            $learnerId   = $object['learnerId'] ?? '';
            $programmeId = $object['programmeId'] ?? '';
            $academicYear = $object['academicYear'] ?? '';

            if ($this->hasIssuedWarning(learnerId: $learnerId, programmeId: $programmeId, academicYear: $academicYear, tenantId: $tenantId) === false) {
                $this->logger->info(
                    'BsaDecisionGuard: no issued BsaWarning found for learner {l}, programme {p}, year {y} — blocking negative decision.',
                    ['l' => $learnerId, 'p' => $programmeId, 'y' => $academicYear]
                );
                return false;
            }

            $rationale = $object['rationale'] ?? '';
            if (is_string($rationale) === false || trim($rationale) === '') {
                $this->logger->info(
                    'BsaDecisionGuard: rationale missing/empty — blocking negative decision.',
                    ['learnerId' => $learnerId]
                );
                return false;
            }
        }//end if

        $tenantKey = $this->tenantKeyService->getCurrentTenantKey($tenantId);

        if ($tenantKey === '') {
            $this->logger->error(
                'BsaDecisionGuard: OR tenant key unavailable; refusing to decide without HMAC key',
                ['tenantId' => $tenantId]
            );
            return false;
        }

        $canonicalPayload = $this->buildCanonicalPayload(object: $object);
        $signature        = hash_hmac('sha256', $canonicalPayload, $tenantKey);

        $transitionContext['payload']['signature']    = $signature;
        $transitionContext['payload']['signingKeyId'] = substr(hash('sha256', $tenantKey), 0, 16);

        return true;
    }//end check()

    /**
     * Query OR for an `issued` BsaWarning matching (learnerId, programmeId, academicYear).
     *
     * @param string $learnerId    Learner Nextcloud user ID.
     * @param string $programmeId  Programme UUID.
     * @param string $academicYear Academic year string.
     * @param string $tenantId     Tenant ID to scope the query.
     *
     * @return bool True when at least one matching issued warning exists.
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
     */
    private function hasIssuedWarning(string $learnerId, string $programmeId, string $academicYear, string $tenantId): bool
    {
        if ($learnerId === '' || $programmeId === '' || $academicYear === '') {
            return false;
        }

        $filters = [
            'learnerId'    => $learnerId,
            'programmeId'  => $programmeId,
            'academicYear' => $academicYear,
            'lifecycle'    => 'issued',
        ];

        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::BSA_WARNING_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        return count($results) > 0;

    }//end hasIssuedWarning()

    /**
     * Build a canonical JSON string of the BsaDecision payload for HMAC input.
     *
     * Mirrors AttestationSigningGuard::buildCanonicalPayload() / BsaWarningSigningGuard's
     * recursive-ksort, signature-excluding canonicalisation.
     *
     * @param array<string,mixed> $object The BsaDecision property array.
     *
     * @return string Canonical JSON string.
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-a-negative-bsa-decision-must-be-blocked-without-a-logged-issued-warning
     */
    private function buildCanonicalPayload(array $object): string
    {
        $excluded = ['signature', 'signingKeyId', 'lifecycle'];
        $payload  = array_diff_key($object, array_flip($excluded));

        $payload = $this->deepKsort(data: $payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }//end buildCanonicalPayload()

    /**
     * Recursively sort an array by keys at all nesting levels.
     *
     * @param array<string,mixed> $data The array to sort.
     *
     * @return array<string,mixed> The sorted array.
     */
    private function deepKsort(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value) === true) {
                $value = $this->deepKsort(data: $value);
            }
        }//end foreach

        unset($value);

        ksort($data);
        return $data;
    }//end deepKsort()
}//end class
