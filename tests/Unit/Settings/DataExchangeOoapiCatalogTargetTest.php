<?php

/**
 * Unit test for the `delegate-ooapi-to-opencatalogi` register-JSON declaration.
 *
 * Regression guard per delegate-ooapi-to-opencatalogi tasks.md#task-4.2: the
 * `ooapi-catalog` target name documented in course-management's OOAPI 5.0
 * publication contract (and named in data-exchange's "Delegate wire
 * protocols to OpenConnector" requirement) must stay discoverable from
 * `DataExchangeJob.properties.target`'s description string, so implementers
 * of the OpenConnector adapter/opencatalogi endpoint find the convention
 * instead of inventing their own target name. `target` remains a free-text
 * `type: string` with no `enum` — this test asserts the documentation, not a
 * schema/type change (there is none).
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies DataExchangeJob.target documents the `ooapi-catalog` OpenConnector target.
 */
class DataExchangeOoapiCatalogTargetTest extends TestCase
{

    /**
     * Decoded register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Load the register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path         = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $this->config = json_decode((string) file_get_contents($path), true);

    }//end setUp()

    /**
     * DataExchangeJob.target is still a free-text string with no enum — the
     * `ooapi-catalog` convention must remain a documented example, not a
     * schema/type constraint (design.md's "no migration" decision).
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.2
     */
    public function testTargetRemainsFreeTextStringWithNoEnum(): void
    {
        $target = $this->config['components']['schemas']['DataExchangeJob']['properties']['target'];

        self::assertSame('string', $target['type']);
        self::assertArrayNotHasKey('enum', $target);

    }//end testTargetRemainsFreeTextStringWithNoEnum()

    /**
     * DataExchangeJob.target's description documents the `ooapi-catalog`
     * target name so implementers discover the convention instead of
     * inventing their own.
     *
     * @return void
     *
     * @spec openspec/changes/delegate-ooapi-to-opencatalogi/tasks.md#task-4.2
     */
    public function testTargetDescriptionDocumentsOoapiCatalog(): void
    {
        $description = $this->config['components']['schemas']['DataExchangeJob']['properties']['target']['description'];

        self::assertStringContainsString('ooapi-catalog', $description);

    }//end testTargetDescriptionDocumentsOoapiCatalog()

    /**
     * The register's own document version was patch-bumped for this doc-only
     * edit (design.md: "no migration — the field is already a free-text
     * string").
     *
     * @return void
     */
    public function testRegisterInfoVersionWasBumped(): void
    {
        self::assertSame('0.6.1', $this->config['info']['version']);
        self::assertStringContainsString('ooapi-catalog', $this->config['info']['description']);

    }//end testRegisterInfoVersionWasBumped()
}//end class
