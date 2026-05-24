<?php

/**
 * Scholiq Attestation Signing Guard
 *
 * Lifecycle guard for the Attestation schema's `drafted → signed` transition.
 * Called by OpenRegister's lifecycle engine when a learner submits an attestation.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards" plus
 * the cryptographic exception: HMAC-SHA256 signing cannot be expressed declaratively.
 * It is the ONLY PHP behaviour file for the compliance-audit feature beyond the
 * AuditPackExportController (document generation).
 *
 * Per ADR-022: HMAC key management and rotation live in OR's TenantKeyService.
 * This guard retrieves the current key via TenantKeyService::getCurrentTenantKey()
 * and MUST NOT maintain a local key store.
 *
 * Per ADR-008: OR emits the `attestation.signed` audit-trail entry automatically
 * when the lifecycle engine completes the transition — no AuditTrail::record()
 * call from this guard.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TenantKeyService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Attestation `drafted → signed` lifecycle transition.
 *
 * Responsibilities (single method, two steps):
 *   1. Verify a matching cmi5.completed (or cmi5.passed) XapiStatement exists
 *      in OpenRegister for the given (learnerId, lessonId) pair.
 *   2. Compute HMAC-SHA256 of the canonicalised Attestation payload using OR's
 *      current tenant key, then inject `signature` and `signingKeyId` into the
 *      transition payload so OR persists them on the signed object.
 *
 * Per ADR-031: no AuditTrail::record(), no HmacKeyService, no event listener.
 * OR's lifecycle engine owns all audit entries; this guard only does guard logic.
 */
class AttestationSigningGuard
{
    /**
     * XAPI verb IDs that count as "completed" for attestation pre-condition.
     *
     * @var string[]
     */
    private const COMPLETION_VERBS = [
        'https://w3id.org/xapi/adl/verbs/completed',
        'https://w3id.org/xapi/adl/verbs/passed',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object query service for
     *                                           XapiStatement lookup.
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
     * Assert xAPI completion exists and compute HMAC signature.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `drafted → signed` transition on an Attestation object. The engine
     * passes $transitionContext and expects:
     *   - return true  → transition proceeds; engine persists the object.
     *   - return false → transition is rejected; OR returns HTTP 422.
     *
     * When returning true this method MUST have injected `signature` and
     * `signingKeyId` into $transitionContext['payload'] so that OR writes
     * those fields onto the signed Attestation record.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine:
     *                                               - 'object'     : Attestation
     *                                               property array
     *                                               - 'transition' : 'sign'
     *                                               - 'from'       : 'drafted'
     *                                               - 'to'         : 'signed'
     *                                               - 'payload'    : mutable array;
     *                                               write signature
     *                                               fields here
     *
     * @return bool True when pre-condition is satisfied and signature has been
     *              computed; false blocks the transition with HTTP 422.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
     */
    public function check(array &$transitionContext): bool
    {
        $object    = $transitionContext['object'] ?? [];
        $learnerId = $object['learnerId'] ?? '';
        $lessonId  = $object['lessonId'] ?? '';
        $tenantId  = $object['tenant_id'] ?? '';

        if ($learnerId === '' || $lessonId === '') {
            $this->logger->warning(
                'AttestationSigningGuard: missing learnerId or lessonId',
                ['object' => $object]
            );
            return false;
        }

        // Step 1 — Verify cmi5.completed (or cmi5.passed) XapiStatement exists.
        $completionExists = $this->xapiCompletionExists(learnerId: $learnerId, lessonId: $lessonId);

        if ($completionExists === false) {
            $this->logger->info(
                'AttestationSigningGuard: no completion statement found',
                ['learnerId' => $learnerId, 'lessonId' => $lessonId]
            );
            return false;
        }

        // Step 2 — Compute HMAC-SHA256 using OR's current tenant key.
        $tenantKey = $this->tenantKeyService->getCurrentTenantKey($tenantId);

        if ($tenantKey === '') {
            $this->logger->error(
                'AttestationSigningGuard: OR tenant key unavailable; refusing to sign without HMAC key',
                ['tenantId' => $tenantId]
            );
            // Per spec: if the HMAC key is unavailable the attestation MUST fail.
            return false;
        }

        $canonicalPayload = $this->buildCanonicalPayload(object: $object);
        $signature        = hash_hmac('sha256', $canonicalPayload, $tenantKey);

        // Inject into the mutable payload so OR persists these on the signed object.
        // signingKeyId is a verifiable fingerprint of the key in use at signing time.
        $transitionContext['payload']['signature']    = $signature;
        $transitionContext['payload']['signingKeyId'] = substr(hash('sha256', $tenantKey), 0, 16);

        return true;
    }//end check()

    /**
     * Query OR for a cmi5.completed or cmi5.passed XapiStatement for the given pair.
     *
     * @param string $learnerId Learner identifier.
     * @param string $lessonId  Lesson UUID.
     *
     * @return bool True when at least one matching statement exists.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
     */
    private function xapiCompletionExists(string $learnerId, string $lessonId): bool
    {
        foreach (self::COMPLETION_VERBS as $verbId) {
            $results = $this->objectService->findAll(
                [
                    'register' => 'scholiq',
                    'schema'   => 'XapiStatement',
                    'filters'  => [
                        'actor.id'  => $learnerId,
                        'object.id' => $lessonId,
                        'verb.id'   => $verbId,
                    ],
                    'limit'    => 1,
                ]
            );

            if (count($results) > 0) {
                return true;
            }
        }

        return false;
    }//end xapiCompletionExists()

    /**
     * Build a canonical JSON string of the Attestation payload for HMAC input.
     *
     * Fields are sorted alphabetically and `signature` / `signingKeyId` are
     * excluded to avoid circular dependency. The resulting string is the stable
     * input to HMAC-SHA256 — any tampering of the stored record will produce a
     * different digest when verified offline.
     *
     * @param array<string,mixed> $object The Attestation property array.
     *
     * @return string Canonical JSON string.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
     */
    private function buildCanonicalPayload(array $object): string
    {
        // Exclude fields set by the signing step itself to avoid circularity.
        $excluded = ['signature', 'signingKeyId', 'lifecycle'];
        $payload  = array_diff_key($object, array_flip($excluded));

        // Sort keys for a deterministic canonical form.
        ksort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }//end buildCanonicalPayload()
}//end class
