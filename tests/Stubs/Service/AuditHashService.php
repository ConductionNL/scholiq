<?php

/**
 * Test stub for OCA\OpenRegister\Service\AuditHashService.
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
 * Minimal AuditHashService stub for Scholiq unit tests.
 */
abstract class AuditHashService
{
    /**
     * @param int|null $fromId
     * @param int|null $toId
     * @return array<string,mixed>
     */
    abstract public function verifyChain(?int $fromId=null, ?int $toId=null): array;
}//end class
