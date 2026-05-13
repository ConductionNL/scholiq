<?php

/**
 * Test stub for OCA\OpenRegister\Service\TenantKeyService.
 *
 * Mirrors the surface the AttestationSigningGuard uses (per ADR-022 — HMAC key
 * management/rotation lives in OpenRegister). Resolved via the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping when the real
 * TenantKeyService is not present in the installed OpenRegister build.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Stub for TenantKeyService.
 */
abstract class TenantKeyService
{

    /**
     * Return the current HMAC signing key for the given tenant.
     *
     * @param string $tenantId The tenant identifier.
     *
     * @return string The current tenant key (empty string when no key is configured).
     */
    abstract public function getCurrentTenantKey(string $tenantId): string;

}//end class
