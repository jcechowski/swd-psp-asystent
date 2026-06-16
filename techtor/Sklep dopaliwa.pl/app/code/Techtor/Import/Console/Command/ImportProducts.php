<?php

declare(strict_types=1);

namespace Techtor\Import\Console\Command;

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Import\Model\CategoryMap;
use Techtor\Import\Model\PimReader;
use Techtor\Import\Model\ProductMapper;

/**
 * Import produktów z PIM do Magento.
 *
 * Użycie:
 *   bin/magento techtor:import:products [--dry-run] [--limit=N] [--sku=XXX] [--skip-existing]
 */
class ImportProducts extends Command
{
    private PimReader $pimReader;
    private ProductMapper $productMapper;
    private ProductInterfaceFactory $productFactory;
    private ProductRepositoryInterface $productRepository;
    private StockRegistryInterface $stockRegistry;
    private EavConfig $eavConfig;
    private StoreManagerInterface $storeManager;
    private State $appState;

    public function __construct(
        PimReader $pimReader,
        ProductMapper $productMapper,
        ProductInterfaceFactory $productFactory,
        ProductRepositoryInterface $productRepository,
        StockRegistryInterface $stockRegistry,
        EavConfig $eavConfig,
        StoreManagerInterface $storeManager,
        State $appState
    ) {
        $this->pimReader = $pimReader;
        $this->productMapper = $productMapper;
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->eavConfig = $eavConfig;
        $this->storeManager = $storeManager;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:import:products')
            ->setDescription('Import produktów z PIM (produkty-configs.json + BEVO) do Magento')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tylko wyświetl co zostanie zaimportowane')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Importuj max N produktów')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Importuj tylko konkretny SKU')
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Pomijaj produkty które już istnieją')
            ->addOption('skip-no-price', null, InputOption::VALUE_NONE, 'Pomijaj produkty bez ceny')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Pomiń pierwszych N produktów');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            // area already set
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $onlySku = $input->getOption('sku');
        $skipExisting = (bool) $input->getOption('skip-existing');
        $skipNoPrice = (bool) $input->getOption('skip-no-price');
        $offset = $input->getOption('offset') ? (int) $input->getOption('offset') : 0;

        // Walidacja plików
        $missing = $this->pimReader->validateImportDir();
        if (!empty($missing)) {
            $output->writeln('<error>Brakujące pliki w var/import/pim/:</error>');
            foreach ($missing as $f) {
                $output->writeln("  - $f");
            }
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] Nic nie zostanie zapisane.</comment>');
        }

        // Wczytaj dane
        $output->writeln('Wczytuję dane PIM...');
        $configs = $this->pimReader->readProductConfigs();
        $categoryMappings = $this->pimReader->readCategoryMappings();
        $productCategories = $this->pimReader->readProductCategoryAssignments();
        $reverseLookup = CategoryMap::getReverseLookup();

        $output->writeln(sprintf(
            '  Produkty: %d | Kategorie Magento: %d | Przypisania: %d',
            count($configs),
            count($categoryMappings),
            count($productCategories)
        ));

        // Resolve attribute set ID
        $attrSetId = $this->resolveAttributeSetId('Sprzet paliwowy');
        if (!$attrSetId) {
            $output->writeln('<comment>Attribute set "Sprzet paliwowy" nie znaleziony, użyję Default</comment>');
            $attrSetId = $this->resolveAttributeSetId('Default');
        }
        $output->writeln(sprintf('  Attribute Set ID: %d', $attrSetId));

        // Filtruj po SKU
        if ($onlySku) {
            if (isset($configs[$onlySku])) {
                $configs = [$onlySku => $configs[$onlySku]];
            } else {
                $output->writeln(sprintf('<error>SKU "%s" nie znaleziony w PIM</error>', $onlySku));
                return Command::FAILURE;
            }
        }

        // Offset
        if ($offset > 0) {
            $configs = array_slice($configs, $offset, null, true);
            $output->writeln(sprintf('  Offset: pomijam %d produktów', $offset));
        }

        // Statystyki
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'no_price' => 0, 'no_category' => 0];
        $processed = 0;

        $output->writeln('');
        $output->writeln('Rozpoczynam import...');

        foreach ($configs as $code => $pimConfig) {
            if ($limit !== null && $processed >= $limit) {
                $output->writeln(sprintf('<comment>Limit %d osiągnięty.</comment>', $limit));
                break;
            }

            $sku = $pimConfig['code'] ?? $code;

            // Pomijaj węże (W*) — te idą przez FlexGen
            if (preg_match('/^W[A-Z]\d{3}\d{3}/', $sku)) {
                continue;
            }

            // Sprawdź czy istnieje
            $exists = false;
            try {
                $this->productRepository->get($sku);
                $exists = true;
            } catch (NoSuchEntityException $e) {
                // nie istnieje — OK
            }

            if ($exists && $skipExisting) {
                $stats['skipped']++;
                continue;
            }

            // Dane BEVO (opcjonalne)
            $bevoData = $this->pimReader->readBevoProduct($sku);

            // Ustal kategorię Magento
            $categoryId = $this->resolveCategoryId(
                $pimConfig,
                $sku,
                $categoryMappings,
                $productCategories,
                $reverseLookup
            );

            if (!$categoryId) {
                $stats['no_category']++;
            }

            // Mapuj na Magento
            $mapped = $this->productMapper->mapToMagento($pimConfig, $bevoData, $categoryId);

            // Sprawdź cenę
            if ($skipNoPrice && $mapped['price'] <= 0) {
                $stats['no_price']++;
                continue;
            }

            $processed++;

            if ($dryRun) {
                $output->writeln(sprintf(
                    '  [%s] %s | %.2f PLN | cat=%s | url=%s',
                    $exists ? 'UPDATE' : 'CREATE',
                    $sku,
                    $mapped['price'],
                    $categoryId ? "ID:$categoryId" : 'BRAK',
                    $mapped['url_key']
                ));
                $stats[$exists ? 'updated' : 'created']++;
                continue;
            }

            // Zapisz produkt
            try {
                if ($exists) {
                    $product = $this->productRepository->get($sku);
                } else {
                    $product = $this->productFactory->create();
                    $product->setSku($sku);
                    $product->setTypeId('simple');
                }

                $product->setName($mapped['name']);
                $product->setPrice($mapped['price']);
                $product->setWeight($mapped['weight']);
                $product->setStatus(Status::STATUS_ENABLED);
                $product->setVisibility(Visibility::VISIBILITY_BOTH);
                $product->setAttributeSetId($attrSetId);
                $product->setTaxClassId(2);
                $product->setStoreId(0);

                // Opisy
                if (!empty($mapped['description'])) {
                    $product->setDescription($mapped['description']);
                }
                if (!empty($mapped['short_description'])) {
                    $product->setShortDescription($mapped['short_description']);
                }

                // SEO
                $product->setUrlKey($mapped['url_key']);
                if (!empty($mapped['meta_title'])) {
                    $product->setMetaTitle($mapped['meta_title']);
                }
                if (!empty($mapped['meta_description'])) {
                    $product->setMetaDescription($mapped['meta_description']);
                }
                if (!empty($mapped['meta_keyword'])) {
                    $product->setMetaKeyword($mapped['meta_keyword']);
                }

                // Custom atrybuty
                if (!empty($mapped['ean'])) {
                    $product->setCustomAttribute('ean', $mapped['ean']);
                }
                if (!empty($mapped['manufacturer_code'])) {
                    $product->setCustomAttribute('manufacturer_code', $mapped['manufacturer_code']);
                }

                // Cost (cena zakupu)
                if ($mapped['cost'] > 0) {
                    $product->setCustomAttribute('cost', $mapped['cost']);
                }

                // Kategorie
                if (!empty($mapped['category_ids'])) {
                    $product->setCategoryIds($mapped['category_ids']);
                }

                $this->productRepository->save($product);

                // Stock
                $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                $qty = $mapped['_stock_qty'];
                $stockItem->setQty($qty);
                $stockItem->setIsInStock($qty > 0);
                $stockItem->setManageStock(true);
                $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

                $stats[$exists ? 'updated' : 'created']++;

                if ($processed % 50 === 0) {
                    $output->writeln(sprintf(
                        '  ... przetworzono %d (created=%d, updated=%d, errors=%d)',
                        $processed,
                        $stats['created'],
                        $stats['updated'],
                        $stats['errors']
                    ));
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $output->writeln(sprintf(
                    '  <error>[ERROR] %s: %s</error>',
                    $sku,
                    $e->getMessage()
                ));
            }
        }

        // Podsumowanie
        $output->writeln('');
        $output->writeln('<info>===== PODSUMOWANIE =====</info>');
        $output->writeln(sprintf('  Przetworzono:  %d', $processed));
        $output->writeln(sprintf('  Utworzono:     %d', $stats['created']));
        $output->writeln(sprintf('  Zaktualizowano: %d', $stats['updated']));
        $output->writeln(sprintf('  Pominięto:     %d', $stats['skipped']));
        $output->writeln(sprintf('  Bez kategorii: %d', $stats['no_category']));
        $output->writeln(sprintf('  Bez ceny:      %d', $stats['no_price']));
        $output->writeln(sprintf('  Błędy:         %d', $stats['errors']));

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Ustal Magento category ID dla produktu.
     *
     * Kolejność lookup:
     * 1. masterCategoryId z PIM config → mappings.magento w categories.json
     * 2. catalog-product-categories.json (bevo:SKU → master ID) → mappings.magento
     * 3. category name z PIM → CategoryMap reverse lookup
     */
    private function resolveCategoryId(
        array $pimConfig,
        string $sku,
        array $categoryMappings,
        array $productCategories,
        array $reverseLookup
    ): ?int {
        // 1. masterCategoryId
        $masterId = $pimConfig['masterCategoryId'] ?? '';
        if ($masterId && isset($categoryMappings[$masterId])) {
            return $categoryMappings[$masterId];
        }

        // 2. catalog-product-categories.json
        if (isset($productCategories[$sku])) {
            $catId = $productCategories[$sku];
            if (isset($categoryMappings[$catId])) {
                return $categoryMappings[$catId];
            }
        }

        // 3. Nazwa kategorii z PIM → reverse lookup
        $catName = $pimConfig['category'] ?? '';
        if ($catName && isset($categoryMappings[$catName])) {
            return $categoryMappings[$catName];
        }

        return null;
    }

    /**
     * Resolve attribute set name → ID.
     */
    private function resolveAttributeSetId(string $name): ?int
    {
        $entityTypeId = $this->eavConfig->getEntityType('catalog_product')->getEntityTypeId();

        /** @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection $collection */
        $collection = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory::class)
            ->create()
            ->setEntityTypeFilter($entityTypeId)
            ->addFieldToFilter('attribute_set_name', $name);

        $set = $collection->getFirstItem();
        return $set && $set->getId() ? (int) $set->getId() : null;
    }
}
