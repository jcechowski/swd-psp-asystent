<?php

declare(strict_types=1);

namespace Techtor\StockSync\Cron;

use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Model\Config as BlConfig;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config as FirmaoConfig;

/**
 * Glowny cron synchronizacji stanow magazynowych.
 *
 * Logika (przeniesiona z techtor.pl sync-stock.py):
 *   stockTotal = stockFirmao + stockTarnawa
 *   - stockTotal == 0 + Tarnawa out-of-stock  → niedostepny, qty=0
 *   - stockTotal == 0 + Tarnawa on-backorder  → na zamowienie, backorders=true
 *   - stockFirmao > 0                         → wysylka 24h
 *   - else (tylko Tarnawa)                    → wysylka 48h
 */
class MainSync
{
    public function __construct(
        private readonly FirmaoClientInterface $firmaoClient,
        private readonly FirmaoConfig $firmaoConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->firmaoConfig->isStockSyncEnabled()) {
            $this->logger->info('StockSync: wylaczony w konfiguracji');
            return;
        }

        $this->logger->info('StockSync MainSync: start');

        try {
            // 1. Pobierz stany z Firmao
            $firmaoProducts = $this->firmaoClient->getAllProducts();
            $this->logger->info(sprintf('StockSync: %d produktow z Firmao', count($firmaoProducts)));

            // 2. Pobierz stany z Tarnawa (scraper output)
            // TODO: Zaimplementowac odczyt z /Scrapery/TARNAWA/output/
            // lub osobnego API scrapera

            foreach ($firmaoProducts as $product) {
                $sku = $product['code'] ?? '';
                $firmaoStock = (float) ($product['currentStoreState'] ?? 0);

                if (empty($sku)) {
                    continue;
                }

                // TODO: Odczytaj stockTarnawa i tarnawaStatus
                $tarnawaStock = 0;
                $tarnawaStatus = 'unknown';

                $totalStock = $firmaoStock + $tarnawaStock;

                // Logika dostepnosci
                if ($totalStock == 0 && $tarnawaStatus === 'out-of-stock') {
                    // Niedostepny
                    $this->updateMagentoStock($sku, 0, false, 'niedostepny');
                } elseif ($totalStock == 0 && $tarnawaStatus === 'on-backorder') {
                    // Na zamowienie
                    $this->updateMagentoStock($sku, 0, true, 'na-zamowienie');
                } elseif ($firmaoStock > 0) {
                    // Wysylka 24h (z wlasnego magazynu)
                    $this->updateMagentoStock($sku, $totalStock, false, '24h');
                } else {
                    // Wysylka 48h (od dostawcy)
                    $this->updateMagentoStock($sku, $tarnawaStock, false, '48h');
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("StockSync error: {$e->getMessage()}");
        }

        $this->logger->info('StockSync MainSync: koniec');
    }

    private function updateMagentoStock(
        string $sku,
        float $qty,
        bool $backorders,
        string $deliveryLabel
    ): void {
        // TODO: Implementacja via Magento MSI API:
        // 1. SourceItemsSaveInterface → aktualizuj qty dla source "default"
        // 2. Ustaw is_in_stock na podstawie qty/backorders
        // 3. Zapisz delivery label jako custom attribute lub w cache

        $this->logger->debug(sprintf(
            'StockSync: SKU=%s qty=%.0f backorders=%s delivery=%s',
            $sku,
            $qty,
            $backorders ? 'yes' : 'no',
            $deliveryLabel
        ));
    }
}
