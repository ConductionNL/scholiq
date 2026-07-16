<?php

/**
 * Scholiq SovereigntyPolicyService unit tests.
 *
 * Coverage for the sovereign-ai-guarantee compliance rule: `unverified` never
 * satisfies `on-premises-only` or `eu-hosted-allowed`; it only ever passes
 * under the explicitly permissive `third-country-allowed` tier. No
 * `SovereigntyPolicy` object yet defaults to `eu-hosted-allowed`.
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
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Service\SovereigntyPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SovereigntyPolicyService::currentPolicy()/isCompliant().
 */
class SovereigntyPolicyServiceTest extends TestCase
{

    /**
     * Build a service backed by an ObjectService mock returning the given
     * `findAll()` result.
     *
     * @param array<int,mixed> $findAllResult The result findAll() should return.
     *
     * @return SovereigntyPolicyService
     */
    private function buildService(array $findAllResult=[]): SovereigntyPolicyService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn($findAllResult);

        return new SovereigntyPolicyService($objectService, $this->createMock(LoggerInterface::class));

    }//end buildService()

    /**
     * No SovereigntyPolicy object exists yet — defaults to eu-hosted-allowed.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-no-policy-set-yet-defaults-to-the-documented-default
     */
    public function testDefaultsToEuHostedAllowedWhenUnset(): void
    {
        $service = $this->buildService([]);

        self::assertSame('eu-hosted-allowed', $service->currentPolicy());

    }//end testDefaultsToEuHostedAllowedWhenUnset()

    /**
     * A stored policy value is returned verbatim.
     *
     * @return void
     */
    public function testReturnsStoredPolicyValue(): void
    {
        $service = $this->buildService([['policy' => 'on-premises-only']]);

        self::assertSame('on-premises-only', $service->currentPolicy());

    }//end testReturnsStoredPolicyValue()

    /**
     * A malformed/unrecognised stored policy value falls back to the default
     * rather than propagating garbage.
     *
     * @return void
     */
    public function testUnrecognisedStoredPolicyValueFallsBackToDefault(): void
    {
        $service = $this->buildService([['policy' => 'not-a-real-tier']]);

        self::assertSame('eu-hosted-allowed', $service->currentPolicy());

    }//end testUnrecognisedStoredPolicyValueFallsBackToDefault()

    /**
     * `unverified` never satisfies `on-premises-only` or `eu-hosted-allowed`.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
     */
    public function testUnverifiedNeverSatisfiesOnPremisesOrEuHostedTiers(): void
    {
        $onPremisesOnly = $this->buildService([['policy' => 'on-premises-only']]);
        self::assertFalse($onPremisesOnly->isCompliant('unverified', false));

        $euHostedAllowed = $this->buildService([['policy' => 'eu-hosted-allowed']]);
        self::assertFalse($euHostedAllowed->isCompliant('unverified', false));

        // Even a locality that LOOKS compliant must be verified=true to pass.
        $euHostedAllowedUnverifiedLabel = $this->buildService([['policy' => 'eu-hosted-allowed']]);
        self::assertFalse($euHostedAllowedUnverifiedLabel->isCompliant('eu-hosted', false));

    }//end testUnverifiedNeverSatisfiesOnPremisesOrEuHostedTiers()

    /**
     * `unverified` satisfies ONLY the `third-country-allowed` tier.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-publish-succeeds-when-the-school-accepts-the-permissive-tier
     */
    public function testUnverifiedSatisfiesThirdCountryAllowedTier(): void
    {
        $service = $this->buildService([['policy' => 'third-country-allowed']]);

        self::assertTrue($service->isCompliant('unverified', false));
        self::assertTrue($service->isCompliant('third-country', true));

    }//end testUnverifiedSatisfiesThirdCountryAllowedTier()

    /**
     * `on-premises-only` requires locality === on-premises AND verified.
     *
     * @return void
     */
    public function testOnPremisesOnlyRequiresOnPremisesAndVerified(): void
    {
        $service = $this->buildService([['policy' => 'on-premises-only']]);

        self::assertTrue($service->isCompliant('on-premises', true));
        self::assertFalse($service->isCompliant('eu-hosted', true));
        self::assertFalse($service->isCompliant('third-country', true));

    }//end testOnPremisesOnlyRequiresOnPremisesAndVerified()

    /**
     * `eu-hosted-allowed` accepts on-premises OR eu-hosted, both requiring
     * verified=true; a verified third-country provider still violates it.
     *
     * @return void
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-publish-is-blocked-when-a-verified-third-country-provider-violates-an-on-premises-only-policy
     */
    public function testEuHostedAllowedAcceptsOnPremisesOrEuHostedButNotThirdCountry(): void
    {
        $service = $this->buildService([['policy' => 'eu-hosted-allowed']]);

        self::assertTrue($service->isCompliant('on-premises', true));
        self::assertTrue($service->isCompliant('eu-hosted', true));
        self::assertFalse($service->isCompliant('third-country', true));

    }//end testEuHostedAllowedAcceptsOnPremisesOrEuHostedButNotThirdCountry()

    /**
     * An ObjectService failure while reading the singleton degrades to the
     * default policy rather than throwing.
     *
     * @return void
     */
    public function testFindAllFailureDegradesToDefaultPolicy(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willThrowException(new \RuntimeException('db unavailable'));

        $service = new SovereigntyPolicyService($objectService, $this->createMock(LoggerInterface::class));

        self::assertSame('eu-hosted-allowed', $service->currentPolicy());

    }//end testFindAllFailureDegradesToDefaultPolicy()
}//end class
