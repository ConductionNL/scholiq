<?php

/**
 * Scholiq Sovereignty Policy Service
 *
 * Reads the school-wide `SovereigntyPolicy` singleton and implements the
 * compliance rule that makes "cannot verify" a structurally distinct outcome
 * from "verified compliant" (sovereign-ai-guarantee design.md "Data Model" ->
 * `SovereigntyPolicyService`): `unverified` never satisfies the two stricter
 * policy tiers, `on-premises-only` and `eu-hosted-allowed`; it only ever
 * passes under the explicitly permissive `third-country-allowed` tier, which
 * means "we accept not knowing" — an honest position a school can choose,
 * never the platform pretending to know.
 *
 * Legitimate PHP per ADR-031: reads a singleton OR object (schema-default
 * fallback when absent) plus a compliance-matrix decision — not expressible
 * as a single declarative OR query.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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

namespace OCA\Scholiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stateless policy-compliance evaluator over the `SovereigntyPolicy`
 * singleton.
 *
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-let-a-school-declare-an-ai-processing-locality-policy
 */
class SovereigntyPolicyService
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for the SovereigntyPolicy singleton.
     */
    private const SOVEREIGNTY_POLICY_SCHEMA = 'sovereignty-policy';

    /**
     * The schema-documented default tier — applied when no `SovereigntyPolicy`
     * object has ever been created on this instance (design.md Decision 2).
     */
    private const DEFAULT_POLICY = 'eu-hosted-allowed';

    /**
     * The three valid `policy` tiers, strictest first.
     *
     * @var string[]
     */
    private const VALID_POLICIES = ['on-premises-only', 'eu-hosted-allowed', 'third-country-allowed'];

    /**
     * Localities that satisfy `eu-hosted-allowed` when `verified === true`.
     *
     * @var string[]
     */
    private const EU_HOSTED_ALLOWED_LOCALITIES = ['on-premises', 'eu-hosted'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for the
     *                                       `SovereigntyPolicy` singleton read.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Read the school's current locality policy tier.
     *
     * Returns the first `SovereigntyPolicy` object's `policy` value, or the
     * schema-documented default (`eu-hosted-allowed`) when no object exists
     * yet, the read fails, or the stored value is not one of the three valid
     * tiers.
     *
     * @return string One of `on-premises-only`, `eu-hosted-allowed`, `third-country-allowed`.
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-no-policy-set-yet-defaults-to-the-documented-default
     */
    public function currentPolicy(): string
    {
        try {
            $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::SOVEREIGNTY_POLICY_SCHEMA,
                    'limit'    => 1,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->info(
                '[SovereigntyPolicyService] SovereigntyPolicy read failed ({message}); defaulting to "{default}".',
                ['message' => $e->getMessage(), 'default' => self::DEFAULT_POLICY]
            );
            return self::DEFAULT_POLICY;
        }

        if (empty($existing) === true) {
            return self::DEFAULT_POLICY;
        }

        $row = $existing[0];
        if (is_array($row) === false) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = $row->jsonSerialize();
            } else {
                return self::DEFAULT_POLICY;
            }
        }

        $policy = (string) ($row['policy'] ?? '');

        if (in_array($policy, self::VALID_POLICIES, true) === false) {
            return self::DEFAULT_POLICY;
        }

        return $policy;

    }//end currentPolicy()

    /**
     * Whether a classified `{locality, verified}` pair complies with the
     * school's current `SovereigntyPolicy`.
     *
     * `unverified` (`verified === false`) MUST NOT satisfy `on-premises-only`
     * or `eu-hosted-allowed` — it only ever passes under the explicitly
     * permissive `third-country-allowed` tier.
     *
     * | Policy tier            | Compliant when                                        |
     * |-------------------------|-------------------------------------------------------|
     * | `on-premises-only`      | `locality === 'on-premises'` AND `verified === true`   |
     * | `eu-hosted-allowed`     | `locality` in `{on-premises, eu-hosted}` AND `verified === true` |
     * | `third-country-allowed` | anything, including `unverified`                      |
     *
     * @param string $locality One of `on-premises`, `eu-hosted`, `third-country`, `unverified`.
     * @param bool   $verified Whether the locality was code-verified true.
     *
     * @return bool
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-must-refuse-to-let-an-ai-assisted-feature-take-effect-when-its-verified-or-unverified-locality-violates-the-school-s-policy
     */
    public function isCompliant(string $locality, bool $verified): bool
    {
        $policy = $this->currentPolicy();

        if ($policy === 'third-country-allowed') {
            return true;
        }

        if ($verified === false) {
            // Unverified never satisfies a stricter-than-permissive tier.
            return false;
        }

        if ($policy === 'on-premises-only') {
            return $locality === 'on-premises';
        }

        // Eu-hosted-allowed.
        return in_array($locality, self::EU_HOSTED_ALLOWED_LOCALITIES, true) === true;

    }//end isCompliant()
}//end class
