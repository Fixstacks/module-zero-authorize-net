<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AuthorizeNet\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\AuthorizeNet\Model\Config;
use Zero\Checkout\ViewModel\Checkout\CheckoutPage;

/**
 * What Zero Checkout's Authorize.Net card form needs: the two public values
 * Accept.js tokenizes with (API Login ID, Client Key) and which Accept.js
 * host to load. The Transaction Key stays server-side (Model\Config).
 */
class CheckoutConfig implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutPage $checkoutPage,
        private readonly CheckoutSession $checkoutSession,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{apiLoginId: string, clientKey: string, testMode: bool}|null null when not offered
     */
    public function getClientConfig(): ?array
    {
        if (!$this->checkoutPage->isPaymentMethodAvailable('zero_authorizenet')) {
            return null;
        }
        $storeId = (int) $this->checkoutSession->getQuote()->getStoreId();
        $apiLoginId = $this->config->getApiLoginId($storeId);
        $clientKey = $this->config->getClientKey($storeId);
        if ($apiLoginId === '' || $clientKey === '') {
            return null;
        }

        return ['apiLoginId' => $apiLoginId, 'clientKey' => $clientKey, 'testMode' => $this->config->isTestMode($storeId)];
    }
}
