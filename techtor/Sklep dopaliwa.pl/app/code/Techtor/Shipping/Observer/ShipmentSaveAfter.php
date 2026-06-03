<?php

declare(strict_types=1);

namespace Techtor\Shipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Psr\Log\LoggerInterface;
use Techtor\Shipping\Model\LabelService;

/**
 * Po utworzeniu shipmentu — automatycznie generuj etykiete
 * i dodaj tracking number (jesli carrier jest Techtor).
 */
class ShipmentSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly LabelService $labelService,
        private readonly TrackFactory $trackFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $shippingMethod = $order->getShippingMethod() ?? '';

        // Reaguj tylko na nasze carriersy
        if (!str_starts_with($shippingMethod, 'techtor_')) {
            return;
        }

        // Pomijaj jesli juz ma tracking (unikamy duplikatow)
        if ($shipment->getTracks() && count($shipment->getTracks()) > 0) {
            return;
        }

        try {
            $result = $this->labelService->generateForOrder($order);

            // Dodaj tracking
            $track = $this->trackFactory->create();
            $track->setCarrierCode($result->getCarrierCode());
            $track->setTitle($this->getCarrierTitle($result->getCarrierCode()));
            $track->setTrackNumber($result->getTrackingNumber());
            $shipment->addTrack($track);

            // Zapisz PDF etykiety w komentarzu (lub jako attachment)
            $shipment->addComment(sprintf(
                'Etykieta wygenerowana automatycznie. Tracking: %s',
                $result->getTrackingNumber()
            ));

            $this->logger->info(sprintf(
                'Shipping: etykieta %s dla zamowienia %s (tracking: %s)',
                $result->getCarrierCode(),
                $order->getIncrementId(),
                $result->getTrackingNumber()
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Shipping: blad generowania etykiety dla %s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));

            $shipment->addComment(sprintf(
                'BLAD generowania etykiety: %s. Wygeneruj recznie.',
                $e->getMessage()
            ));
        }
    }

    private function getCarrierTitle(string $code): string
    {
        return match ($code) {
            'techtor_inpost' => 'InPost',
            'techtor_dpd' => 'DPD',
            'techtor_dhl' => 'DHL',
            default => $code,
        };
    }
}
