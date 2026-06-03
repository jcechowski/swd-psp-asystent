<?php

declare(strict_types=1);

namespace Techtor\Firmao\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;

/**
 * Po zrealizowaniu zamowienia (status=complete) — tworzy dokument PZ w Firmao.
 *
 * Logika z techtor-platform: jedno PZ na miesiac.
 * Tu uproszczona wersja: jedno PZ na zamowienie (rozszerzalny).
 */
class OrderCompleteSync implements ObserverInterface
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isOrderSyncEnabled()) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        // Reaguj tylko na zmiane statusu na "complete"
        if ($order->getStatus() !== 'complete') {
            return;
        }
        if ($order->getOrigData('status') === 'complete') {
            return; // juz byl complete, nie tworzmy duplikatu
        }

        try {
            $this->createPzFromOrder($order);
        } catch (\Exception $e) {
            // Nie blokuj zamowienia — loguj blad
            $this->logger->error(sprintf(
                'Firmao PZ error dla zamowienia %s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    private function createPzFromOrder(Order $order): void
    {
        $now = new \DateTime();
        $pzNumber = sprintf(
            '%s/%s/%s/PZ',
            $now->format('Y'),
            $now->format('m'),
            $order->getIncrementId()
        );

        // Utworz PZ
        $pzId = $this->client->createStorageDoc([
            'storageDocNumber' => $pzNumber,
            'storagedocDate' => $now->format('Y-m-d'),
            'invoiceDate' => $now->format('Y-m-d'),
            'paymentDate' => $now->format('Y-m-d'),
            'description' => sprintf(
                'Zamowienie %s z dopaliwa.pl',
                $order->getIncrementId()
            ),
        ]);

        // Dodaj pozycje
        foreach ($order->getAllVisibleItems() as $item) {
            $sku = $item->getSku();

            // Znajdz produkt w Firmao po SKU
            $firmaoProduct = $this->client->getProductByCode($sku);
            if ($firmaoProduct === null) {
                $this->logger->warning(sprintf(
                    'Firmao PZ: SKU=%s nie znaleziony w Firmao, pomijam',
                    $sku
                ));
                continue;
            }

            $firmaoProductId = (int) $firmaoProduct['id'];
            $purchaseNetto = (float) ($firmaoProduct['purchasePriceGroup']['netPrice'] ?? 0);
            $unit = $firmaoProduct['purchasePriceGroup']['unit'] ?? 'szt';

            $this->client->addTransactionEntry($pzId, [
                'product' => ['id' => $firmaoProductId],
                'quantity' => (int) $item->getQtyOrdered(),
                'entrySize' => (int) $item->getQtyOrdered(),
                'unit' => $unit,
                'unitNettoPrice' => $purchaseNetto,
                'vatPercent' => $this->config->getDefaultVatRate(),
            ]);
        }

        $this->logger->info(sprintf(
            'Firmao: utworzono PZ #%d (%s) dla zamowienia %s',
            $pzId,
            $pzNumber,
            $order->getIncrementId()
        ));
    }
}
