<?php

/**
 * Unit tests for the `pupil-dossier-notes` register delta.
 *
 * Asserts the schema shape for DossierNote, BehaviourIncident, and
 * WellbeingCheckIn — appendOnly, required fields, the confidentiality-floor
 * x-property-rbac boundary (design.md Decision 1: admin/mentor/coordinator/
 * author, the tightest common floor the dialect can express — NOT a
 * per-confidentiality-tier guarantee), the create-role restriction
 * (DossierNote/BehaviourIncident staff-only, WellbeingCheckIn unrestricted
 * by design), and BehaviourIncident's reference-only escalation into
 * SupportRequest. Mirrors AssessmentItemPoolsRegisterTest.php's style —
 * these are declarative OpenRegister schemas with no PHP controller, so the
 * enforceable surface this suite covers is the schema shape itself; live
 * RBAC-floor denial is re-verified against a running instance per
 * tasks.md 4.1, not repeated here as an HTTP round-trip.
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
 * @spec openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the pupil-dossier schema delta.
 */
class PupilDossierNotesRegisterTest extends TestCase
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
     * DossierNote is appendOnly, carries the required fields, and its
     * confidentiality field defaults to the safer care-team-only tier.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister
     */
    public function testDossierNoteIsAppendOnlyWithRequiredFieldsAndSafeDefault(): void
    {
        $schema = $this->config['components']['schemas']['DossierNote'] ?? null;
        $this->assertIsArray($schema, 'DossierNote schema MUST exist');

        $this->assertTrue($schema['appendOnly'] ?? false, 'DossierNote MUST be appendOnly');
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'DossierNote MUST have no lifecycle — a flat record');

        foreach (['learnerId', 'authorId', 'date', 'category', 'body', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "DossierNote.required MUST include $field");
        }

        $properties = $schema['properties'] ?? [];
        $this->assertEqualsCanonicalizing(
            ['observation', 'conversation', 'phone-call-home', 'concern', 'positive'],
            $properties['category']['enum'] ?? [],
            'DossierNote.category MUST carry the five documented values'
        );
        $this->assertEqualsCanonicalizing(
            ['team-visible', 'care-team-only', 'private-to-author'],
            $properties['confidentiality']['enum'] ?? [],
            'DossierNote.confidentiality MUST carry the three documented tiers'
        );
        $this->assertSame(
            'care-team-only',
            $properties['confidentiality']['default'] ?? null,
            'DossierNote.confidentiality MUST default to the safer care-team-only tier'
        );

        // Every property (including confidentiality itself) carries title + description (gate-28).
        foreach ($properties as $name => $property) {
            $this->assertArrayHasKey('title', $property, "DossierNote.$name MUST carry a title");
            $this->assertArrayHasKey('description', $property, "DossierNote.$name MUST carry a description");
        }

    }//end testDossierNoteIsAppendOnlyWithRequiredFieldsAndSafeDefault()

    /**
     * DossierNote creation is restricted to admin/mentor/coordinator, and
     * its x-property-rbac read floor is the tightest common bound the
     * dialect can express — admin/mentor/coordinator/the note's own author
     * — never a bare `team-visible` (loosest) fallback.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-dossiernote-confidentiality-is-enforced-server-side-at-the-object-level-per-tier-rbac-beyond-that-floor-is-a-named-platform-gap
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-creation-is-role-restricted-wellbeingcheckin-is-learner-authored
     */
    public function testDossierNoteCreateAndReadFloorAreStaffOrAuthorOnly(): void
    {
        $schema = $this->config['components']['schemas']['DossierNote'] ?? null;
        $this->assertIsArray($schema, 'DossierNote schema MUST exist');

        $createRoles = $schema['x-openregister-authorization']['create'] ?? [];
        $this->assertEqualsCanonicalizing(
            ['admin', 'mentor', 'coordinator'],
            $createRoles,
            'DossierNote creation MUST be restricted to admin/mentor/coordinator'
        );
        $this->assertNotContains('learner', $createRoles, 'A learner MUST NOT be able to author a DossierNote');

        $anyOf     = $schema['x-property-rbac']['read']['anyOf'] ?? [];
        $rbacRoles = array_filter(array_column($anyOf, 'role'));
        $this->assertEqualsCanonicalizing(
            ['admin', 'mentor', 'coordinator'],
            $rbacRoles,
            'DossierNote read floor MUST be exactly admin/mentor/coordinator plus the author match'
        );

        $matches = array_filter(array_column($anyOf, 'match'));
        $this->assertCount(1, $matches, 'DossierNote read floor MUST carry exactly one self-match clause');
        $match = array_values($matches)[0];
        $this->assertSame('authorId', $match['field'] ?? null);
        $this->assertSame('eq', $match['operator'] ?? null);
        $this->assertSame('$userId', $match['value'] ?? null);

    }//end testDossierNoteCreateAndReadFloorAreStaffOrAuthorOnly()

    /**
     * BehaviourIncident is appendOnly, carries its own unguarded
     * open -> in-handling -> resolved lifecycle (resolve reachable from
     * both open and in-handling), and its followUpActions log matches
     * AttendanceFlag.interventions' append-only entry shape.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-persist-dossiernote-behaviourincident-and-wellbeingcheckin-domain-objects-in-openregister
     */
    public function testBehaviourIncidentLifecycleAndFollowUpShape(): void
    {
        $schema = $this->config['components']['schemas']['BehaviourIncident'] ?? null;
        $this->assertIsArray($schema, 'BehaviourIncident schema MUST exist');

        $this->assertTrue($schema['appendOnly'] ?? false, 'BehaviourIncident MUST be appendOnly');

        foreach (['learnerId', 'reportedBy', 'occurredAt', 'what', 'severity', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "BehaviourIncident.required MUST include $field");
        }

        $lifecycle = $schema['x-openregister-lifecycle'] ?? null;
        $this->assertIsArray($lifecycle, 'BehaviourIncident MUST declare x-openregister-lifecycle');
        $this->assertSame('open', $lifecycle['initial'] ?? null);

        $transitions = $lifecycle['transitions'] ?? [];
        $this->assertSame('open', $transitions['startHandling']['from'] ?? null);
        $this->assertSame('in-handling', $transitions['startHandling']['to'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['open', 'in-handling'],
            $transitions['resolve']['from'] ?? [],
            'resolve MUST be reachable from both open and in-handling'
        );
        $this->assertSame('resolved', $transitions['resolve']['to'] ?? null);

        // No guard on either transition, mirroring AttendanceThreshold's unguarded activate/archive.
        $this->assertArrayNotHasKey('requires', $transitions['startHandling'] ?? []);
        $this->assertArrayNotHasKey('requires', $transitions['resolve'] ?? []);

        $properties = $schema['properties'] ?? [];
        $followUp   = $properties['followUpActions'] ?? [];
        $this->assertSame([], $followUp['default'] ?? null, 'followUpActions MUST default to an empty array');
        $entryRequired = $followUp['items']['required'] ?? [];
        $this->assertEqualsCanonicalizing(
            ['recordedBy', 'recordedAt', 'action'],
            $entryRequired,
            'followUpActions entries MUST require recordedBy/recordedAt/action, same shape as AttendanceFlag.interventions'
        );

        foreach ($followUp['items']['properties'] ?? [] as $name => $property) {
            $this->assertArrayHasKey('title', $property, "followUpActions entry.$name MUST carry a title");
            $this->assertArrayHasKey('description', $property, "followUpActions entry.$name MUST carry a description");
        }

        foreach ($properties as $name => $property) {
            $this->assertArrayHasKey('title', $property, "BehaviourIncident.$name MUST carry a title");
            $this->assertArrayHasKey('description', $property, "BehaviourIncident.$name MUST carry a description");
        }

    }//end testBehaviourIncidentLifecycleAndFollowUpShape()

    /**
     * BehaviourIncident creation is staff-only and its read floor mirrors
     * DossierNote's (admin/mentor/coordinator + the reportedBy author).
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#scenario-a-learner-cannot-author-a-dossiernote-about-another-learner
     */
    public function testBehaviourIncidentCreateAndReadFloorAreStaffOrAuthorOnly(): void
    {
        $schema = $this->config['components']['schemas']['BehaviourIncident'] ?? null;
        $this->assertIsArray($schema, 'BehaviourIncident schema MUST exist');

        $createRoles = $schema['x-openregister-authorization']['create'] ?? [];
        $this->assertEqualsCanonicalizing(
            ['admin', 'mentor', 'coordinator'],
            $createRoles,
            'BehaviourIncident creation MUST be restricted to admin/mentor/coordinator'
        );
        $this->assertNotContains('learner', $createRoles, 'A learner MUST NOT be able to author a BehaviourIncident');

        $anyOf     = $schema['x-property-rbac']['read']['anyOf'] ?? [];
        $rbacRoles = array_filter(array_column($anyOf, 'role'));
        $this->assertEqualsCanonicalizing(['admin', 'mentor', 'coordinator'], $rbacRoles);

        $matches = array_filter(array_column($anyOf, 'match'));
        $match   = array_values($matches)[0] ?? [];
        $this->assertSame('reportedBy', $match['field'] ?? null);
        $this->assertSame('eq', $match['operator'] ?? null);
        $this->assertSame('$userId', $match['value'] ?? null);

        // Notification opt-in for created incidents (mentor + coordinator).
        $notification = $schema['x-openregister-notifications']['incidentRecorded'] ?? null;
        $this->assertIsArray($notification, 'BehaviourIncident MUST notify on creation');
        $this->assertSame('created', $notification['trigger']['type'] ?? null);

    }//end testBehaviourIncidentCreateAndReadFloorAreStaffOrAuthorOnly()

    /**
     * BehaviourIncident escalates into a SupportRequest by REFERENCE only —
     * escalatedSupportRequestId is a nullable $ref: SupportRequest, and no
     * SupportRequest-only field (supportDomain / urgency / raisedBy) is
     * duplicated onto BehaviourIncident.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-a-behaviour-incident-escalates-by-referencing-supportrequest-never-duplicating-it
     */
    public function testBehaviourIncidentEscalatesByReferenceOnly(): void
    {
        $schema = $this->config['components']['schemas']['BehaviourIncident'] ?? null;
        $this->assertIsArray($schema, 'BehaviourIncident schema MUST exist');

        $properties = $schema['properties'] ?? [];
        $escalated  = $properties['escalatedSupportRequestId'] ?? null;
        $this->assertIsArray($escalated, 'BehaviourIncident MUST carry escalatedSupportRequestId');
        $this->assertTrue($escalated['nullable'] ?? false, 'escalatedSupportRequestId MUST be nullable — not every incident escalates');
        $this->assertSame('SupportRequest', $escalated['$ref'] ?? null, 'escalatedSupportRequestId MUST reference SupportRequest');
        $this->assertArrayHasKey('default', $escalated, 'escalatedSupportRequestId MUST declare a default');
        $this->assertNull($escalated['default'], 'escalatedSupportRequestId MUST default to null');

        foreach (['supportDomain', 'urgency', 'raisedBy', 'description'] as $supportRequestOnlyField) {
            $this->assertArrayNotHasKey(
                $supportRequestOnlyField,
                $properties,
                "BehaviourIncident MUST NOT duplicate SupportRequest.$supportRequestOnlyField"
            );
        }

        // SupportRequest itself MUST still exist and be the target this $ref names.
        $this->assertArrayHasKey('SupportRequest', $this->config['components']['schemas'] ?? [], 'SupportRequest MUST exist for the $ref to resolve');

    }//end testBehaviourIncidentEscalatesByReferenceOnly()

    /**
     * WellbeingCheckIn is appendOnly, has no lifecycle, carries a 1-5
     * moodScale, and — unlike DossierNote/BehaviourIncident — declares NO
     * x-openregister-authorization restriction: any authenticated user may
     * submit their own check-in (a documented platform gap at create time,
     * same as SupportRequest.raisedBy, not a new one).
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-creation-is-role-restricted-wellbeingcheckin-is-learner-authored
     */
    public function testWellbeingCheckInIsLearnerAuthoredWithNoCreateRestriction(): void
    {
        $schema = $this->config['components']['schemas']['WellbeingCheckIn'] ?? null;
        $this->assertIsArray($schema, 'WellbeingCheckIn schema MUST exist');

        $this->assertTrue($schema['appendOnly'] ?? false, 'WellbeingCheckIn MUST be appendOnly');
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'WellbeingCheckIn MUST have no lifecycle — a single point-in-time record');
        $this->assertArrayNotHasKey(
            'x-openregister-authorization',
            $schema,
            'WellbeingCheckIn MUST declare NO create restriction — any authenticated user may submit their own check-in'
        );

        foreach (['learnerId', 'submittedAt', 'moodScale', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "WellbeingCheckIn.required MUST include $field");
        }

        $properties = $schema['properties'] ?? [];
        $this->assertSame('integer', $properties['moodScale']['type'] ?? null);
        $this->assertSame(1, $properties['moodScale']['minimum'] ?? null);
        $this->assertSame(5, $properties['moodScale']['maximum'] ?? null);

        foreach ($properties as $name => $property) {
            $this->assertArrayHasKey('title', $property, "WellbeingCheckIn.$name MUST carry a title");
            $this->assertArrayHasKey('description', $property, "WellbeingCheckIn.$name MUST carry a description");
        }

    }//end testWellbeingCheckInIsLearnerAuthoredWithNoCreateRestriction()

    /**
     * WellbeingCheckIn's read floor is admin/mentor/coordinator plus the
     * submitting learner's own self-match — the same shape as DossierNote/
     * BehaviourIncident, matched on learnerId instead of authorId/reportedBy.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#requirement-dossiernote-confidentiality-is-enforced-server-side-at-the-object-level-per-tier-rbac-beyond-that-floor-is-a-named-platform-gap
     */
    public function testWellbeingCheckInReadFloorIsStaffOrSelf(): void
    {
        $schema = $this->config['components']['schemas']['WellbeingCheckIn'] ?? null;
        $this->assertIsArray($schema, 'WellbeingCheckIn schema MUST exist');

        $anyOf     = $schema['x-property-rbac']['read']['anyOf'] ?? [];
        $rbacRoles = array_filter(array_column($anyOf, 'role'));
        $this->assertEqualsCanonicalizing(['admin', 'mentor', 'coordinator'], $rbacRoles);

        $matches = array_filter(array_column($anyOf, 'match'));
        $match   = array_values($matches)[0] ?? [];
        $this->assertSame('learnerId', $match['field'] ?? null);
        $this->assertSame('eq', $match['operator'] ?? null);
        $this->assertSame('$userId', $match['value'] ?? null);

        $notification = $schema['x-openregister-notifications']['checkInSubmitted'] ?? null;
        $this->assertIsArray($notification, 'WellbeingCheckIn MUST notify the mentor group on submission');
        $this->assertSame('created', $notification['trigger']['type'] ?? null);

    }//end testWellbeingCheckInReadFloorIsStaffOrSelf()

    /**
     * None of the three pupil-dossier schemas fabricates a row-conditional
     * RBAC guarantee — x-property-rbac.read is a single flat anyOf per
     * schema (no allOf combinator anywhere in these three blocks), matching
     * the platform-capability gap design.md Decision 1 documents.
     *
     * @return void
     * @spec   openspec/changes/pupil-dossier-notes/specs/pupil-dossier/spec.md#scenario-the-three-way-confidentiality-tier-is-a-documented-gap-not-a-fabricated-guarantee
     */
    public function testNoRowConditionalRbacIsFabricated(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];

        foreach (['DossierNote', 'BehaviourIncident', 'WellbeingCheckIn'] as $schemaName) {
            $rbac = $schemas[$schemaName]['x-property-rbac'] ?? [];
            $this->assertArrayNotHasKey(
                'allOf',
                $rbac,
                "$schemaName x-property-rbac MUST NOT declare an allOf row-conditional combinator — not a HEAD capability"
            );
            $this->assertArrayHasKey('read', $rbac, "$schemaName MUST declare a read policy");
            $this->assertArrayHasKey('anyOf', $rbac['read'], "$schemaName read policy MUST be the flat anyOf shape");
        }

    }//end testNoRowConditionalRbacIsFabricated()
}//end class
