<?php

declare(strict_types=1);

namespace Techtor\Firmao\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;
use Techtor\Firmao\Model\ProductMapper;

/**
 * Cron: synchronizacja cen Firmao → Magento.
 *
 * Codziennie o 5:15 — aktualizuje ceny sprzedazy (brutto z grupy cenowej)
 * i ceny zakupu (cost) z Firmao.
 *
 * Oddzielny cron od SyncProducts bo ceny zmieniaja sie czesciej niz dane produktowe.
 */
class SyncPrices
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductMapper $mapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isPriceSyncEnabled()) {
            return;
        }

        $this->logger->info('Firmao SyncPrices cron: start');

        try {
            $firmaoProducts = $this->client->getAllProducts();
            $priceGroup = $this->config->getSalePriceGroup();

            $updated = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($firmaoProducts as $fp) {
                $sku = $fp['productCode'] ?? '';
                if (empty($sku)) {
                    $skipped++;
                    continue;
                }

                try {
                    $product = $this->productRepository->get($sku);
                } catch (NoSuchEntityException) {
                    $skipped++;
                    continue;
                }

                try {
                    $mappedData = $this->mapper->mapToMagento($fp, $priceGroup);
                    $newPrice = $mappedData['price'];
                    $newCost = $mappedData['cost'];
                    $oldPrice = (float) $product->getPrice();
                    $oldCost = (float) $product->getData('cost');

                    $changed = false;

                    if (abs($newPrice - $oldPrice) > 0.01) {
                        $product->setPrice($newPrice);
                        $changed = true;
                        $this->logger->info(sprintf(
                            'Firmao SyncPrices: %s cena %.2f → %.2f',
                            $sku,
                            $oldPrice,
                            $newPrice
                        ));
                    }

                    if (abs($newCost - $oldCost) > 0.01) {
                        $product->setData('cost', $newCost);
                        $changed = true;
                    }

                    if ($changed) {
                        $this->productRepository->save($product);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Firmao SyncPrices: SKU=%s error: %s',
                        $sku,
                        $e->getMessage()
                    ));
                    $errors++;
                }
            }

            $this->logger->info(sprintf(
                'Firmao SyncPrices: updated=%d, skipped=%d, errors=%d',
                $updated,
                $skipped,
                $errors
            ));
        } catch (\Exception $e) {
            $this->logger->error("Firmao SyncPrices error: {$e->getMessage()}");
        }

        $this->logger->info('Firmao SyncPrices cron: koniec');
    }
}
