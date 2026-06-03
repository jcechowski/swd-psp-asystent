<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED           = 'techtor_firmao/general/enabled';
    private const XML_PATH_API_URL           = 'techtor_firmao/general/api_url';
    private const XML_PATH_LOGIN             = 'techtor_firmao/general/login';
    private const XML_PATH_PASSWORD          = 'techtor_firmao/general/password';
    private const XML_PATH_WAREHOUSE_ID      = 'techtor_firmao/general/warehouse_id';
    private const XML_PATH_STOCK_ENABLED     = 'techtor_firmao/stock/sync_enabled';
    private const XML_PATH_PRODUCTS_ENABLED  = 'techtor_firmao/products/sync_enabled';
    private const XML_PATH_PRODUCTS_CREATE   = 'techtor_firmao/products/auto_create';
    private const XML_PATH_PRICES_ENABLED    = 'techtor_firmao/prices/sync_enabled';
    private const XML_PATH_PRICE_GROUP       = 'techtor_firmao/prices/sale_price_group';
    private const XML_PATH_DEFAULT_VAT       = 'techtor_firmao/prices/default_vat';
    private const XML_PATH_ORDER_SYNC        = 'techtor_firmao/orders/sync_enabled';

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

    public function getWarehouseId(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_PATH_WAREHOUSE_ID) ?: 1);
    }

    public function isStockSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_STOCK_ENABLED);
    }

    public function isProductSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_PRODUCTS_ENABLED);
    }

    public function isAutoCreateProducts(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PRODUCTS_CREATE);
    }

    public function isPriceSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_PRICES_ENABLED);
    }

    public function getSalePriceGroup(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_PRICE_GROUP) ?: 'A');
    }

    public function getDefaultVatRate(): float
    {
        return (float) ($this->scopeConfig->getValue(self::XML_PATH_DEFAULT_VAT) ?: 23);
    }

    public function isOrderSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_ORDER_SYNC);
    }
}
