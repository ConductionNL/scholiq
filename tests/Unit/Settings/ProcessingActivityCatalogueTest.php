<?php

/**
 * Unit tests for the AVG Art. 30 processing-activity catalogue annotations
 * added to `scholiq_register.json` by the `avg-verwerkingsregister` change.
 *
 * Scholiq is a THIN CONSUMER of OpenRegister's platform processing-activity
 * register (OR-PA-1..9): it declares its seven processing activities as
 * `x-openregister-processing` catalogue annotations and opts the carrying
 * schemas into OpenRegister's per-access read-logging. The ProcessingActivity
 * entity, validation, lifecycle, versioning, review-due notifications, the
 * aggregate Art. 30 export, and the access gating all live in OpenRegister
 * (ADR-022); Scholiq owns NO export service, controller, schema, or template.
 *
 * These tests assert:
 *   - the seven activities are declared with the required catalogue fields;
 *   - each annotation opts the schema into read-logging and attributes to its
 *     own activity code (resolvable by OpenRegister's ProcessingLogService);
 *   - owner/review fields are present so OR-PA-1 review notifications fire,
 *     while Scholiq ships NO notification rule of its own;
 *   - the register requires a processing-capable OpenRegister (>= 0.2.14);
 *   - Scholiq ships NO route or controller that aggregates / exports
 *     processing activities (the export is OR-PA-7's).
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the processing-activity catalogue contract, the read-log opt-in,
 * the audit-pack inclusion step, and the no-export-engine guard.
 */
class ProcessingActivityCatalogueTest extends TestCase
{

    /**
     * Decoded register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Map of carrying schema => expected activity code.
     *
     * @var array<string, string>
     */
    private const ACTIVITY_SCHEMAS = [
        'LearnerProfile'   => 'scholiq-learner-administration',
        'AttendanceRecord' => 'scholiq-attendance-leerplicht',
        'Assessment'       => 'scholiq-grading-assessment',
        'Attestation'      => 'scholiq-attestations',
        'Credential'       => 'scholiq-credentialing',
        'DataExchangeJob'  => 'scholiq-data-exchange',
        'AiFeature'        => 'scholiq-ai-features',
    ];


    /**
     * Load the register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $raw  = file_get_contents($path);
        $this->assertNotFalse($raw, 'scholiq_register.json must be readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'scholiq_register.json must be valid JSON');
        $this->config = $decoded;

    }//end setUp()


    /**
     * The seven activities are declared, each on its carrying schema, with the
     * required Art. 30 catalogue fields and its own attribution code.
     *
     * @return void
     */
    public function testSevenActivitiesDeclaredWithCatalogueFields(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];
        $codes   = [];

        foreach (self::ACTIVITY_SCHEMAS as $schemaName => $expectedCode) {
            $this->assertArrayHasKey($schemaName, $schemas, "schema $schemaName MUST exist");
            $processing = $schemas[$schemaName]['x-openregister-processing'] ?? null;
            $this->assertIsArray($processing, "$schemaName MUST carry x-openregister-processing");

            // Required Art. 30 catalogue fields.
            foreach (['code', 'naam', 'doelbinding', 'rechtsgrond', 'dataCategories', 'backend', 'retentionReference', 'grondslagSource'] as $field) {
                $this->assertArrayHasKey($field, $processing, "$schemaName.$field MUST be declared");
                $this->assertNotEmpty($processing[$field], "$schemaName.$field MUST be non-empty");
            }

            $this->assertSame($expectedCode, $processing['code'], "$schemaName MUST declare code $expectedCode");
            $codes[] = $processing['code'];
        }

        $this->assertCount(7, array_unique($codes), 'Exactly seven distinct activity codes expected');

    }//end testSevenActivitiesDeclaredWithCatalogueFields()


    /**
     * Each annotation opts the schema into read-logging and attributes reads
     * to its own activity code (resolvable by OpenRegister by code).
     *
     * @return void
     */
    public function testActivitiesOptInToReadLoggingAndSelfAttribute(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];

        foreach (self::ACTIVITY_SCHEMAS as $schemaName => $expectedCode) {
            $processing = $schemas[$schemaName]['x-openregister-processing'];

            $this->assertTrue(
                ($processing['logReads'] ?? false) === true,
                "$schemaName MUST opt into per-access read logging (logReads: true)"
            );

            $this->assertArrayHasKey('attribution', $processing, "$schemaName MUST declare attribution");
            $this->assertSame(
                $expectedCode,
                $processing['attribution']['default'] ?? null,
                "$schemaName attribution.default MUST reference its own activity code"
            );

            $this->assertArrayHasKey('subjectIdFields', $processing, "$schemaName MUST declare subjectIdFields (may be empty)");
            $this->assertIsArray($processing['subjectIdFields']);
        }

    }//end testActivitiesOptInToReadLoggingAndSelfAttribute()


    /**
     * Each activity carries owner/review fields so OpenRegister's review-due
     * notification (OR-PA-1) fires — and Scholiq ships NO notification rule of
     * its own for processing-activity reviews.
     *
     * @return void
     */
    public function testOwnerReviewFieldsPresentAndNoScholiqNotificationRule(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];

        foreach (self::ACTIVITY_SCHEMAS as $schemaName => $code) {
            $processing = $schemas[$schemaName]['x-openregister-processing'];

            foreach (['ownerUserId', 'reviewIntervalMonths', 'nextReviewAt'] as $field) {
                $this->assertArrayHasKey($field, $processing, "$schemaName.$field MUST be set so OR-PA-1 review notifications fire");
                $this->assertNotEmpty($processing[$field], "$schemaName.$field MUST be non-empty");
            }

            // Seeds arrive as drafts; the privacy officer activates them.
            $this->assertSame('draft', $processing['lifecycle'] ?? null, "$schemaName MUST seed as a draft");

            // No notification rule referencing processing-activity reviews lives
            // in the carrying schema — the platform owns the notification.
            $encoded = (string) json_encode($schemas[$schemaName]['x-openregister-notifications'] ?? []);
            $this->assertStringNotContainsStringIgnoringCase('review', $encoded, "$schemaName MUST NOT declare a processing-activity review notification rule");
        }

    }//end testOwnerReviewFieldsPresentAndNoScholiqNotificationRule()


    /**
     * The register requires the OpenRegister version that ships the per-access
     * read-logging dialect (>= 0.2.14).
     *
     * @return void
     */
    public function testRegisterRequiresProcessingCapableOpenRegister(): void
    {
        $constraint = (string) ($this->config['x-openregister']['openregister'] ?? '');
        $this->assertNotSame('', $constraint, 'OpenRegister version constraint MUST be declared');

        // Extract the minor version from a `^vX.Y.Z` style constraint.
        $this->assertMatchesRegularExpression('/0\.2\.(1[4-9]|[2-9][0-9]|[3-9])/', $constraint, 'OpenRegister constraint MUST be >= 0.2.14');

    }//end testRegisterRequiresProcessingCapableOpenRegister()


    /**
     * The compliance audit-pack writer includes the platform-generated
     * verwerkingsregister artefact and degrades loudly when the platform
     * capability is absent — and ships NO export engine of its own.
     *
     * @return void
     */
    public function testAuditPackIncludesVerwerkingsregisterAndFailsLoudly(): void
    {
        $source = file_get_contents(__DIR__.'/../../../lib/Controller/AuditPackExportController.php');
        $this->assertIsString($source);

        // The artefact is added to the ZIP file set.
        $this->assertStringContainsString(
            "'verwerkingsregister.csv'",
            $source,
            'The audit pack MUST include a verwerkingsregister.csv artefact'
        );

        // The artefact is fetched from OpenRegister's processing-log capability
        // (OR-PA-7/8) — scholiq does not generate the register itself.
        $this->assertStringContainsString(
            'openregister.processingLog.index',
            $source,
            'The verwerkingsregister artefact MUST be sourced from OpenRegister, not generated by scholiq'
        );

        // The capability-missing path degrades loudly, never silently.
        $this->assertStringContainsString(
            'PLATFORM CAPABILITY MISSING',
            $source,
            'A missing platform capability MUST surface a loud warning, never a silent omission'
        );

    }//end testAuditPackIncludesVerwerkingsregisterAndFailsLoudly()


    /**
     * Scholiq ships NO endpoint or controller that aggregates / exports
     * processing activities — that surface is OpenRegister's (OR-PA-7), per
     * ADR-022.
     *
     * @return void
     */
    public function testNoScholiqProcessingExportEndpointExists(): void
    {
        $routesPath = __DIR__.'/../../../appinfo/routes.php';
        $routes     = require $routesPath;
        $this->assertIsArray($routes);

        $names = [];
        foreach (($routes['routes'] ?? []) as $route) {
            $names[] = strtolower((string) ($route['name'] ?? ''));
        }

        $forbidden = ['verwerkingen', 'verwerkingsactiviteit', 'verwerkingsregister', 'processingactivit', 'art30', 'art-30'];
        foreach ($names as $name) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $name,
                    "Scholiq MUST NOT register a processing-activity export route ($name) — the export is OR-PA-7's"
                );
            }
        }

        // No controller class implements an aggregation/export surface.
        $controllerDir = __DIR__.'/../../../lib/Controller';
        $this->assertDirectoryExists($controllerDir);
        $hits = glob($controllerDir.'/*ProcessingActivit*Controller.php') ?: [];
        $hits = array_merge($hits, (glob($controllerDir.'/*Verwerking*Controller.php') ?: []));
        $this->assertSame([], $hits, 'Scholiq MUST NOT ship a processing-activity / verwerkingsregister controller');

        // No schema named ProcessingActivity is defined in scholiq's register —
        // the entity is OpenRegister's (OR-PA-1).
        $schemas = $this->config['components']['schemas'] ?? [];
        $this->assertArrayNotHasKey('ProcessingActivity', $schemas, 'Scholiq MUST NOT define a ProcessingActivity schema — the entity is OR-PA-1');

    }//end testNoScholiqProcessingExportEndpointExists()
}//end class
