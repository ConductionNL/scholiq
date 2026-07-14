<?php

/**
 * Test stub for OC\Hooks\Emitter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stub for the internal Nextcloud server `OC\Hooks\Emitter` interface.
 *
 * `OCP\Files\IRootFolder` extends `OCP\Files\Folder` AND this OC-internal
 * (non-OCP) interface. The nextcloud/ocp Composer package only ships OCP\*
 * definitions, not OC\* server-internal ones, so a standalone PHPUnit run
 * (no live Nextcloud server) cannot resolve `IRootFolder`'s full interface
 * hierarchy without this stub — `PortfolioShareGrantHandlerTest` needs to
 * mock `IRootFolder` to test the NC Files share-creation bridge. Kept in
 * sync with the real interface (`lib/private/Hooks/Emitter.php`
 * upstream) — method signatures only, no behaviour.
 */
interface Emitter
{
    /**
     * @param string   $scope
     * @param string   $method
     * @param callable $callback
     *
     * @return void
     */
    public function listen($scope, $method, callable $callback);

    /**
     * @param string|null   $scope
     * @param string|null   $method
     * @param callable|null $callback
     *
     * @return void
     */
    public function removeListener($scope=null, $method=null, callable $callback=null);
}//end interface
