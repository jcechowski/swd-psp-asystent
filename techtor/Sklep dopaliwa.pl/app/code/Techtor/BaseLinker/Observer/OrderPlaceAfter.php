<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;

class OrderPlaceAfter implements ObserverInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
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
            $orderData = $this->buildOrderPayload($order);
            $blOrderId = $this->client->createOrder($orderData);
            $this->logger->info("Zamowienie {$order->getIncrementId()} wyslane do BL (ID: {$blOrderId})");
        } catch (\Exception $e) {
            // Nie blokuj zamowienia — loguj blad, cron ponowi probe
            $this->logger->error(
                "Blad sync zamowienia {$order->getIncrementId()} do BL: {$e->getMessage()}"
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderPayload(\Magento\Sales\Model\Order $order): array
    {
        $shippingAddress = $order->getShippingAddress();
        $products = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'storage' => 'db',
                'storage_id' => 0,
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price_brutto' => (float) $item->getPriceInclTax(),
                'tax_rate' => (float) $item->getTaxPercent(),
                'weight' => (float) $item->getWeight(),
            ];
        }

        return [
            'order_source_id' => $this->config->getOrderSourceId(),
            'order_status_id' => 0, // nowe zamowienie
            'date_add' => (int) strtotime($order->getCreatedAt()),
            'currency' => $order->getOrderCurrencyCode(),
            'payment_method' => $order->getPayment()->getMethod(),
            'paid' => $order->getTotalPaid() > 0 ? 1 : 0,
            'email' => $order->getCustomerEmail(),
            'phone' => $shippingAddress?->getTelephone() ?? '',
            'delivery_fullname' => $shippingAddress?->getName() ?? '',
            'delivery_address' => implode(' ', $shippingAddress?->getStreet() ?? []),
            'delivery_city' => $shippingAddress?->getCity() ?? '',
            'delivery_postcode' => $shippingAddress?->getPostcode() ?? '',
            'delivery_country_code' => $shippingAddress?->getCountryId() ?? 'PL',
            'delivery_price' => (float) $order->getShippingInclTax(),
            'products' => $products,
            'extra_field_1' => $order->getIncrementId(), // Magento order ID
        ];
    }
}
