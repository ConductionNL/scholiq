<?php

/**
 * Test stub for OCA\OpenRegister\Db\AuditTrailMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Stub for AuditTrailMapper.
 */
abstract class AuditTrailMapper
{
    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $sort
     * @return array<int,mixed>
     */
    abstract public function findAll(array $filters=[], array $sort=[]): array;
}//end class
