<?php

/**
 * Scholiq FeeItem Voluntary Entitlement Guard
 *
 * Lifecycle guard for the Entitlement schema's `grant` transition (pending ->
 * active). This is the STRUCTURAL enforcement of the Wet vrijwillige
 * ouderbijdrage (in force since 1 August 2021, amending WPO/WVO): non-payment
 * of a voluntary contribution MUST NOT exclude a pupil from the activity it
 * funds, and offering a lesser/substitute activity to non-payers is equally
 * non-compliant (rijksoverheid.nl / vo-raad.nl, "Een alternatief bieden is
 * niet voldoende"). Resolves the Entitlement's feeItemId and refuses the
 * transition unconditionally whenever the linked FeeItem.voluntary is true —
 * regardless of whether the linked Order ever reaches `paid`. Because nothing
 * in this capability gates access on a `pending` Entitlement (only `active`
 * ones grant anything), this makes it structurally impossible for a
 * voluntary fee to ever become an access gate through this capability's own
 * mechanism, not merely discouraged by convention.
 *
 * NAMED IN THE SCHEMA: this class — not {@see EntitlementOrderPaidGuard} — is
 * the value of Entitlement.grant.requires in scholiq_register.json.
 * OpenRegister's lifecycle engine accepts exactly one `requires` string per
 * transition (verified precedent: ReportPeriodLockGuard's own docblock/
 * changelog entry — LifecycleAnnotationValidator rejects a non-string
 * `requires` value, so there is no "second requires entry" array shape to add
 * alongside this guard). The `grant` transition needs BOTH the voluntary
 * check (this class) AND the payment check ({@see EntitlementOrderPaidGuard})
 * to pass, so this class composes EntitlementOrderPaidGuard by constructor
 * injection and calls its check() after its own voluntary check passes —
 * mirroring ReportPeriodLockGuard's own composition of FraudCaseBlockGuard.
 * The voluntary check runs FIRST and short-circuits, so a voluntary FeeItem
 * is refused regardless of the linked Order's paid status even if a caller
 * only ever exercises this class directly.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-a-voluntary-feeitem-must-not-gate-enrolment-or-participation
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Entitlement `grant` (pending -> active) transition against ever
 * activating for a voluntary FeeItem, then composes EntitlementOrderPaidGuard
 * for the payment check.
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
 */
class FeeItemVoluntaryEntitlementGuard
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const FEE_ITEM_SCHEMA  = 'fee-item';

    /**
     * Constructor.
     *
     * @param ObjectService             $objectService  OR object access service.
     * @param EntitlementOrderPaidGuard $orderPaidGuard Composed payment-status guard,
     *                                                  invoked after the voluntary
     *                                                  check passes.
     * @param LoggerInterface           $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly EntitlementOrderPaidGuard $orderPaidGuard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Refuse the `grant` transition unconditionally for a voluntary FeeItem;
     * otherwise delegate to the composed EntitlementOrderPaidGuard.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Entitlement data array
     *                                               - 'transition' : 'grant'
     *
     * @return bool True only when the linked FeeItem is non-voluntary AND the
     *              linked Order is paid; false blocks the transition (HTTP 422).
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-entitlement-referencing-a-voluntary-feeitem-can-never-activate
     */
    public function check(array &$transitionContext): bool
    {
        $entitlement   = $transitionContext['object'] ?? [];
        $entitlementId = $entitlement['id'] ?? ($entitlement['uuid'] ?? '');
        $feeItemId     = $entitlement['feeItemId'] ?? null;

        if (is_string($feeItemId) === false || $feeItemId === '') {
            $this->logger->warning(
                '[FeeItemVoluntaryEntitlementGuard] Entitlement {id} has no feeItemId — denying grant (fail closed).',
                ['id' => $entitlementId]
            );
            return false;
        }

        $feeItem = $this->fetchFeeItem(feeItemId: $feeItemId);
        if ($feeItem === null) {
            $this->logger->warning(
                '[FeeItemVoluntaryEntitlementGuard] Entitlement {id} links FeeItem {feeItemId} which was not found — denying grant (fail closed).',
                ['id' => $entitlementId, 'feeItemId' => $feeItemId]
            );
            return false;
        }

        if (($feeItem['voluntary'] ?? false) === true) {
            $this->logger->info(
                '[FeeItemVoluntaryEntitlementGuard] Entitlement {id} permanently blocked — linked FeeItem'
                .' {feeItemId} is voluntary (Wet vrijwillige ouderbijdrage); no Order status can override this.',
                ['id' => $entitlementId, 'feeItemId' => $feeItemId]
            );
            return false;
        }

        // Voluntary check passed — compose the payment-status check.
        return $this->orderPaidGuard->check($transitionContext);

    }//end check()

    /**
     * Fetch the linked FeeItem by id.
     *
     * @param string $feeItemId UUID of the FeeItem.
     *
     * @return array<string,mixed>|null The FeeItem data array, or null if not found.
     */
    private function fetchFeeItem(string $feeItemId): ?array
    {
        $obj = $this->objectService->find(
            id: $feeItemId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::FEE_ITEM_SCHEMA
        );

        if ($obj === null) {
            return null;
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return $obj->jsonSerialize();

    }//end fetchFeeItem()
}//end class
