<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;
use Techtor\BaseLinker\Model\StatusMapper;

/**
 * Gdy status zamowienia zmieni sie w Magento → aktualizuj status w BL.
 *
 * Dziala tylko dla zamowien, ktore maja bl_order_id (sa zsynchronizowane).
 */
class OrderStatusChange implements ObserverInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly StatusMapper $statusMapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isStatusSyncEnabled()) {
            return;
        }

        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        $blOrderId = (int) $order->getData('bl_order_id');

        if ($blOrderId === 0) {
            return; // zamowienie nie jest w BL
        }

        $newStatus = $order->getStatus();
        $origStatus = $order->getOrigData('status');

        // Pomijaj jesli status sie nie zmienil
        if ($newStatus === $origStatus) {
            return;
        }

        $blStatusId = $this->statusMapper->magentoToBl($newStatus);
        if ($blStatusId === null) {
            return; // brak mapowania
        }

        try {
            $this->client->setOrderStatus($blOrderId, $blStatusId);
            $this->logger->info(sprintf(
                'BL status update: %s → BL #%d status=%d (Magento: %s→%s)',
                $order->getIncrementId(),
                $blOrderId,
                $blStatusId,
                $origStatus,
                $newStatus
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'BL status update FAILED: %s BL #%d: %s',
                $order->getIncrementId(),
                $blOrderId,
                $e->getMessage()
            ));
        }
    }
}
