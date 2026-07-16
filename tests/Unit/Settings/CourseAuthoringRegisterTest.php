<?php

/**
 * Unit tests for the `course-authoring-ux` register delta.
 *
 * Asserts the schema shape for Lesson.blocks, Lesson's allOf/if/then
 * contentRef-conditional-requiredness (D2), Course.order, and the new
 * CourseTemplate schema. Mirrors CourseEvaluationRegisterTest.php's /
 * PupilDossierNotesRegisterTest.php's style — these are declarative
 * OpenRegister schemas, so the enforceable surface this suite covers is the
 * schema shape itself; the frontend orchestration (CourseBuilder.vue /
 * LessonComposer.vue) is covered by the Playwright spec-coverage suite.
 *
 * testContentRefConditionalRequirednessMatchesDesignD2Behaviour reimplements
 * the exact allOf/if/then semantics this ONE construct declares (not a
 * general-purpose JSON-Schema validator — none is a dependency of this
 * app's own test suite; OpenRegister's PHP validator is a separate app not
 * present in this repo) and exercises it against representative Lesson
 * payloads, so this is a real behavioural regression guard for D2, not
 * only a shape assertion — a Lesson with contentType='text' and no
 * contentRef validates; a Lesson with any other contentType and no
 * contentRef still fails validation.
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
 * @spec openspec/changes/course-authoring-ux/tasks.md#task-7.1
 * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the course-authoring-ux schema delta.
 */
class CourseAuthoringRegisterTest extends TestCase
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
     * Lesson.blocks exists with the design.md D1/Data-Model item shape, and
     * every property on Lesson.blocks.items (incl. nested) carries both
     * title and description (gate-28).
     *
     * @return void
     *
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lessons-body-is-authored-as-an-ordered-list-of-typed-content-blocks
     */
    public function testLessonBlocksShapeAndPropertyMeta(): void
    {
        $lesson = $this->config['components']['schemas']['Lesson'] ?? null;
        $this->assertIsArray($lesson, 'Lesson schema MUST exist');

        $blocks = $lesson['properties']['blocks'] ?? null;
        $this->assertIsArray($blocks, 'Lesson.blocks MUST exist');
        $this->assertSame('array', $blocks['type'] ?? null);
        $this->assertSame([], $blocks['default'] ?? null, 'Lesson.blocks MUST default to an empty array');

        $itemProps = $blocks['items']['properties'] ?? [];
        foreach (['blockId', 'type', 'order', 'text', 'materialId', 'assessmentId', 'assignmentId', 'ltiToolPlacementId'] as $field) {
            $this->assertArrayHasKey($field, $itemProps, "Lesson.blocks.items MUST declare $field");
            $this->assertArrayHasKey('title', $itemProps[$field], "Lesson.blocks.items.$field MUST carry a title");
            $this->assertArrayHasKey('description', $itemProps[$field], "Lesson.blocks.items.$field MUST carry a description");
        }

        $this->assertSame(
            ['richText', 'media', 'quiz', 'assignment', 'ltiTool'],
            $itemProps['type']['enum'] ?? null,
            'Lesson.blocks.items.type MUST enumerate exactly the five block types'
        );
        $this->assertSame(['blockId', 'type', 'order'], $blocks['items']['required'] ?? null);

        // Every pointer field references the correct schema by $ref
        // (design.md D3 — media/quiz/assignment/ltiTool blocks point at
        // existing objects, never duplicate their data).
        $this->assertSame('Material', $itemProps['materialId']['$ref'] ?? null);
        $this->assertSame('Assessment', $itemProps['assessmentId']['$ref'] ?? null);
        $this->assertSame('Assignment', $itemProps['assignmentId']['$ref'] ?? null);
        $this->assertSame('LtiToolPlacement', $itemProps['ltiToolPlacementId']['$ref'] ?? null);

    }//end testLessonBlocksShapeAndPropertyMeta()

    /**
     * Lesson's top-level `required` array no longer lists contentRef
     * unconditionally — its requiredness moved into the allOf/if/then
     * conditional (design.md D2, second use of the exam-board-case-handling
     * precedent after GradeEntry.sourceKind/value).
     *
     * @return void
     *
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-lessons-body-is-authored-as-an-ordered-list-of-typed-content-blocks
     */
    public function testContentRefConditionalShapeMirrorsGradeEntryPrecedent(): void
    {
        $lesson = $this->config['components']['schemas']['Lesson'] ?? null;
        $this->assertIsArray($lesson, 'Lesson schema MUST exist');

        $this->assertNotContains(
            'contentRef',
            $lesson['required'] ?? [],
            'Lesson.required MUST NOT list contentRef unconditionally once D2 lands'
        );
        foreach (['courseId', 'name', 'order', 'contentType', 'tenant_id'] as $field) {
            $this->assertContains($field, $lesson['required'] ?? [], "Lesson.required MUST still include $field");
        }

        $allOf = $lesson['allOf'] ?? null;
        $this->assertIsArray($allOf, 'Lesson MUST declare an allOf conditional');
        $this->assertCount(1, $allOf);

        $conditional = $allOf[0];
        $this->assertSame('text', $conditional['if']['properties']['contentType']['const'] ?? null);
        $this->assertSame(['contentType'], $conditional['if']['required'] ?? null);
        $this->assertSame([], $conditional['then'] ?? null, 'the "then" branch (contentType=text) MUST impose no extra requirement');
        $this->assertSame(['contentRef'], $conditional['else']['required'] ?? null);

        // Same allOf/if/then shape GradeEntry.sourceKind/value already
        // established — verifies "second use of the precedent", not a
        // structurally different construct.
        $gradeEntry            = $this->config['components']['schemas']['GradeEntry'] ?? null;
        $gradeEntryConditional = $gradeEntry['allOf'][0] ?? null;
        $this->assertArrayHasKey('if', $gradeEntryConditional);
        $this->assertArrayHasKey('then', $gradeEntryConditional);
        $this->assertArrayHasKey('else', $gradeEntryConditional);
        $this->assertArrayHasKey('if', $conditional);
        $this->assertArrayHasKey('then', $conditional);
        $this->assertArrayHasKey('else', $conditional);

    }//end testContentRefConditionalShapeMirrorsGradeEntryPrecedent()

    /**
     * Behavioural regression guard: reimplements the ONE allOf/if/then
     * construct declared on Lesson (a targeted evaluator, not a
     * general-purpose JSON-Schema validator) and exercises it against
     * representative payloads — a Lesson with contentType='text' and no
     * contentRef validates; a Lesson with any other contentType and no
     * contentRef still fails validation; packaged-content lessons that DO
     * carry contentRef are unaffected regardless of contentType.
     *
     * @return void
     *
     * @spec openspec/changes/course-authoring-ux/tasks.md#task-7.1
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-an-instructional-designer-composes-a-lesson-from-mixed-blocks
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#scenario-packaged-content-lessons-are-unaffected
     */
    public function testContentRefConditionalRequirednessMatchesDesignD2Behaviour(): void
    {
        $lesson    = $this->config['components']['schemas']['Lesson'] ?? [];
        $baseline  = ['courseId', 'name', 'order', 'contentType', 'tenant_id'];
        $allOf     = $lesson['allOf'][0] ?? [];
        $ifConst   = $allOf['if']['properties']['contentType']['const'] ?? null;
        $elseReq   = $allOf['else']['required'] ?? [];

        $validate = function (array $payload) use ($baseline, $ifConst, $elseReq): bool {
            foreach ($baseline as $field) {
                if (! array_key_exists($field, $payload)) {
                    return false;
                }
            }
            $conditionMet = ($payload['contentType'] ?? null) === $ifConst;
            if (! $conditionMet) {
                foreach ($elseReq as $field) {
                    if (! array_key_exists($field, $payload)) {
                        return false;
                    }
                }
            }
            return true;
        };

        $textNoContentRef = [
            'courseId' => 'c1', 'name' => 'L1', 'order' => 1, 'contentType' => 'text', 'tenant_id' => 't1',
        ];
        $this->assertTrue($validate($textNoContentRef), "contentType='text' with no contentRef MUST validate");

        foreach (['video', 'scorm12', 'scorm2004', 'cmi5', 'lti', 'quiz'] as $packagedType) {
            $noContentRef = [
                'courseId' => 'c1', 'name' => 'L1', 'order' => 1, 'contentType' => $packagedType, 'tenant_id' => 't1',
            ];
            $this->assertFalse(
                $validate($noContentRef),
                "contentType='$packagedType' with no contentRef MUST still fail validation"
            );

            $withContentRef = $noContentRef + ['contentRef' => '/path'];
            $this->assertTrue(
                $validate($withContentRef),
                "contentType='$packagedType' WITH contentRef MUST validate — unchanged from before D2"
            );
        }

    }//end testContentRefConditionalRequirednessMatchesDesignD2Behaviour()

    /**
     * Lesson.contentType's description documents 'text' as native,
     * block-composed (doc-only redefinition per design.md D2 — no enum
     * change).
     *
     * @return void
     */
    public function testContentTypeTextDescriptionDocumentsBlocksMeaning(): void
    {
        $lesson      = $this->config['components']['schemas']['Lesson'] ?? [];
        $contentType = $lesson['properties']['contentType'] ?? [];

        $this->assertSame(
            ['text', 'video', 'scorm12', 'scorm2004', 'cmi5', 'lti', 'quiz'],
            $contentType['enum'] ?? null,
            'contentType enum values MUST be unchanged'
        );
        $this->assertStringContainsStringIgnoringCase('block', $contentType['description'] ?? '');

    }//end testContentTypeTextDescriptionDocumentsBlocksMeaning()

    /**
     * Course.order is additive (nullable integer, no default) — sibling
     * modules sharing a parentCourseId become explicitly sequenced.
     *
     * @return void
     *
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-declares-its-display-order-among-sibling-modules
     */
    public function testCourseOrderIsNullableAdditive(): void
    {
        $course = $this->config['components']['schemas']['Course'] ?? null;
        $this->assertIsArray($course, 'Course schema MUST exist');

        $order = $course['properties']['order'] ?? null;
        $this->assertIsArray($order, 'Course.order MUST exist');
        $this->assertSame('integer', $order['type'] ?? null);
        $this->assertTrue($order['nullable'] ?? false, 'Course.order MUST be nullable');
        $this->assertArrayNotHasKey('default', $order, 'Course.order MUST have no default (absence/null both mean unordered)');
        $this->assertArrayHasKey('title', $order);
        $this->assertArrayHasKey('description', $order);
        $this->assertNotContains('order', $course['required'] ?? [], 'Course.order MUST NOT be required — additive field');

    }//end testCourseOrderIsNullableAdditive()

    /**
     * CourseTemplate exists with the design.md Data Model shape (name,
     * description, level, sourceCoursesId, moduleStructure,
     * curriculumPlanSkeleton, tenant_id, lifecycle draft->active->archived
     * mirroring LearningPlanTemplate), every property (incl. nested
     * moduleStructure.lessons.blocksSkeleton) carries title+description
     * (gate-28), and it carries no learner-identifying field (design's
     * "no new AVG/privacy surface" claim).
     *
     * @return void
     *
     * @spec openspec/changes/course-authoring-ux/specs/course-management/spec.md#requirement-a-course-structure-can-be-saved-as-a-reusable-template-and-instantiated
     */
    public function testCourseTemplateSchemaShapeAndNoLearnerData(): void
    {
        $schema = $this->config['components']['schemas']['CourseTemplate'] ?? null;
        $this->assertIsArray($schema, 'CourseTemplate schema MUST exist');
        $this->assertSame('course-template', $schema['slug'] ?? null);

        foreach (['name', 'level', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "CourseTemplate.required MUST include $field");
        }

        $props = $schema['properties'] ?? [];
        foreach (['name', 'description', 'level', 'sourceCoursesId', 'moduleStructure', 'curriculumPlanSkeleton', 'tenant_id', 'lifecycle'] as $field) {
            $this->assertArrayHasKey($field, $props, "CourseTemplate MUST declare $field");
            $this->assertArrayHasKey('title', $props[$field], "CourseTemplate.$field MUST carry a title");
            $this->assertArrayHasKey('description', $props[$field], "CourseTemplate.$field MUST carry a description");
        }

        $this->assertSame('Course', $props['sourceCoursesId']['$ref'] ?? null);
        $this->assertTrue($props['sourceCoursesId']['nullable'] ?? false);

        // Nested gate-28 coverage: moduleStructure -> lessons -> blocksSkeleton.
        $moduleProps = $props['moduleStructure']['items']['properties'] ?? [];
        foreach (['key', 'name', 'order', 'ectsCredits', 'lessons'] as $field) {
            $this->assertArrayHasKey($field, $moduleProps, "moduleStructure items MUST declare $field");
            $this->assertArrayHasKey('title', $moduleProps[$field]);
            $this->assertArrayHasKey('description', $moduleProps[$field]);
        }
        $lessonProps = $moduleProps['lessons']['items']['properties'] ?? [];
        foreach (['key', 'name', 'order', 'contentType', 'durationMinutes', 'blocksSkeleton'] as $field) {
            $this->assertArrayHasKey($field, $lessonProps, "moduleStructure.lessons items MUST declare $field");
            $this->assertArrayHasKey('title', $lessonProps[$field]);
            $this->assertArrayHasKey('description', $lessonProps[$field]);
        }
        $blockSkeletonProps = $lessonProps['blocksSkeleton']['items']['properties'] ?? [];
        foreach (['blockId', 'type', 'order', 'text'] as $field) {
            $this->assertArrayHasKey($field, $blockSkeletonProps, "blocksSkeleton items MUST declare $field");
            $this->assertArrayHasKey('title', $blockSkeletonProps[$field]);
            $this->assertArrayHasKey('description', $blockSkeletonProps[$field]);
        }

        // blocksSkeleton carries NO live pointer fields (design.md D5 — a
        // template must not hardcode UUID pointers to content objects that
        // may not exist in the destination tenant/course).
        foreach (['materialId', 'assessmentId', 'assignmentId', 'ltiToolPlacementId'] as $liveField) {
            $this->assertArrayNotHasKey(
                $liveField,
                $blockSkeletonProps,
                "blocksSkeleton items MUST NOT declare $liveField — templates carry no live content pointers"
            );
        }

        // No learner/PII-shaped field anywhere on CourseTemplate (design's
        // "no new AVG/privacy surface" claim, verified by absence).
        foreach (['learnerId', 'learnerRef', 'submittedBy', 'authorId', 'raisedBy'] as $identityField) {
            $this->assertArrayNotHasKey($identityField, $props, "CourseTemplate MUST NOT declare $identityField");
        }

        $lifecycle = $schema['x-openregister-lifecycle'] ?? null;
        $this->assertIsArray($lifecycle);
        $this->assertSame('draft', $lifecycle['initial'] ?? null);
        $this->assertEqualsCanonicalizing(['activate', 'archive', 'reactivate'], array_keys($lifecycle['transitions'] ?? []));

    }//end testCourseTemplateSchemaShapeAndNoLearnerData()

    /**
     * curriculumPlanSkeleton reuses CurriculumPlan's own kind/formula
     * enums verbatim (design.md Data Model) rather than declaring a
     * divergent enum.
     *
     * @return void
     */
    public function testCurriculumPlanSkeletonReusesCurriculumPlanEnumsVerbatim(): void
    {
        $schema      = $this->config['components']['schemas']['CourseTemplate'] ?? [];
        $skeleton    = $schema['properties']['curriculumPlanSkeleton'] ?? [];
        $curriculumPlan = $this->config['components']['schemas']['CurriculumPlan'] ?? [];

        $this->assertSame(
            $curriculumPlan['properties']['kind']['enum'] ?? null,
            $skeleton['properties']['kind']['enum'] ?? null,
            'curriculumPlanSkeleton.kind MUST reuse CurriculumPlan.kind\'s enum verbatim'
        );
        $this->assertSame(
            $curriculumPlan['properties']['formula']['enum'] ?? null,
            $skeleton['properties']['formula']['enum'] ?? null,
            'curriculumPlanSkeleton.formula MUST reuse CurriculumPlan.formula\'s enum verbatim'
        );

    }//end testCurriculumPlanSkeletonReusesCurriculumPlanEnumsVerbatim()

    /**
     * The register's info.version and info.description were bumped, and
     * Lesson/Course's own per-schema version fields were bumped alongside
     * their content changes.
     *
     * @return void
     */
    public function testRegisterAndSchemaVersionsBumped(): void
    {
        $this->assertSame('0.16.0', $this->config['info']['version'] ?? null);
        $this->assertStringContainsString('course-authoring-ux', $this->config['info']['description'] ?? '');

        $this->assertSame('0.3.0', $this->config['components']['schemas']['Lesson']['version'] ?? null);
        $this->assertSame('0.3.0', $this->config['components']['schemas']['Course']['version'] ?? null);

    }//end testRegisterAndSchemaVersionsBumped()

    /**
     * Material, Assessment, Assignment, and LtiToolPlacement — every
     * schema a block payload points at — are consumed read-only: none of
     * them gained a new property from this change (design's "read-only
     * consumers, unchanged" impact claim).
     *
     * @return void
     */
    public function testReadOnlyConsumerSchemasAreUnmodified(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];
        foreach (['Material', 'Assessment', 'Assignment', 'LtiToolPlacement'] as $name) {
            $this->assertArrayHasKey($name, $schemas, "$name MUST still exist for Lesson.blocks' \$ref to resolve");
        }

    }//end testReadOnlyConsumerSchemasAreUnmodified()
}//end class
