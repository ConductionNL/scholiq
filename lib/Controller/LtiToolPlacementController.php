<?php

/**
 * Scholiq LTI Tool Placement Controller
 *
 * Delegates an LTI 1.3 launch to OpenConnector's `lti-13-platform` adapter.
 * Scholiq implements NO LTI protocol code (OIDC, JWT signing/verification,
 * JWKS) — this controller resolves the `LtiToolPlacement` the caller wants to
 * launch, forwards its `openconnectorDeploymentId` to OpenConnector, and
 * returns the opaque launch response (auto-submitting form / URL) unmodified.
 * It never inspects, caches, or re-derives any LTI claim (design.md D5).
 *
 * The outbound call reuses the exact `IClientService` + `IURLGenerator` +
 * `IAppConfig` bearer-token shape `DataExchangeRunHandler::callOpenConnector()`
 * already established, and the same `scholiq.openconnector_api_token` config
 * key — see {@see self::OPENCONNECTOR_LAUNCH_PATH} for the documented
 * assumption about the target endpoint's shape.
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.1
 * @spec openspec/changes/lti-tool-placement/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-oidc-launch-to-the-openconnector-adapter
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin, opaque proxy that starts an LTI Platform-role launch in OpenConnector.
 *
 * @spec openspec/changes/lti-tool-placement/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-oidc-launch-to-the-openconnector-adapter
 */
class LtiToolPlacementController extends Controller
{

    /**
     * OpenRegister register slug that owns the Scholiq schemas.
     *
     * @var string
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OpenRegister schema slug for LtiToolPlacement.
     *
     * @var string
     */
    private const PLACEMENT_SCHEMA = 'lti-tool-placement';

    /**
     * ASSUMED OpenConnector REST endpoint for Platform-role launch initiation
     * (openconnector `lti-13-platform` REQ-LTI-006).
     *
     * DOCUMENTED ASSUMPTION (mirrors DataExchangeRunHandler::OPENCONNECTOR_RUN_PATH's
     * comment style): verified against openconnector HEAD at the time this
     * class was written — the merged `lti-13-platform` adapter exposes
     * `OCA\OpenConnector\Service\Lti\LtiLaunchService::initiatePlatformLaunch()`
     * ONLY as an in-process PHP service method
     * (lib/Service/Lti/LtiLaunchService.php:370); `appinfo/routes.php` wires
     * NO route to it — the only registered `lti#*` routes cover the Tool-role
     * inbound surface (login/launch/token/agsScore/agsLineItem/nrpsMembership)
     * plus JWKS publish and admin key management. OpenConnector's own
     * REQ-LTI-006 requirement text describes this as "an internal service
     * method a consuming app calls" — it never committed to a REST surface.
     *
     * Scholiq's design.md nonetheless commits to the REST-only cross-app
     * pattern `DataExchangeRunHandler` already established, since no direct
     * PHP cross-app service injection exists anywhere in this codebase (REST
     * is the sanctioned scholiq→openconnector boundary; scholiq's
     * `composer.json`/autoloading has no dependency on OpenConnector's PHP
     * namespace). This constant therefore names the path a thin
     * OpenConnector-side REST wrapper around `initiatePlatformLaunch()`
     * would need to expose. Until that wrapper ships in the other repo, a
     * call through this constant returns HTTP 404 and `callOpenConnectorLaunch()`
     * below fails closed (`null`), which `launch()` turns into a 502. Update
     * this constant (and the request/response mapping below) once the real
     * endpoint lands.
     *
     * Assumed request body: `{"subject": string, "messageType": string}`.
     * Assumed response body: `{"formActionUrl": string, "idToken": string}`
     * — mirrors `initiatePlatformLaunch()`'s actual return shape
     * (`['formActionUrl' => ..., 'idToken' => ...]`), so once the wrapper
     * exists it can return that array unmodified.
     *
     * @var string
     */
    private const OPENCONNECTOR_LAUNCH_PATH = '/apps/openconnector/api/lti/deployments/%s/launch';

    /**
     * App-config key for the OpenConnector internal API token. Same key
     * `DataExchangeRunHandler` already uses — reused rather than adding a
     * second cross-app credential.
     *
     * @var string
     */
    private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

    /**
     * Constructor.
     *
     * @param IRequest        $request       The current request.
     * @param IUserSession    $userSession   NC user session.
     * @param ObjectService   $objectService OR object access service.
     * @param IClientService  $clientService NC HTTP client factory.
     * @param IURLGenerator   $urlGenerator  NC URL generator for internal requests.
     * @param IAppConfig      $appConfig     NC app config for token lookup.
     * @param LoggerInterface $logger        PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ObjectService $objectService,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Start an LTI launch for a placement.
     *
     * Resolves the `LtiToolPlacement`, forwards its `openconnectorDeploymentId`
     * to OpenConnector's Platform-role launch-initiation surface, and returns
     * the opaque launch response unmodified. Any authenticated caller may
     * launch a placement they can resolve — per-object visibility is
     * whatever already gates the placement/Lesson (OR RBAC), not a bespoke
     * check here.
     *
     * @param string $placementId UUID of the LtiToolPlacement to launch.
     *
     * @return JSONResponse The opaque `{formActionUrl, idToken}` launch response, or an error.
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.1
     * @spec openspec/changes/lti-tool-placement/specs/course-management/spec.md#scenario-opening-an-lti-lesson-delegates-the-launch-and-renders-the-response-opaquely
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function launch(string $placementId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $placement = $this->resolvePlacement(placementId: $placementId);
        if ($placement === null) {
            return new JSONResponse(data: ['error' => 'Placement not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $deploymentId = (string) ($placement['openconnectorDeploymentId'] ?? '');
        if ($deploymentId === '') {
            return new JSONResponse(
                data: ['error' => 'Placement has no openconnectorDeploymentId configured'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $messageType = 'LtiResourceLinkRequest';
        if (($placement['launchMode'] ?? 'resource-link') === 'deep-linking') {
            $messageType = 'LtiDeepLinkingRequest';
        }

        $launchResponse = $this->callOpenConnectorLaunch(
            deploymentId: $deploymentId,
            subject: $user->getUID(),
            messageType: $messageType
        );

        if ($launchResponse === null) {
            return new JSONResponse(
                data: ['error' => 'OpenConnector launch-initiation failed or is unavailable'],
                statusCode: Http::STATUS_BAD_GATEWAY
            );
        }

        // D5: forward the response as-is — Scholiq MUST NOT parse any LTI
        // claim from it (id_token, formActionUrl target). `launchMode` is
        // NOT an LTI claim: it is Scholiq's own placement configuration,
        // added here purely so the frontend can decide new-tab vs in-page
        // frame without a second round trip to read the LtiToolPlacement
        // object directly (a learner may not have OR read access to it).
        $launchResponse['launchMode'] = ($placement['launchMode'] ?? 'resource-link');

        return new JSONResponse(data: $launchResponse);

    }//end launch()

    /**
     * Resolve an `LtiToolPlacement` by UUID.
     *
     * @param string $placementId UUID of the placement.
     *
     * @return array<string,mixed>|null The placement data, or null if not found.
     */
    private function resolvePlacement(string $placementId): ?array
    {
        $object = $this->objectService->find(
            id: $placementId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::PLACEMENT_SCHEMA
        );

        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end resolvePlacement()

    /**
     * Call OpenConnector's (assumed, see {@see self::OPENCONNECTOR_LAUNCH_PATH})
     * Platform-role launch-initiation endpoint.
     *
     * @param string $deploymentId UUID of the `lti_deployment` in OpenConnector's register.
     * @param string $subject      The LTI `sub` claim to request — this instance's caller uid.
     * @param string $messageType  `LtiResourceLinkRequest` or `LtiDeepLinkingRequest`.
     *
     * @return array<string,mixed>|null The opaque launch response, or null on failure.
     *
     * @spec openspec/changes/lti-tool-placement/tasks.md#task-2.1
     */
    private function callOpenConnectorLaunch(string $deploymentId, string $subject, string $messageType): ?array
    {
        $path = sprintf(self::OPENCONNECTOR_LAUNCH_PATH, rawurlencode($deploymentId));
        $url  = $this->urlGenerator->getAbsoluteURL('/index.php'.$path);

        $apiToken = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::OPENCONNECTOR_TOKEN_KEY,
            default: ''
        );

        $requestOptions = [
            'json'    => [
                'subject'     => $subject,
                'messageType' => $messageType,
            ],
            'timeout' => 30,
        ];

        if ($apiToken !== '') {
            $requestOptions['headers'] = [
                'Authorization' => 'Bearer '.$apiToken,
            ];
        } else {
            $this->logger->warning(
                '[LtiToolPlacementController] No OpenConnector API token configured ('
                .'scholiq.openconnector_api_token); the launch call may fail with 401/403.'
            );
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post($url, $requestOptions);

            $body = json_decode($response->getBody(), true);
            if (is_array($body) === false) {
                $this->logger->error('[LtiToolPlacementController] OpenConnector returned non-JSON for launch.');
                return null;
            }

            return $body;
        } catch (Throwable $exception) {
            $this->logger->error(
                '[LtiToolPlacementController] OpenConnector launch call failed: {msg}',
                ['msg' => $exception->getMessage()]
            );
            return null;
        }//end try

    }//end callOpenConnectorLaunch()
}//end class
