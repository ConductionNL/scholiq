<?php

/**
 * Unit tests for the `secure-exam-test-mode` register delta.
 *
 * This change is deliberately declarative-only on the backend (ADR-022,
 * ADR-031): session/flag persistence goes through OpenRegister's existing
 * generic object API the same way `TakeAssessmentView.vue` and
 * `ProctoringReviewQueue.vue` already do — there is NO new controller, NO new
 * service class, NO new route. The "backend" surface of this change is
 * entirely the `lib/Settings/scholiq_register.json` delta:
 *   - `Assessment.proctoring` gains `nativeTestMode` / `navigationLock`;
 *   - `lockdownBrowser` / `recordWebcam` descriptions gain native-mode
 *     clauses;
 *   - `ProctoringSession` gains an explicit `x-openregister-authorization`
 *     block (`create: ["user"]`) — the first time this schema's write
 *     posture has ever been decided (previously undecided/default).
 *
 * These tests assert that delta, and guard the "no new PHP" design decision
 * (design.md §"No new PHP") plus the existing `ProctoringSession` lifecycle
 * transitions native mode depends on (`activate`, `end`) so a future schema
 * edit can't silently break `TakeAssessmentView.vue`'s
 * `createProctoringSession()` / `teardownNativeTestMode()`.
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
 * @spec openspec/changes/secure-exam-test-mode/specs/assessment/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the native-test-mode schema delta and the "no new PHP" guard.
 */
class SecureExamTestModeTest extends TestCase
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
     * `Assessment.proctoring` declares `nativeTestMode` (boolean, default
     * false) and `navigationLock` (boolean, default true), each with an
     * English title + description (gate-28).
     *
     * @return void
     */
    public function testProctoringGainsNativeTestModeAndNavigationLockProperties(): void
    {
        $schemas    = $this->config['components']['schemas'] ?? [];
        $proctoring = $schemas['Assessment']['properties']['proctoring']['properties'] ?? null;
        $this->assertIsArray($proctoring, 'Assessment.proctoring.properties MUST exist');

        $this->assertArrayHasKey('nativeTestMode', $proctoring);
        $this->assertSame('boolean', $proctoring['nativeTestMode']['type'] ?? null);
        $this->assertSame(false, $proctoring['nativeTestMode']['default'] ?? null);
        $this->assertNotEmpty($proctoring['nativeTestMode']['title'] ?? '', 'nativeTestMode MUST carry a title');
        $this->assertNotEmpty($proctoring['nativeTestMode']['description'] ?? '', 'nativeTestMode MUST carry a description');

        $this->assertArrayHasKey('navigationLock', $proctoring);
        $this->assertSame('boolean', $proctoring['navigationLock']['type'] ?? null);
        $this->assertSame(true, $proctoring['navigationLock']['default'] ?? null);
        $this->assertNotEmpty($proctoring['navigationLock']['title'] ?? '', 'navigationLock MUST carry a title');
        $this->assertNotEmpty($proctoring['navigationLock']['description'] ?? '', 'navigationLock MUST carry a description');

    }//end testProctoringGainsNativeTestModeAndNavigationLockProperties()


    /**
     * `lockdownBrowser` and `recordWebcam` descriptions are updated with the
     * native-mode clauses design.md §4.1 specifies, and `recordWebcam`
     * stays functionally untouched (still boolean, default false) — this
     * change never requests camera/microphone access.
     *
     * @return void
     */
    public function testLockdownBrowserAndRecordWebcamDescriptionsGainNativeModeClauses(): void
    {
        $proctoring = $this->config['components']['schemas']['Assessment']['properties']['proctoring']['properties'] ?? [];

        $lockdown = $proctoring['lockdownBrowser'] ?? null;
        $this->assertIsArray($lockdown);
        $this->assertStringContainsString('nativeTestMode', $lockdown['description'] ?? '');
        $this->assertStringContainsString('Fullscreen API', $lockdown['description'] ?? '');

        $webcam = $proctoring['recordWebcam'] ?? null;
        $this->assertIsArray($webcam);
        $this->assertStringContainsString('nativeTestMode', $webcam['description'] ?? '');
        $this->assertSame('boolean', $webcam['type'] ?? null);
        $this->assertSame(false, $webcam['default'] ?? null);

    }//end testLockdownBrowserAndRecordWebcamDescriptionsGainNativeModeClauses()


    /**
     * `ProctoringSession` gains an explicit `x-openregister-authorization`
     * block scoping `create` to `["user"]` — this is the first change to
     * ever decide this schema's write posture (design.md §4.2), mirroring
     * the `XapiStatement` precedent for the annotation shape (though with a
     * deliberately different value: a learner's own browser, or an external
     * provider adapter, must be able to create a session — admin-only would
     * make native test mode unusable).
     *
     * @return void
     */
    public function testProctoringSessionDeclaresExplicitCreateAuthorization(): void
    {
        $schema = $this->config['components']['schemas']['ProctoringSession'] ?? null;
        $this->assertIsArray($schema, 'ProctoringSession schema MUST exist');

        $auth = $schema['x-openregister-authorization'] ?? null;
        $this->assertIsArray($auth, 'ProctoringSession MUST declare x-openregister-authorization');
        $this->assertSame(['user'], $auth['create'] ?? null, 'ProctoringSession.create MUST be scoped to ["user"], not left undecided or admin-only');

    }//end testProctoringSessionDeclaresExplicitCreateAuthorization()


    /**
     * The `ProctoringSession` lifecycle still declares the `activate` and
     * `end` transitions `TakeAssessmentView.vue`'s `createProctoringSession()`
     * / `teardownNativeTestMode()` dispatch by name — a guard against a
     * future schema edit silently renaming/removing them out from under the
     * frontend.
     *
     * @return void
     */
    public function testProctoringSessionRetainsActivateAndEndTransitions(): void
    {
        $lifecycle = $this->config['components']['schemas']['ProctoringSession']['x-openregister-lifecycle'] ?? null;
        $this->assertIsArray($lifecycle);

        $transitions = $lifecycle['transitions'] ?? [];
        $this->assertArrayHasKey('activate', $transitions);
        $this->assertSame('created', $transitions['activate']['from'] ?? null);
        $this->assertSame('active', $transitions['activate']['to'] ?? null);

        $this->assertArrayHasKey('end', $transitions);
        $this->assertSame('active', $transitions['end']['from'] ?? null);
        $this->assertSame('ended', $transitions['end']['to'] ?? null);

    }//end testProctoringSessionRetainsActivateAndEndTransitions()


    /**
     * `ProctoringSession.flags[].kind` stays free-text `string` (no enum) —
     * design.md §5 is explicit this is NOT a schema enum change, so external
     * provider flag kinds (gaze-away, audio-event, object-detected) and the
     * native-mode vocabulary (fullscreen-exit, tab-hidden, window-blur,
     * blocked-navigation, concurrent-session-detected) both remain valid
     * without a schema change.
     *
     * @return void
     */
    public function testFlagKindRemainsFreeTextNotEnum(): void
    {
        $kindSchema = $this->config['components']['schemas']['ProctoringSession']['properties']['flags']['items']['properties']['kind'] ?? null;
        $this->assertIsArray($kindSchema);
        $this->assertSame('string', $kindSchema['type'] ?? null);
        $this->assertArrayNotHasKey('enum', $kindSchema, 'flags[].kind MUST remain free-text — no enum constraint per design.md §5');

    }//end testFlagKindRemainsFreeTextNotEnum()


    /**
     * Scholiq ships NO new controller, service, or route for
     * ProctoringSession/native-test-mode ingestion — session/flag
     * persistence goes entirely through OpenRegister's existing generic
     * object API (ADR-022), exactly like the pre-existing
     * `ProctoringReviewQueue.vue` write path. This guards the "No new PHP"
     * design decision (design.md, "No new PHP") against silent scope creep.
     *
     * @return void
     */
    public function testNoNewProctoringControllerServiceOrRouteExists(): void
    {
        $controllerDir = __DIR__.'/../../../lib/Controller';
        $this->assertDirectoryExists($controllerDir);
        $hits = glob($controllerDir.'/*Proctoring*Controller.php') ?: [];
        $hits = array_merge($hits, (glob($controllerDir.'/*TestMode*Controller.php') ?: []));
        $this->assertSame([], $hits, 'Scholiq MUST NOT ship a Proctoring/TestMode controller — session/flag writes go through the generic OR object API (ADR-022)');

        $serviceDir = __DIR__.'/../../../lib/Service';
        if (is_dir($serviceDir)) {
            $serviceHits = glob($serviceDir.'/*Proctoring*.php') ?: [];
            $serviceHits = array_merge($serviceHits, (glob($serviceDir.'/*TestMode*.php') ?: []));
            $this->assertSame([], $serviceHits, 'Scholiq MUST NOT ship a Proctoring/TestMode service class');
        }

        $routesPath = __DIR__.'/../../../appinfo/routes.php';
        $routes     = require $routesPath;
        $this->assertIsArray($routes);

        $names = [];
        foreach (($routes['routes'] ?? []) as $route) {
            $names[] = strtolower((string) ($route['name'] ?? ''));
        }

        foreach ($names as $name) {
            $this->assertStringNotContainsString(
                'testmode',
                $name,
                "Scholiq MUST NOT register a native-test-mode route ($name) — persistence is the generic OR object API's"
            );
        }

    }//end testNoNewProctoringControllerServiceOrRouteExists()

}//end class
