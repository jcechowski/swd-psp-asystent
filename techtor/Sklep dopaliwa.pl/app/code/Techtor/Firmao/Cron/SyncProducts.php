<?php

declare(strict_types=1);

namespace Techtor\Firmao\Cron;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;
use Techtor\Firmao\Model\ProductMapper;

/**
 * Cron: synchronizacja produktow Firmao → Magento.
 *
 * Codziennie o 5:00 — pobiera wszystkie produkty z Firmao,
 * aktualizuje istniejace w Magento po SKU, opcjonalnie tworzy nowe.
 */
class SyncProducts
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductMapper $mapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isProductSyncEnabled()) {
            return;
        }

        $this->logger->info('Firmao SyncProducts cron: start');

        try {
            $firmaoProducts = $this->client->getAllProducts();
            $this->logger->info(sprintf('Firmao: pobrano %d produktow', count($firmaoProducts)));

            $priceGroup = $this->config->getSalePriceGroup();
            $autoCreate = $this->config->isAutoCreateProducts();
            $updated = 0;
            $created = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($firmaoProducts as $fp) {
                $sku = $fp['productCode'] ?? '';
                if (empty($sku)) {
                    $skipped++;
                    continue;
                }

                try {
                    $mappedData = $this->mapper->mapToMagento($fp, $priceGroup);
                    $product = $this->getOrCreateProduct($sku, $autoCreate);

                    if ($product === null) {
                        $skipped++;
                        continue;
                    }

                    // Aktualizuj pola
                    $product->setName($mappedData['name']);
                    $product->setPrice($mappedData['price']);
                    $product->setWeight($mappedData['weight']);

                    if (!empty($mappedData['description'])) {
                        $product->setData('description', $mappedData['description']);
                    }
                    if (!empty($mappedData['short_description'])) {
                        $product->setData('short_description', $mappedData['short_description']);
                    }

                    // Custom atrybuty
                    if (!empty($mappedData['ean'])) {
                        $product->setCustomAttribute('ean', $mappedData['ean']);
                    }
                    if (!empty($mappedData['manufacturer_code'])) {
                        $product->setCustomAttribute('manufacturer_code', $mappedData['manufacturer_code']);
                    }

                    // Typ produktu
                    $firmaoType = $fp['type']['label'] ?? '';
                    if (!empty($firmaoType)) {
                        $magentoType = $this->mapper->mapProductType($firmaoType);
                        if ($magentoType !== null) {
                            $product->setCustomAttribute('product_type_techtor', $magentoType);
                        }
                    }

                    // Cost (cena zakupu) — custom attribute lub wbudowany
                    $product->setData('cost', $mappedData['cost']);

                    $this->productRepository->save($product);

                    if ($product->isObjectNew()) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Firmao SyncProducts: SKU=%s error: %s',
                        $sku,
                        $e->getMessage()
                    ));
                    $errors++;
                }
            }

            $this->logger->info(sprintf(
                'Firmao SyncProducts: updated=%d, created=%d, skipped=%d, errors=%d',
                $updated,
                $created,
                $skipped,
                $errors
            ));
        } catch (\Exception $e) {
            $this->logger->error("Firmao SyncProducts error: {$e->getMessage()}");
        }

        $this->logger->info('Firmao SyncProducts cron: koniec');
    }

    private function getOrCreateProduct(string $sku, bool $autoCreate): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        try {
            return $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            if (!$autoCreate) {
                return null;
            }

            // Utworz nowy produkt
            $product = $this->productFactory->create();
            $product->setSku($sku);
            $product->setTypeId('simple');
            $product->setAttributeSetId(4); // Default — docelowo: "Sprzet paliwowy"
            $product->setStatus(Status::STATUS_DISABLED); // Nowe produkty nieaktywne
            $product->setVisibility(4);
            $product->setWebsiteIds([1]);

            $this->logger->info(sprintf('Firmao: tworzenie nowego produktu SKU=%s', $sku));
            return $product;
        }
    }
}
