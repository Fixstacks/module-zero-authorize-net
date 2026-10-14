<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AuthorizeNet\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Authorize.Net settings (Stores > Configuration > Sales > Payment
 * Methods > Authorize.Net): test/live mode and the matching
 * credentials. The Transaction Key is stored encrypted and is
 * server-only; the API Login ID and Client Key are what Accept.js needs
 * in the browser.
 */
class Config
{
    private const PREFIX = 'payment/zero_authorizenet/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTestMode(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'test_mode', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiLoginId(int $storeId): string
    {
        return $this->getModeValue('api_login_id', $storeId);
    }

    public function getClientKey(int $storeId): string
    {
        return $this->getModeValue('client_key', $storeId);
    }

    /**
     * Decrypted Transaction Key for the active mode, '' when not configured.
     */
    public function getTransactionKey(int $storeId): string
    {
        $encrypted = $this->getModeValue('transaction_key', $storeId);

        return $encrypted !== '' ? $this->encryptor->decrypt($encrypted) : '';
    }

    private function getModeValue(string $field, int $storeId): string
    {
        $path = self::PREFIX . ($this->isTestMode($storeId) ? 'test_' : 'live_') . $field;

        return (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
