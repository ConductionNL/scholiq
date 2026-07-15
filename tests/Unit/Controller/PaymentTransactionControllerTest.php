<?php

/**
 * Scholiq PaymentTransactionController unit tests.
 *
 * Covers initiate() against a mocked OpenConnector response (success +
 * 502-on-failure, mirroring LtiToolPlacementControllerTest's pattern) and
 * callback() against a synthetic payload, including the dedicated
 * callback-token authentication.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-initiating-payment-delegates-to-openconnector-and-returns-an-opaque-checkout-reference
 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\PaymentTransactionController;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for PaymentTransactionController::initiate() and ::callback().
 */
class PaymentTransactionControllerTest extends TestCase
{

    /**
     * ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * TransitionEngine mock.
     *
     * @var TransitionEngine&MockObject
     */
    private TransitionEngine&MockObject $transitionEngine;

    /**
     * User-session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * HTTP client-service mock.
     *
     * @var IClientService&MockObject
     */
    private IClientService&MockObject $clientService;

    /**
     * URL generator mock.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator&MockObject $urlGenerator;

    /**
     * App-config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Build fresh mocks for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->transitionEngine = $this->createMock(TransitionEngine::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->clientService    = $this->createMock(IClientService::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);
        $this->appConfig        = $this->createMock(IAppConfig::class);
        $this->request          = $this->createMock(IRequest::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return PaymentTransactionController
     */
    private function controller(): PaymentTransactionController
    {
        return new PaymentTransactionController(
            request: $this->request,
            userSession: $this->userSession,
            objectService: $this->objectService,
            transitionEngine: $this->transitionEngine,
            clientService: $this->clientService,
            urlGenerator: $this->urlGenerator,
            appConfig: $this->appConfig,
            logger: new NullLogger()
        );

    }//end controller()

    /**
     * Sign the caller in as the given uid.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signInAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end signInAs()

    /**
     * A valid initiate() call creates a pending PaymentTransaction, delegates
     * to OpenConnector, and returns its response unmodified plus the new
     * PaymentTransaction id.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-initiating-payment-delegates-to-openconnector-and-returns-an-opaque-checkout-reference
     */
    public function testInitiateSuccessDelegatesAndReturnsOpaqueResponse(): void
    {
        $this->signInAs('payer-1');

        $this->objectService->method('find')->willReturn(
            ['id' => 'order-1', 'lifecycle' => 'open', 'totalAmount' => 50.00, 'currency' => 'EUR']
        );
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('saveObject')->willReturn(['id' => 'txn-1']);

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );
        $this->appConfig->method('getValueString')->willReturn('outbound-token');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['checkoutUrl' => 'https://psp.example/checkout/abc']));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->transitionEngine->expects($this->once())->method('transition')->with('txn-1', 'initiate');

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame('https://psp.example/checkout/abc', $result->getData()['checkoutUrl']);
        self::assertSame('txn-1', $result->getData()['paymentTransactionId']);

    }//end testInitiateSuccessDelegatesAndReturnsOpaqueResponse()

    /**
     * OpenConnector unreachable: the PaymentTransaction moves to `failed`
     * and a 502 is returned.
     *
     * @return void
     */
    public function testInitiateOpenConnectorFailureReturns502AndFailsTransaction(): void
    {
        $this->signInAs('payer-1');

        $this->objectService->method('find')->willReturn(
            ['id' => 'order-1', 'lifecycle' => 'open', 'totalAmount' => 50.00, 'currency' => 'EUR']
        );
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('saveObject')->willReturn(['id' => 'txn-1']);

        $this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => 'https://scholiq.example'.$path
        );
        $this->appConfig->method('getValueString')->willReturn('outbound-token');

        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $this->transitionEngine->expects($this->once())->method('transition')->with('txn-1', 'fail');

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_BAD_GATEWAY, $result->getStatus());
        self::assertArrayHasKey('error', $result->getData());

    }//end testInitiateOpenConnectorFailureReturns502AndFailsTransaction()

    /**
     * An unauthenticated caller receives 401.
     *
     * @return void
     */
    public function testInitiateRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->objectService->expects($this->never())->method('find');

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testInitiateRequiresAuthentication()

    /**
     * An invalid pspProvider is refused.
     *
     * @return void
     */
    public function testInitiateRejectsInvalidPspProvider(): void
    {
        $this->signInAs('payer-1');

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'paypal');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testInitiateRejectsInvalidPspProvider()

    /**
     * A missing Order returns 404.
     *
     * @return void
     */
    public function testInitiateReturnsNotFoundForUnknownOrder(): void
    {
        $this->signInAs('payer-1');
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller()->initiate(orderId: 'missing-order', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testInitiateReturnsNotFoundForUnknownOrder()

    /**
     * An Order not in open/partially-paid state is refused.
     *
     * @return void
     */
    public function testInitiateRefusesOrderNotOpenForPayment(): void
    {
        $this->signInAs('payer-1');
        $this->objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'draft', 'totalAmount' => 50.00]);

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testInitiateRefusesOrderNotOpenForPayment()

    /**
     * An Order already fully paid (sum of succeeded transactions >= totalAmount) is refused.
     *
     * @return void
     */
    public function testInitiateRefusesAlreadyFullyPaidOrder(): void
    {
        $this->signInAs('payer-1');
        $this->objectService->method('find')->willReturn(['id' => 'order-1', 'lifecycle' => 'open', 'totalAmount' => 50.00]);
        $this->objectService->method('findAll')->willReturn(
            [['id' => 'txn-old', 'orderId' => 'order-1', 'lifecycle' => 'succeeded', 'amount' => 50.00]]
        );

        $result = $this->controller()->initiate(orderId: 'order-1', pspProvider: 'mollie');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testInitiateRefusesAlreadyFullyPaidOrder()

    /**
     * A valid callback() call with the correct token drives the mapped transition.
     *
     * @return void
     *
     * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-an-inbound-status-callback-updates-the-paymenttransaction-and-rolls-up-to-the-order
     */
    public function testCallbackWithValidTokenDrivesTransition(): void
    {
        $this->appConfig->method('getValueString')->willReturn('callback-secret');
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer callback-secret');
        $this->request->method('getParam')->willReturnMap(
            [
                ['paymentTransactionId', '', 'txn-1'],
                ['status', '', 'succeeded'],
            ]
        );

        $this->objectService->method('find')->willReturn(['id' => 'txn-1', 'lifecycle' => 'awaiting-redirect']);

        $this->transitionEngine->expects($this->once())->method('transition')->with('txn-1', 'succeed');

        $result = $this->controller()->callback();

        self::assertSame(Http::STATUS_OK, $result->getStatus());

    }//end testCallbackWithValidTokenDrivesTransition()

    /**
     * Callback() with a missing/wrong token is refused with 401, and no
     * transition is attempted.
     *
     * @return void
     */
    public function testCallbackWithInvalidTokenRefused(): void
    {
        $this->appConfig->method('getValueString')->willReturn('callback-secret');
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer wrong-token');

        $this->transitionEngine->expects($this->never())->method('transition');

        $result = $this->controller()->callback();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testCallbackWithInvalidTokenRefused()

    /**
     * Callback() with an unconfigured callback token refuses every call
     * (fail closed).
     *
     * @return void
     */
    public function testCallbackFailsClosedWhenNoTokenConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->request->method('getHeader')->willReturn('Bearer anything');

        $result = $this->controller()->callback();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testCallbackFailsClosedWhenNoTokenConfigured()

    /**
     * Callback() with an unknown status value is refused.
     *
     * @return void
     */
    public function testCallbackRejectsUnknownStatus(): void
    {
        $this->appConfig->method('getValueString')->willReturn('callback-secret');
        $this->request->method('getHeader')->willReturn('Bearer callback-secret');
        $this->request->method('getParam')->willReturnMap(
            [
                ['paymentTransactionId', '', 'txn-1'],
                ['status', '', 'not-a-real-status'],
            ]
        );

        $result = $this->controller()->callback();

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result->getStatus());

    }//end testCallbackRejectsUnknownStatus()

    /**
     * Callback() for an unknown PaymentTransaction id returns 404.
     *
     * @return void
     */
    public function testCallbackReturnsNotFoundForUnknownTransaction(): void
    {
        $this->appConfig->method('getValueString')->willReturn('callback-secret');
        $this->request->method('getHeader')->willReturn('Bearer callback-secret');
        $this->request->method('getParam')->willReturnMap(
            [
                ['paymentTransactionId', '', 'missing-txn'],
                ['status', '', 'succeeded'],
            ]
        );
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller()->callback();

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());

    }//end testCallbackReturnsNotFoundForUnknownTransaction()
}//end class
