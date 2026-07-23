<?php

/**
 * Unit tests for the `duo-afkeurmelding-correction` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` is evaluated by
 * OpenRegister core at runtime, which does not live in this repository (only
 * test stubs for its PHP service classes do — see composer.json's
 * `autoload-dev`). Scholiq cannot unit-test the numeric OUTPUT of a declared
 * calculation (no existing test in this suite does — see
 * VerzuimReportComposerRegisterTest). What Scholiq CAN and MUST verify is
 * that the declared SHAPE is correct — mirrors that established pattern.
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-4.5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ExchangeRejection/ExchangeErrorCode declarations, the
 * markCorrected/resubmit/accept/reopen/waive lifecycle, the ageDays/overdue
 * calculations, and the admin/principal-only x-property-rbac read gate.
 */
class ExchangeRejectionRegisterTest extends TestCase
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
     * ExchangeRejection carries one nullable typed $ref id field per
     * sourceKind enum value, mirroring GradeEntry.sourceKind.
     *
     * @return void
     */
    public function testSourceKindTypedRefFields(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];
        $props  = $schema['properties'];

        self::assertSame(
            ['learner-profile', 'enrolment', 'final-grade', 'attendance-flag', 'support-request'],
            $props['sourceKind']['enum']
        );

        self::assertSame('LearnerProfile', $props['learnerProfileId']['$ref']);
        self::assertSame('Enrolment', $props['enrolmentId']['$ref']);
        self::assertSame('FinalGrade', $props['finalGradeId']['$ref']);
        self::assertSame('AttendanceFlag', $props['attendanceFlagId']['$ref']);
        self::assertSame('SupportRequest', $props['supportRequestId']['$ref']);

        foreach (['learnerProfileId', 'enrolmentId', 'finalGradeId', 'attendanceFlagId', 'supportRequestId'] as $field) {
            self::assertTrue($props[$field]['nullable'], "{$field} must be nullable");
            self::assertNull($props[$field]['default']);
        }

    }//end testSourceKindTypedRefFields()

    /**
     * ExchangeRejection's dataExchangeJobId/resubmittedJobId both $ref
     * DataExchangeJob, and errorCodeRef $refs ExchangeErrorCode.
     *
     * @return void
     */
    public function testJobAndErrorCodeReferences(): void
    {
        $props = $this->config['components']['schemas']['ExchangeRejection']['properties'];

        self::assertSame('DataExchangeJob', $props['dataExchangeJobId']['$ref']);
        self::assertSame('DataExchangeJob', $props['resubmittedJobId']['$ref']);
        self::assertTrue($props['resubmittedJobId']['nullable']);
        self::assertSame('ExchangeErrorCode', $props['errorCodeRef']['$ref']);
        self::assertTrue($props['errorCodeRef']['nullable']);

    }//end testJobAndErrorCodeReferences()

    /**
     * ExchangeRejection's lifecycle: open (initial) → corrected → resubmitted
     * (guarded by RejectionResubmitGuard) → accepted | open, and open|corrected
     * → waived (guarded by RejectionWaiveGuard).
     *
     * @return void
     */
    public function testLifecycleTransitionShape(): void
    {
        $schema      = $this->config['components']['schemas']['ExchangeRejection'];
        $lifecycle   = $schema['x-openregister-lifecycle'];
        $transitions = $lifecycle['transitions'];

        self::assertSame('status', $lifecycle['property']);
        self::assertSame('open', $lifecycle['initial']);

        self::assertSame('open', $transitions['markCorrected']['from']);
        self::assertSame('corrected', $transitions['markCorrected']['to']);

        self::assertSame('corrected', $transitions['resubmit']['from']);
        self::assertSame('resubmitted', $transitions['resubmit']['to']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\RejectionResubmitGuard', $transitions['resubmit']['requires']);

        self::assertSame('resubmitted', $transitions['accept']['from']);
        self::assertSame('accepted', $transitions['accept']['to']);
        self::assertArrayNotHasKey('requires', $transitions['accept']);

        self::assertSame('resubmitted', $transitions['reopen']['from']);
        self::assertSame('open', $transitions['reopen']['to']);
        self::assertArrayNotHasKey('requires', $transitions['reopen']);

        self::assertSame(['open', 'corrected'], $transitions['waive']['from']);
        self::assertSame('waived', $transitions['waive']['to']);
        self::assertSame('OCA\\Scholiq\\Lifecycle\\RejectionWaiveGuard', $transitions['waive']['requires']);

    }//end testLifecycleTransitionShape()

    /**
     * ageDays is a materialised dateDiff(detectedAt, now, days) calculation —
     * always available regardless of correctionDeadlineAt.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-age-is-always-available-regardless-of-a-deadline
     */
    public function testAgeDaysCalculationShape(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];
        $calc   = $schema['x-openregister-calculations']['ageDays'];

        self::assertTrue($calc['materialise']);
        self::assertSame('integer', $calc['type']);
        self::assertSame('detectedAt', $calc['expression']['dateDiff']['from']['prop']);
        self::assertSame('now', $calc['expression']['dateDiff']['to']);
        self::assertSame('days', $calc['expression']['dateDiff']['unit']);

    }//end testAgeDaysCalculationShape()

    /**
     * overdue is null-safe on an unset correctionDeadlineAt: the expression
     * is an `if(eq(correctionDeadlineAt, null), false, ...)` guard, mirroring
     * DataExchangeJob.durationSeconds' own null-guard shape (design.md task
     * 1.4's fallback instruction).
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-age-is-always-available-regardless-of-a-deadline
     */
    public function testOverdueNullSafeWhenDeadlineUnset(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];
        $calc   = $schema['x-openregister-calculations']['overdue'];

        self::assertTrue($calc['materialise']);
        self::assertSame('boolean', $calc['type']);

        $ifExpr = $calc['expression']['if'];
        self::assertSame('correctionDeadlineAt', $ifExpr[0]['eq'][0]['prop']);
        self::assertNull($ifExpr[0]['eq'][1]);
        self::assertFalse($ifExpr[1]);

    }//end testOverdueNullSafeWhenDeadlineUnset()

    /**
     * overdue's true-branch requires correctionDeadlineAt <= now AND status
     * not in (accepted, waived).
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-overdue-activates-once-a-deadline-is-set-and-passes
     */
    public function testOverdueTrueWhenDeadlinePassed(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];
        $calc   = $schema['x-openregister-calculations']['overdue'];

        $terms = $calc['expression']['if'][2]['and'];
        self::assertSame('correctionDeadlineAt', $terms[0]['lte'][0]['prop']);
        self::assertSame('now', $terms[0]['lte'][1]);
        self::assertSame('status', $terms[1]['ne'][0]['prop']);
        self::assertSame('accepted', $terms[1]['ne'][1]);
        self::assertSame('status', $terms[2]['ne'][0]['prop']);
        self::assertSame('waived', $terms[2]['ne'][1]);

    }//end testOverdueTrueWhenDeadlinePassed()

    /**
     * correctionDeadlineAt is a plain nullable date field, NOT a calculation —
     * design.md "input, not a computed statutory countdown".
     *
     * @return void
     */
    public function testCorrectionDeadlineAtIsPlainInputNotCalculated(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];

        self::assertArrayNotHasKey('correctionDeadlineAt', $schema['x-openregister-calculations']);

        $prop = $schema['properties']['correctionDeadlineAt'];
        self::assertTrue($prop['nullable']);
        self::assertSame('date', $prop['format']);
        self::assertNull($prop['default']);

    }//end testCorrectionDeadlineAtIsPlainInputNotCalculated()

    /**
     * ExchangeRejection read access is restricted to admin/principal, and no
     * x-openregister-authorization.create block exists — listener-created
     * only, mirroring GradeNotification.
     *
     * @return void
     *
     * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-an-unauthorised-user-cannot-read-rejection-detail
     */
    public function testRbacReadRestrictedToAdminPrincipalNoCreateBlock(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeRejection'];

        self::assertArrayNotHasKey('x-openregister-authorization', $schema);

        $anyOf = $schema['x-property-rbac']['read']['anyOf'];
        self::assertCount(2, $anyOf);
        self::assertSame('admin', $anyOf[0]['role']);
        self::assertSame('principal', $anyOf[1]['role']);

    }//end testRbacReadRestrictedToAdminPrincipalNoCreateBlock()

    /**
     * ExchangeErrorCode is seeded with at least 6 illustrative starter codes,
     * each carrying a bilingual description.
     *
     * @return void
     */
    public function testErrorCodeCatalogueSeedShape(): void
    {
        $schema = $this->config['components']['schemas']['ExchangeErrorCode'];
        $seed   = $schema['x-openregister-seed'];

        self::assertGreaterThanOrEqual(6, count($seed));

        foreach ($seed as $entry) {
            self::assertArrayHasKey('code', $entry);
            self::assertArrayHasKey('nl', $entry['description']);
            self::assertArrayHasKey('en', $entry['description']);
        }

    }//end testErrorCodeCatalogueSeedShape()

    /**
     * ExchangeErrorCode.severity defaults to blocking, active defaults true.
     *
     * @return void
     */
    public function testErrorCodeDefaults(): void
    {
        $props = $this->config['components']['schemas']['ExchangeErrorCode']['properties'];

        self::assertSame('blocking', $props['severity']['default']);
        self::assertSame(['blocking', 'warning'], $props['severity']['enum']);
        self::assertTrue($props['active']['default']);

    }//end testErrorCodeDefaults()

    /**
     * DataExchangeJob.result.validationReport's description now documents the
     * assumed per-item shape, with no `type` change (doc-only per task 1.1).
     *
     * @return void
     */
    public function testValidationReportDocumentsAssumedShape(): void
    {
        $prop = $this->config['components']['schemas']['DataExchangeJob']['properties']['result']['properties']['validationReport'];

        self::assertSame('array', $prop['type']);
        self::assertStringContainsString('recordId', $prop['description']);
        self::assertStringContainsString('errorCode', $prop['description']);
        self::assertStringContainsString('errorMessage', $prop['description']);
        self::assertSame(['type' => 'object'], $prop['items']);

    }//end testValidationReportDocumentsAssumedShape()

    /**
     * Every ExchangeRejection and ExchangeErrorCode property carries both a
     * title and a description (gate-28 discipline), including the nested
     * ExchangeErrorCode.description.{nl,en} sub-properties.
     *
     * @return void
     */
    public function testEveryPropertyHasTitleAndDescription(): void
    {
        foreach (['ExchangeRejection', 'ExchangeErrorCode'] as $schemaName) {
            $props = $this->config['components']['schemas'][$schemaName]['properties'];
            foreach ($props as $name => $prop) {
                self::assertArrayHasKey('title', $prop, "{$schemaName}.{$name} missing title");
                self::assertArrayHasKey('description', $prop, "{$schemaName}.{$name} missing description");
            }
        }

        $descProps = $this->config['components']['schemas']['ExchangeErrorCode']['properties']['description']['properties'];
        foreach ($descProps as $name => $prop) {
            self::assertArrayHasKey('title', $prop, "ExchangeErrorCode.description.{$name} missing title");
            self::assertArrayHasKey('description', $prop, "ExchangeErrorCode.description.{$name} missing description");
        }

    }//end testEveryPropertyHasTitleAndDescription()
}//end class
