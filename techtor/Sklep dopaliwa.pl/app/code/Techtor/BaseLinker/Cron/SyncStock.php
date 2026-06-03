<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Cron;

use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;

class SyncStock
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->logger->info('BaseLinker SyncStock cron: start');

        try {
            $inventoryId = $this->config->getInventoryId();
            $products = $this->client->getInventoryProducts($inventoryId);

            // TODO: Implementacja:
            // 1. Iteruj po $products
            // 2. Znajdz produkt w Magento po SKU
            // 3. Zaktualizuj qty w MSI (Multi-Source Inventory)
            // 4. Loguj zmiany

            $count = count($products['products'] ?? []);
            $this->logger->info("BaseLinker SyncStock: pobrano {$count} produktow z BL");
        } catch (\Exception $e) {
            $this->logger->error("BaseLinker SyncStock error: {$e->getMessage()}");
        }

        $this->logger->info('BaseLinker SyncStock cron: koniec');
    }
}
