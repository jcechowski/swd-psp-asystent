<?php

declare(strict_types=1);

namespace Techtor\Firmao\Cron;

use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;

class SyncStock
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
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
            $products = $this->client->getAllProducts();

            foreach ($products as $product) {
                $sku = $product['code'] ?? '';
                $firmaoStock = (float) ($product['currentStoreState'] ?? 0);

                if (empty($sku)) {
                    continue;
                }

                // TODO: Implementacja:
                // 1. Pobierz stockTarnawa z scraperów (osobne zrodlo danych)
                // 2. stockTotal = firmaoStock + stockTarnawa
                // 3. Logika dostepnosci:
                //    - stockTotal == 0 + Tarnawa out-of-stock → qty=0
                //    - stockTotal == 0 + Tarnawa on-backorder → qty=0, backorders=true
                //    - firmaoStock > 0 → delivery "24h"
                //    - else → delivery "48h"
                // 4. Aktualizuj MSI source items
            }
        } catch (\Exception $e) {
            $this->logger->error("Firmao SyncStock error: {$e->getMessage()}");
        }

        $this->logger->info('Firmao SyncStock cron: koniec');
    }
}
