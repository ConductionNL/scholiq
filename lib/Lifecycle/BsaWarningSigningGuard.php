<?php

/**
 * Scholiq BSA Warning Signing Guard
 *
 * Lifecycle guard for the BsaWarning schema's `drafted -> issued` transition.
 * Called by OpenRegister's lifecycle engine when a study-advisor issues a
 * formal BSA warning.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards" plus
 * the cryptographic exception: HMAC-SHA256 signing cannot be expressed
 * declaratively. Mirrors AttestationSigningGuard's `drafted -> signed`
 * behaviour on Attestation.
 *
 * Blocks the transition unless `improvementPeriod` (startDate + endDate) and
 * `offeredGuidance` (non-empty) are both set — the "sufficient study
 * guidance" and timely-warning safeguards a negative BSA decision is judged
 * against on appeal (rijksoverheid.nl, per proposal.md "Why").
 *
 * Per ADR-022: HMAC key management and rotation live in OR's TenantKeyService.
 * This guard retrieves the current key via TenantKeyService::getCurrentTenantKey()
 * and MUST NOT maintain a local key store.
 *
 * Per ADR-008: OR emits the `bsa-warning.issued` audit-trail entry
 * automatically when the lifecycle engine completes the transition — no
 * AuditTrail::record() call from this guard.
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
 * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the BsaWarning `drafted -> issued` lifecycle transition.
 *
 * Responsibilities (single method, two steps):
 *   1. Verify `improvementPeriod.startDate`/`improvementPeriod.endDate` and
 *      non-empty `offeredGuidance` are present.
 *   2. Compute HMAC-SHA256 of the canonicalised BsaWarning payload using OR's
 *      current tenant key, then inject `signature` and `signingKeyId` into the
 *      transition payload so OR persists them on the issued object.
 *
 * Per ADR-031: no AuditTrail::record(), no HmacKeyService, no event listener.
 * OR's lifecycle engine owns all audit entries; this guard only does guard logic.
 */
class BsaWarningSigningGuard
{

    /**
     * Constructor.
     *
     * @param TenantKeyService $tenantKeyService OR tenant-key abstraction that
     *                                           exposes the current HMAC
     *                                           signing key.
     * @param LoggerInterface  $logger           PSR logger for guard rejections.
     */
    public function __construct(
        private readonly TenantKeyService $tenantKeyService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert the guidance/improvement-period pre-conditions and compute the
     * HMAC signature.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `drafted -> issued` transition on a BsaWarning object. The engine
     * passes $transitionContext and expects:
     *   - return true  → transition proceeds; engine persists the object.
     *   - return false → transition is rejected; OR returns HTTP 422.
     *
     * When returning true this method MUST have injected `signature` and
     * `signingKeyId` into $transitionContext['payload'] so that OR writes
     * those fields onto the issued BsaWarning record.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine:
     *                                               - 'object'     : BsaWarning
     *                                               property array
     *                                               - 'transition' : 'issue'
     *                                               - 'from'       : 'drafted'
     *                                               - 'to'         : 'issued'
     *                                               - 'payload'    : mutable array;
     *                                               write signature
     *                                               fields here
     *
     * @return bool True when pre-conditions are satisfied and signature has
     *              been computed; false blocks the transition with HTTP 422.
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $tenantId = $object['tenant_id'] ?? '';

        if ($this->hasValidImprovementPeriod(object: $object) === false) {
            $this->logger->info(
                'BsaWarningSigningGuard: improvementPeriod missing startDate/endDate — blocking issue',
                ['object' => $object]
            );
            return false;
        }

        $offeredGuidance = $object['offeredGuidance'] ?? '';
        if (is_string($offeredGuidance) === false || trim($offeredGuidance) === '') {
            $this->logger->info(
                'BsaWarningSigningGuard: offeredGuidance missing/empty — blocking issue',
                ['object' => $object]
            );
            return false;
        }

        $tenantKey = $this->tenantKeyService->getCurrentTenantKey($tenantId);

        if ($tenantKey === '') {
            $this->logger->error(
                'BsaWarningSigningGuard: OR tenant key unavailable; refusing to sign without HMAC key',
                ['tenantId' => $tenantId]
            );
            // Per spec: if the HMAC key is unavailable the warning MUST fail to issue.
            return false;
        }

        $canonicalPayload = $this->buildCanonicalPayload(object: $object);
        $signature        = hash_hmac('sha256', $canonicalPayload, $tenantKey);

        // Inject into the mutable payload so OR persists these on the issued object.
        // signingKeyId is a verifiable fingerprint of the key in use at signing time.
        $transitionContext['payload']['signature']    = $signature;
        $transitionContext['payload']['signingKeyId'] = substr(hash('sha256', $tenantKey), 0, 16);

        return true;
    }//end check()

    /**
     * Check that improvementPeriod carries non-empty startDate and endDate.
     *
     * @param array<string,mixed> $object The BsaWarning property array.
     *
     * @return bool
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
     */
    private function hasValidImprovementPeriod(array $object): bool
    {
        $period = $object['improvementPeriod'] ?? null;
        if (is_array($period) === false) {
            return false;
        }

        $startDate = $period['startDate'] ?? null;
        $endDate   = $period['endDate'] ?? null;

        if (is_string($startDate) === false || trim($startDate) === '') {
            return false;
        }

        if (is_string($endDate) === false || trim($endDate) === '') {
            return false;
        }

        return true;

    }//end hasValidImprovementPeriod()

    /**
     * Build a canonical JSON string of the BsaWarning payload for HMAC input.
     *
     * Fields are sorted alphabetically at ALL nesting levels (recursive ksort) and
     * `signature` / `signingKeyId` are excluded to avoid circular dependency, mirroring
     * AttestationSigningGuard::buildCanonicalPayload().
     *
     * @param array<string,mixed> $object The BsaWarning property array.
     *
     * @return string Canonical JSON string.
     *
     * @spec openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#requirement-the-formal-warning-captures-improvement-period-guidance-and-personal-circumstances-and-is-signed-evidence
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
