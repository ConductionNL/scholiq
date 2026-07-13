<?php

/**
 * Scholiq Wallet Offer Delegation Service
 *
 * Lifecycle guard for the Credential schema's `offerToWallet` transition.
 * Pushes an issued Credential to the EUDI wallet by delegating to
 * openconnector's OpenID4VCI adapter (`eudi-wallet-credential-issuance`,
 * ConductionNL/openconnector) over REST.
 *
 * DEVIATION FROM THE ORIGINAL DESIGN — read before touching this file:
 * `openspec/changes/eudi-wallet-credential-push/proposal.md` and `tasks.md`
 * specify an ADR-041 typed-event contract (`WalletOfferRequestedEvent`
 * dispatched via IEventDispatcher, consumed by an openconnector listener).
 * That mechanism does not exist and was never built: openconnector's merged
 * companion change (`openconnector-dev/openspec/changes/
 * eudi-wallet-credential-issuance/proposal.md`, "Consumer leaf" note) is
 * explicit that scholiq is "the caller of `POST /api/eudi/credential-offers`"
 * — a REST endpoint on `OCA\OpenConnector\Controller\EudiWalletController`,
 * registered in `appinfo/routes.php` (`eudiWallet#createOffer`), not an
 * event contract. A repo-wide grep of openconnector-dev for
 * `OCA\OpenConnector\Event\Wallet*` returns zero hits. This class therefore
 * follows the REST-delegation pattern scholiq already established for
 * OpenConnector calls (`LtiToolPlacementController::callOpenConnectorLaunch()`,
 * `DataExchangeRunHandler::callOpenConnector()`) — same `IClientService` +
 * `IURLGenerator` + `IAppConfig` bearer-token shape and the same
 * `scholiq.openconnector_api_token` config key — rather than the
 * unimplementable event contract.
 *
 * AUTH GAP (flag to a human): `EudiWalletController::authenticateConsumer()`
 * requires an `Authorization: Bearer <jwt>` that resolves to a registered
 * openconnector `consumer` via `authorization-jwt` REQ-001 — a signed JWT,
 * not an opaque static token. Scholiq mints no JWTs (stays wallet-wire-
 * protocol-free per the task scope) and has no consumer-registration flow
 * against openconnector. This class reuses the existing
 * `scholiq.openconnector_api_token` config value verbatim as the bearer
 * value, matching the established DataExchangeRunHandler/
 * LtiToolPlacementController convention of "an admin pastes in a
 * pre-provisioned credential" — but for THIS endpoint the pasted value must
 * specifically be a long-lived JWT issued to a registered openconnector
 * consumer, not a generic API token. That provisioning step is an
 * out-of-band admin action this change does not implement.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.offerToWallet.requires in
 * scholiq_register.json. Built to the `check(array &$transitionContext): bool`
 * contract `CredentialSigningService` establishes (see that class's docblock)
 * and every other Lifecycle guard in this app uses.
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the Credential `offerToWallet` transition.
 *
 * Builds a credential-offer request from the Credential's already-signed
 * payload, POSTs it to openconnector's `/api/eudi/credential-offers`
 * endpoint, and writes the resulting wallet-offer fields back into the
 * transition context. Fails closed: any failure (missing config, HTTP
 * error, malformed response) sets `walletOfferError` and blocks the
 * transition — it never leaves partial wallet-offer state.
 */
class WalletOfferDelegationService
{

    /**
     * OpenConnector REST endpoint for app-facing, consumer-gated offer
     * creation (openconnector `eudi-wallet-credential-issuance` REQ-EUDI-004,
     * `appinfo/routes.php`: `eudiWallet#createOffer`).
     *
     * @var string
     */
    private const OPENCONNECTOR_CREATE_OFFER_PATH = '/apps/openconnector/api/eudi/credential-offers';

    /**
     * App-config key for the OpenConnector bearer credential. Same key
     * `DataExchangeRunHandler`/`LtiToolPlacementController` already use —
     * reused rather than adding a second cross-app credential (see class
     * docblock's AUTH GAP note for the shape mismatch this carries).
     *
     * @var string
     */
    private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

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
     * Called before executing the `offerToWallet` transition on a Credential
     * object. Builds the offer request from the Credential's signed payload,
     * calls openconnector, and on success writes `walletOfferStatus=offered`,
     * `walletOfferedAt`, `walletAttestationRef` into the context, clearing
     * `walletOfferError`. On any failure sets `walletOfferError` and returns
     * false, blocking the transition.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Credential data array
     *                                               - 'transition' : 'offerToWallet'
     *                                               - 'from'       : 'issued'
     *                                               - 'to'         : 'issued'
     *
     * @return bool True when the offer was created; false blocks the transition.
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
     */
    public function check(array &$transitionContext): bool
    {
        $object = &$transitionContext['object'];

        $requestBody = $this->buildOfferRequest(credential: $object);
        if ($requestBody === null) {
            $object['walletOfferError'] = 'Credential has no signed payload to offer (openbadges3Payload and edciPayload are both empty).';
            return false;
        }

        $result = $this->callOpenConnectorCreateOffer(requestBody: $requestBody);
        if ($result === null) {
            $object['walletOfferError'] = 'OpenConnector wallet offer creation failed or is unavailable.';
            return false;
        }

        $attestationRef = $this->extractOfferUuid(response: $result);
        if ($attestationRef === null) {
            $object['walletOfferError'] = 'OpenConnector returned no usable credentialOfferUri for the wallet offer.';
            return false;
        }

        $object['walletOfferStatus']    = 'offered';
        $object['walletOfferedAt']      = gmdate('c');
        $object['walletAttestationRef'] = $attestationRef;
        $object['walletOfferError']     = null;

        $this->logger->info(
            '[WalletOfferDelegationService] Credential {id} pushed to EUDI wallet, attestationRef={ref}',
            ['id' => ($object['id'] ?? $object['uuid'] ?? ''), 'ref' => $attestationRef]
        );

        return true;
    }//end check()

    /**
     * Build the openconnector `createOffer` request body from the
     * Credential's already-signed payload.
     *
     * Prefers `edciPayload` (EDCI/Europass ELM, "Phase 3" — nullable, not yet
     * populated by any current issuance path) when present, otherwise falls
     * back to `openbadges3Payload` (always populated by
     * `CredentialSigningService::check()` on `issue`). Both are already
     * RS256-signed by `CredentialSigningService`, so `format` is always
     * `jwt_vc_json` (verbatim pass-through per
     * `EudiCredentialOfferService::issueCredential()` — never `dc+sd-jwt`,
     * which triggers openconnector to mint a *fresh* credential instead of
     * carrying the one scholiq already signed).
     *
     * @param array<string,mixed> $credential The Credential data array.
     *
     * @return array<string,mixed>|null The request body, or null when there is no payload to offer.
     */
    private function buildOfferRequest(array $credential): ?array
    {
        $payload = ($credential['edciPayload'] ?? null);
        if (empty($payload) === true) {
            $payload = ($credential['openbadges3Payload'] ?? null);
        }

        if (empty($payload) === true) {
            return null;
        }

        $subjectId = ($payload['credentialSubject']['id'] ?? null);
        if (is_string($subjectId) === false || $subjectId === '') {
            $subjectId = (string) ($credential['learnerId'] ?? '');
        }

        if ($subjectId === '') {
            return null;
        }

        $kind = (string) ($credential['kind'] ?? '');
        $credentialConfigurationId = 'edci-diploma';
        if ($kind === 'badge' || $kind === 'microcredential') {
            $credentialConfigurationId = 'open-badges-3';
        }

        return [
            'credentialPayload'         => $payload,
            'format'                    => 'jwt_vc_json',
            'subjectId'                 => $subjectId,
            'credentialConfigurationId' => $credentialConfigurationId,
        ];
    }//end buildOfferRequest()

    /**
     * Extract the offer uuid (the correlation key for claim sync-back and
     * revocation) from openconnector's `createOffer` response.
     *
     * The controller response is `{offerUrl, credentialOfferUri, qrPayload}`
     * — the uuid is only present as the final path segment of
     * `credentialOfferUri` (`.../api/eudi/credential-offers/{uuid}`), not as
     * a standalone field.
     *
     * @param array<string,mixed> $response The decoded `createOffer` response body.
     *
     * @return string|null The extracted offer uuid, or null when unresolvable.
     */
    private function extractOfferUuid(array $response): ?string
    {
        $uri = ($response['credentialOfferUri'] ?? null);
        if (is_string($uri) === false || $uri === '') {
            return null;
        }

        $path     = (string) parse_url($uri, PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        $uuid     = end($segments);

        if (is_string($uuid) === false || $uuid === '') {
            return null;
        }

        return $uuid;
    }//end extractOfferUuid()

    /**
     * Call openconnector's app-facing, consumer-gated offer-creation
     * endpoint.
     *
     * @param array<string,mixed> $requestBody The `createOffer` request body.
     *
     * @return array<string,mixed>|null The decoded response body, or null on failure.
     */
    private function callOpenConnectorCreateOffer(array $requestBody): ?array
    {
        $url = $this->urlGenerator->getAbsoluteURL('/index.php'.self::OPENCONNECTOR_CREATE_OFFER_PATH);

        $apiToken = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::OPENCONNECTOR_TOKEN_KEY,
            default: ''
        );

        if ($apiToken === '') {
            $this->logger->warning(
                '[WalletOfferDelegationService] No OpenConnector API token configured ('
                .'scholiq.openconnector_api_token); the wallet offer call will fail with 401.'
            );
            return null;
        }

        $requestOptions = [
            'json'    => $requestBody,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.$apiToken,
            ],
        ];

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post($url, $requestOptions);

            $body = json_decode($response->getBody(), true);
            if (is_array($body) === false) {
                $this->logger->error('[WalletOfferDelegationService] OpenConnector returned non-JSON for createOffer.');
                return null;
            }

            return $body;
        } catch (Throwable $exception) {
            $this->logger->error(
                '[WalletOfferDelegationService] OpenConnector createOffer call failed: {msg}',
                ['msg' => $exception->getMessage()]
            );
            return null;
        }//end try
    }//end callOpenConnectorCreateOffer()
}//end class
