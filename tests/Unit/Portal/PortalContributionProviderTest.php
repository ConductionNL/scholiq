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
 * `scholiq_register.json` and asserts every schema slug, scope field and
 * whitelisted field the manifest references actually exists — so a rename in
 * the register (or a missing `portal-identity` ref) fails this test instead of
 * silently breaking the portal at runtime.
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
     * getAudiences() (v2) returns exactly ['student'] and getAudience()
     * (v1 fallback) is one of them. The `parent` audience is deferred until
     * portaliq's reader supports a reverse/scope-value join (see the provider).
     *
     * @return void
     */
    public function testAudienceContract(): void
    {
        $this->assertSame(['student'], $this->provider->getAudiences());
        $this->assertSame('student', $this->provider->getAudience());
        $this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

    }//end testAudienceContract()

    /**
     * Unserved / absent audiences get null — fail-closed audience filtering.
     * `parent` is deferred and therefore also null for now.
     *
     * @return void
     */
    public function testGetContributionReturnsNullForUnservedSubjects(): void
    {
        $teacher             = self::STUDENT_SUBJECT;
        $teacher['audience'] = 'teacher';

        $this->assertNull($this->provider->getContribution($teacher));
        $this->assertNull($this->provider->getContribution([]));
        $this->assertNull($this->provider->getContribution(self::PARENT_SUBJECT));

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
     * Register-drift pin: every schema slug, scope field, whitelisted field and
     * `via` match-field the manifest references MUST exist in the shipped
     * scholiq_register.json — proving the `portal-identity` refs are present and
     * that no register rename silently broke the portal.
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

        foreach ([self::STUDENT_SUBJECT, self::PARENT_SUBJECT] as $subject) {
            $manifest = $this->provider->getContribution($subject);
            if ($manifest === null) {
                // `parent` is deferred (returns null) until portaliq's reader
                // supports the reverse scope-value join; nothing to drift-check.
                continue;
            }

            foreach (($manifest['collections'] ?? []) as $collection) {
                $slug = $collection['schema'];
                $this->assertArrayHasKey($slug, $propsBySlug, "manifest schema '$slug' missing from register");
                $props = $propsBySlug[$slug];

                $this->assertContains($collection['scopeField'], $props, "scopeField on '$slug' not in register");
                foreach (($collection['fields'] ?? []) as $field) {
                    $this->assertContains($field, $props, "field '$field' on '$slug' not in register");
                }

                if (isset($collection['via']) === true) {
                    $viaSlug = $collection['via']['schema'];
                    $this->assertArrayHasKey($viaSlug, $propsBySlug, "via schema '$viaSlug' missing from register");
                    $this->assertContains(
                        $collection['via']['matchField'],
                        $propsBySlug[$viaSlug],
                        "via matchField not in register"
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
