<?php

/**
 * Scholiq Payment Transaction Controller
 *
 * Two thin, opaque delegation endpoints for the payments capability:
 *
 * - initiate(orderId): outbound. Resolves the Order, computes the remaining
 *   balance server-side (never trusts a client-supplied amount), creates a
 *   `pending` PaymentTransaction, and delegates to OpenConnector's
 *   (not-yet-built) mollie-stripe-payment-adapter using the exact
 *   IClientService + IURLGenerator::getAbsoluteURL() + IAppConfig
 *   bearer-token shape LtiToolPlacementController::callOpenConnectorLaunch()
 *   and DataExchangeRunHandler::callOpenConnector() already establish, under
 *   the existing scholiq.openconnector_api_token config key — a fourth
 *   instance of this established pattern, not a new one
 *   (WalletOfferDelegationService explicitly reuses the same shape too).
 * - callback(): inbound. The FIRST OpenConnector-to-scholiq call in this
 *   codebase (every existing callOpenConnector* call is scholiq-initiated).
 *   Authenticates the caller via a SEPARATE, narrowly-scoped
 *   scholiq.openconnector_callback_token — never the outbound token reused in
 *   reverse (design.md's explicit requirement) — then drives the matching
 *   PaymentTransaction's lifecycle transition. The concrete inbound-auth
 *   mechanism is provisional: OpenConnector's actual mollie-stripe adapter
 *   does not exist yet, so this shared-secret-header check is this change's
 *   best-effort proposal, not a negotiated contract (see design.md "What is
 *   still genuinely missing").
 *
 * KNOWN GAP, documented not fabricated: PaymentTransaction is appendOnly
 * (OpenRegister rejects any UPDATE after creation — verified by reading
 * ObjectService::saveObject()/deleteObject() at HEAD, INSERT only). Neither
 * initiate() nor callback() can persist pspPaymentId or completedAt on any
 * existing PaymentTransaction row — only the lifecycle field itself can move,
 * via TransitionEngine::transition(), which takes no data payload. Both
 * fields remain declared, nullable schema properties for forward
 * compatibility but are not populated by this change. This is a structural
 * property of OpenRegister's append-only enforcement, not something scoped
 * to fix here.
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
 * @spec openspec/changes/school-payments/tasks.md#task-3.5
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin, opaque proxy for PSP payment initiation and its inbound status callback.
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol
 */
class PaymentTransactionController extends Controller
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const ORDER_SCHEMA     = 'order';
    private const PAYMENT_TRANSACTION_SCHEMA = 'payment-transaction';

    private const ORDER_STATE_OPEN           = 'open';
    private const ORDER_STATE_PARTIALLY_PAID = 'partially-paid';

    /**
     * Floating-point comparison tolerance for currency amounts (half a cent).
     *
     * @var float
     */
    private const AMOUNT_EPSILON = 0.005;

    private const PSP_PROVIDERS = ['mollie', 'stripe'];

    private const CALLBACK_STATUS_TO_ACTION = [
        'succeeded' => 'succeed',
        'failed'    => 'fail',
        'expired'   => 'expire',
        'cancelled' => 'cancel',
        'refunded'  => 'refund',
    ];

    /**
     * ASSUMED OpenConnector REST endpoint for PSP launch-initiation
     * (mirrors LtiToolPlacementController::OPENCONNECTOR_LAUNCH_PATH's
     * "documented assumption" convention). OpenConnector's own
     * mollie-stripe-payment-adapter does not exist yet at HEAD (see
     * proposal.md "Why") — this constant names the path that adapter would
     * need to expose, following the same path-shape convention as the
     * existing lti/deployments and sources endpoints. Update once the real
     * endpoint lands.
     *
     * Assumed request body: {orderId, amount, currency, pspProvider,
     * callbackReference} where callbackReference is this PaymentTransaction's
     * own scholiq-side id, echoed back on the callback() call.
     * Assumed response body: {checkoutUrl: string, pspPaymentId?: string}.
     *
     * @var string
     */
    private const OPENCONNECTOR_INITIATE_PATH = '/apps/openconnector/api/payments/initiate';

    /**
     * App-config key for the outbound OpenConnector API token. Same key
     * LtiToolPlacementController/DataExchangeRunHandler already use.
     *
     * @var string
     */
    private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

    /**
     * App-config key for the INBOUND callback shared secret. Deliberately
     * separate from OPENCONNECTOR_TOKEN_KEY — design.md requires callback()
     * to use its own documented authentication mechanism, not the outbound
     * token reused in reverse (a shared bearer token used bidirectionally
     * would let anything holding it forge either side's calls).
     *
     * @var string
     */
    private const OPENCONNECTOR_CALLBACK_TOKEN_KEY = 'openconnector_callback_token';

    /**
     * Constructor.
     *
     * @param IRequest         $request          The current request.
     * @param IUserSession     $userSession      NC user session.
     * @param ObjectService    $objectService    OR object access service.
     * @param TransitionEngine $transitionEngine OR lifecycle engine.
     * @param IClientService   $clientService    NC HTTP client factory.
     * @param IURLGenerator    $urlGenerator     NC URL generator for internal requests.
     * @param IAppConfig       $appConfig        NC app config for token lookup.
     * @param LoggerInterface  $logger           PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Initiate payment for an open/partially-paid Order.
     *
     * Computes the amount owed server-side from the Order's own totalAmount
     * minus already-succeeded PaymentTransactions — never from a client
     * payload — creates a `pending` PaymentTransaction, and delegates to
     * OpenConnector. On success the PaymentTransaction moves to
     * `awaiting-redirect` and OpenConnector's opaque checkout reference is
     * forwarded to the frontend unmodified (no PSP-specific field is
     * inspected). On failure the PaymentTransaction moves to `failed` and a
     * 502 is returned.
     *
     * @param string $orderId     UUID of the Order to pay.
     * @param string $pspProvider Which PSP to route through ("mollie" or "stripe").
     *
     * @return JSONResponse The opaque checkout reference, or an error.
     *
     * @spec openspec/changes/school-payments/tasks.md#task-3.5
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-initiating-payment-delegates-to-openconnector-and-returns-an-opaque-checkout-reference
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function initiate(string $orderId, string $pspProvider=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if (in_array($pspProvider, self::PSP_PROVIDERS, true) === false) {
            return new JSONResponse(
                data: ['error' => 'pspProvider must be one of: '.implode(', ', self::PSP_PROVIDERS)],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $order = $this->resolveOrder(orderId: $orderId);
        if ($order === null) {
            return new JSONResponse(data: ['error' => 'Order not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $orderLifecycle = $order['lifecycle'] ?? '';
        if ($orderLifecycle !== self::ORDER_STATE_OPEN && $orderLifecycle !== self::ORDER_STATE_PARTIALLY_PAID) {
            return new JSONResponse(
                data: ['error' => 'Order is not open for payment'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $totalAmount      = (float) ($order['totalAmount'] ?? 0);
        $alreadySucceeded = $this->sumSucceededTransactions(orderId: $orderId);
        $remaining        = $totalAmount - $alreadySucceeded;

        if ($remaining <= self::AMOUNT_EPSILON) {
            return new JSONResponse(
                data: ['error' => 'Order is already fully paid'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $now = new DateTimeImmutable();

        $saved = $this->objectService->saveObject(
            object: [
                'orderId'     => $orderId,
                'pspProvider' => $pspProvider,
                'amount'      => $remaining,
                'currency'    => (string) ($order['currency'] ?? 'EUR'),
                'initiatedBy' => $user->getUID(),
                'initiatedAt' => $now->format(DATE_ATOM),
            ],
            register: self::SCHOLIQ_REGISTER,
            schema: self::PAYMENT_TRANSACTION_SCHEMA
        );

        $savedData = $saved;
        if (is_array($saved) === false) {
            $savedData = $saved->jsonSerialize();
        }

        $transactionId = $savedData['id'] ?? ($savedData['uuid'] ?? null);

        if ($transactionId === null) {
            $this->logger->error('[PaymentTransactionController] PaymentTransaction creation returned no id — cannot proceed.');
            return new JSONResponse(
                data: ['error' => 'Failed to create PaymentTransaction'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $transactionId = (string) $transactionId;

        $launchResponse = $this->callOpenConnectorInitiate(
            paymentTransactionId: $transactionId,
            orderId: $orderId,
            amount: $remaining,
            currency: (string) ($order['currency'] ?? 'EUR'),
            pspProvider: $pspProvider
        );

        if ($launchResponse === null) {
            $this->transitionEngine->transition($transactionId, 'fail');
            return new JSONResponse(
                data: ['error' => 'OpenConnector payment-initiation failed or is unavailable'],
                statusCode: Http::STATUS_BAD_GATEWAY
            );
        }

        $this->transitionEngine->transition($transactionId, 'initiate');

        // Forward the response as-is (LtiToolPlacementController's D5 rule) —
        // Scholiq MUST NOT parse any PSP-specific claim from it.
        $launchResponse['paymentTransactionId'] = $transactionId;

        return new JSONResponse(data: $launchResponse);

    }//end initiate()

    /**
     * Receive a status update from OpenConnector's PSP adapter.
     *
     * Authenticates the caller via the dedicated
     * scholiq.openconnector_callback_token (never the outbound token reused
     * in reverse), then drives the matching PaymentTransaction's lifecycle
     * transition. Does not persist pspPaymentId/completedAt — see this
     * class's own docblock "KNOWN GAP" note.
     *
     * @return JSONResponse Empty success body, or an error.
     *
     * @spec openspec/changes/school-payments/tasks.md#task-3.5
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function callback(): JSONResponse
    {
        if ($this->isAuthenticCallback() === false) {
            return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $paymentTransactionId = (string) $this->request->getParam('paymentTransactionId', '');
        $status = (string) $this->request->getParam('status', '');

        if ($paymentTransactionId === '' || $status === '') {
            return new JSONResponse(
                data: ['error' => 'paymentTransactionId and status are required'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if (array_key_exists($status, self::CALLBACK_STATUS_TO_ACTION) === false) {
            return new JSONResponse(
                data: ['error' => 'Unknown status: '.$status],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $transaction = $this->objectService->find(
            id: $paymentTransactionId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::PAYMENT_TRANSACTION_SCHEMA
        );

        if ($transaction === null) {
            return new JSONResponse(data: ['error' => 'PaymentTransaction not found'], statusCode: Http::STATUS_NOT_FOUND);
        }

        $action = self::CALLBACK_STATUS_TO_ACTION[$status];

        try {
            $this->transitionEngine->transition($paymentTransactionId, $action);
        } catch (Throwable $exception) {
            $this->logger->warning(
                '[PaymentTransactionController] callback() could not apply transition {action} to PaymentTransaction {id}: {msg}',
                ['action' => $action, 'id' => $paymentTransactionId, 'msg' => $exception->getMessage()]
            );
            return new JSONResponse(
                data: ['error' => 'Transition not allowed from current state'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse(data: []);

    }//end callback()

    /**
     * Authenticate an inbound callback() call against the dedicated
     * callback shared secret.
     *
     * @return bool True when the Authorization header matches the configured
     *              scholiq.openconnector_callback_token.
     */
    private function isAuthenticCallback(): bool
    {
        $expectedToken = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::OPENCONNECTOR_CALLBACK_TOKEN_KEY,
            default: ''
        );

        if ($expectedToken === '') {
            $this->logger->warning(
                '[PaymentTransactionController] No callback token configured'
                .' (scholiq.openconnector_callback_token) — refusing every callback until one is set.'
            );
            return false;
        }

        $authHeader = (string) $this->request->getHeader('Authorization');

        return hash_equals('Bearer '.$expectedToken, $authHeader);

    }//end isAuthenticCallback()

    /**
     * Resolve an Order by UUID.
     *
     * @param string $orderId UUID of the Order.
     *
     * @return array<string,mixed>|null The Order data, or null if not found.
     */
    private function resolveOrder(string $orderId): ?array
    {
        $object = $this->objectService->find(
            id: $orderId,
            register: self::SCHOLIQ_REGISTER,
            schema: self::ORDER_SCHEMA
        );

        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        return $object->jsonSerialize();

    }//end resolveOrder()

    /**
     * Sum every `succeeded` PaymentTransaction.amount for the given Order.
     *
     * @param string $orderId UUID of the Order.
     *
     * @return float The sum of succeeded amounts.
     */
    private function sumSucceededTransactions(string $orderId): float
    {
        $transactions = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::PAYMENT_TRANSACTION_SCHEMA,
                'filters'  => [
                    'orderId'   => $orderId,
                    'lifecycle' => 'succeeded',
                ],
            ]
        );

        $sum = 0.0;
        foreach ($transactions as $transaction) {
            if (is_array($transaction) === false) {
                $transaction = $transaction->jsonSerialize();
            }

            $sum += (float) ($transaction['amount'] ?? 0);
        }

        return $sum;

    }//end sumSucceededTransactions()

    /**
     * Call OpenConnector's (assumed, see {@see self::OPENCONNECTOR_INITIATE_PATH})
     * PSP launch-initiation endpoint.
     *
     * @param string $paymentTransactionId UUID of the newly-created PaymentTransaction —
     *                                     sent as the callback reference.
     * @param string $orderId              UUID of the Order being paid.
     * @param float  $amount               Amount to charge.
     * @param string $currency             ISO 4217 currency code.
     * @param string $pspProvider          "mollie" or "stripe".
     *
     * @return array<string,mixed>|null The opaque launch response, or null on failure.
     *
     * @spec openspec/changes/school-payments/tasks.md#task-3.5
     */
    private function callOpenConnectorInitiate(
        string $paymentTransactionId,
        string $orderId,
        float $amount,
        string $currency,
        string $pspProvider
    ): ?array {
        $url = $this->urlGenerator->getAbsoluteURL('/index.php'.self::OPENCONNECTOR_INITIATE_PATH);

        $apiToken = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: self::OPENCONNECTOR_TOKEN_KEY,
            default: ''
        );

        $requestOptions = [
            'json'    => [
                'orderId'           => $orderId,
                'amount'            => $amount,
                'currency'          => $currency,
                'pspProvider'       => $pspProvider,
                'callbackReference' => $paymentTransactionId,
            ],
            'timeout' => 30,
        ];

        if ($apiToken !== '') {
            $requestOptions['headers'] = [
                'Authorization' => 'Bearer '.$apiToken,
            ];
        } else {
            $this->logger->warning(
                '[PaymentTransactionController] No OpenConnector API token configured ('
                .'scholiq.openconnector_api_token); the initiate call may fail with 401/403.'
            );
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post($url, $requestOptions);

            $body = json_decode($response->getBody(), true);
            if (is_array($body) === false) {
                $this->logger->error('[PaymentTransactionController] OpenConnector returned non-JSON for initiate.');
                return null;
            }

            return $body;
        } catch (Throwable $exception) {
            $this->logger->error(
                '[PaymentTransactionController] OpenConnector initiate call failed: {msg}',
                ['msg' => $exception->getMessage()]
            );
            return null;
        }//end try

    }//end callOpenConnectorInitiate()
}//end class
