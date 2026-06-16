<?php

/**
 * Test stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * The real ObjectEntity is an NC AppFramework Entity whose setters/getters are
 * `__call` magic methods, so PHPUnit cannot configure them on a mock. This stub
 * declares the surface the CredentialVerifyController tests need. Resolved via
 * the `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;

/**
 * Minimal ObjectEntity stub for Scholiq unit tests.
 */
abstract class ObjectEntity implements JsonSerializable
{
    /**
     * @return array<string,mixed>
     */
    abstract public function jsonSerialize(): array;

    abstract public function getRegister(): string;
    abstract public function getSchema(): string;
    abstract public function getUuid(): ?string;
}//end class
