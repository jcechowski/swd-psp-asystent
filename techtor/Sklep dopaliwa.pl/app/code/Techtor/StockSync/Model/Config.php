<?php

declare(strict_types=1);

namespace Techtor\StockSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    private const XML_PATH_ENABLED         = 'techtor_stocksync/general/enabled';
    private const XML_PATH_TARNAWA_DIR     = 'techtor_stocksync/tarnawa/scraper_dir';
    private const XML_PATH_TARNAWA_ENABLED = 'techtor_stocksync/tarnawa/enabled';
    private const XML_PATH_SOURCE_CODE     = 'techtor_stocksync/general/source_code';
    private const XML_PATH_EXPORT_ENABLED  = 'techtor_stocksync/export/enabled';
    private const XML_PATH_EXPORT_PATH     = 'techtor_stocksync/export/json_path';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isTarnawaEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_TARNAWA_ENABLED);
    }

    public function getTarnawaDir(): string
    {
        return rtrim(
            (string) ($this->scopeConfig->getValue(self::XML_PATH_TARNAWA_DIR)
                ?: '/root/projects/projekty/techtor/Scrapery/TARNAWA/output'),
            '/'
        );
    }

    public function getSourceCode(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_SOURCE_CODE) ?: 'default');
    }

    public function isExportEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_EXPORT_ENABLED);
    }

    public function getExportPath(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PATH_EXPORT_PATH)
            ?: '/var/www/dopaliwa/pub/media/stock-data.json');
    }
}
