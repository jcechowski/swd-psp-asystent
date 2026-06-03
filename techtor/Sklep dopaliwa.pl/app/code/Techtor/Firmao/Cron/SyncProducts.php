<?php

declare(strict_types=1);

namespace Techtor\Firmao\Cron;

use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;

class SyncProducts
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->logger->info('Firmao SyncProducts cron: start');

        try {
            $products = $this->client->getAllProducts();
            $this->logger->info(sprintf('Firmao: pobrano %d produktow', count($products)));

            // TODO: Implementacja:
            // 1. Iteruj po produktach Firmao
            // 2. Szukaj w Magento po SKU (code)
            // 3. Aktualizuj atrybuty: nazwa, cena, waga, EAN, manufacturer_code
            // 4. Tworz nowe produkty jesli nie istnieja (opcjonalnie)

        } catch (\Exception $e) {
            $this->logger->error("Firmao SyncProducts error: {$e->getMessage()}");
        }

        $this->logger->info('Firmao SyncProducts cron: koniec');
    }
}
