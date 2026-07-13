<?php

/**
 * Scholiq DataExchangeRunHandler unit tests — verzuimloket + SWV dossier composers.
 *
 * Covers the `verzuim-report-composer` change: for target=leerplicht, the
 * payload composed by buildPayload()/composeLeerplichtDossier() includes the
 * flag's resolved breachingRecordIds (as full AttendanceRecord objects) and
 * its interventions history — not only the flat scalar fields the
 * DataMappingProfile.fieldMappings mechanism can express. Non-leerplicht
 * targets are asserted unaffected (no breachingRecords/interventions section
 * added).
 *
 * Also covers the `zorgvraag-swv-tlv-chain` change: for target=swv, the
 * payload composed by buildPayload()/composeSwvDossier() includes a
 * minimal-disclosure `learner` whitelist (resolved from LearnerProfile) and,
 * when the SupportRequest carries a learningPlanId, a `learningPlanContext`
 * whitelist (resolved from LearningPlan) — never a full-object dump, and
 * never bsnEncrypted/bsnHash/email. `swv` is also in MANDATORY_PROFILE_TARGETS,
 * so a swv job with no configured DataMappingProfile MUST throw rather than
 * fall through to pass-through (fail-closed, design.md Security Considerations).
 *
 * These tests invoke the private buildPayload()/composeLeerplichtDossier()/
 * resolveAttendanceRecords()/composeSwvDossier() methods via reflection (an
 * established pattern in this suite, see
 * tests/Unit/Bpv/ProvidesLeerbedrijfVerificationTest.php) to exercise the
 * real composition logic without standing up the full OpenConnector HTTP
 * call chain that runJob()/handle() also perform.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verzuim-report-composer/specs/data-exchange/spec.md#requirement-verzuimloket-dossier-composition-mirrors-the-oso-dossier-composer
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-minimal-disclosure-to-the-swv-via-a-field-whitelisting-datamappingprofile
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Listener\DataExchangeRunHandler;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for DataExchangeRunHandler's leerplicht dossier composition.
 */
class DataExchangeRunHandlerTest extends TestCase
{
    /**
     * Build a handler whose ObjectService::findAll() returns the given
     * AttendanceRecord data for any 'attendance-record' schema query.
     *
     * @param array<string,array<string,mixed>> $recordsById Map of record UUID => record data.
     *
     * @return DataExchangeRunHandler
     */
    private function makeHandler(array $recordsById): DataExchangeRunHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($recordsById): array {
                if (($config['schema'] ?? '') !== 'attendance-record') {
                    return [];
                }

                $id = $config['filters']['id'] ?? null;
                if ($id === null || isset($recordsById[$id]) === false) {
                    return [];
                }

                return [$recordsById[$id]];
            }
        );

        return new DataExchangeRunHandler(
            $objectService,
            $this->createMock(TransitionEngine::class),
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IAppConfig::class),
            new NullLogger()
        );

    }//end makeHandler()

    /**
     * Invoke the private buildPayload() method via reflection.
     *
     * @param DataExchangeRunHandler         $handler The handler under test.
     * @param array<int,array<string,mixed>> $objects Source objects.
     * @param array<string,mixed>|null       $profile DataMappingProfile data, or null.
     * @param string                         $target  Data-exchange target slug.
     *
     * @return array<int,array<string,mixed>> The composed payload.
     */
    private function buildPayload(DataExchangeRunHandler $handler, array $objects, ?array $profile, string $target): array
    {
        $method = new \ReflectionMethod($handler, 'buildPayload');
        return $method->invoke($handler, $objects, $profile, $target);

    }//end buildPayload()

    /**
     * target=leerplicht with a mapping profile composes breachingRecords +
     * interventions on top of the flat field-mapped record.
     *
     * @return void
     *
     * @spec openspec/changes/verzuim-report-composer/specs/data-exchange/spec.md#scenario-verzuimloket-dossier-is-composed-like-the-oso-dossier-without-a-parent-review-gate
     */
    public function testLeerplichtTargetComposesBreachingRecordsAndInterventions(): void
    {
        $handler = $this->makeHandler(
            [
                'rec-1' => ['id' => 'rec-1', 'status' => 'absent-unexcused', 'minutesAttended' => null],
                'rec-2' => ['id' => 'rec-2', 'status' => 'absent-unexcused', 'minutesAttended' => null],
            ]
        );

        $flag = [
            'id'                 => 'flag-1',
            'learnerId'          => 'learner-1',
            'windowStart'        => '2026-06-01',
            'windowEnd'          => '2026-07-01',
            'metricValue'        => 16,
            'breachingRecordIds' => ['rec-1', 'rec-2'],
            'interventions'      => [
                ['recordedBy' => 'mentor-1', 'recordedAt' => '2026-06-15T10:00:00+00:00', 'note' => 'Belde met ouders.'],
            ],
            'tenant_id'          => 'tenant-1',
        ];

        $profile = [
            'fieldMappings' => [
                ['scholiqField' => 'learnerId', 'targetField' => 'leerlingId', 'transform' => null],
            ],
        ];

        $payload = $this->buildPayload($handler, [$flag], $profile, 'leerplicht');

        self::assertCount(1, $payload);
        $record = $payload[0];

        self::assertSame('learner-1', $record['leerlingId']);
        self::assertCount(2, $record['breachingRecords']);
        self::assertSame('rec-1', $record['breachingRecords'][0]['id']);
        self::assertSame('rec-2', $record['breachingRecords'][1]['id']);
        self::assertCount(1, $record['interventions']);
        self::assertSame('Belde met ouders.', $record['interventions'][0]['note']);

    }//end testLeerplichtTargetComposesBreachingRecordsAndInterventions()

    /**
     * target=leerplicht with NO mapping profile still composes the dossier
     * on top of the PII-stripped pass-through record.
     *
     * @return void
     */
    public function testLeerplichtTargetComposesWithoutProfile(): void
    {
        $handler = $this->makeHandler(
            ['rec-1' => ['id' => 'rec-1', 'status' => 'absent-unexcused']]
        );

        $flag = [
            'id'                 => 'flag-1',
            'learnerId'          => 'learner-1',
            'breachingRecordIds' => ['rec-1'],
            'interventions'      => [],
            'tenant_id'          => 'tenant-1',
        ];

        $payload = $this->buildPayload($handler, [$flag], null, 'leerplicht');

        self::assertCount(1, $payload);
        self::assertCount(1, $payload[0]['breachingRecords']);
        self::assertSame([], $payload[0]['interventions']);

    }//end testLeerplichtTargetComposesWithoutProfile()

    /**
     * A non-leerplicht target (e.g. bron-rod) is NOT given a breachingRecords/
     * interventions section — the composer is scoped strictly to leerplicht.
     *
     * @return void
     */
    public function testNonLeerplichtTargetDoesNotComposeDossier(): void
    {
        $handler = $this->makeHandler([]);

        $object = ['id' => 'lp-1', 'eckId' => 'eck-1', 'givenName' => 'Foo'];

        $profile = [
            'fieldMappings' => [
                ['scholiqField' => 'eckId', 'targetField' => 'eckId', 'transform' => null],
            ],
        ];

        $payload = $this->buildPayload($handler, [$object], $profile, 'bron-rod');

        self::assertCount(1, $payload);
        self::assertArrayNotHasKey('breachingRecords', $payload[0]);
        self::assertArrayNotHasKey('interventions', $payload[0]);

    }//end testNonLeerplichtTargetDoesNotComposeDossier()

    /**
     * A breachingRecordId that resolves to no AttendanceRecord is skipped,
     * not fatal — the dossier composes with whatever records DO resolve.
     *
     * @return void
     */
    public function testUnresolvableBreachingRecordIsSkipped(): void
    {
        $handler = $this->makeHandler(
            ['rec-1' => ['id' => 'rec-1', 'status' => 'absent-unexcused']]
        );

        $flag = [
            'id'                 => 'flag-1',
            'breachingRecordIds' => ['rec-1', 'rec-missing'],
            'interventions'      => [],
            'tenant_id'          => 'tenant-1',
        ];

        $payload = $this->buildPayload($handler, [$flag], null, 'leerplicht');

        self::assertCount(1, $payload[0]['breachingRecords']);
        self::assertSame('rec-1', $payload[0]['breachingRecords'][0]['id']);

    }//end testUnresolvableBreachingRecordIsSkipped()

    /**
     * Build a handler whose ObjectService::findAll() resolves LearnerProfile
     * (by ncUserId) and LearningPlan (by id) lookups for the SWV dossier composer.
     *
     * @param array<string,array<string,mixed>> $profilesByNcUserId Map of learner NC user ID => LearnerProfile data.
     * @param array<string,array<string,mixed>> $plansById          Map of LearningPlan UUID => LearningPlan data.
     *
     * @return DataExchangeRunHandler
     */
    private function makeSwvHandler(array $profilesByNcUserId, array $plansById=[]): DataExchangeRunHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($profilesByNcUserId, $plansById): array {
                $schema  = $config['schema'] ?? '';
                $filters = $config['filters'] ?? [];

                if ($schema === 'learner-profile') {
                    $ncUserId = $filters['ncUserId'] ?? null;
                    if ($ncUserId === null || isset($profilesByNcUserId[$ncUserId]) === false) {
                        return [];
                    }

                    return [$profilesByNcUserId[$ncUserId]];
                }

                if ($schema === 'learning-plan') {
                    $id = $filters['id'] ?? null;
                    if ($id === null || isset($plansById[$id]) === false) {
                        return [];
                    }

                    return [$plansById[$id]];
                }

                return [];
            }
        );

        return new DataExchangeRunHandler(
            $objectService,
            $this->createMock(TransitionEngine::class),
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IAppConfig::class),
            new NullLogger()
        );

    }//end makeSwvHandler()

    /**
     * target=swv with a mapping profile composes a minimal-disclosure `learner`
     * whitelist and a `learningPlanContext` whitelist when learningPlanId is set.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-6.4
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#scenario-swv-dossier-composition-drops-non-whitelisted-fields
     */
    public function testSwvTargetComposesLearnerAndLearningPlanContext(): void
    {
        $handler = $this->makeSwvHandler(
            [
                'learner-1' => [
                    'ncUserId'     => 'learner-1',
                    'eckId'        => 'eck-abc',
                    'givenName'    => 'Fatima',
                    'familyName'   => 'El-Amrani',
                    'birthDate'    => '2012-03-04',
                    'schoolId'     => 'school-1',
                    'bsnEncrypted' => 'ZmFrZS1jaXBoZXJ0ZXh0LWZha2UtY2lwaGVydGV4dA==',
                    'email'        => 'fatima@example.org',
                ],
            ],
            [
                'plan-1' => [
                    'id'              => 'plan-1',
                    'kind'            => 'opp',
                    'period'          => '2025-2026',
                    'goals'           => [['goalId' => 'g1', 'description' => 'Lezen op niveau']],
                    'supportMeasures' => [['measureId' => 'm1', 'description' => 'RT begeleiding']],
                    'templateId'      => 'template-1',
                    'coordinatorId'   => 'coord-1',
                ],
            ]
        );

        $supportRequest = [
            'id'             => 'sr-1',
            'learnerId'      => 'learner-1',
            'learningPlanId' => 'plan-1',
            'supportDomain'  => 'gedrag',
            'description'    => 'Extra ondersteuning nodig bij gedrag in de klas.',
            'urgency'        => 'medium',
            'tenant_id'      => 'tenant-1',
        ];

        $profile = [
            'fieldMappings' => [
                ['scholiqField' => 'supportDomain', 'targetField' => 'hulpvraagDomein', 'transform' => null],
                ['scholiqField' => 'description', 'targetField' => 'hulpvraagOmschrijving', 'transform' => null],
            ],
        ];

        $payload = $this->buildPayload($handler, [$supportRequest], $profile, 'swv');

        self::assertCount(1, $payload);
        $record = $payload[0];

        self::assertSame('gedrag', $record['hulpvraagDomein']);

        self::assertArrayHasKey('learner', $record);
        self::assertSame('eck-abc', $record['learner']['eckId']);
        self::assertSame('Fatima', $record['learner']['givenName']);
        self::assertSame('El-Amrani', $record['learner']['familyName']);
        self::assertArrayNotHasKey('bsnEncrypted', $record['learner']);
        self::assertArrayNotHasKey('email', $record['learner']);

        self::assertArrayHasKey('learningPlanContext', $record);
        self::assertSame('opp', $record['learningPlanContext']['kind']);
        self::assertCount(1, $record['learningPlanContext']['goals']);
        self::assertArrayNotHasKey('templateId', $record['learningPlanContext']);
        self::assertArrayNotHasKey('coordinatorId', $record['learningPlanContext']);

    }//end testSwvTargetComposesLearnerAndLearningPlanContext()

    /**
     * target=swv with no learningPlanId set on the SupportRequest omits the
     * learningPlanContext section entirely — fail-closed, never a wider export.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#scenario-supportrequest-created-without-a-prior-learningplan
     */
    public function testSwvTargetWithoutLearningPlanIdOmitsLearningPlanContext(): void
    {
        $handler = $this->makeSwvHandler(
            ['learner-1' => ['ncUserId' => 'learner-1', 'eckId' => 'eck-abc']]
        );

        $supportRequest = [
            'id'             => 'sr-2',
            'learnerId'      => 'learner-1',
            'learningPlanId' => null,
            'supportDomain'  => 'leren',
            'tenant_id'      => 'tenant-1',
        ];

        $payload = $this->buildPayload($handler, [$supportRequest], ['fieldMappings' => []], 'swv');

        self::assertArrayHasKey('learner', $payload[0]);
        self::assertArrayNotHasKey('learningPlanContext', $payload[0]);

    }//end testSwvTargetWithoutLearningPlanIdOmitsLearningPlanContext()

    /**
     * target=swv with an unresolvable LearnerProfile yields a null `learner`
     * section rather than inventing data or failing the whole payload.
     *
     * @return void
     */
    public function testSwvTargetWithUnresolvableLearnerProfileYieldsNullLearnerSection(): void
    {
        $handler = $this->makeSwvHandler([]);

        $supportRequest = [
            'id'        => 'sr-3',
            'learnerId' => 'learner-missing',
            'tenant_id' => 'tenant-1',
        ];

        $payload = $this->buildPayload($handler, [$supportRequest], ['fieldMappings' => []], 'swv');

        self::assertArrayHasKey('learner', $payload[0]);
        self::assertNull($payload[0]['learner']);

    }//end testSwvTargetWithUnresolvableLearnerProfileYieldsNullLearnerSection()

    /**
     * A non-swv target (e.g. bron-rod) is NOT given a learner/learningPlanContext
     * section — the SWV composer is scoped strictly to target=swv.
     *
     * @return void
     */
    public function testNonSwvTargetDoesNotComposeSwvDossier(): void
    {
        $handler = $this->makeSwvHandler(['learner-1' => ['ncUserId' => 'learner-1']]);

        $object  = ['id' => 'lp-1', 'eckId' => 'eck-1', 'learnerId' => 'learner-1'];
        $profile = ['fieldMappings' => [['scholiqField' => 'eckId', 'targetField' => 'eckId', 'transform' => null]]];

        $payload = $this->buildPayload($handler, [$object], $profile, 'bron-rod');

        self::assertArrayNotHasKey('learner', $payload[0]);
        self::assertArrayNotHasKey('learningPlanContext', $payload[0]);

    }//end testNonSwvTargetDoesNotComposeSwvDossier()

    /**
     * target=swv with NO mapping profile MUST throw rather than fall through to
     * a PII-stripped pass-through — `swv` is in MANDATORY_PROFILE_TARGETS
     * (design.md Security Considerations "Fail-closed on the SWV export").
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/design.md
     */
    public function testSwvTargetWithNoProfileThrows(): void
    {
        $handler = $this->makeSwvHandler([]);

        $this->expectException(\RuntimeException::class);

        $this->buildPayload($handler, [['id' => 'sr-4', 'learnerId' => 'learner-1', 'tenant_id' => 'tenant-1']], null, 'swv');

    }//end testSwvTargetWithNoProfileThrows()

    /**
     * Build a handler whose TransitionEngine::transition() calls are captured,
     * and whose ObjectService::findAll() resolves a support-request lookup —
     * for exercising routeSupportRequestToSwv() via reflection.
     *
     * @param array<string,mixed>|null $supportRequest The SupportRequest row findAll() returns for
     *                                                  schema=support-request, or null for none.
     * @param array<int,array<string,string>> $transitions Capture buffer, passed by reference so
     *                                                  the caller can assert on it.
     *
     * @return DataExchangeRunHandler
     */
    private function makeRoutingHandler(?array $supportRequest, array &$transitions): DataExchangeRunHandler
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($supportRequest): array {
                if (($config['schema'] ?? '') !== 'support-request') {
                    return [];
                }
                return $supportRequest === null ? [] : [$supportRequest];
            }
        );

        $transitionEngine = $this->createMock(TransitionEngine::class);
        $transitionEngine->method('transition')->willReturnCallback(
            function (string $objectId, string $action) use (&$transitions): void {
                $transitions[] = ['objectId' => $objectId, 'action' => $action];
            }
        );

        return new DataExchangeRunHandler(
            $objectService,
            $transitionEngine,
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(IAppConfig::class),
            new NullLogger()
        );

    }//end makeRoutingHandler()

    /**
     * Invoke the private routeSupportRequestToSwv() method via reflection.
     *
     * @param DataExchangeRunHandler $handler   The handler under test.
     * @param string                 $target    Job target slug.
     * @param string                 $nextState The lifecycle state the job just transitioned to.
     * @param array<string,mixed>    $scope     Job scope.
     * @param string                 $tenantId  Tenant ID.
     *
     * @return void
     */
    private function routeSupportRequestToSwv(
        DataExchangeRunHandler $handler,
        string $target,
        string $nextState,
        array $scope,
        string $tenantId,
    ): void {
        $method = new \ReflectionMethod($handler, 'routeSupportRequestToSwv');
        $method->invoke($handler, $target, $nextState, $scope, $tenantId);

    }//end routeSupportRequestToSwv()

    /**
     * A succeeded swv-target job transitions the originating SupportRequest
     * (resolved via scope.filters.supportRequestId) to routeToSwv.
     *
     * @return void
     *
     * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#scenario-supportrequest-tracks-the-routed-job-through-to-decision
     */
    public function testSucceededSwvJobRoutesSupportRequestToSwv(): void
    {
        $transitions = [];
        $handler     = $this->makeRoutingHandler(['id' => 'sr-1', 'tenant_id' => 'tenant-a'], $transitions);

        $scope = ['schema' => 'support-request', 'filters' => ['supportRequestId' => 'sr-1', 'learnerId' => 'learner-1']];

        $this->routeSupportRequestToSwv($handler, 'swv', 'succeed', $scope, 'tenant-a');

        self::assertCount(1, $transitions);
        self::assertSame('sr-1', $transitions[0]['objectId']);
        self::assertSame('routeToSwv', $transitions[0]['action']);

    }//end testSucceededSwvJobRoutesSupportRequestToSwv()

    /**
     * A non-swv target is a no-op — no transition attempted, even if scope
     * carries a supportRequestId.
     *
     * @return void
     */
    public function testNonSwvTargetDoesNotRouteSupportRequest(): void
    {
        $transitions = [];
        $handler     = $this->makeRoutingHandler(['id' => 'sr-2', 'tenant_id' => 'tenant-a'], $transitions);

        $scope = ['schema' => 'attendance-flag', 'filters' => ['supportRequestId' => 'sr-2']];

        $this->routeSupportRequestToSwv($handler, 'leerplicht', 'succeed', $scope, 'tenant-a');

        self::assertCount(0, $transitions);

    }//end testNonSwvTargetDoesNotRouteSupportRequest()

    /**
     * A swv-target job that did NOT succeed (e.g. partial/failed) is a no-op —
     * only a succeeded job routes the SupportRequest forward.
     *
     * @return void
     */
    public function testNonSucceededSwvJobDoesNotRouteSupportRequest(): void
    {
        $transitions = [];
        $handler     = $this->makeRoutingHandler(['id' => 'sr-3', 'tenant_id' => 'tenant-a'], $transitions);

        $scope = ['schema' => 'support-request', 'filters' => ['supportRequestId' => 'sr-3']];

        $this->routeSupportRequestToSwv($handler, 'swv', 'partial', $scope, 'tenant-a');

        self::assertCount(0, $transitions);

    }//end testNonSucceededSwvJobDoesNotRouteSupportRequest()

    /**
     * A succeeded swv job with no scope.filters.supportRequestId is a no-op —
     * logged and skipped, never fatal.
     *
     * @return void
     */
    public function testMissingSupportRequestIdIsSkipped(): void
    {
        $transitions = [];
        $handler     = $this->makeRoutingHandler(null, $transitions);

        $scope = ['schema' => 'support-request', 'filters' => []];

        $this->routeSupportRequestToSwv($handler, 'swv', 'succeed', $scope, 'tenant-a');

        self::assertCount(0, $transitions);

    }//end testMissingSupportRequestIdIsSkipped()

    /**
     * A succeeded swv job whose supportRequestId does not resolve to any
     * SupportRequest is a no-op — logged and skipped, never fatal.
     *
     * @return void
     */
    public function testUnresolvableSupportRequestIsSkipped(): void
    {
        $transitions = [];
        $handler     = $this->makeRoutingHandler(null, $transitions);

        $scope = ['schema' => 'support-request', 'filters' => ['supportRequestId' => 'sr-missing']];

        $this->routeSupportRequestToSwv($handler, 'swv', 'succeed', $scope, 'tenant-a');

        self::assertCount(0, $transitions);

    }//end testUnresolvableSupportRequestIsSkipped()
}//end class
