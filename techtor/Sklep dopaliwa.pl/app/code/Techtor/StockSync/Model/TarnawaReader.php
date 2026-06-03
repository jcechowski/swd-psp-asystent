<?php

declare(strict_types=1);

namespace Techtor\StockSync\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Odczytuje dane stanow magazynowych z outputu scrapera Tarnawa.
 *
 * Struktura katalogu:
 *   TARNAWA/output/{SKU}/product.json
 *
 * Kazdy product.json zawiera m.in.:
 *   stock_quantity: int
 *   stock_status: "in-stock" | "on-backorder" | "out-of-stock"
 *   price_netto: float
 *   name: string
 */
class TarnawaReader
{
    public function __construct(
        private readonly Config $config,
        private readonly FileDriver $fileDriver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Pobierz stany wszystkich produktow Tarnawa.
     *
     * @return array<string, TarnawaProduct> SKU => TarnawaProduct
     */
    public function readAll(): array
    {
        $dir = $this->config->getTarnawaDir();

        if (!$this->fileDriver->isDirectory($dir)) {
            $this->logger->warning(sprintf('TarnawaReader: katalog nie istnieje: %s', $dir));
            return [];
        }

        $products = [];
        $entries = $this->fileDriver->readDirectory($dir);

        foreach ($entries as $entry) {
            if (!$this->fileDriver->isDirectory($entry)) {
                continue;
            }

            $productFile = $entry . '/product.json';
            if (!$this->fileDriver->isExists($productFile)) {
                continue;
            }

            try {
                $json = $this->fileDriver->fileGetContents($productFile);
                $data = json_decode($json, true);

                if (!is_array($data)) {
                    continue;
                }

                $sku = $data['sku'] ?? basename($entry);
                $products[$sku] = new TarnawaProduct(
                    sku: $sku,
                    quantity: (float) ($data['stock_quantity'] ?? $data['stockQuantity'] ?? 0),
                    status: $data['stock_status'] ?? $data['stockStatus'] ?? 'unknown',
                    priceNetto: (float) ($data['price_netto'] ?? $data['priceNetto'] ?? 0),
                    name: $data['name'] ?? '',
                    lastUpdated: $data['stock_updated'] ?? ''
                );
            } catch (\Exception $e) {
                $this->logger->debug(sprintf(
                    'TarnawaReader: blad odczytu %s: %s',
                    $productFile,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('TarnawaReader: odczytano %d produktow', count($products)));
        return $products;
    }

    /**
     * Pobierz dane jednego produktu Tarnawa.
     */
    public function readProduct(string $sku): ?TarnawaProduct
    {
        $dir = $this->config->getTarnawaDir();
        $productFile = $dir . '/' . $sku . '/product.json';

        if (!$this->fileDriver->isExists($productFile)) {
            return null;
        }

        try {
            $json = $this->fileDriver->fileGetContents($productFile);
            $data = json_decode($json, true);

            if (!is_array($data)) {
                return null;
            }

            return new TarnawaProduct(
                sku: $data['sku'] ?? $sku,
                quantity: (float) ($data['stock_quantity'] ?? $data['stockQuantity'] ?? 0),
                status: $data['stock_status'] ?? $data['stockStatus'] ?? 'unknown',
                priceNetto: (float) ($data['price_netto'] ?? $data['priceNetto'] ?? 0),
                name: $data['name'] ?? '',
                lastUpdated: $data['stock_updated'] ?? ''
            );
        } catch (\Exception $e) {
            $this->logger->error(sprintf('TarnawaReader: blad odczytu %s: %s', $sku, $e->getMessage()));
            return null;
        }
    }

    /**
     * Statystyki z ostatniego scrape'a.
     *
     * @return array<string, mixed>|null
     */
    public function getLastScrapeStats(): ?array
    {
        $statsFile = dirname($this->config->getTarnawaDir()) . '/stock_updates.json';

        if (!$this->fileDriver->isExists($statsFile)) {
            return null;
        }

        try {
            $json = $this->fileDriver->fileGetContents($statsFile);
            $data = json_decode($json, true);

            if (!is_array($data)) {
                return null;
            }

            // stock_updates.json to tablica — ostatni wpis to najswiezszy
            if (isset($data[0]) && is_array($data[0])) {
                return end($data);
            }

            return $data;
        } catch (\Exception) {
            return null;
        }
    }
}
