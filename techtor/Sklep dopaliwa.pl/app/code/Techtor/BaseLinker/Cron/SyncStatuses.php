<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;
use Techtor\BaseLinker\Model\StatusMapper;

/**
 * Cron: synchronizacja statusow zamowien BL → Magento.
 *
 * Pobiera zamowienia z BL (po dacie), porownuje statusy z Magento,
 * aktualizuje status Magento jesli zmienil sie w BL.
 */
class SyncStatuses
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StatusMapper $statusMapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOrderSyncEnabled()) {
            return;
        }

        $this->logger->info('BL SyncStatuses cron: start');

        try {
            // Pobierz zamowienia z BL z ostatnich 24h
            $dateFrom = time() - 86400;
            $blOrders = $this->client->getOrders($dateFrom);

            $this->logger->info(sprintf('BL SyncStatuses: %d zamowien z BL', count($blOrders)));

            $updated = 0;
            foreach ($blOrders as $blOrder) {
                $blOrderId = (int) ($blOrder['order_id'] ?? 0);
                $blStatusId = (int) ($blOrder['order_status_id'] ?? 0);

                if ($blOrderId === 0) {
                    continue;
                }

                // Znajdz zamowienie Magento po bl_order_id
                $magentoOrder = $this->findMagentoOrder($blOrderId);
                if ($magentoOrder === null) {
                    continue;
                }

                // Mapuj status BL → Magento
                $magentoStatus = $this->statusMapper->blToMagento($blStatusId);
                if ($magentoStatus === null) {
                    continue;
                }

                // Aktualizuj jesli status sie zmienil
                $currentStatus = $magentoOrder->getStatus();
                if ($currentStatus !== $magentoStatus) {
                    $magentoOrder->setStatus($magentoStatus);
                    $magentoOrder->addCommentToStatusHistory(
                        sprintf('Status zmieniony przez BL (BL status: %d)', $blStatusId),
                        $magentoStatus
                    );
                    $this->orderRepository->save($magentoOrder);

                    $this->logger->info(sprintf(
                        'BL SyncStatuses: %s: %s → %s (BL #%d)',
                        $magentoOrder->getIncrementId(),
                        $currentStatus,
                        $magentoStatus,
                        $blOrderId
                    ));
                    $updated++;
                }
            }

            $this->logger->info(sprintf('BL SyncStatuses: zaktualizowano %d zamowien', $updated));
        } catch (\Exception $e) {
            $this->logger->error("BL SyncStatuses error: {$e->getMessage()}");
        }

        $this->logger->info('BL SyncStatuses cron: koniec');
    }

    private function findMagentoOrder(int $blOrderId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('bl_order_id', $blOrderId)
            ->setPageSize(1)
            ->create();

        $result = $this->orderRepository->getList($searchCriteria);
        $items = $result->getItems();

        return !empty($items) ? reset($items) : null;
    }
}
