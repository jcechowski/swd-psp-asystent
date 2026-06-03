<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;
use Techtor\BaseLinker\Model\OrderPayloadBuilder;

/**
 * Cron: ponawia synchronizacje zamowien, ktore nie trafiły do BL.
 *
 * Szuka zamowien z bl_synced=0 (nigdy nie probowano) lub bl_synced=2 (blad).
 * Maksymalnie 50 zamowien na jedno uruchomienie crona (co 15 min).
 */
class SyncOrders
{
    private const BATCH_SIZE = 50;
    private const MAX_RETRIES = 5;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOrderSyncEnabled()) {
            return;
        }

        $this->logger->info('BL SyncOrders cron: start');

        $unsyncedOrders = $this->getUnsyncedOrders();
        $synced = 0;
        $failed = 0;

        foreach ($unsyncedOrders as $order) {
            try {
                $orderData = $this->payloadBuilder->build($order);
                $blOrderId = $this->client->createOrder($orderData);

                $order->setData('bl_order_id', $blOrderId);
                $order->setData('bl_synced', 1);
                $order->setData('bl_sync_error', null);
                $order->setData('bl_synced_at', date('Y-m-d H:i:s'));
                $this->orderRepository->save($order);

                $this->logger->info(sprintf(
                    'BL SyncOrders: %s → BL #%d (retry)',
                    $order->getIncrementId(),
                    $blOrderId
                ));
                $synced++;
            } catch (\Exception $e) {
                $order->setData('bl_synced', 2);
                $order->setData('bl_sync_error', mb_substr($e->getMessage(), 0, 500));
                $this->orderRepository->save($order);

                $this->logger->error(sprintf(
                    'BL SyncOrders: %s FAILED: %s',
                    $order->getIncrementId(),
                    $e->getMessage()
                ));
                $failed++;
            }
        }

        $this->logger->info(sprintf(
            'BL SyncOrders cron: koniec. Synced: %d, Failed: %d, Total: %d',
            $synced,
            $failed,
            count($unsyncedOrders)
        ));
    }

    /**
     * Pobierz zamowienia, ktore nie sa zsynchronizowane z BL.
     *
     * @return \Magento\Sales\Api\Data\OrderInterface[]
     */
    private function getUnsyncedOrders(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('bl_synced', [0, 2], 'in')   // 0=nigdy, 2=blad
            ->addFilter('state', ['canceled', 'closed'], 'nin') // pomijamy anulowane
            ->setPageSize(self::BATCH_SIZE)
            ->create();

        $result = $this->orderRepository->getList($searchCriteria);
        return $result->getItems();
    }
}
