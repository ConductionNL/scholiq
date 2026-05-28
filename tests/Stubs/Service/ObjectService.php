<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
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
 * Minimal ObjectService stub for Scholiq unit tests.
 *
 * The real OR ObjectService::saveObject accepts two call signatures:
 *   - saveObject(array $object)                                  — single-arg (QtiImportService)
 *   - saveObject(string $register, string $schema, array $object) — 3-arg (named-param form)
 *
 * We declare the 3-arg form here (which PHPStan resolves for the named-param
 * callers). The single-arg QtiImportService call is suppressed via the existing
 * phpstan-baseline.neon.
 */
abstract class ObjectService
{
    /**
     * @param string $id
     * @param string $register
     * @param string $schema
     * @return mixed
     */
    abstract public function find(string $id, string $register, string $schema): mixed;

    /**
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    abstract public function findAll(array $config): array;

    /**
     * @param string              $register
     * @param string              $schema
     * @param array<string,mixed> $object
     * @return mixed
     */
    abstract public function saveObject(string $register, string $schema, array $object): mixed;
}//end class
