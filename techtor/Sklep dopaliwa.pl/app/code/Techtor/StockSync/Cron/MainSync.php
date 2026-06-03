<?php

declare(strict_types=1);

namespace Techtor\StockSync\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\StockSync\Model\Config;
use Techtor\StockSync\Model\StockDataExporter;
use Techtor\StockSync\Model\TarnawaReader;

/**
 * Glowny cron synchronizacji stanow magazynowych — codziennie o 6:00.
 *
 * Logika przeniesiona 1:1 z techtor.pl sync-stock.py:
 *
 *   stockTotal = stockFirmao + stockTarnawa
 *
 *   1. stockFirmao > 0              → qty=total, delivery="24h", in_stock=true
 *   2. stockFirmao=0, Tarnawa>0     → qty=tarnawa, delivery="48h", in_stock=true
 *   3. total=0, Tarnawa=backorder   → qty=0, backorders=true, delivery="na-zamowienie"
 *   4. total=0, Tarnawa=out-of-stock → qty=0, in_stock=false, delivery="niedostepny"
 *   5. total=0, brak w Tarnawa      → qty=0, in_stock=false, delivery="niedostepny"
 *
 * Po synchronizacji eksportuje stock-data.json dla frontendu.
 */
class MainSync
{
    /** @var array<string, array{qty: float, delivery: string, backorders: bool, in_stock: bool, firmao: float, tarnawa: float, tarnawa_status: string}> */
    private array $syncResults = [];

    public function __construct(
        private readonly FirmaoClientInterface $firmaoClient,
        private readonly TarnawaReader $tarnawaReader,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly StockDataExporter $exporter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('StockSync: wylaczony w konfiguracji');
            return;
        }

        $this->logger->info('StockSync MainSync: start');
        $this->syncResults = [];

        try {
            // 1. Pobierz stany z Firmao
            $firmaoProducts = $this->firmaoClient->getAllProducts();
            $firmaoStocks = [];
            foreach ($firmaoProducts as $fp) {
                $sku = $fp['productCode'] ?? '';
                if (!empty($sku)) {
                    $firmaoStocks[$sku] = (float) ($fp['currentStoreState'] ?? 0);
                }
            }
            $this->logger->info(sprintf('StockSync: %d SKU z Firmao', count($firmaoStocks)));

            // 2. Pobierz stany z Tarnawa (scraper output)
            $tarnawaProducts = [];
            if ($this->config->isTarnawaEnabled()) {
                $tarnawaProducts = $this->tarnawaReader->readAll();
                $this->logger->info(sprintf('StockSync: %d SKU z Tarnawa', count($tarnawaProducts)));
            }

            // 3. Zbierz unikalne SKU z obu zrodel
            $allSkus = array_unique(array_merge(
                array_keys($firmaoStocks),
                array_keys($tarnawaProducts)
            ));
            $this->logger->info(sprintf('StockSync: %d unikalnych SKU do przetworzenia', count($allSkus)));

            // 4. Przelicz stany i zaktualizuj MSI
            $sourceItems = [];
            $updated = 0;
            $skipped = 0;

            foreach ($allSkus as $sku) {
                // Sprawdz czy produkt istnieje w Magento
                if (!$this->productExists($sku)) {
                    $skipped++;
                    continue;
                }

                $firmaoStock = $firmaoStocks[$sku] ?? 0;
                $tarnawaProduct = $tarnawaProducts[$sku] ?? null;
                $tarnawaStock = $tarnawaProduct?->quantity ?? 0;
                $tarnawaStatus = $tarnawaProduct?->status ?? 'unknown';

                $totalStock = $firmaoStock + $tarnawaStock;

                // Logika dostepnosci
                if ($firmaoStock > 0) {
                    // Scenariusz 1: mamy na wlasnym magazynie → 24h
                    $result = [
                        'qty' => $totalStock,
                        'delivery' => '24h',
                        'backorders' => false,
                        'in_stock' => true,
                    ];
                } elseif ($tarnawaStock > 0) {
                    // Scenariusz 2: tylko u dostawcy → 48h
                    $result = [
                        'qty' => $tarnawaStock,
                        'delivery' => '48h',
                        'backorders' => false,
                        'in_stock' => true,
                    ];
                } elseif ($tarnawaProduct !== null && $tarnawaProduct->isOnBackorder()) {
                    // Scenariusz 3: dostawca ma na backorder
                    $result = [
                        'qty' => 0,
                        'delivery' => 'na-zamowienie',
                        'backorders' => true,
                        'in_stock' => true, // MSI: in_stock bo backorders=true
                    ];
                } else {
                    // Scenariusz 4/5: niedostepny
                    $result = [
                        'qty' => 0,
                        'delivery' => 'niedostepny',
                        'backorders' => false,
                        'in_stock' => false,
                    ];
                }

                // Zapisz do wynikow (dla eksportu)
                $this->syncResults[$sku] = array_merge($result, [
                    'firmao' => $firmaoStock,
                    'tarnawa' => $tarnawaStock,
                    'tarnawa_status' => $tarnawaStatus,
                ]);

                // Przygotuj MSI source item
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSourceCode($this->config->getSourceCode());
                $sourceItem->setSku($sku);
                $sourceItem->setQuantity($result['qty']);
                $sourceItem->setStatus(
                    $result['in_stock']
                        ? SourceItemInterface::STATUS_IN_STOCK
                        : SourceItemInterface::STATUS_OUT_OF_STOCK
                );

                $sourceItems[] = $sourceItem;
                $updated++;

                // Aktualizuj delivery_time custom attribute
                $this->updateDeliveryAttribute($sku, $result['delivery']);

                // Batch save co 100
                if (count($sourceItems) >= 100) {
                    $this->saveBatch($sourceItems);
                    $sourceItems = [];
                }
            }

            // Zapisz pozostale
            if (!empty($sourceItems)) {
                $this->saveBatch($sourceItems);
            }

            // 5. Eksportuj stock-data.json
            if ($this->config->isExportEnabled()) {
                $this->exporter->export($this->syncResults);
            }

            // Loguj podsumowanie
            $this->logSummary($updated, $skipped);

        } catch (\Exception $e) {
            $this->logger->error("StockSync MainSync error: {$e->getMessage()}");
        }

        $this->logger->info('StockSync MainSync: koniec');
    }

    /**
     * Zwroc wyniki ostatniego synca (dla CLI).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getResults(): array
    {
        return $this->syncResults;
    }

    private function productExists(string $sku): bool
    {
        try {
            $this->productRepository->get($sku);
            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    private function updateDeliveryAttribute(string $sku, string $delivery): void
    {
        try {
            $product = $this->productRepository->get($sku);
            $currentDelivery = $product->getData('delivery_time') ?? '';

            if ($currentDelivery !== $delivery) {
                $product->setCustomAttribute('delivery_time', $delivery);
                $this->productRepository->save($product);
            }
        } catch (\Exception $e) {
            // Nie przerywaj synca — delivery_time jest nice-to-have
            $this->logger->debug(sprintf('StockSync: delivery attr error SKU=%s: %s', $sku, $e->getMessage()));
        }
    }

    /**
     * @param SourceItemInterface[] $items
     */
    private function saveBatch(array $items): void
    {
        try {
            $this->sourceItemsSave->execute($items);
            $this->logger->debug(sprintf('StockSync: batch %d saved', count($items)));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('StockSync: batch save error: %s', $e->getMessage()));
        }
    }

    private function logSummary(int $updated, int $skipped): void
    {
        $deliveryCounts = ['24h' => 0, '48h' => 0, 'na-zamowienie' => 0, 'niedostepny' => 0];
        foreach ($this->syncResults as $result) {
            $d = $result['delivery'];
            $deliveryCounts[$d] = ($deliveryCounts[$d] ?? 0) + 1;
        }

        $this->logger->info(sprintf(
            'StockSync: updated=%d, skipped=%d | 24h=%d, 48h=%d, backorder=%d, niedostepny=%d',
            $updated,
            $skipped,
            $deliveryCounts['24h'],
            $deliveryCounts['48h'],
            $deliveryCounts['na-zamowienie'],
            $deliveryCounts['niedostepny']
        ));
    }
}
