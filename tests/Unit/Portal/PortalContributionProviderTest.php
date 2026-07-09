<?php

/**
 * Unit tests for the Scholiq PortalContributionProvider.
 *
 * Pins the ADR-046 contribution contract v2: the dual v2/v1 audience
 * declaration, the fail-closed null for unserved audiences, and the exact
 * declarative manifest shape for the `student` and `parent` audiences
 * (UUID-domain-ref-scoped collections + inbox + strict create whitelists). The
 * provider is constructed directly — it is a plain dependency-free class by
 * contract (amendment A1), so no mocks and no container are involved.
 *
 * A register-drift pin (testManifestMatchesRegisterSchemas) loads the shipped
 * `scholiq_register.json` and asserts every schema slug, scope field,
 * whitelisted field AND parent `via` scope field the manifest references
 * actually exists — so a rename in the register (or a missing `portal-identity`
 * ref) fails this test instead of silently breaking the portal at runtime. The
 * `parent` reverse-join collections are covered now that portaliq ships the
 * reverse / scope-value `via` join (`match: 'scopeField'`).
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Portal;

use OCA\Scholiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProviderTest extends TestCase
{

    /**
     * The provider under test.
     *
     * @var PortalContributionProvider
     */
    private PortalContributionProvider $provider;

    /**
     * A fully server-derived student subject, as portaliq's auth edge builds it.
     *
     * @var array<string, mixed>
     */
    private const STUDENT_SUBJECT = [
        'subjectRef'   => '11111111-1111-1111-1111-111111111111',
        'audience'     => 'student',
        'organisation' => '00000000-0000-0000-0000-000000000000',
        'trust'        => 'low',
    ];

    /**
     * A fully server-derived parent (guardian) subject.
     *
     * @var array<string, mixed>
     */
    private const PARENT_SUBJECT = [
        'subjectRef'   => '22222222-2222-2222-2222-222222222222',
        'audience'     => 'parent',
        'organisation' => '00000000-0000-0000-0000-000000000000',
        'trust'        => 'substantial',
    ];

    /**
     * Set up the provider — direct construction, no dependencies by contract.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PortalContributionProvider();

    }//end setUp()

    /**
     * The class is plain: no interfaces, no parent, no constructor deps.
     *
     * @return void
     */
    public function testClassIsPlainAndDependencyFree(): void
    {
        $reflection = new \ReflectionClass(PortalContributionProvider::class);

        $this->assertSame([], $reflection->getInterfaceNames());
        $this->assertFalse($reflection->getParentClass());
        $this->assertNull($reflection->getConstructor());

    }//end testClassIsPlainAndDependencyFree()

    /**
     * getAudiences() (v2) returns exactly ['student','parent'] and getAudience()
     * (v1 fallback) is one of them. The `parent` audience is re-enabled now that
     * portaliq ships the reverse / scope-value `via` join (match: 'scopeField').
     *
     * @return void
     */
    public function testAudienceContract(): void
    {
        $this->assertSame(['student', 'parent'], $this->provider->getAudiences());
        $this->assertSame('student', $this->provider->getAudience());
        $this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

    }//end testAudienceContract()

    /**
     * Unserved / absent audiences get null — fail-closed audience filtering.
     * `student` and `parent` are served; everything else (and an empty subject)
     * is null.
     *
     * @return void
     */
    public function testGetContributionReturnsNullForUnservedSubjects(): void
    {
        $teacher             = self::STUDENT_SUBJECT;
        $teacher['audience'] = 'teacher';

        $this->assertNull($this->provider->getContribution($teacher));
        $this->assertNull($this->provider->getContribution([]));

        // `parent` is now a served audience — it returns a manifest, not null.
        $this->assertIsArray($this->provider->getContribution(self::PARENT_SUBJECT));

    }//end testGetContributionReturnsNullForUnservedSubjects()

    /**
     * The student manifest is labelled and carries all four sections, with the
     * six learner-scoped read collections plus the inbox.
     *
     * @return void
     */
    public function testStudentManifestShape(): void
    {
        $manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);

        $this->assertIsArray($manifest);
        $this->assertSame('Scholiq', $manifest['label']);
        $this->assertSame([], $manifest['notifications']);

        $collections = $manifest['collections'];
        $this->assertCount(7, $collections);
        $this->assertSame(
            [
                'studentGrades',
                'studentFinalGrades',
                'studentAttendance',
                'studentEnrolments',
                'studentSubmissions',
                'studentExcuseRequests',
                'studentInbox',
            ],
            array_column($collections, 'id')
        );

        foreach ($collections as $collection) {
            $this->assertSame('scholiq', $collection['register']);
            $this->assertSame('learnerRef', $collection['scopeClaim']);
            $this->assertNotEmpty($collection['fields']);
            // Submission is scoped by the learnerRefs ARRAY (membership); every
            // other collection is scoped by the scalar learnerRef.
            if ($collection['schema'] === 'submission') {
                $this->assertSame('learnerRefs', $collection['scopeField']);
            } else {
                $this->assertSame('learnerRef', $collection['scopeField']);
            }
        }

    }//end testStudentManifestShape()

    /**
     * The student inbox is a `kind: inbox` collection scoped by learnerRef.
     *
     * @return void
     */
    public function testStudentInboxIsScopedInbox(): void
    {
        $manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
        $inbox    = array_values(
            array_filter(
                $manifest['collections'],
                static fn(array $c): bool => ($c['id'] ?? '') === 'studentInbox'
            )
        )[0];

        $this->assertSame('inbox', $inbox['kind']);
        $this->assertSame('grade-notification', $inbox['schema']);
        $this->assertSame('learnerRef', $inbox['scopeField']);

    }//end testStudentInboxIsScopedInbox()

    /**
     * Student create-actions whitelist intake fields only — no grade, status,
     * lifecycle or staff field is exposed.
     *
     * @return void
     */
    public function testStudentCreateActionsWhitelistIntakeFields(): void
    {
        $manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
        $actions  = $manifest['actions'];

        $this->assertSame(['createSubmission', 'createExcuseRequest'], array_column($actions, 'id'));

        $submission = $actions[0];
        $this->assertSame('create', $submission['type']);
        $this->assertSame('submission', $submission['schema']);
        $this->assertSame('learnerRefs', $submission['scopeField']);
        $this->assertSame(['assignmentId', 'attachmentRefs'], $submission['fields']);

        $excuse = $actions[1];
        $this->assertSame('create', $excuse['type']);
        $this->assertSame('excuse-request', $excuse['schema']);
        $this->assertSame('learnerRef', $excuse['scopeField']);
        $this->assertSame('low', $excuse['minTrust']);
        $this->assertSame(
            ['dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef'],
            $excuse['fields']
        );
        // A student create never lets the client set grade/status/staff fields.
        foreach (['value', 'passed', 'lifecycle', 'submittedBy', 'submittedAuthLevel', 'decidedBy'] as $forbidden) {
            $this->assertNotContains($forbidden, $excuse['fields']);
            $this->assertNotContains($forbidden, $submission['fields']);
        }

    }//end testStudentCreateActionsWhitelistIntakeFields()

    /**
     * The parent manifest is labelled and carries exactly the three
     * reverse-joined read collections (grades, attendance, excuse-requests),
     * each guardian-claimed, learnerRef-scoped and substantial-trust,
     * field-projected identically to the student surface.
     *
     * @return void
     */
    public function testParentManifestShape(): void
    {
        $manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

        $this->assertIsArray($manifest);
        $this->assertSame('Scholiq', $manifest['label']);
        $this->assertSame([], $manifest['notifications']);

        $collections = $manifest['collections'];
        $this->assertCount(3, $collections);
        $this->assertSame(
            ['parentGrades', 'parentAttendance', 'parentExcuseRequests'],
            array_column($collections, 'id')
        );

        foreach ($collections as $collection) {
            $this->assertSame('scholiq', $collection['register']);
            // Parent scope key is the guardian claim; the outer record scope
            // field is the child's learnerRef (matched by the reverse via).
            $this->assertSame('guardianRef', $collection['scopeClaim']);
            $this->assertSame('learnerRef', $collection['scopeField']);
            // A guardian reading a MINOR's data needs substantial assurance.
            $this->assertSame('substantial', $collection['minTrust']);
            $this->assertNotEmpty($collection['fields']);
            // Parent reads never expose staff-only columns (same drop as student).
            foreach (['grader', 'comment', 'markedBy', 'submittedBy', 'submittedByRef', 'decidedBy', 'decisionNote'] as $forbidden) {
                $this->assertNotContains($forbidden, $collection['fields']);
            }
        }

        // Parent grade/attendance/excuse projections mirror the student ones.
        $byId = array_column($collections, null, 'id');
        $this->assertSame(
            ['learnerRef', 'courseId', 'curriculumPlanId', 'componentId', 'value', 'gradeScaleId', 'period', 'gradedAt'],
            $byId['parentGrades']['fields']
        );
        $this->assertSame(
            ['learnerRef', 'sessionId', 'cohortId', 'status', 'minutesAttended', 'markedAt'],
            $byId['parentAttendance']['fields']
        );
        $this->assertSame(
            ['learnerRef', 'dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef', 'lifecycle', 'decidedAt'],
            $byId['parentExcuseRequests']['fields']
        );

    }//end testParentManifestShape()

    /**
     * Every parent read collection carries the reverse / scope-value `via` join
     * with EXACTLY the reader's contract keys — `{register, schema, scopeField,
     * targetField, match}` — and `match: 'scopeField'`. The join resolves the
     * guardian's children through `learner-profile.guardianRefs` and collects
     * each child profile's own OR object UUID (`id`), which the outer records
     * match on their own `learnerRef`. Invented keys (`matchField`/`selectField`)
     * would fail portaliq's `isValidVia()` fail-closed — so pin the exact set.
     *
     * @return void
     */
    public function testParentCollectionsUseReverseScopeValueVia(): void
    {
        $manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

        foreach ($manifest['collections'] as $collection) {
            $via = $collection['via'] ?? null;
            $this->assertIsArray($via, "parent collection '{$collection['id']}' must declare a via join");

            // The reader (PortalObjectReader::isValidVia) recognises EXACTLY these
            // keys; anything else (matchField/selectField) is ignored/fails closed.
            $this->assertSame(
                ['register', 'schema', 'scopeField', 'targetField', 'match'],
                array_keys($via),
                "via keys must be exactly the reader's contract for '{$collection['id']}'"
            );

            $this->assertSame('scholiq', $via['register']);
            $this->assertSame('learner-profile', $via['schema']);
            // The join row's field matched against the guardian scope value.
            $this->assertSame('guardianRefs', $via['scopeField']);
            // The child LearnerProfile's own object UUID — a normalised OR row
            // exposes it at top-level `id` (ObjectEntity::jsonSerialize sets
            // $object['id'] = $this->uuid), which is what learnerRef points at.
            $this->assertSame('id', $via['targetField']);
            // Reverse mode: keep outer rows whose OWN scopeField is in the set.
            $this->assertSame('scopeField', $via['match']);

            // The outer collection's own scope field the reverse match reads.
            $this->assertSame('learnerRef', $collection['scopeField']);
        }

    }//end testParentCollectionsUseReverseScopeValueVia()

    /**
     * The parent audience ships READS only — no create action. A guardian
     * reporting an absence would supply the child `learnerRef` in the create
     * body, but portaliq's writer only server-stamps the scope field
     * (`submittedByRef` = guardian); it does not verify a client-supplied
     * cross-reference (`learnerRef`) against the guardian's own children. That
     * would be a write IDOR, so the create is withheld until portaliq validates
     * create-body cross-refs against the subject's reverse-join set. Parent
     * reads are safe (the reverse `via` verifies the child set per row).
     *
     * @return void
     */
    public function testParentShipsNoCreateActionPendingCrossRefValidation(): void
    {
        $manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

        $this->assertSame([], $manifest['actions']);

    }//end testParentShipsNoCreateActionPendingCrossRefValidation()

    /**
     * Register-drift pin: every schema slug, scope field, whitelisted field and
     * `via` scope-field the manifest references MUST exist in the shipped
     * scholiq_register.json — proving the `portal-identity` refs are present and
     * that no register rename silently broke the portal. Covers the parent
     * reverse-join collections too (their via `scopeField` is `guardianRefs` on
     * `learner-profile`; `targetField` is the OR object-identity token `id`, not
     * a schema property, so it is checked against the identity tokens).
     *
     * @return void
     */
    public function testManifestMatchesRegisterSchemas(): void
    {
        $registerPath = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $this->assertFileExists($registerPath);

        $register = json_decode((string) file_get_contents($registerPath), true);
        $this->assertIsArray($register);

        // Build slug => property-names map from the register.
        $propsBySlug = [];
        foreach (($register['components']['schemas'] ?? []) as $schema) {
            $slug = $schema['slug'] ?? null;
            if ($slug !== null) {
                $propsBySlug[$slug] = array_keys($schema['properties'] ?? []);
            }
        }

        // The portal-identity refs MUST exist (the change this provider depends on).
        $this->assertContains('learnerRef', $propsBySlug['grade-entry'] ?? []);
        $this->assertContains('learnerRefs', $propsBySlug['submission'] ?? []);
        $this->assertContains('submittedByRef', $propsBySlug['excuse-request'] ?? []);
        $this->assertContains('guardianRefs', $propsBySlug['learner-profile'] ?? []);

        // Both audiences are served (parent is re-enabled); each yields a manifest.
        foreach ([self::STUDENT_SUBJECT, self::PARENT_SUBJECT] as $subject) {
            $manifest = $this->provider->getContribution($subject);
            $this->assertIsArray($manifest);

            foreach (($manifest['collections'] ?? []) as $collection) {
                $slug = $collection['schema'];
                $this->assertArrayHasKey($slug, $propsBySlug, "manifest schema '$slug' missing from register");
                $props = $propsBySlug[$slug];

                $this->assertContains($collection['scopeField'], $props, "scopeField on '$slug' not in register");
                foreach (($collection['fields'] ?? []) as $field) {
                    $this->assertContains($field, $props, "field '$field' on '$slug' not in register");
                }

                if (isset($collection['via']) === true) {
                    $via     = $collection['via'];
                    $viaSlug = $via['schema'];
                    $this->assertArrayHasKey($viaSlug, $propsBySlug, "via schema '$viaSlug' missing from register");
                    // The via's join scope field (guardianRefs) MUST be a real
                    // property on the via schema — this is the drift-detectable ref.
                    $this->assertContains(
                        $via['scopeField'],
                        $propsBySlug[$viaSlug],
                        "via scopeField '{$via['scopeField']}' not in register schema '$viaSlug'"
                    );
                    // The via's targetField is either a schema property OR the OR
                    // object-identity token ('id'/'uuid') the normalised row
                    // exposes — never an invented key.
                    $this->assertContains(
                        $via['targetField'],
                        array_merge($propsBySlug[$viaSlug], ['id', 'uuid']),
                        "via targetField '{$via['targetField']}' is neither a register property on '$viaSlug' nor an identity token"
                    );
                }
            }

            foreach (($manifest['actions'] ?? []) as $action) {
                $slug = $action['schema'];
                $this->assertArrayHasKey($slug, $propsBySlug, "action schema '$slug' missing from register");
                $props = $propsBySlug[$slug];
                $this->assertContains($action['scopeField'], $props, "action scopeField on '$slug' not in register");
                foreach (($action['fields'] ?? []) as $field) {
                    $this->assertContains($field, $props, "action field '$field' on '$slug' not in register");
                }
            }
        }

    }//end testManifestMatchesRegisterSchemas()
}//end class
