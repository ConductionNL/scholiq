<?php

/**
 * Scholiq Leerbedrijf Verification Provider Interface
 *
 * Pluggable interface for the SBB erkend-leerbedrijf check (WEB art.
 * 7.2.8/7.2.9 — the employer hosting a BPV placement must be a leerbedrijf
 * erkend by SBB, Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven).
 * Concrete implementations are configured via BpvPlacement's
 * `leerbedrijfVerification.provider` field and resolved by the application
 * container — no concrete provider ships with Scholiq itself. The SBB
 * register OpenConnector adapter is explicit cross-repo follow-up work on
 * `ConductionNL/openconnector`, not built here — Scholiq works standalone
 * without it (an unconfigured provider simply leaves BpvPlacement unable to
 * confirm, not broken).
 *
 * Analogous to ProvidesProctoring (assessment) and ProvidesPlagiarismCheck
 * (assignments) — this is the third pluggable-provider seam in Scholiq.
 *
 * This is the single PHP seam for leerbedrijf verification per the `bpv`
 * spec (ADR-031: "External-system contract — SDK/API bridge that must be
 * expressed in PHP").
 *
 * @category Bpv
 * @package  OCA\Scholiq\Bpv
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
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Scholiq\Bpv;

/**
 * Pluggable leerbedrijf-verification provider interface.
 *
 * Adapters for external leerbedrijf registers (the SBB erkend-leerbedrijf
 * register, or any custom on-premises/test double) implement this interface.
 * No concrete provider ships with Scholiq; this is the integration seam only.
 *
 * Implementation notes:
 * - `verify()` is a synchronous, per-placement, blocking check — NOT a bulk
 *   job. It is invoked once per `checkLeerbedrijf` transition by
 *   BpvLeerbedrijfVerificationHandler, which writes the result back onto
 *   BpvPlacement.leerbedrijfVerification. BpvConfirmationGuard reads that
 *   STORED result (never calls the provider synchronously during a
 *   transition), the same pattern AssessmentPublishGuard and
 *   LearningPlanSignatureGuard use for other stored lifecycle-adjacent state.
 * - `status` in the returned array MUST be one of `verified`, `rejected`, or
 *   `pending`. Only `verified` satisfies BpvConfirmationGuard.
 */
interface ProvidesLeerbedrijfVerification
{
    /**
     * Verify whether a company is an SBB-erkend leerbedrijf.
     *
     * @param string $kvkOrErkenningNumber The company's KVK number or an existing SBB
     *                                     erkenning number to re-check.
     *
     * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array}
     *                                     `status` is one of `verified`|`rejected`|`pending`.
     *                                     `erkenningNumber` and `expiresAt` are populated when
     *                                     `status` is `verified`. `raw` carries the provider's
     *                                     raw response payload for audit purposes.
     *
     * @throws \RuntimeException When the provider cannot be reached.
     *
     * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
     */
    public function verify(string $kvkOrErkenningNumber): array;
}//end interface
