<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AuthorizeNet\Test\Unit\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Payment;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\CreateTransactionResponse;
use net\authorize\api\contract\v1\CreditCardMaskedType;
use net\authorize\api\contract\v1\GetTransactionDetailsResponse;
use net\authorize\api\contract\v1\PaymentMaskedType;
use net\authorize\api\contract\v1\TransactionDetailsType;
use net\authorize\api\contract\v1\MessagesType;
use net\authorize\api\contract\v1\MessagesType\MessageAType;
use net\authorize\api\contract\v1\TransactionResponseType;
use net\authorize\api\contract\v1\TransactionResponseType\ErrorsAType\ErrorAType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AuthorizeNet\Model\Config;
use Zero\AuthorizeNet\Model\Standard;
use Zero\AuthorizeNet\Model\TransactionGateway;

/**
 * capture() builds an authCaptureTransaction from the Accept.js opaque data and
 * the order, and only records a transaction for an approved response; refund()
 * gives money back through Authorize.Net, voiding a payment not settled yet.
 */
#[AllowMockObjectsWithoutExpectations]
class StandardTest extends TestCase
{
    private Config&MockObject $config;
    private TransactionGateway&MockObject $gateway;
    private Standard $method;
    private ?CreateTransactionRequest $sentRequest = null;
    private ?string $sentEnvironment = null;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiLoginId')->willReturn('login');
        $this->config->method('getTransactionKey')->willReturn('txkey');
        $this->config->method('isTestMode')->willReturn(true);
        $this->gateway = $this->createMock(TransactionGateway::class);

        (new ObjectManager($this))->prepareObjectManager([
            [DirectoryHelper::class, $this->createMock(DirectoryHelper::class)],
        ]);
        $this->method = (new ObjectManager($this))->getObject(Standard::class, [
            'authNetConfig' => $this->config,
            'transactionGateway' => $this->gateway,
        ]);
    }

    public function testApprovedTransactionIsRecorded(): void
    {
        $this->respondWith($this->response('Ok', $this->transaction('60123')));
        $payment = $this->payment();
        $payment->expects($this->once())->method('setTransactionId')->with('60123');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);

        $this->method->capture($payment, 12.3);

        $txRequest = $this->sentRequest->getTransactionRequest();
        $this->assertSame(ANetEnvironment::SANDBOX, $this->sentEnvironment);
        $this->assertSame('authCaptureTransaction', $txRequest->getTransactionType());
        $this->assertSame('12.30', $txRequest->getAmount());
        $this->assertSame('COMMON.ACCEPT.INAPP.PAYMENT', $txRequest->getPayment()->getOpaqueData()->getDataDescriptor());
        $this->assertSame('nonce-value', $txRequest->getPayment()->getOpaqueData()->getDataValue());
        $this->assertSame('46282', $txRequest->getBillTo()->getZip());
        $this->assertSame('100000077', $this->sentRequest->getRefId());
        $this->assertSame('login', $this->sentRequest->getMerchantAuthentication()->getName());
    }

    public function testLiveModeUsesProductionEnvironment(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiLoginId')->willReturn('login');
        $config->method('getTransactionKey')->willReturn('txkey');
        $config->method('isTestMode')->willReturn(false);
        $method = (new ObjectManager($this))->getObject(Standard::class, [
            'authNetConfig' => $config,
            'transactionGateway' => $this->gateway,
        ]);
        $this->respondWith($this->response('Ok', $this->transaction('1')));

        $method->capture($this->payment(), 12.3);

        $this->assertSame(ANetEnvironment::PRODUCTION, $this->sentEnvironment);
    }

    public function testDeclineShowsTransactionError(): void
    {
        $transaction = $this->transaction('0');
        $error = new ErrorAType();
        $error->setErrorCode('2');
        $error->setErrorText('This transaction has been declined.');
        $transaction->setErrors([$error]);
        // The top-level result can still be Ok for a declined card.
        $this->respondWith($this->response('Ok', $transaction));
        $payment = $this->payment();
        $payment->expects($this->never())->method('setTransactionId');

        $this->expectExceptionMessage('This transaction has been declined.');
        $this->method->capture($payment, 12.3);
    }

    public function testApiErrorShowsResultMessage(): void
    {
        $this->respondWith($this->response('Error', null, 'User authentication failed due to invalid authentication values.'));

        $this->expectExceptionMessage('User authentication failed');
        $this->method->capture($this->payment(), 12.3);
    }

    public function testUnreachableGatewayIsReported(): void
    {
        $this->respondWith(null);

        $this->expectExceptionMessage('couldn\'t reach Authorize.Net');
        $this->method->capture($this->payment(), 12.3);
    }

    public function testMissingOpaqueDataIsRejectedBeforeCallingGateway(): void
    {
        $this->gateway->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->method->capture($this->payment(''), 12.3);
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiLoginId')->willReturn('');
        $method = (new ObjectManager($this))->getObject(Standard::class, [
            'authNetConfig' => $config,
            'transactionGateway' => $this->gateway,
        ]);
        $this->gateway->expects($this->never())->method('execute');

        $this->expectExceptionMessage('not fully configured');
        $method->capture($this->payment(), 12.3);
    }

    public function testAssignDataStoresOpaqueData(): void
    {
        $info = $this->createMock(InfoInterface::class);
        $info->expects($this->exactly(2))->method('setAdditionalInformation')
            ->willReturnCallback(function (string $key, string $value) use ($info) {
                $this->assertContains([$key, $value], [
                    ['authnet_data_descriptor', 'COMMON.ACCEPT.INAPP.PAYMENT'],
                    ['authnet_data_value', 'nonce'],
                ]);
                return $info;
            });
        $this->method->setInfoInstance($info);

        $this->method->assignData(new DataObject(['additional_data' => [
            'authnet_data_descriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'authnet_data_value' => 'nonce',
        ]]));
    }

    public function testRefundOfASettledPayment(): void
    {
        $sent = $this->respondInTurn([$this->response('Ok', $this->transaction('70001'))]);
        $payment = $this->refundPayment('1111');

        $this->method->refund($payment, 12.3);

        $refund = $sent[0]->getTransactionRequest();
        $this->assertSame('refundTransaction', $refund->getTransactionType());
        $this->assertSame('60123', $refund->getRefTransId());
        $this->assertSame('12.30', $refund->getAmount());
        $this->assertSame('1111', $refund->getPayment()->getCreditCard()->getCardNumber());
        $this->assertSame('70001', $payment->getTransactionId());
        $this->assertSame('60123', $payment->getParentTransactionId());
    }

    public function testAPaymentNotSettledYetIsVoidedWhole(): void
    {
        $sent = $this->respondInTurn([
            $this->response('Error', $this->failed('54', 'The referenced transaction does not meet the criteria.')),
            $this->response('Ok', $this->transaction('60123')),
        ]);
        $payment = $this->refundPayment('1111');

        $this->method->refund($payment, 12.3);

        $this->assertSame('voidTransaction', $sent[1]->getTransactionRequest()->getTransactionType());
        $this->assertSame('60123', $sent[1]->getTransactionRequest()->getRefTransId());
        $this->assertSame('60123-void', $payment->getTransactionId());
    }

    public function testPartOfAPaymentNotSettledYetIsRefused(): void
    {
        $sent = $this->respondInTurn([
            $this->response('Error', $this->failed('54', 'The referenced transaction does not meet the criteria.')),
        ]);
        $this->expectExceptionMessage('hasn\'t settled this payment yet');

        try {
            $this->method->refund($this->refundPayment('1111'), 5.0);
        } finally {
            $this->assertCount(1, $sent);
        }
    }

    public function testARefusedRefundStopsTheCreditMemo(): void
    {
        $this->respondInTurn([$this->response('Error', $this->failed('11', 'A duplicate transaction has been submitted.'))]);
        $this->expectExceptionMessage('refused the refund: A duplicate transaction');

        $this->method->refund($this->refundPayment('1111'), 12.3);
    }

    public function testTheCardIsAskedForWhenTheOrderDoesntKeepIt(): void
    {
        $sent = $this->respondInTurn([$this->response('Ok', $this->transaction('70001'))]);
        $card = new CreditCardMaskedType();
        $card->setCardNumber('XXXX4242');
        $masked = new PaymentMaskedType();
        $masked->setCreditCard($card);
        $transaction = new TransactionDetailsType();
        $transaction->setPayment($masked);
        $details = new GetTransactionDetailsResponse();
        $details->setTransaction($transaction);
        $this->gateway->expects($this->once())->method('details')->willReturn($details);

        $this->method->refund($this->refundPayment(''), 12.3);

        $this->assertSame('4242', $sent[0]->getTransactionRequest()->getPayment()->getCreditCard()->getCardNumber());
    }

    /**
     * @param CreateTransactionResponse[] $responses In the order the requests are sent
     * @return \ArrayObject<int, CreateTransactionRequest> The requests sent
     */
    private function respondInTurn(array $responses): \ArrayObject
    {
        $sent = new \ArrayObject();
        $this->gateway->method('execute')->willReturnCallback(
            function (CreateTransactionRequest $request) use ($sent, &$responses) {
                $sent[] = $request;

                return array_shift($responses);
            }
        );

        return $sent;
    }

    private function failed(string $code, string $text): TransactionResponseType
    {
        $error = new ErrorAType();
        $error->setErrorCode($code);
        $error->setErrorText($text);
        $transaction = new TransactionResponseType();
        $transaction->setResponseCode('3');
        $transaction->setErrors([$error]);

        return $transaction;
    }

    /**
     * A payment captured as transaction 60123, as a credit memo hands it over.
     */
    private function refundPayment(string $last4): Payment&MockObject
    {
        $order = $this->createStub(Order::class);
        $order->method('getBaseGrandTotal')->willReturn(12.3);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000077');
        $payment = $this->createPartialMock(Payment::class, ['getOrder', 'getAdditionalInformation']);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([[Standard::CARD_LAST4, $last4]]);
        $payment->setData('refund_transaction_id', '60123');

        return $payment;
    }

    private function respondWith(?CreateTransactionResponse $response): void
    {
        $this->gateway->method('execute')->willReturnCallback(
            function (CreateTransactionRequest $request, string $environment) use ($response) {
                $this->sentRequest = $request;
                $this->sentEnvironment = $environment;
                return $response;
            }
        );
    }

    private function response(string $resultCode, ?TransactionResponseType $transaction, string $text = 'Successful.'): CreateTransactionResponse
    {
        $message = new MessageAType();
        $message->setCode($resultCode === 'Ok' ? 'I00001' : 'E00007');
        $message->setText($text);
        $messages = new MessagesType();
        $messages->setResultCode($resultCode);
        $messages->setMessage([$message]);

        $response = new CreateTransactionResponse();
        $response->setMessages($messages);
        if ($transaction !== null) {
            $response->setTransactionResponse($transaction);
        }

        return $response;
    }

    private function transaction(string $transId): TransactionResponseType
    {
        $transaction = new TransactionResponseType();
        $transaction->setTransId($transId);
        $transaction->setResponseCode('1');

        return $transaction;
    }

    private function payment(string $dataValue = 'nonce-value'): Payment&MockObject
    {
        $address = $this->createStub(OrderAddress::class);
        $address->method('getFirstname')->willReturn('Jane');
        $address->method('getLastname')->willReturn('Doe');
        $address->method('getStreetLine')->willReturn('1 Main St');
        $address->method('getCity')->willReturn('Austin');
        $address->method('getRegionCode')->willReturn('TX');
        $address->method('getPostcode')->willReturn('46282');
        $address->method('getCountryId')->willReturn('US');

        $order = $this->createStub(Order::class);
        $order->method('getBaseGrandTotal')->willReturn(12.3);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000077');
        $order->method('getBillingAddress')->willReturn($address);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([
            ['authnet_data_descriptor', 'COMMON.ACCEPT.INAPP.PAYMENT'],
            ['authnet_data_value', $dataValue],
        ]);

        return $payment;
    }
}
