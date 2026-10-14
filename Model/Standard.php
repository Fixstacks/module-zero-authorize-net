<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AuthorizeNet\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\CreateTransactionRequest;
use net\authorize\api\contract\v1\CreditCardType;
use net\authorize\api\contract\v1\CustomerAddressType;
use net\authorize\api\contract\v1\GetTransactionDetailsRequest;
use net\authorize\api\contract\v1\MerchantAuthenticationType;
use net\authorize\api\contract\v1\OpaqueDataType;
use net\authorize\api\contract\v1\PaymentType;
use net\authorize\api\contract\v1\TransactionRequestType;

/**
 * Authorize.Net card payment (zero_authorizenet). Accept.js turns the card
 * fields into a short-lived opaqueData token in the browser; capture() performs
 * the actual charge here (createTransactionRequest, authCaptureTransaction)
 * with that token and the order's own total — same shape as Braintree, unlike
 * Stripe where the charge happens client-side.
 */
class Standard extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_code = 'zero_authorizenet';

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /** The payment's card, its last four digits ("1111"): a refund names it. */
    public const CARD_LAST4 = 'authnet_card_last4';

    /** Authorize.Net's error for a refund of a payment not settled yet. */
    private const NOT_SETTLED = '54';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param PaymentData $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param Config $authNetConfig
     * @param TransactionGateway $transactionGateway
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Config $authNetConfig,
        private readonly TransactionGateway $transactionGateway,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * Copies the opaqueData token from additional_data into the payment's
     * additional information (the parent only dispatches an event).
     *
     * @return $this
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData('additional_data');
        if (is_array($additionalData)) {
            foreach (['authnet_data_descriptor', 'authnet_data_value'] as $key) {
                if (isset($additionalData[$key]) && $additionalData[$key] !== '') {
                    $this->getInfoInstance()->setAdditionalInformation($key, (string) $additionalData[$key]);
                }
            }
        }

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        /** @var Order $order */
        $order = $payment->getOrder();

        $dataDescriptor = (string) $payment->getAdditionalInformation('authnet_data_descriptor');
        $dataValue = (string) $payment->getAdditionalInformation('authnet_data_value');
        if ($dataDescriptor === '' || $dataValue === '') {
            throw new LocalizedException(
                __('Your card payment could not be verified. Please try again.')
            );
        }

        $storeId = (int) $order->getStoreId();
        $apiLoginId = $this->authNetConfig->getApiLoginId($storeId);
        $transactionKey = $this->authNetConfig->getTransactionKey($storeId);
        if ($apiLoginId === '' || $transactionKey === '') {
            throw new LocalizedException(
                __('Authorize.Net is not fully configured — an API Login ID and Transaction Key are required.')
            );
        }

        $merchantAuthentication = $this->merchantAuthentication($apiLoginId, $transactionKey);

        $opaqueData = new OpaqueDataType();
        $opaqueData->setDataDescriptor($dataDescriptor);
        $opaqueData->setDataValue($dataValue);

        $paymentType = new PaymentType();
        $paymentType->setOpaqueData($opaqueData);

        $transactionRequestType = new TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');
        $transactionRequestType->setAmount($this->formatAmount((float) $order->getBaseGrandTotal()));
        $transactionRequestType->setPayment($paymentType);

        // Send billTo: it's standard AVS fraud data, and the sandbox's AVS decline
        // trigger (billing ZIP 46282) needs it.
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress !== null) {
            $billTo = new CustomerAddressType();
            $billTo->setFirstName((string) $billingAddress->getFirstname());
            $billTo->setLastName((string) $billingAddress->getLastname());
            $billTo->setAddress((string) $billingAddress->getStreetLine(1));
            $billTo->setCity((string) $billingAddress->getCity());
            $billTo->setState((string) $billingAddress->getRegionCode());
            $billTo->setZip((string) $billingAddress->getPostcode());
            $billTo->setCountry((string) $billingAddress->getCountryId());
            $transactionRequestType->setBillTo($billTo);
        }

        $request = new CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId((string) $order->getIncrementId());
        $request->setTransactionRequest($transactionRequestType);

        $environment = $this->environment($storeId);

        $response = $this->transactionGateway->execute($request, $environment);

        if ($response === null || $response->getMessages() === null) {
            throw new LocalizedException(
                __('We couldn\'t reach Authorize.Net to process your card. Please try again.')
            );
        }

        $transactionResponse = $response->getTransactionResponse();

        // The top-level result can be "Ok" while the transaction itself declined —
        // the decline is on the transaction response's own error list.
        if ($transactionResponse !== null && $transactionResponse->getErrors()) {
            $firstError = $transactionResponse->getErrors()[0];
            throw new LocalizedException(
                __('Your card payment could not be completed: %1', $firstError->getErrorText())
            );
        }

        if ($response->getMessages()->getResultCode() !== 'Ok' || $transactionResponse === null) {
            $messages = $response->getMessages()->getMessage();
            $reason = $messages && count($messages) > 0 ? $messages[0]->getText() : (string) __('Unknown error.');
            throw new LocalizedException(
                __('Your card payment could not be completed: %1', $reason)
            );
        }

        $payment->setTransactionId($transactionResponse->getTransId());
        $payment->setIsTransactionClosed(true);
        $last4 = substr(preg_replace('/\D/', '', (string) $transactionResponse->getAccountNumber()), -4);
        if ($last4 !== '') {
            $payment->setAdditionalInformation(self::CARD_LAST4, $last4);
        }

        return $this;
    }

    /**
     * Refunds a credit memo on the card. An unsettled payment (Authorize.Net
     * settles daily) can only be voided whole, so a partial refund is refused
     * until it settles.
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $transId = (string) ($payment->getRefundTransactionId() ?: $payment->getParentTransactionId()
            ?: $payment->getLastTransId());
        $transId = preg_replace('/-(capture|void|refund)$/', '', $transId);
        $apiLoginId = $this->authNetConfig->getApiLoginId($storeId);
        $transactionKey = $this->authNetConfig->getTransactionKey($storeId);
        if ($transId === '' || $apiLoginId === '' || $transactionKey === '') {
            throw new LocalizedException(__('This order has no Authorize.Net payment to refund.'));
        }
        $auth = $this->merchantAuthentication($apiLoginId, $transactionKey);
        $environment = $this->environment($storeId);

        $card = new CreditCardType();
        $card->setCardNumber($this->cardLast4($payment, $auth, $transId, $environment));
        $card->setExpirationDate('XXXX');
        $paymentType = new PaymentType();
        $paymentType->setCreditCard($card);
        $refund = new TransactionRequestType();
        $refund->setTransactionType('refundTransaction');
        $refund->setAmount($this->formatAmount((float) $amount));
        $refund->setRefTransId($transId);
        $refund->setPayment($paymentType);

        $refId = (string) $order->getIncrementId();
        [$resultTransId, $errorCode, $error] = $this->send($auth, $refund, $refId, $environment);
        if ($error !== null && $errorCode === self::NOT_SETTLED) {
            if (abs((float) $amount - (float) $order->getBaseGrandTotal()) > 0.001) {
                throw new LocalizedException(__(
                    'Authorize.Net hasn\'t settled this payment yet, so only all of it can be given back now '
                    . '(the payment is voided). Refund part of it after it settles, within a day.'
                ));
            }
            $void = new TransactionRequestType();
            $void->setTransactionType('voidTransaction');
            $void->setRefTransId($transId);
            [$resultTransId, , $error] = $this->send($auth, $void, $refId, $environment);
            $resultTransId = $error === null ? $transId . '-void' : $resultTransId;
        }
        if ($error !== null) {
            throw new LocalizedException(__('Authorize.Net refused the refund: %1', $error));
        }

        $payment->setTransactionId($resultTransId !== '' ? $resultTransId : $transId . '-refund');
        $payment->setParentTransactionId($transId);
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(true);

        return $this;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null} The transaction id, the error code and text (null: done)
     */
    private function send(
        MerchantAuthenticationType $auth,
        TransactionRequestType $transaction,
        string $refId,
        string $environment
    ): array {
        $request = new CreateTransactionRequest();
        $request->setMerchantAuthentication($auth);
        $request->setRefId($refId);
        $request->setTransactionRequest($transaction);
        $response = $this->transactionGateway->execute($request, $environment);
        if ($response === null || $response->getMessages() === null) {
            return ['', '', (string) __('Authorize.Net is not answering. Please try again.')];
        }
        $transactionResponse = $response->getTransactionResponse();
        if ($transactionResponse !== null && $transactionResponse->getErrors()) {
            $first = $transactionResponse->getErrors()[0];

            return ['', (string) $first->getErrorCode(), (string) $first->getErrorText()];
        }
        if ($response->getMessages()->getResultCode() !== 'Ok' || $transactionResponse === null) {
            $messages = $response->getMessages()->getMessage();

            return ['', '', $messages ? (string) $messages[0]->getText() : (string) __('Unknown error.')];
        }

        return [(string) $transactionResponse->getTransId(), '', null];
    }

    /**
     * The card's last four digits: kept with the payment since this was added, asked
     * from Authorize.Net (the transaction's details) for payments made before.
     *
     * @throws LocalizedException
     */
    private function cardLast4(
        InfoInterface $payment,
        MerchantAuthenticationType $auth,
        string $transId,
        string $environment
    ): string {
        $last4 = (string) $payment->getAdditionalInformation(self::CARD_LAST4);
        if ($last4 !== '') {
            return $last4;
        }
        $request = new GetTransactionDetailsRequest();
        $request->setMerchantAuthentication($auth);
        $request->setTransId($transId);
        $details = $this->transactionGateway->details($request, $environment);
        $number = (string) $details?->getTransaction()?->getPayment()?->getCreditCard()?->getCardNumber();
        $last4 = substr(preg_replace('/\D/', '', $number), -4);
        if ($last4 === '') {
            throw new LocalizedException(
                __('Authorize.Net didn\'t say which card paid. Refund it in the Authorize.Net account.')
            );
        }

        return $last4;
    }

    private function environment(int $storeId): string
    {
        return $this->authNetConfig->isTestMode($storeId) ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
    }

    private function merchantAuthentication(string $apiLoginId, string $transactionKey): MerchantAuthenticationType
    {
        $auth = new MerchantAuthenticationType();
        $auth->setName($apiLoginId);
        $auth->setTransactionKey($transactionKey);

        return $auth;
    }

    /**
     * Authorize.Net's own NVP-style amount format — a plain
     * "1234.56" string, matching `Zero\Paypal\Model\Standard::
     * formatAmount()`'s own established convention for this exact
     * class of API.
     *
     * @param float $amount
     * @return string
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @inheritDoc
     */
    public function validate(): self
    {
        return $this;
    }
}
