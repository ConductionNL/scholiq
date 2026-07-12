<?php

/**
 * Scholiq BPV Confirmation Guard
 *
 * Lifecycle guard for the BpvPlacement schema's `confirm` transition
 * (sbb-verification-pending → confirmed). Blocks confirmation unless the
 * placement's stored `leerbedrijfVerification.status` is `verified` — WEB
 * art. 7.2.8/7.2.9 requires the employer hosting a BPV placement to be a
 * leerbedrijf erkend by SBB.
 *
 * Reads the STORED verification result already present on the transitioning
 * object (written earlier by BpvLeerbedrijfVerificationHandler on the
 * `checkLeerbedrijf` transition) — it never calls a
 * ProvidesLeerbedrijfVerification provider synchronously during the
 * transition, the same "read stored lifecycle-adjacent state" pattern
 * AssessmentPublishGuard and LearningPlanSignatureGuard use for their own
 * gates.
 *
 * ADR-031 legitimate exception: "Lifecycle guard — business rule that must
 * run before a state transition and cannot be expressed as a schema
 * declaration." Referenced from BpvPlacement's x-openregister-lifecycle
 * `confirm` transition's `requires` in scholiq_register.json.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the BpvPlacement `confirm` lifecycle transition.
 *
 * A BpvPlacement may only be confirmed when its stored
 * `leerbedrijfVerification.status` equals `verified`. Every other status
 * (`unverified`, `pending`, `rejected`, `expired`) or a missing verification
 * block fails closed.
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
 */
class BpvConfirmationGuard
{

    /**
     * The verification status value that satisfies the gate.
     */
    private const VERIFIED_STATUS = 'verified';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `confirm` transition on a BpvPlacement object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the BpvPlacement data array
     *                                               - 'transition' : 'confirm'
     *                                               - 'from'       : 'sbb-verification-pending'
     *                                               - 'to'         : 'confirmed'
     *
     * @return bool True when leerbedrijfVerification.status is `verified`; false blocks the
     *              transition (HTTP 422).
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-bpvplacement-confirmation-is-gated-on-verified-leerbedrijf-status
     */
    public function check(array &$transitionContext): bool
    {
        $placement    = $transitionContext['object'] ?? [];
        $placementId  = $placement['id'] ?? ($placement['uuid'] ?? '');
        $verification = $placement['leerbedrijfVerification'] ?? null;

        if (is_array($verification) === false) {
            $this->logger->info(
                '[BpvConfirmationGuard] BpvPlacement {id} has no leerbedrijfVerification block; blocking confirm.',
                ['id' => $placementId]
            );
            return false;
        }

        $status = $verification['status'] ?? 'unverified';

        if ($status !== self::VERIFIED_STATUS) {
            $this->logger->info(
                '[BpvPlacement] {id} leerbedrijfVerification.status is "{status}", not verified; blocking confirm.',
                ['id' => $placementId, 'status' => $status]
            );
            return false;
        }

        $this->logger->info(
            '[BpvConfirmationGuard] BpvPlacement {id} leerbedrijf is verified — allowing confirm.',
            ['id' => $placementId]
        );

        return true;

    }//end check()
}//end class
