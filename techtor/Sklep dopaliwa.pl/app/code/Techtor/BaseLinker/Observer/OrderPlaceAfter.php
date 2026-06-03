<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;
use Techtor\BaseLinker\Model\OrderPayloadBuilder;

class OrderPlaceAfter implements ObserverInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isOrderSyncEnabled()) {
            return;
        }

        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();

        try {
            $orderData = $this->payloadBuilder->build($order);
            $blOrderId = $this->client->createOrder($orderData);

            // Zapisz BL ID i status sync na zamowieniu
            $order->setData('bl_order_id', $blOrderId);
            $order->setData('bl_synced', 1);
            $order->setData('bl_sync_error', null);
            $order->setData('bl_synced_at', date('Y-m-d H:i:s'));
            $this->orderRepository->save($order);

            $this->logger->info(sprintf(
                'Zamowienie %s → BL #%d',
                $order->getIncrementId(),
                $blOrderId
            ));
        } catch (\Exception $e) {
            // Nie blokuj zamowienia — oznacz jako niezsynchronizowane, cron ponowi
            $order->setData('bl_synced', 2); // 2 = blad
            $order->setData('bl_sync_error', mb_substr($e->getMessage(), 0, 500));
            $this->orderRepository->save($order);

            $this->logger->error(sprintf(
                'Blad sync zamowienia %s do BL: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
