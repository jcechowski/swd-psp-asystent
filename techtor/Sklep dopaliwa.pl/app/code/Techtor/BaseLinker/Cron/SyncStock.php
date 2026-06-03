<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;

/**
 * Cron: synchronizacja stanow magazynowych BL → Magento MSI.
 *
 * Pobiera produkty z inventory BL, dopasowuje po SKU do Magento,
 * aktualizuje qty w domyslnym MSI source.
 */
class SyncStock
{
    private const SOURCE_CODE = 'default';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->logger->info('BL SyncStock cron: start');

        try {
            $inventoryId = $this->config->getInventoryId();
            $blProducts = $this->client->getInventoryProducts($inventoryId);

            $products = $blProducts['products'] ?? [];
            $this->logger->info(sprintf('BL SyncStock: %d produktow z BL', count($products)));

            $sourceItemsToSave = [];
            $updated = 0;
            $skipped = 0;

            foreach ($products as $blProduct) {
                $sku = $blProduct['sku'] ?? '';
                if (empty($sku)) {
                    $skipped++;
                    continue;
                }

                // Sprawdz czy produkt istnieje w Magento
                if (!$this->productExistsInMagento($sku)) {
                    $skipped++;
                    continue;
                }

                // Sumuj stany ze wszystkich magazynow BL
                $blQty = 0;
                foreach ($blProduct['stock'] ?? [] as $warehouseQty) {
                    $blQty += (float) $warehouseQty;
                }

                // Przygotuj MSI source item
                /** @var SourceItemInterface $sourceItem */
                $sourceItem = $this->sourceItemFactory->create();
                $sourceItem->setSourceCode(self::SOURCE_CODE);
                $sourceItem->setSku($sku);
                $sourceItem->setQuantity($blQty);
                $sourceItem->setStatus($blQty > 0
                    ? SourceItemInterface::STATUS_IN_STOCK
                    : SourceItemInterface::STATUS_OUT_OF_STOCK
                );

                $sourceItemsToSave[] = $sourceItem;
                $updated++;

                // Zapisuj w batchach po 100 (MSI performance)
                if (count($sourceItemsToSave) >= 100) {
                    $this->saveSourceItems($sourceItemsToSave);
                    $sourceItemsToSave = [];
                }
            }

            // Zapisz pozostale
            if (!empty($sourceItemsToSave)) {
                $this->saveSourceItems($sourceItemsToSave);
            }

            $this->logger->info(sprintf(
                'BL SyncStock: zaktualizowano %d, pominieto %d',
                $updated,
                $skipped
            ));
        } catch (\Exception $e) {
            $this->logger->error("BL SyncStock error: {$e->getMessage()}");
        }

        $this->logger->info('BL SyncStock cron: koniec');
    }

    private function productExistsInMagento(string $sku): bool
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
    private function saveSourceItems(array $sourceItems): void
    {
        try {
            $this->sourceItemsSave->execute($sourceItems);
            $this->logger->debug(sprintf('BL SyncStock: zapisano batch %d source items', count($sourceItems)));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('BL SyncStock: blad zapisu batch: %s', $e->getMessage()));
        }
    }
}
