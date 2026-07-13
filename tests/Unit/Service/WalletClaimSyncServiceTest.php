<?php

/**
 * Unit tests for WalletClaimSyncService.
 *
 * Covers the `recordWalletClaim` guard contract per
 * `specs/certification/spec.md`: writes `walletOfferStatus=claimed` and
 * `walletClaimedAt` into the transition context and always allows the
 * transition.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Service
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\WalletClaimSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WalletClaimSyncService::check().
 */
class WalletClaimSyncServiceTest extends TestCase
{

    /**
     * A claimed offer writes `walletOfferStatus=claimed` and
     * `walletClaimedAt`, and always allows the transition.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#scenario-a-claimed-wallet-offer-updates-the-credentials-wallet-offer-status
     */
    public function testClaimWritesStatusAndTimestamp(): void
    {
        $context = [
            'object'     => [
                'id'                => 'credential-1',
                'walletOfferStatus' => 'offered',
                'walletClaimedAt'   => null,
            ],
            'transition' => 'recordWalletClaim',
            'from'       => 'issued',
            'to'         => 'issued',
        ];

        $service = new WalletClaimSyncService();
        $result  = $service->check($context);

        self::assertTrue($result);
        self::assertSame('claimed', $context['object']['walletOfferStatus']);
        self::assertNotEmpty($context['object']['walletClaimedAt']);
    }//end testClaimWritesStatusAndTimestamp()
}//end class
