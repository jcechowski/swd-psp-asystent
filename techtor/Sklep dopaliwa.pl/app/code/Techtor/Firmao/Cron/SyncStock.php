<?php

declare(strict_types=1);

namespace Techtor\Firmao\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;

/**
 * Cron: synchronizacja stanow magazynowych Firmao → Magento MSI.
 *
 * Codziennie o 5:30 — pobiera currentStoreState z Firmao,
 * aktualizuje MSI source items w Magento.
 *
 * Logika dostepnosci (z techtor.pl):
 *   - stockFirmao > 0  → "Wysylka 24h", is_in_stock=true
 *   - stockFirmao == 0 → qty=0 (stock z dostawcow obsluguje StockSync modul)
 */
class SyncStock
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isStockSyncEnabled()) {
            return;
        }

        $this->logger->info('Firmao SyncStock cron: start');

        try {
            $firmaoProducts = $this->client->getAllProducts();
            $this->logger->info(sprintf('Firmao SyncStock: %d produktow', count($firmaoProducts)));

            $sourceItems = [];
            $updated = 0;
            $skipped = 0;

            foreach ($firmaoProducts as $fp) {
                $sku = $fp['productCode'] ?? '';
                $firmaoStock = (float) ($fp['currentStoreState'] ?? 0);

                if (empty($sku)) {
                    $skipped++;
                    continue;
                }

                // Sprawdz czy produkt istnieje w Magento
                if (!$this->productExists($sku)) {
                    $skipped++;
                    continue;
                }

                /** @var SourceItemInterface $sourceItem */
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSourceCode('default');
                $sourceItem->setSku($sku);
                $sourceItem->setQuantity($firmaoStock);
                $sourceItem->setStatus(
                    $firmaoStock > 0
                        ? SourceItemInterface::STATUS_IN_STOCK
                        : SourceItemInterface::STATUS_OUT_OF_STOCK
                );

                $sourceItems[] = $sourceItem;
                $updated++;

                // Batch save co 100 produktow
                if (count($sourceItems) >= 100) {
                    $this->saveBatch($sourceItems);
                    $sourceItems = [];
                }
            }

            // Zapisz pozostale
            if (!empty($sourceItems)) {
                $this->saveBatch($sourceItems);
            }

            $this->logger->info(sprintf(
                'Firmao SyncStock: zaktualizowano %d, pominieto %d',
                $updated,
                $skipped
            ));
        } catch (\Exception $e) {
            $this->logger->error("Firmao SyncStock error: {$e->getMessage()}");
        }

        $this->logger->info('Firmao SyncStock cron: koniec');
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

    /**
     * @param SourceItemInterface[] $sourceItems
     */
    private function saveBatch(array $sourceItems): void
    {
        try {
            $this->sourceItemsSave->execute($sourceItems);
            $this->logger->debug(sprintf('Firmao SyncStock: batch %d saved', count($sourceItems)));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Firmao SyncStock batch error: %s', $e->getMessage()));
        }
    }
}
