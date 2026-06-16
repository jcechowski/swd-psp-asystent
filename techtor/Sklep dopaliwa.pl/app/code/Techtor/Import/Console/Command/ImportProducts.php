<?php

declare(strict_types=1);

namespace Techtor\Import\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Import\Model\CategoryMap;
use Techtor\Import\Model\PimReader;
use Techtor\Import\Model\ProductMapper;

/**
 * Import produktów z PIM do Magento — direct SQL approach.
 *
 * ProductRepository::save() w Magento 2.4.7 ma bug z EntityManager/URL rewrite
 * który powoduje rollback transakcji EAV. Zamiast tego robimy bezpośrednie
 * INSERT/UPDATE do tabel EAV co jest 10x szybsze i stabilne.
 *
 * Użycie:
 *   bin/magento techtor:import:products [--dry-run] [--limit=N] [--sku=XXX] [--skip-existing]
 */
class ImportProducts extends Command
{
    private PimReader $pimReader;
    private ProductMapper $productMapper;
    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;
    private State $appState;

    /** @var array<string, int> attribute_code → attribute_id */
    private array $attrIds = [];

    /** @var int|null attribute_set_id */
    private ?int $attrSetId = null;

    public function __construct(
        PimReader $pimReader,
        ProductMapper $productMapper,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        State $appState
    ) {
        $this->pimReader = $pimReader;
        $this->productMapper = $productMapper;
        $this->resource = $resource;
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

        // Załaduj attribute IDs i attribute set ID
        $conn = $this->resource->getConnection();
        $this->loadAttributeIds($conn);
        $this->loadAttributeSetId($conn);

        $output->writeln(sprintf(
            '  Produkty: %d | Kategorie Magento: %d | Attr set: %d',
            count($configs),
            count($categoryMappings),
            $this->attrSetId ?? 0
        ));

        // Zbuduj indeks istniejących SKU → entity_id
        $existingSkus = $this->loadExistingSkus($conn);
        $output->writeln(sprintf('  Istniejące produkty w Magento: %d', count($existingSkus)));

        // Filtr SKU
        if ($onlySku) {
            if (isset($configs[$onlySku])) {
                $configs = [$onlySku => $configs[$onlySku]];
            } else {
                $output->writeln(sprintf('<error>SKU "%s" nie znaleziony w PIM</error>', $onlySku));
                return Command::FAILURE;
            }
        }

        if ($offset > 0) {
            $configs = array_slice($configs, $offset, null, true);
        }

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

            // Pomijaj węże (W*) — FlexGen
            if (preg_match('/^W[A-Z]\d{3}\d{3}/', $sku)) {
                continue;
            }

            $exists = isset($existingSkus[$sku]);

            if ($exists && $skipExisting) {
                $stats['skipped']++;
                continue;
            }

            // BEVO data
            $bevoData = $this->pimReader->readBevoProduct($sku);

            // Kategoria
            $categoryId = $this->resolveCategoryId($pimConfig, $sku, $categoryMappings, $productCategories);
            if (!$categoryId) {
                $stats['no_category']++;
            }

            // Mapuj
            $mapped = $this->productMapper->mapToMagento($pimConfig, $bevoData, $categoryId);

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

            try {
                if ($exists) {
                    $entityId = $existingSkus[$sku];
                    $this->updateProduct($conn, $entityId, $mapped);
                    $stats['updated']++;
                } else {
                    $entityId = $this->createProduct($conn, $sku, $mapped);
                    $existingSkus[$sku] = $entityId;
                    $stats['created']++;
                }

                if ($processed % 50 === 0) {
                    $output->writeln(sprintf(
                        '  ... %d (created=%d, updated=%d, errors=%d)',
                        $processed, $stats['created'], $stats['updated'], $stats['errors']
                    ));
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $output->writeln(sprintf('  <error>[ERROR] %s: %s</error>', $sku, $e->getMessage()));
            }
        }

        // Podsumowanie
        $output->writeln('');
        $output->writeln('<info>===== PODSUMOWANIE =====</info>');
        $output->writeln(sprintf('  Przetworzono:    %d', $processed));
        $output->writeln(sprintf('  Utworzono:       %d', $stats['created']));
        $output->writeln(sprintf('  Zaktualizowano:  %d', $stats['updated']));
        $output->writeln(sprintf('  Pominięto:       %d', $stats['skipped']));
        $output->writeln(sprintf('  Bez kategorii:   %d', $stats['no_category']));
        $output->writeln(sprintf('  Bez ceny:        %d', $stats['no_price']));
        $output->writeln(sprintf('  Błędy:           %d', $stats['errors']));

        if (!$dryRun && $stats['created'] > 0) {
            $output->writeln('');
            $output->writeln('<comment>Uruchom po imporcie:</comment>');
            $output->writeln('  bin/magento indexer:reindex');
            $output->writeln('  bin/magento cache:flush');
        }

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Utwórz nowy produkt — INSERT do catalog_product_entity + EAV tables + stock + category.
     */
    private function createProduct(AdapterInterface $conn, string $sku, array $mapped): int
    {
        // 1. Entity row
        $conn->insert('catalog_product_entity', [
            'attribute_set_id' => $this->attrSetId,
            'type_id' => 'simple',
            'sku' => $sku,
            'has_options' => 0,
            'required_options' => 0,
        ]);
        $entityId = (int) $conn->lastInsertId('catalog_product_entity');

        // 2. EAV attributes
        $this->saveEavValues($conn, $entityId, $mapped);

        // 3. Website assignment
        $conn->insertOnDuplicate('catalog_product_website', [
            'product_id' => $entityId,
            'website_id' => 1,
        ]);

        // 4. Stock
        $qty = $mapped['_stock_qty'] ?? 0;
        $conn->insert('cataloginventory_stock_item', [
            'product_id' => $entityId,
            'stock_id' => 1,
            'qty' => $qty,
            'is_in_stock' => $qty > 0 ? 1 : 0,
            'manage_stock' => 1,
            'use_config_manage_stock' => 1,
        ]);
        $conn->insert('cataloginventory_stock_status', [
            'product_id' => $entityId,
            'website_id' => 0,
            'stock_id' => 1,
            'qty' => $qty,
            'stock_status' => $qty > 0 ? 1 : 0,
        ]);

        // 5. Category assignment
        if (!empty($mapped['category_ids'])) {
            foreach ($mapped['category_ids'] as $catId) {
                $conn->insertOnDuplicate('catalog_category_product', [
                    'category_id' => $catId,
                    'product_id' => $entityId,
                    'position' => 0,
                ]);
            }
        }

        // 6. URL rewrite
        if (!empty($mapped['url_key'])) {
            $conn->insertOnDuplicate('url_rewrite', [
                'entity_type' => 'product',
                'entity_id' => $entityId,
                'request_path' => $mapped['url_key'] . '.html',
                'target_path' => 'catalog/product/view/id/' . $entityId,
                'store_id' => 1,
                'is_autogenerated' => 1,
            ], ['request_path', 'target_path']);
        }

        return $entityId;
    }

    /**
     * Aktualizuj istniejący produkt.
     */
    private function updateProduct(AdapterInterface $conn, int $entityId, array $mapped): void
    {
        $this->saveEavValues($conn, $entityId, $mapped);

        // Update stock
        $qty = $mapped['_stock_qty'] ?? 0;
        $conn->update('cataloginventory_stock_item', [
            'qty' => $qty,
            'is_in_stock' => $qty > 0 ? 1 : 0,
        ], ['product_id = ?' => $entityId, 'stock_id = ?' => 1]);

        $conn->insertOnDuplicate('cataloginventory_stock_status', [
            'product_id' => $entityId,
            'website_id' => 0,
            'stock_id' => 1,
            'qty' => $qty,
            'stock_status' => $qty > 0 ? 1 : 0,
        ], ['qty', 'stock_status']);

        // Update categories
        if (!empty($mapped['category_ids'])) {
            foreach ($mapped['category_ids'] as $catId) {
                $conn->insertOnDuplicate('catalog_category_product', [
                    'category_id' => $catId,
                    'product_id' => $entityId,
                    'position' => 0,
                ]);
            }
        }
    }

    /**
     * Zapisz wartości EAV (varchar, decimal, int, text) dla produktu.
     */
    private function saveEavValues(AdapterInterface $conn, int $entityId, array $mapped): void
    {
        $storeId = 0;

        // Varchar attributes
        $varchars = [
            'name' => $mapped['name'] ?? '',
            'url_key' => $mapped['url_key'] ?? '',
            'meta_title' => $mapped['meta_title'] ?? '',
            'meta_description' => $mapped['meta_description'] ?? '',
            'meta_keyword' => $mapped['meta_keyword'] ?? '',
            'manufacturer_code' => $mapped['manufacturer_code'] ?? '',
            'ean' => $mapped['ean'] ?? '',
        ];

        foreach ($varchars as $code => $value) {
            if ($value === '' || !isset($this->attrIds[$code])) {
                continue;
            }
            $conn->insertOnDuplicate('catalog_product_entity_varchar', [
                'attribute_id' => $this->attrIds[$code],
                'store_id' => $storeId,
                'entity_id' => $entityId,
                'value' => mb_substr((string) $value, 0, 255),
            ], ['value']);
        }

        // Decimal attributes
        $decimals = [
            'price' => $mapped['price'] ?? 0,
            'cost' => $mapped['cost'] ?? 0,
            'weight' => $mapped['weight'] ?? 0,
        ];

        foreach ($decimals as $code => $value) {
            if (!isset($this->attrIds[$code])) {
                continue;
            }
            $floatVal = (float) $value;
            if ($floatVal <= 0 && $code !== 'weight') {
                continue;
            }
            $conn->insertOnDuplicate('catalog_product_entity_decimal', [
                'attribute_id' => $this->attrIds[$code],
                'store_id' => $storeId,
                'entity_id' => $entityId,
                'value' => $floatVal,
            ], ['value']);
        }

        // Int attributes
        $ints = [
            'status' => 1, // enabled
            'visibility' => 4, // catalog + search
            'tax_class_id' => 2, // taxable goods
        ];

        foreach ($ints as $code => $value) {
            if (!isset($this->attrIds[$code])) {
                continue;
            }
            $conn->insertOnDuplicate('catalog_product_entity_int', [
                'attribute_id' => $this->attrIds[$code],
                'store_id' => $storeId,
                'entity_id' => $entityId,
                'value' => (int) $value,
            ], ['value']);
        }

        // Text attributes
        $texts = [
            'description' => $mapped['description'] ?? '',
            'short_description' => $mapped['short_description'] ?? '',
        ];

        foreach ($texts as $code => $value) {
            if ($value === '' || !isset($this->attrIds[$code])) {
                continue;
            }
            $conn->insertOnDuplicate('catalog_product_entity_text', [
                'attribute_id' => $this->attrIds[$code],
                'store_id' => $storeId,
                'entity_id' => $entityId,
                'value' => $value,
            ], ['value']);
        }
    }

    /**
     * Załaduj attribute_code → attribute_id mapping.
     */
    private function loadAttributeIds(AdapterInterface $conn): void
    {
        $codes = [
            'name', 'url_key', 'meta_title', 'meta_description', 'meta_keyword',
            'manufacturer_code', 'ean', 'price', 'cost', 'weight',
            'status', 'visibility', 'tax_class_id', 'description', 'short_description',
        ];

        $select = $conn->select()
            ->from('eav_attribute', ['attribute_code', 'attribute_id'])
            ->where('entity_type_id = ?', 4) // catalog_product
            ->where('attribute_code IN (?)', $codes);

        $this->attrIds = $conn->fetchPairs($select);
    }

    /**
     * Załaduj attribute set ID "Sprzet paliwowy".
     */
    private function loadAttributeSetId(AdapterInterface $conn): void
    {
        $select = $conn->select()
            ->from('eav_attribute_set', 'attribute_set_id')
            ->where('entity_type_id = ?', 4)
            ->where('attribute_set_name = ?', 'Sprzet paliwowy');

        $this->attrSetId = (int) $conn->fetchOne($select) ?: 4; // fallback to Default
    }

    /**
     * Załaduj istniejące SKU → entity_id.
     */
    private function loadExistingSkus(AdapterInterface $conn): array
    {
        $select = $conn->select()
            ->from('catalog_product_entity', ['sku', 'entity_id']);
        return $conn->fetchPairs($select);
    }

    /**
     * Ustal Magento category ID.
     */
    private function resolveCategoryId(array $pimConfig, string $sku, array $categoryMappings, array $productCategories): ?int
    {
        $masterId = $pimConfig['masterCategoryId'] ?? '';
        if ($masterId && isset($categoryMappings[$masterId])) {
            return $categoryMappings[$masterId];
        }

        if (isset($productCategories[$sku])) {
            $catId = $productCategories[$sku];
            if (isset($categoryMappings[$catId])) {
                return $categoryMappings[$catId];
            }
        }

        $catName = $pimConfig['category'] ?? '';
        if ($catName && isset($categoryMappings[$catName])) {
            return $categoryMappings[$catName];
        }

        return null;
    }
}
