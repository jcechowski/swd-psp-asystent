<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Cron;

use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Model\Config;

class SyncOrders
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOrderSyncEnabled()) {
            return;
        }

        $this->logger->info('BaseLinker SyncOrders cron: start');

        // TODO: Implementacja ponownej synchronizacji zamowien,
        // ktore nie zostaly wyslane przez Observer (np. blad sieci).
        // 1. Pobierz zamowienia bez flagi "bl_synced"
        // 2. Dla kazdego wywolaj Client::createOrder()
        // 3. Zapisz bl_order_id w sales_order

        $this->logger->info('BaseLinker SyncOrders cron: koniec');
    }
}
