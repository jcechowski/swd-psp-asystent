<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED       = 'techtor_firmao/general/enabled';
    private const XML_PATH_API_URL       = 'techtor_firmao/general/api_url';
    private const XML_PATH_LOGIN         = 'techtor_firmao/general/login';
    private const XML_PATH_PASSWORD      = 'techtor_firmao/general/password';
    private const XML_PATH_STOCK_ENABLED = 'techtor_firmao/stock/sync_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getApiUrl(): string
    {
        return rtrim((string) $this->scopeConfig->getValue(self::XML_PATH_API_URL), '/');
    }

    public function getLogin(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_LOGIN);
    }

    public function getPassword(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_PASSWORD);
        return $this->encryptor->decrypt($encrypted);
    }

    public function isStockSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_STOCK_ENABLED);
    }
}
