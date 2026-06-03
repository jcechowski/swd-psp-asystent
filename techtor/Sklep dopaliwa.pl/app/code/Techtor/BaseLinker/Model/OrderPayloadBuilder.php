<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Model;

use Magento\Sales\Model\Order;

class OrderPayloadBuilder
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Buduje payload zamowienia zgodny z BL API addOrder.
     *
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();

        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'storage' => 'db',
                'storage_id' => 0,
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price_brutto' => round((float) $item->getPriceInclTax(), 2),
                'tax_rate' => (float) $item->getTaxPercent(),
                'weight' => (float) ($item->getWeight() ?? 0),
                'ean' => $item->getProduct()?->getData('ean') ?? '',
            ];
        }

        $payload = [
            'order_source_id' => $this->config->getOrderSourceId(),
            'order_status_id' => $this->config->getDefaultNewOrderStatusId(),
            'date_add' => (int) strtotime($order->getCreatedAt()),
            'currency' => $order->getOrderCurrencyCode(),
            'payment_method' => $this->mapPaymentMethod($order),
            'payment_method_cod' => $this->isCod($order) ? 1 : 0,
            'paid' => ((float) $order->getTotalPaid()) > 0 ? 1 : 0,

            // Dane kupujacego (invoice)
            'user_login' => $order->getCustomerEmail(),
            'email' => $order->getCustomerEmail(),
            'phone' => $billingAddress?->getTelephone()
                ?? $shippingAddress?->getTelephone()
                ?? '',
            'user_comments' => $order->getCustomerNote() ?? '',

            // Adres do faktury
            'invoice_fullname' => $billingAddress?->getName() ?? '',
            'invoice_company' => $billingAddress?->getCompany() ?? '',
            'invoice_nip' => $order->getData('customer_taxvat')
                ?? $billingAddress?->getVatId()
                ?? '',
            'invoice_address' => implode(' ', $billingAddress?->getStreet() ?? []),
            'invoice_city' => $billingAddress?->getCity() ?? '',
            'invoice_postcode' => $billingAddress?->getPostcode() ?? '',
            'invoice_country_code' => $billingAddress?->getCountryId() ?? 'PL',

            // Adres dostawy
            'delivery_fullname' => $shippingAddress?->getName() ?? '',
            'delivery_company' => $shippingAddress?->getCompany() ?? '',
            'delivery_address' => implode(' ', $shippingAddress?->getStreet() ?? []),
            'delivery_city' => $shippingAddress?->getCity() ?? '',
            'delivery_postcode' => $shippingAddress?->getPostcode() ?? '',
            'delivery_country_code' => $shippingAddress?->getCountryId() ?? 'PL',
            'delivery_point_id' => $order->getData('inpost_locker_id') ?? '',
            'delivery_point_name' => $order->getData('inpost_locker_name') ?? '',
            'delivery_method' => $order->getShippingDescription() ?? '',
            'delivery_price' => round((float) $order->getShippingInclTax(), 2),

            'products' => $products,

            // Dodatkowe pola — Magento order ID do referencji
            'extra_field_1' => $order->getIncrementId(),
            'extra_field_2' => 'dopaliwa.pl',
        ];

        // Rabat zamowienia
        $discount = abs((float) $order->getDiscountAmount());
        if ($discount > 0) {
            $payload['extra_field_3'] = sprintf('Rabat: -%.2f PLN', $discount);
        }

        return $payload;
    }

    private function mapPaymentMethod(Order $order): string
    {
        $method = $order->getPayment()->getMethod();

        return match ($method) {
            'przelewy24'        => 'Przelewy24',
            'payu_gateway'      => 'PayU',
            'banktransfer'      => 'Przelew tradycyjny',
            'cashondelivery'    => 'Pobranie',
            'free'              => 'Darmowe',
            default             => $method,
        };
    }

    private function isCod(Order $order): bool
    {
        return $order->getPayment()->getMethod() === 'cashondelivery';
    }
}
