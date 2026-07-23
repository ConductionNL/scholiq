<?php

/**
 * Unit tests for the `zorgvraag-swv-tlv-chain` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` are evaluated by
 * OpenRegister core at runtime, which does not live in this repository (only
 * test stubs for its PHP service classes do — see composer.json's
 * `autoload-dev`). Scholiq cannot unit-test the numeric OUTPUT of a declared
 * calculation (no existing test in this suite does — see e.g.
 * VerzuimReportComposerRegisterTest, Course.isPublished). What Scholiq CAN
 * and MUST verify is that the declared SHAPE is correct: the right fields
 * exist, the expression references the right props/thresholds, and the
 * lifecycle/RBAC/notification declarations are wired the way design.md
 * specifies. This mirrors the established pattern in
 * VerzuimReportComposerRegisterTest and ProcessingActivityCatalogueTest.
 *
 * Covers the TlvApplication.tlvExpiringSoon "TLV expiry calc" test the task
 * bar requires, plus SupportRequest/DeliberationRecord shape coverage and
 * the DataExchangeJob/DataMappingProfile swv-target extension.
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
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-6.3
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the SupportRequest/TlvApplication/DeliberationRecord declarations
 * plus the DataExchangeJob/DataMappingProfile swv-target extension.
 */
class ZorgvraagSwvTlvChainRegisterTest extends TestCase
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
     * SupportRequest declares the full draft→…→closed lifecycle, a nullable
     * LearningPlan link, and admin/principal-only creation (design.md
     * "tightest posture" — no dedicated coordinator role exists yet).
     *
     * @return void
     */
    public function testSupportRequestLifecycleAndAuthorizationShape(): void
    {
        $schema = $this->config['components']['schemas']['SupportRequest'];

        $transitions = $schema['x-openregister-lifecycle']['transitions'];
        self::assertSame('draft', $schema['x-openregister-lifecycle']['initial']);
        self::assertSame('submitted', $transitions['submit']['to']);
        self::assertSame('routed-to-swv', $transitions['routeToSwv']['to']);
        self::assertSame('in-deliberation', $transitions['startDeliberation']['to']);
        self::assertSame('decided', $transitions['decide']['to']);
        self::assertSame('closed', $transitions['close']['to']);

        $learningPlanId = $schema['properties']['learningPlanId'];
        self::assertTrue($learningPlanId['nullable']);
        self::assertSame('LearningPlan', $learningPlanId['$ref']);
        self::assertNull($learningPlanId['default']);

        self::assertSame(['admin', 'principal'], $schema['x-openregister-authorization']['create']);

    }//end testSupportRequestLifecycleAndAuthorizationShape()

    /**
     * SupportRequest.supportRequestRouted notification fires on the
     * routeToSwv transition, addressed to the raising coordinator (raisedBy).
     *
     * @return void
     */
    public function testSupportRequestRoutedNotificationShape(): void
    {
        $schema       = $this->config['components']['schemas']['SupportRequest'];
        $notification = $schema['x-openregister-notifications']['supportRequestRouted'];

        self::assertSame('transition', $notification['trigger']['type']);
        self::assertSame('routeToSwv', $notification['trigger']['action']);
        self::assertSame('raisedBy', $notification['recipients'][0]['field']);

    }//end testSupportRequestRoutedNotificationShape()

    /**
     * TlvApplication.decide records the SWV's externally-issued decision with
     * no `requires` guard — the SWV is the sole deciding authority, Scholiq
     * implements no adjudication logic (design.md "TLV decision recorded,
     * never adjudicated").
     *
     * @return void
     */
    public function testTlvApplicationDecideTransitionHasNoAdjudicationGuard(): void
    {
        $schema      = $this->config['components']['schemas']['TlvApplication'];
        $transitions = $schema['x-openregister-lifecycle']['transitions'];

        self::assertSame('under-review', $transitions['decide']['from']);
        self::assertSame('decided', $transitions['decide']['to']);
        self::assertArrayNotHasKey('requires', $transitions['decide']);

        $decision = $schema['properties']['decision'];
        self::assertContains('approved', $decision['enum']);
        self::assertContains('rejected', $decision['enum']);
        self::assertContains('conditional', $decision['enum']);
        self::assertNull($decision['default']);

    }//end testTlvApplicationDecideTransitionHasNoAdjudicationGuard()

    /**
     * TLV expiry calc: TlvApplication.tlvExpiringSoon is a declared
     * `x-openregister-calculations` boolean, materialised, evaluated against
     * validUntil via the intermediate daysUntilValidUntil calc — the same
     * declared-calculation-trigger pattern as Credential's
     * daysUntilExpiry/isExpiringIn30Days (design.md "TLV expiry is a declared
     * calculation trigger, not a PHP TimedJob"). No PHP TimedJob class exists
     * for this — verified by the absence of any Cron/TimedJob registration
     * for tlv-application in Application.php (task 4's grep already covers
     * that; this test asserts the declared SHAPE only, per the scope note
     * above).
     *
     * @return void
     */
    public function testTlvExpiringSoonCalculationShape(): void
    {
        $schema = $this->config['components']['schemas']['TlvApplication'];
        $calcs  = $schema['x-openregister-calculations'];

        $daysUntil = $calcs['daysUntilValidUntil'];
        self::assertTrue($daysUntil['materialise']);
        self::assertSame('integer', $daysUntil['type']);
        self::assertSame('validUntil', $daysUntil['expression']['if'][2]['dateDiff']['to']['prop']);
        self::assertSame('days', $daysUntil['expression']['if'][2]['dateDiff']['unit']);

        $expiringSoon = $calcs['tlvExpiringSoon'];
        self::assertTrue($expiringSoon['materialise']);
        self::assertSame('boolean', $expiringSoon['type']);

        $terms = $expiringSoon['expression']['and'];
        self::assertSame('decision', $terms[0]['eq'][0]['prop']);
        self::assertSame('approved', $terms[0]['eq'][1]);
        self::assertSame('validUntil', $terms[1]['ne'][0]['prop']);
        self::assertNull($terms[1]['ne'][1]);
        self::assertSame('daysUntilValidUntil', $terms[2]['lte'][0]['prop']);
        self::assertSame(30, $terms[2]['lte'][1]);
        self::assertSame('daysUntilValidUntil', $terms[3]['gt'][0]['prop']);
        self::assertSame(0, $terms[3]['gt'][1]);

    }//end testTlvExpiringSoonCalculationShape()

    /**
     * TlvApplication.tlvExpiringSoon notification is a calculatedChange
     * trigger flipping false → true, idempotent via the same
     * previously/condition mechanism as AttendanceFlag.reportDeadlineOverdue
     * and Credential.expiringSoon.
     *
     * @return void
     */
    public function testTlvExpiringSoonNotificationShape(): void
    {
        $schema       = $this->config['components']['schemas']['TlvApplication'];
        $notification = $schema['x-openregister-notifications']['tlvExpiringSoon'];

        self::assertSame('calculatedChange', $notification['trigger']['type']);
        self::assertSame('tlvExpiringSoon', $notification['trigger']['field']);
        self::assertTrue($notification['trigger']['condition']['eq']);
        self::assertFalse($notification['trigger']['previously']['eq']);

    }//end testTlvExpiringSoonNotificationShape()

    /**
     * DeliberationRecord is appendOnly, requires at least one of
     * supportRequestId/tlvApplicationId (schema-level anyOf), and the
     * scheduled → recorded transition requires PupilVoiceGuard.
     *
     * @return void
     */
    public function testDeliberationRecordAppendOnlyAndRequiredOneOfShape(): void
    {
        $schema = $this->config['components']['schemas']['DeliberationRecord'];

        self::assertTrue($schema['appendOnly']);

        $anyOf = $schema['anyOf'];
        self::assertSame(['supportRequestId'], $anyOf[0]['required']);
        self::assertSame(['tlvApplicationId'], $anyOf[1]['required']);

        $recordTransition = $schema['x-openregister-lifecycle']['transitions']['record'];
        self::assertSame('scheduled', $recordTransition['from']);
        self::assertSame('recorded', $recordTransition['to']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\PupilVoiceGuard', $recordTransition['requires']);

    }//end testDeliberationRecordAppendOnlyAndRequiredOneOfShape()

    /**
     * DeliberationRecord.pupilVoice carries heard/statementNote/waived/
     * waiverReason, distinct from any parent Signature/consent (2025
     * hoorrecht, insight 1145).
     *
     * @return void
     */
    public function testPupilVoicePropertyShape(): void
    {
        $schema     = $this->config['components']['schemas']['DeliberationRecord'];
        $pupilVoice = $schema['properties']['pupilVoice'];

        self::assertSame('object', $pupilVoice['type']);
        self::assertSame(['heard', 'waived'], $pupilVoice['required']);

        $props = $pupilVoice['properties'];
        self::assertSame('boolean', $props['heard']['type']);
        self::assertFalse($props['heard']['default']);
        self::assertTrue($props['statementNote']['nullable']);
        self::assertSame('boolean', $props['waived']['type']);
        self::assertFalse($props['waived']['default']);
        self::assertTrue($props['waiverReason']['nullable']);

    }//end testPupilVoicePropertyShape()

    /**
     * DataExchangeJob.target and DataMappingProfile.target descriptions name
     * `swv` alongside the existing bron-rod/oso/leerplicht/surfconext/hr
     * targets (both fields are free strings, not a JSON-schema enum, so this
     * is a documentation-level assertion — the actual gating lives in
     * DataExchangeRunGuard::GATED_TARGETS, covered by
     * DataExchangeRunGuardTest).
     *
     * @return void
     */
    public function testDataExchangeJobAndMappingProfileTargetDescriptionsNameSwv(): void
    {
        $job     = $this->config['components']['schemas']['DataExchangeJob'];
        $profile = $this->config['components']['schemas']['DataMappingProfile'];

        self::assertStringContainsString('swv', $job['properties']['target']['description']);
        self::assertStringContainsString('swv', $profile['properties']['target']['description']);
        self::assertStringContainsString('support-request', $job['properties']['scope']['properties']['schema']['description']);

    }//end testDataExchangeJobAndMappingProfileTargetDescriptionsNameSwv()

    /**
     * The seeded "SWV zorgvraag dossier" DataMappingProfile whitelists only
     * supportDomain/description/urgency — no learnerId/bsn/full-object
     * mapping in the flat fieldMappings (the learner/learningPlan whitelist
     * sections are composed separately by DataExchangeRunHandler::
     * composeSwvDossier(), covered by DataExchangeRunHandlerTest).
     *
     * @return void
     */
    public function testSwvDataMappingProfileSeedShape(): void
    {
        $profile = $this->config['components']['schemas']['DataMappingProfile'];
        $seeds   = $profile['x-openregister-seed'];

        $swvSeed = null;
        foreach ($seeds as $seed) {
            if (($seed['target'] ?? '') === 'swv') {
                $swvSeed = $seed;
                break;
            }
        }

        self::assertNotNull($swvSeed, 'Expected a seeded DataMappingProfile with target=swv.');
        self::assertSame('support-request', $swvSeed['sourceSchema']);

        $mappedFields = array_column($swvSeed['fieldMappings'], 'scholiqField');
        self::assertContains('supportDomain', $mappedFields);
        self::assertContains('description', $mappedFields);
        self::assertContains('urgency', $mappedFields);
        self::assertNotContains('learnerId', $mappedFields);
        self::assertNotContains('bsnEncrypted', $mappedFields);

    }//end testSwvDataMappingProfileSeedShape()
}//end class
