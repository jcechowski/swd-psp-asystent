<?php

declare(strict_types=1);

namespace Techtor\Shipping\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;
use Techtor\Shipping\Api\LabelGeneratorInterface;
use Techtor\Shipping\Api\LabelResult;

/**
 * Serwis generowania etykiet — deleguje do odpowiedniego API clienta
 * na podstawie carrier code zamowienia.
 */
class LabelService
{
    /**
     * @param LabelGeneratorInterface[] $generators
     */
    public function __construct(
        private readonly array $generators,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Wygeneruj etykiete dla zamowienia.
     */
    public function generateForOrder(Order $order): LabelResult
    {
        $shippingMethod = $order->getShippingMethod();
        $carrierCode = explode('_', $shippingMethod)[0] ?? '';

        // Pelny carrier code: techtor_inpost, techtor_dpd, techtor_dhl
        // shippingMethod format: techtor_inpost_locker lub techtor_dpd_techtor_dpd
        $fullCarrierCode = $this->resolveCarrierCode($shippingMethod);

        $generator = $this->findGenerator($fullCarrierCode);
        if ($generator === null) {
            throw new LocalizedException(__(
                'Brak generatora etykiet dla carrier: %1',
                $fullCarrierCode
            ));
        }

        $shippingAddress = $order->getShippingAddress();

        $shipmentData = [
            'reference' => $order->getIncrementId(),
            'receiver_name' => $shippingAddress?->getName() ?? '',
            'receiver_email' => $order->getCustomerEmail(),
            'receiver_phone' => $shippingAddress?->getTelephone() ?? '',
            'street' => implode(' ', $shippingAddress?->getStreet() ?? []),
            'building_number' => '',
            'city' => $shippingAddress?->getCity() ?? '',
            'postcode' => $shippingAddress?->getPostcode() ?? '',
            'country' => $shippingAddress?->getCountryId() ?? 'PL',
            'weight' => $this->calculateOrderWeight($order),
            'method' => $shippingMethod,

            // InPost locker ID (jesli Paczkomat)
            'locker_id' => $order->getData('inpost_locker_id') ?? '',

            // COD
            'cod_amount' => $order->getPayment()->getMethod() === 'cashondelivery'
                ? $order->getGrandTotal()
                : null,
        ];

        return $generator->generate($shipmentData);
    }

    /**
     * Pobierz tracking dla numeru przesylki.
     */
    public function getTracking(string $trackingNumber, string $carrierCode): array
    {
        $generator = $this->findGenerator($carrierCode);
        if ($generator === null) {
            return ['status' => 'unsupported', 'events' => []];
        }

        return $generator->getTracking($trackingNumber);
    }

    private function findGenerator(string $carrierCode): ?LabelGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->supports($carrierCode)) {
                return $generator;
            }
        }
        return null;
    }

    private function resolveCarrierCode(string $shippingMethod): string
    {
        // Magento shipping method format: carrier_method
        // Nasze: techtor_inpost_locker, techtor_dpd_techtor_dpd, techtor_dhl_techtor_dhl
        if (str_starts_with($shippingMethod, 'techtor_inpost')) {
            return 'techtor_inpost';
        }
        if (str_starts_with($shippingMethod, 'techtor_dpd')) {
            return 'techtor_dpd';
        }
        if (str_starts_with($shippingMethod, 'techtor_dhl')) {
            return 'techtor_dhl';
        }
        return $shippingMethod;
    }

    private function calculateOrderWeight(Order $order): float
    {
        $weight = 0.0;
        foreach ($order->getAllVisibleItems() as $item) {
            $weight += ((float) $item->getWeight()) * ((float) $item->getQtyOrdered());
        }
        return max($weight, 0.5); // Min 0.5 kg
    }
}
