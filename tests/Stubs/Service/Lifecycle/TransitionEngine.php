<?php

/**
 * Test stub for OCA\OpenRegister\Service\Lifecycle\TransitionEngine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Stubs\Service\Lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Minimal TransitionEngine stub for Scholiq unit tests.
 */
abstract class TransitionEngine
{
    /**
     * @param string $objectId
     * @param string $action
     * @return void
     */
    abstract public function transition(string $objectId, string $action): void;
}//end class
