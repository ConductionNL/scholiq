<?php

/**
 * Scholiq Attendance Flag Report Guard
 *
 * Lifecycle guard for the AttendanceFlag schema's `in-handling → reported`
 * transition. Verifies that the DataExchangeJob associated with this flag
 * has been queued AND has succeeded before allowing the `reported` state.
 *
 * This ensures the leerplicht report has been successfully sent to the
 * municipality via OpenConnector (leerplicht target) before the coordinator
 * can mark the flag as `reported`.
 *
 * If the flag has no dataExchangeJobId (no outbound report was configured),
 * the transition is allowed — the flag was handled manually without a
 * data exchange target.
 *
 * ADR-031: guards referenced via schema `requires:` are resolved by OR's
 * lifecycle engine by class name — no `Application.php` registration needed.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AttendanceFlag `in-handling → reported` lifecycle transition.
 *
 * When a dataExchangeJobId is set on the flag, verifies the linked
 * DataExchangeJob has reached `succeeded` state. When no job is linked,
 * allows the transition unconditionally (manual report).
 */
class AttendanceFlagReportGuard
{

    private const SCHOLIQ_REGISTER         = 'scholiq';
    private const DATA_EXCHANGE_JOB_SCHEMA = 'data-exchange-job';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object access service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Allow the `in-handling → reported` transition.
     *
     * Returns true when:
     * - The flag has no dataExchangeJobId (manual report, no data exchange required).
     * - The linked DataExchangeJob is in `succeeded` state.
     *
     * Returns false when:
     * - The linked DataExchangeJob is not yet `succeeded` (queued, running, pending-parent-review, failed, partial).
     * - The linked DataExchangeJob cannot be found.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the AttendanceFlag data array
     *                                               - 'transition' : 'report'
     *                                               - 'from'       : 'in-handling'
     *                                               - 'to'         : 'reported'
     *
     * @return bool True if the report transition is allowed; false otherwise.
     */
    public function check(array &$transitionContext): bool
    {
        $object            = $transitionContext['object'] ?? [];
        $dataExchangeJobId = $object['dataExchangeJobId'] ?? null;

        // No data exchange job linked — the flag was handled manually.
        // Allow the transition unconditionally.
        if ($dataExchangeJobId === null || $dataExchangeJobId === '') {
            return true;
        }

        // Fetch the DataExchangeJob to check its lifecycle state.
        $jobs = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::DATA_EXCHANGE_JOB_SCHEMA,
                'filters'  => ['id' => (string) $dataExchangeJobId],
                'limit'    => 1,
            ]
        );

        if (empty($jobs) === true) {
            $this->logger->warning(
                '[AttendanceFlagReportGuard] DataExchangeJob {id} not found — denying report transition.',
                ['id' => $dataExchangeJobId]
            );
            return false;
        }

        $job = $jobs[0];
        if (is_array($jobs[0]) === false) {
            $job = $jobs[0]->jsonSerialize();
        }

        $jobState = $job['lifecycle'] ?? '';

        if ($jobState !== 'succeeded') {
            $this->logger->info(
                '[AttendanceFlagReportGuard] DataExchangeJob {id} is in state {s}, not succeeded — denying report.',
                ['id' => $dataExchangeJobId, 's' => $jobState]
            );
            return false;
        }

        return true;

    }//end check()
}//end class
