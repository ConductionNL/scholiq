<?php

/**
 * Scholiq POK Activation Guard
 *
 * Lifecycle guard for the Praktijkovereenkomst schema's `activate` transition
 * (pending-signatures → active). Blocks activation unless a PokSignature
 * exists for each of the three required roles (`student`, `school`,
 * `praktijkopleider`) on the POK's current (subjectId, subjectVersion) pair —
 * WEB art. 7.2.8/7.2.9 requires all three parties to sign before the
 * placement starts.
 *
 * Mirrors LearningPlanSignatureGuard's shape (constructor-injected
 * ObjectService + LoggerInterface, a `fetchSignatures()` + `indexByRole()`
 * cross-schema query): this guard independently re-derives the same fact the
 * declarative `isFullySigned` calculation on Praktijkovereenkomst expresses —
 * this class is the tested, authoritative enforcement; `isFullySigned` is the
 * declarative read-surface for the frontend. A duplicate signature for the
 * same role still counts as one distinct role toward the three (indexing by
 * role naturally collapses duplicates).
 *
 * ADR-031 legitimate exception: multi-schema guard logic (Praktijkovereenkomst
 * → PokSignature) cannot be expressed as a schema metadata declaration.
 * Referenced from Praktijkovereenkomst's x-openregister-lifecycle `activate`
 * transition's `requires` in scholiq_register.json.
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Praktijkovereenkomst `activate` lifecycle transition.
 *
 * A Praktijkovereenkomst may only activate once a PokSignature exists for
 * each of `student`, `school`, and `praktijkopleider` on the current
 * (subjectId, subjectVersion) pair.
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 */
class PokActivationGuard
{

    /**
     * Scholiq register slug.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * PokSignature schema slug.
     */
    private const POK_SIGNATURE_SCHEMA = 'pok-signature';

    /**
     * The three roles required for full signing, per the `bpv` spec.
     *
     * @var string[]
     */
    private const REQUIRED_ROLES = ['student', 'school', 'praktijkopleider'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object query service.
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
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the
     * `activate` transition on a Praktijkovereenkomst object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Praktijkovereenkomst data array
     *                                               - 'transition' : 'activate'
     *                                               - 'from'       : 'pending-signatures'
     *                                               - 'to'         : 'active'
     *
     * @return bool True when all three required roles have a PokSignature for this version;
     *              false blocks the transition (HTTP 422).
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
     */
    public function check(array &$transitionContext): bool
    {
        $pok      = $transitionContext['object'] ?? [];
        $pokId    = $pok['id'] ?? ($pok['uuid'] ?? '');
        $version  = (int) ($pok['version'] ?? 1);
        $tenantId = $pok['tenant_id'] ?? '';

        if ($pokId === '') {
            $this->logger->warning('[PokActivationGuard] Praktijkovereenkomst has no id; blocking activation.');
            return false;
        }

        $signedRoles = $this->fetchSignedRoles(pokId: $pokId, version: $version, tenantId: $tenantId);

        $missing = array_diff(self::REQUIRED_ROLES, $signedRoles);

        if (empty($missing) === false) {
            $this->logger->info(
                '[PokActivationGuard] Praktijkovereenkomst {id} v{v} missing signatures for role(s): {missing}.',
                ['id' => $pokId, 'v' => $version, 'missing' => implode(', ', $missing)]
            );
            return false;
        }

        $this->logger->info(
            '[PokActivationGuard] Praktijkovereenkomst {id} v{v} fully signed — allowing activation.',
            ['id' => $pokId, 'v' => $version]
        );

        return true;

    }//end check()

    /**
     * Fetch the distinct set of signerRoles that have signed this POK version.
     *
     * @param string $pokId    Praktijkovereenkomst UUID.
     * @param int    $version  Praktijkovereenkomst version.
     * @param string $tenantId Tenant UUID scope filter.
     *
     * @return string[] Distinct signerRole values present among the matching PokSignatures.
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
     */
    private function fetchSignedRoles(string $pokId, int $version, string $tenantId=''): array
    {
        $filters = ['subjectId' => $pokId, 'subjectVersion' => $version];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $raw = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::POK_SIGNATURE_SCHEMA,
                'filters'  => $filters,
                'limit'    => 200,
            ]
        );

        $roles = [];
        foreach ($raw as $item) {
            $row = $item;
            if (is_array($item) === false) {
                $row = $item->jsonSerialize();
            }

            $role = $row['signerRole'] ?? null;
            if ($role !== null && in_array($role, $roles, strict: true) === false) {
                $roles[] = $role;
            }
        }

        return $roles;

    }//end fetchSignedRoles()
}//end class
