<?php

/**
 * Unit tests for the `assessment-item-pools-and-analysis` register delta.
 *
 * Asserts the schema shape for Part A (Assessment.itemSelectionMode /
 * itemPoolConfig / shuffleItemOrder / shuffleAnswerOptions, Item.variantGroupId,
 * AssessmentResult.drawnItemRefs) and Part B (Assessment.itemAnalysisConfig,
 * the new ItemStatistics/AssessmentReliability/ItemRevisionFlag objects), and
 * the "no invented register-level config block" design decision (design.md
 * "Minimum-N thresholds"). Mirrors SecureExamTestModeTest.php's style.
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
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the item-pools/analysis schema delta.
 */
class AssessmentItemPoolsRegisterTest extends TestCase
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
        $path = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $raw  = file_get_contents($path);
        $this->assertNotFalse($raw, 'scholiq_register.json must be readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'scholiq_register.json must be valid JSON');
        $this->config = $decoded;

    }//end setUp()

    /**
     * Assessment.itemSelectionMode is a fixed|random-draw enum defaulting to fixed.
     *
     * @return void
     */
    public function testAssessmentDeclaresItemSelectionMode(): void
    {
        $schema = $this->config['components']['schemas']['Assessment']['properties']['itemSelectionMode'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame(['fixed', 'random-draw'], $schema['enum'] ?? null);
        $this->assertSame('fixed', $schema['default'] ?? null);

    }//end testAssessmentDeclaresItemSelectionMode()

    /**
     * Assessment.itemPoolConfig is a nullable object declaring itemBankId,
     * drawCount (minimum 1), subjectTags (default []), and nullable
     * difficultyMin/difficultyMax.
     *
     * @return void
     */
    public function testAssessmentDeclaresItemPoolConfig(): void
    {
        $schema = $this->config['components']['schemas']['Assessment']['properties']['itemPoolConfig'] ?? null;
        $this->assertIsArray($schema);
        $this->assertTrue($schema['nullable'] ?? false);

        $properties = $schema['properties'] ?? [];
        $this->assertArrayHasKey('itemBankId', $properties);
        $this->assertSame('ItemBank', $properties['itemBankId']['$ref'] ?? null);

        $this->assertArrayHasKey('drawCount', $properties);
        $this->assertSame('integer', $properties['drawCount']['type'] ?? null);
        $this->assertSame(1, $properties['drawCount']['minimum'] ?? null);

        $this->assertArrayHasKey('subjectTags', $properties);
        $this->assertSame([], $properties['subjectTags']['default'] ?? null);

        $this->assertArrayHasKey('difficultyMin', $properties);
        $this->assertTrue($properties['difficultyMin']['nullable'] ?? false);
        $this->assertArrayHasKey('difficultyMax', $properties);
        $this->assertTrue($properties['difficultyMax']['nullable'] ?? false);

    }//end testAssessmentDeclaresItemPoolConfig()

    /**
     * shuffleItemOrder and shuffleAnswerOptions are independent booleans,
     * both defaulting to false, unconditioned on itemSelectionMode.
     *
     * @return void
     */
    public function testAssessmentDeclaresIndependentShuffleFlags(): void
    {
        $properties = $this->config['components']['schemas']['Assessment']['properties'] ?? [];

        $this->assertSame('boolean', $properties['shuffleItemOrder']['type'] ?? null);
        $this->assertSame(false, $properties['shuffleItemOrder']['default'] ?? null);

        $this->assertSame('boolean', $properties['shuffleAnswerOptions']['type'] ?? null);
        $this->assertSame(false, $properties['shuffleAnswerOptions']['default'] ?? null);

    }//end testAssessmentDeclaresIndependentShuffleFlags()

    /**
     * Assessment.itemAnalysisConfig is a nullable object whose property
     * defaults match ItemAnalysisService's schema-default fallbacks exactly
     * (design.md "Minimum-N thresholds": 20 / 30 / 0.20 / 0.95 / 0.10) — this
     * is the verified "field with a schema default" mechanism, not an
     * invented register-level config block.
     *
     * @return void
     */
    public function testAssessmentDeclaresItemAnalysisConfigWithDesignDefaults(): void
    {
        $schema = $this->config['components']['schemas']['Assessment']['properties']['itemAnalysisConfig'] ?? null;
        $this->assertIsArray($schema);
        $this->assertTrue($schema['nullable'] ?? false);

        $properties = $schema['properties'] ?? [];
        $this->assertSame(20, $properties['minSampleSize']['default'] ?? null);
        $this->assertSame(30, $properties['reliabilityMinSampleSize']['default'] ?? null);
        $this->assertSame(0.2, $properties['tooDifficultyBelow']['default'] ?? null);
        $this->assertSame(0.95, $properties['tooEasyAbove']['default'] ?? null);
        $this->assertSame(0.1, $properties['lowDiscriminationBelow']['default'] ?? null);

    }//end testAssessmentDeclaresItemAnalysisConfigWithDesignDefaults()

    /**
     * The register JSON's only extension point outside a schema's own
     * properties/x-openregister-* blocks is the fixed, OR-owned top-level
     * x-openregister register-metadata key — asserting this pins the design
     * decision that itemAnalysisConfig lives on Assessment, not a new
     * top-level register-wide config block.
     *
     * @return void
     */
    public function testNoInventedRegisterLevelConfigBlockExists(): void
    {
        $topLevelKeys = array_keys($this->config);
        $unexpected   = array_diff($topLevelKeys, ['openapi', 'info', 'components', 'x-openregister', 'paths']);
        $this->assertSame(
            [],
            $unexpected,
            'No new top-level register-wide config block should have been added — '
            .'unexpected top-level keys: '.implode(', ', $unexpected)
        );

    }//end testNoInventedRegisterLevelConfigBlockExists()

    /**
     * Item.variantGroupId is a nullable uuid.
     *
     * @return void
     */
    public function testItemDeclaresVariantGroupId(): void
    {
        $schema = $this->config['components']['schemas']['Item']['properties']['variantGroupId'] ?? null;
        $this->assertIsArray($schema);
        $this->assertTrue($schema['nullable'] ?? false);
        $this->assertSame('uuid', $schema['format'] ?? null);

    }//end testItemDeclaresVariantGroupId()

    /**
     * AssessmentResult.drawnItemRefs is an array (default []) of
     * {itemId, points, optionOrder} entries, additive so existing
     * AssessmentResult rows leave it [].
     *
     * @return void
     */
    public function testAssessmentResultDeclaresDrawnItemRefs(): void
    {
        $schema = $this->config['components']['schemas']['AssessmentResult']['properties']['drawnItemRefs'] ?? null;
        $this->assertIsArray($schema);
        $this->assertSame('array', $schema['type'] ?? null);
        $this->assertSame([], $schema['default'] ?? null);

        $itemProperties = $schema['items']['properties'] ?? [];
        $this->assertArrayHasKey('itemId', $itemProperties);
        $this->assertArrayHasKey('points', $itemProperties);
        $this->assertArrayHasKey('optionOrder', $itemProperties);
        $this->assertTrue($itemProperties['optionOrder']['nullable'] ?? false);

    }//end testAssessmentResultDeclaresDrawnItemRefs()

    /**
     * ItemStatistics is a fully-derived, no-lifecycle, read-only object
     * scoped to (itemId, assessmentId), mirroring the FinalGrade precedent,
     * with staff-only x-property-rbac.
     *
     * @return void
     */
    public function testItemStatisticsIsFullyDerivedAndStaffOnly(): void
    {
        $schema = $this->config['components']['schemas']['ItemStatistics'] ?? null;
        $this->assertIsArray($schema, 'ItemStatistics schema MUST exist');

        $this->assertTrue($schema['x-openregister']['readOnly'] ?? false);
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'ItemStatistics MUST have no lifecycle — fully derived');

        $properties = $schema['properties'] ?? [];
        $this->assertSame('Item', $properties['itemId']['$ref'] ?? null);
        $this->assertSame('Assessment', $properties['assessmentId']['$ref'] ?? null);
        $this->assertArrayHasKey('sampleSize', $properties);
        $this->assertTrue($properties['pValue']['nullable'] ?? false);
        $this->assertTrue($properties['itemTotalCorrelation']['nullable'] ?? false);
        $this->assertTrue($properties['distractorAnalysis']['nullable'] ?? false);
        $this->assertArrayHasKey('insufficientData', $properties);

        $rbacRoles = array_column($schema['x-property-rbac']['read']['anyOf'] ?? [], 'role');
        $this->assertEqualsCanonicalizing(['admin', 'teacher', 'examboard'], array_filter($rbacRoles));

    }//end testItemStatisticsIsFullyDerivedAndStaffOnly()

    /**
     * AssessmentReliability is a fully-derived, no-lifecycle, read-only
     * object, mirroring the FinalGrade precedent, with staff-only
     * x-property-rbac.
     *
     * @return void
     */
    public function testAssessmentReliabilityIsFullyDerivedAndStaffOnly(): void
    {
        $schema = $this->config['components']['schemas']['AssessmentReliability'] ?? null;
        $this->assertIsArray($schema, 'AssessmentReliability schema MUST exist');

        $this->assertTrue($schema['x-openregister']['readOnly'] ?? false);
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'AssessmentReliability MUST have no lifecycle — fully derived');

        $properties = $schema['properties'] ?? [];
        $this->assertSame('Assessment', $properties['assessmentId']['$ref'] ?? null);
        $this->assertArrayHasKey('sampleSize', $properties);
        $this->assertArrayHasKey('itemCount', $properties);
        $this->assertTrue($properties['cronbachAlpha']['nullable'] ?? false);
        $this->assertArrayHasKey('insufficientData', $properties);

        $rbacRoles = array_column($schema['x-property-rbac']['read']['anyOf'] ?? [], 'role');
        $this->assertEqualsCanonicalizing(['admin', 'teacher', 'examboard'], array_filter($rbacRoles));

    }//end testAssessmentReliabilityIsFullyDerivedAndStaffOnly()

    /**
     * ItemRevisionFlag is appendOnly, open->acknowledged->revised|dismissed,
     * notifies examboard+admin on creation, and is staff-only readable.
     *
     * @return void
     */
    public function testItemRevisionFlagShapeAndNotifications(): void
    {
        $schema = $this->config['components']['schemas']['ItemRevisionFlag'] ?? null;
        $this->assertIsArray($schema, 'ItemRevisionFlag schema MUST exist');
        $this->assertTrue($schema['appendOnly'] ?? false);

        $properties = $schema['properties'] ?? [];
        $this->assertSame(
            ['too-difficult', 'too-easy', 'low-discrimination', 'negative-discrimination'],
            $properties['reason']['enum'] ?? null
        );

        $lifecycle   = $schema['x-openregister-lifecycle'] ?? [];
        $transitions = $lifecycle['transitions'] ?? [];
        $this->assertSame('open', $lifecycle['initial'] ?? null);
        $this->assertArrayHasKey('acknowledge', $transitions);
        $this->assertArrayHasKey('revise', $transitions);
        $this->assertArrayHasKey('dismiss', $transitions);
        $this->assertSame(['open', 'acknowledged'], $transitions['revise']['from'] ?? null);
        $this->assertSame('revised', $transitions['revise']['to'] ?? null);
        $this->assertSame(['open', 'acknowledged'], $transitions['dismiss']['from'] ?? null);
        $this->assertSame('dismissed', $transitions['dismiss']['to'] ?? null);

        $notification = $schema['x-openregister-notifications']['flagRaised'] ?? null;
        $this->assertIsArray($notification);
        $this->assertSame('created', $notification['trigger']['type'] ?? null);
        $recipientGroups = [];
        foreach (($notification['recipients'] ?? []) as $recipient) {
            if (($recipient['kind'] ?? null) === 'groups') {
                $recipientGroups = array_merge($recipientGroups, ($recipient['groups'] ?? []));
            }
        }

        $this->assertEqualsCanonicalizing(['examboard', 'admin'], $recipientGroups);
        $this->assertNotEmpty($notification['subject']['nl'] ?? '');
        $this->assertNotEmpty($notification['subject']['en'] ?? '');

        $rbacRoles = array_column($schema['x-property-rbac']['read']['anyOf'] ?? [], 'role');
        $this->assertEqualsCanonicalizing(['admin', 'teacher', 'examboard'], array_filter($rbacRoles));

    }//end testItemRevisionFlagShapeAndNotifications()
}//end class
