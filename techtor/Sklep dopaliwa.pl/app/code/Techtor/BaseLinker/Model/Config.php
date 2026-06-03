<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED              = 'techtor_baselinker/general/enabled';
    private const XML_PATH_API_TOKEN            = 'techtor_baselinker/general/api_token';
    private const XML_PATH_INVENTORY_ID         = 'techtor_baselinker/general/inventory_id';
    private const XML_PATH_SYNC_ORDERS          = 'techtor_baselinker/orders/sync_enabled';
    private const XML_PATH_ORDER_SOURCE         = 'techtor_baselinker/orders/order_source_id';
    private const XML_PATH_NEW_ORDER_STATUS     = 'techtor_baselinker/orders/new_order_status_id';
    private const XML_PATH_SYNC_STATUSES        = 'techtor_baselinker/orders/sync_statuses';
    private const XML_PATH_STOCK_SYNC_ENABLED   = 'techtor_baselinker/stock/stock_sync_enabled';
    private const XML_PATH_STOCK_SOURCE_CODE    = 'techtor_baselinker/stock/stock_source_code';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getApiToken(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_API_TOKEN);
        return $this->encryptor->decrypt($encrypted);
    }

    public function getInventoryId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_INVENTORY_ID);
    }

    public function isOrderSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_ORDERS);
    }

    public function getOrderSourceId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_ORDER_SOURCE);
    }

    public function getDefaultNewOrderStatusId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_NEW_ORDER_STATUS);
    }

    public function isStatusSyncEnabled(): bool
    {
        return $this->isOrderSyncEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_SYNC_STATUSES);
    }

    public function isStockSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_STOCK_SYNC_ENABLED);
    }

    public function getStockSourceCode(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_STOCK_SOURCE_CODE) ?: 'default');
    }
}
