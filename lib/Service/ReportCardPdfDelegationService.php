<?php

/**
 * Scholiq Report Card PDF Delegation Service
 *
 * Lifecycle guard for the ReportCard schema's `renderToPdf`
 * (finalised -> finalised) and `rerenderToPdf` (published-to-parents ->
 * published-to-parents) self-loop transitions. POSTs a **proposed, not-yet-
 * verified** docudesk REST contract
 * (`POST /apps/docudesk/api/v1/documents/render`) — no docudesk endpoint,
 * controller, or PHP call exists anywhere in this repo to reference
 * (verified: `grep -rni docudesk` across every non-vendor file returns only
 * prose mentions). The docudesk-side endpoint implementation is an explicit,
 * tracked follow-up leaf, mirroring `bpv-praktijkovereenkomst`'s POK-PDF
 * precedent: the `ReportCard` OpenRegister object, its `subjectGrades[]`,
 * and its `mentorComment` are the legally complete record regardless of
 * whether a rendered PDF exists.
 *
 * FAIL-SOFT BY DESIGN (per spec): a PDF-render failure is logged and
 * recorded in `docudeskRenderError`, and MUST NOT block any ReportCard
 * lifecycle transition — this guard therefore always returns `true`,
 * catching every `Throwable` and mirroring
 * {@see \OCA\Scholiq\Service\WalletRevocationPropagationService}'s
 * fail-soft shape (a render failure is a convenience-feature-degraded
 * state, not a compliance blocker).
 *
 * Reuses the `IClientService` + `IURLGenerator` + `IAppConfig`
 * bearer-token seam `DataExchangeRunHandler::callOpenConnector()` /
 * `WalletOfferDelegationService` already establish
 * (`scholiq.docudesk_api_token`, mirroring `scholiq.openconnector_api_token`).
 *
 * Legitimate PHP per ADR-031: "external-system bridge — a genuine
 * cross-app delegation that cannot be expressed as a schema declaration."
 * Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.renderToPdf/rerenderToPdf.requires
 * in scholiq_register.json.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the ReportCard `renderToPdf`/`rerenderToPdf` self-loop transitions
 * (as an additional `requires` hook, fail-soft).
 *
 * POSTs the ReportCard's rendering payload to the proposed docudesk
 * endpoint. On a confirmed 2xx with a document reference, sets
 * `docudeskRenderStatus=rendered`, stamps `docudeskDocumentRef`, and clears
 * `docudeskRenderError`. On any failure (missing config, HTTP error, thrown
 * exception, non-2xx, malformed body) sets `docudeskRenderStatus=failed` +
 * `docudeskRenderError`, logs, and still returns `true`.
 *
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
 */
class ReportCardPdfDelegationService
{

    /**
     * Proposed, not-yet-verified docudesk REST contract for rendering a
     * report card to PDF. No docudesk endpoint exists in this repo to
     * confirm the path against — filed as an explicit follow-up leaf.
     *
     * @var string
     */
    private const DOCUDESK_RENDER_PATH = '/apps/docudesk/api/v1/documents/render';

    /**
     * App-config key for the docudesk bearer credential, mirroring
     * `scholiq.openconnector_api_token`.
     *
     * @var string
     */
    private const DOCUDESK_TOKEN_KEY = 'docudesk_api_token';

    /**
     * Template slug requested for a report-card render.
     *
     * @var string
     */
    private const TEMPLATE_SLUG = 'report-card';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService NC HTTP client factory.
     * @param IURLGenerator   $urlGenerator  NC URL generator for internal requests.
     * @param IAppConfig      $appConfig     NC app config for token lookup.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called before executing the `renderToPdf`/`rerenderToPdf` self-loop
     * transition on a ReportCard object. Always returns true — never blocks
     * the transition (fail-soft by design).
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the ReportCard data array (mutated)
     *                                               - 'transition' : 'renderToPdf' or 'rerenderToPdf'
     *
     * @return bool Always true (fail-soft by design).
     *
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
     * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
     */
    public function check(array &$transitionContext): bool
    {
        $object   = &$transitionContext['object'];
        $reportId = (string) ($object['id'] ?? ($object['uuid'] ?? ''));

        $object['docudeskRequestedAt'] = date('c');

        try {
            $result = $this->callDocudeskRender(reportCard: $object);

            if ($result !== null && ($result['documentRef'] ?? null) !== null) {
                $object['docudeskRenderStatus'] = 'rendered';
                $object['docudeskDocumentRef']  = (string) $result['documentRef'];
                $object['docudeskRenderError']  = null;
                $this->logger->info(
                    '[ReportCardPdfDelegationService] ReportCard {id} rendered — docudeskDocumentRef {ref}.',
                    ['id' => $reportId, 'ref' => $result['documentRef']]
                );
            } else {
                $object['docudeskRenderStatus'] = 'failed';
                $object['docudeskRenderError']  = 'docudesk render failed or returned no document reference '
                    .'(the docudesk-side endpoint is a proposed, not-yet-verified contract — see design.md).';
                $this->logger->warning(
                    '[ReportCardPdfDelegationService] ReportCard {id} render did not succeed — recording failure, not blocking the transition.',
                    ['id' => $reportId]
                );
            }
        } catch (Throwable $exception) {
            // Fail-soft by design: never block renderToPdf/rerenderToPdf on the docudesk rail.
            $object['docudeskRenderStatus'] = 'failed';
            $object['docudeskRenderError']  = 'docudesk render error: '.$exception->getMessage();
            $this->logger->warning(
                '[ReportCardPdfDelegationService] Render for ReportCard {id} threw: {msg}',
                ['id' => $reportId, 'msg' => $exception->getMessage()]
            );
        }//end try

        return true;

    }//end check()

    /**
     * Call docudesk's proposed render endpoint.
     *
     * @param array<string,mixed> $reportCard The ReportCard data array.
     *
     * @return array<string,mixed>|null Response data (expects `documentRef` on success), or null on failure/absence.
     */
    private function callDocudeskRender(array $reportCard): ?array
    {
        $url = $this->urlGenerator->getAbsoluteURL('/index.php'.self::DOCUDESK_RENDER_PATH);

        $apiToken = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::DOCUDESK_TOKEN_KEY,
            default: ''
        );

        if ($apiToken === '') {
            $this->logger->warning(
                '[ReportCardPdfDelegationService] No docudesk API token configured (scholiq.docudesk_api_token); '
                .'the render call will fail with 401/403.'
            );
            return null;
        }

        $payload = [
            'reportCardId'      => $reportCard['id'] ?? ($reportCard['uuid'] ?? null),
            'subjectGrades'     => $reportCard['subjectGrades'] ?? [],
            'mentorComment'     => $reportCard['mentorComment'] ?? null,
            'attendanceSummary' => $reportCard['attendanceSummary'] ?? null,
            'templateSlug'      => self::TEMPLATE_SLUG,
        ];

        $requestOptions = [
            'json'    => $payload,
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer '.$apiToken,
            ],
        ];

        $client   = $this->clientService->newClient();
        $response = $client->post($url, $requestOptions);

        $body = json_decode($response->getBody(), true);
        if (is_array($body) === false) {
            return null;
        }

        return $body;

    }//end callDocudeskRender()
}//end class
