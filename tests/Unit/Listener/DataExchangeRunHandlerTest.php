<?php

/**
 * Scholiq DataExchangeRunHandler unit tests — verzuimloket dossier composer.
 *
 * Covers the `verzuim-report-composer` change: for target=leerplicht, the
 * payload composed by buildPayload()/composeLeerplichtDossier() includes the
 * flag's resolved breachingRecordIds (as full AttendanceRecord objects) and
 * its interventions history — not only the flat scalar fields the
 * DataMappingProfile.fieldMappings mechanism can express. Non-leerplicht
 * targets are asserted unaffected (no breachingRecords/interventions section
 * added).
 *
 * These tests invoke the private buildPayload()/composeLeerplichtDossier()/
 * resolveAttendanceRecords() methods via reflection (an established pattern
 * in this suite, see tests/Unit/Bpv/ProvidesLeerbedrijfVerificationTest.php)
 * to exercise the real composition logic without standing up the full
 * OpenConnector HTTP call chain that runJob()/handle() also perform.
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
}//end class
