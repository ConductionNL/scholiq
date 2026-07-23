<?php

/**
 * Scholiq PokActivationGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\PokActivationGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PokActivationGuard lifecycle guard (pending-signatures → active).
 */
class PokActivationGuardTest extends TestCase
{

    /**
     * Build a guard whose ObjectService::findAll() returns the given PokSignature rows.
     *
     * @param array<int, array<string, mixed>> $signatures Rows to return for any pok-signature query.
     *
     * @return PokActivationGuard
     */
    private function makeGuard(array $signatures): PokActivationGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($signatures) {
                if ($config['schema'] === 'pok-signature') {
                    return $signatures;
                }

                return [];
            }
        );

        return new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * Build the transitionContext for a Praktijkovereenkomst.
     *
     * @param int $version POK version.
     *
     * @return array<string, mixed>
     */
    private function pokContext(int $version=1): array
    {
        return ['object' => ['id' => 'pok-1', 'version' => $version, 'tenant_id' => 'tenant-a']];

    }//end pokContext()

    /**
     * All three roles signed → activation allowed.
     *
     * @return void
     */
    public function testAllThreeRolesSignedAllowsActivation(): void
    {
        $signatures = [
            ['signerRole' => 'student'],
            ['signerRole' => 'school'],
            ['signerRole' => 'praktijkopleider'],
        ];

        $context = $this->pokContext();
        $this->assertTrue($this->makeGuard($signatures)->check($context));

    }//end testAllThreeRolesSignedAllowsActivation()

    /**
     * Zero, one, or two of three roles signed → activation blocked.
     *
     * @return void
     */
    public function testIncompleteSignaturesBlockActivation(): void
    {
        $cases = [
            [],
            [['signerRole' => 'student']],
            [['signerRole' => 'student'], ['signerRole' => 'school']],
        ];

        foreach ($cases as $signatures) {
            $context = $this->pokContext();
            $this->assertFalse($this->makeGuard($signatures)->check($context));
        }

    }//end testIncompleteSignaturesBlockActivation()

    /**
     * A duplicate signerRole (e.g. two student signatures) still counts as one distinct role —
     * two duplicated roles is NOT the same as three distinct roles, so activation stays blocked.
     *
     * @return void
     */
    public function testDuplicateRoleStillCountsAsOneDistinctRole(): void
    {
        $signatures = [
            ['signerRole' => 'student'],
            ['signerRole' => 'student'],
            ['signerRole' => 'school'],
        ];

        $context = $this->pokContext();
        $this->assertFalse($this->makeGuard($signatures)->check($context));

    }//end testDuplicateRoleStillCountsAsOneDistinctRole()

    /**
     * A missing object id fails closed without querying.
     *
     * @return void
     */
    public function testMissingIdFailsClosedWithoutQuerying(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('findAll');

        $guard   = new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class));
        $context = ['object' => ['version' => 1]];

        $this->assertFalse($guard->check($context));

    }//end testMissingIdFailsClosedWithoutQuerying()
}//end class
