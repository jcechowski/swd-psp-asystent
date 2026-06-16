<?php

declare(strict_types=1);

namespace Techtor\Import\Console\Command;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Import\Model\CategoryMap;

/**
 * Tworzy drzewo kategorii Magento na podstawie CategoryMap.
 *
 * Uzycie:
 *   bin/magento techtor:import:categories [--dry-run] [--update-json=/path/to/categories.json]
 */
class ImportCategories extends Command
{
    private CategoryFactory $categoryFactory;
    private CategoryRepositoryInterface $categoryRepository;
    private CategoryCollectionFactory $collectionFactory;
    private StoreManagerInterface $storeManager;
    private State $appState;

    public function __construct(
        CategoryFactory $categoryFactory,
        CategoryRepositoryInterface $categoryRepository,
        CategoryCollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager,
        State $appState
    ) {
        $this->categoryFactory = $categoryFactory;
        $this->categoryRepository = $categoryRepository;
        $this->collectionFactory = $collectionFactory;
        $this->storeManager = $storeManager;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:import:categories')
            ->setDescription('Tworzy drzewo kategorii Magento z CategoryMap (PIM → Magento)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tylko wyswietl co zostanie utworzone')
            ->addOption(
                'update-json',
                null,
                InputOption::VALUE_REQUIRED,
                'Sciezka do categories.json — wpisze ID Magento do mappings.magento'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            // area already set
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $jsonPath = $input->getOption('update-json');
        $tree = CategoryMap::getTree();
        $stats = CategoryMap::stats();

        $output->writeln(sprintf(
            '<info>Drzewo kategorii: %d L1, %d L2</info>',
            $stats['l1'],
            $stats['l2']
        ));

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] Nic nie zostanie utworzone.</comment>');
        }

        // Root category (Default Category) — Magento default = ID 2
        $rootCategoryId = (int) $this->storeManager->getStore()->getRootCategoryId();
        if ($rootCategoryId === 0) {
            $rootCategoryId = 2;
        }

        $output->writeln(sprintf('Root category ID: %d', $rootCategoryId));

        // Zbuduj indeks istniejących kategorii po nazwie
        $existingByNameAndParent = $this->buildExistingIndex();

        $createdL1 = 0;
        $createdL2 = 0;
        $skippedL1 = 0;
        $skippedL2 = 0;

        // Mapowanie PIM name → Magento category ID (do zapisu w JSON)
        $pimToMagentoId = [];

        $l1Position = 10;
        foreach ($tree as $l1Name => $l2s) {
            $l1Key = $rootCategoryId . '/' . $l1Name;
            $l1Id = $existingByNameAndParent[$l1Key] ?? null;

            if ($l1Id) {
                $output->writeln(sprintf('  [SKIP] L1: %s (ID %d already exists)', $l1Name, $l1Id));
                $skippedL1++;
            } elseif ($dryRun) {
                $output->writeln(sprintf('  [CREATE] L1: %s', $l1Name));
                $createdL1++;
                // W dry-run nie mamy ID
                foreach ($l2s as $l2Name => $pimNames) {
                    $output->writeln(sprintf('    [CREATE] L2: %s ← PIM: %s', $l2Name, implode(', ', $pimNames)));
                    $createdL2++;
                }
                continue;
            } else {
                $l1Id = $this->createCategory($l1Name, $rootCategoryId, $l1Position, true);
                $output->writeln(sprintf('  [CREATED] L1: %s → ID %d', $l1Name, $l1Id));
                $createdL1++;
            }

            // L2 categories
            $l2Position = 10;
            foreach ($l2s as $l2Name => $pimNames) {
                $l2Key = $l1Id . '/' . $l2Name;
                $l2Id = $existingByNameAndParent[$l2Key] ?? null;

                if ($l2Id) {
                    $output->writeln(sprintf('    [SKIP] L2: %s (ID %d)', $l2Name, $l2Id));
                    $skippedL2++;
                } elseif (!$dryRun) {
                    $l2Id = $this->createCategory($l2Name, (int) $l1Id, $l2Position, true);
                    $output->writeln(sprintf('    [CREATED] L2: %s → ID %d ← PIM: %s', $l2Name, $l2Id, implode(', ', $pimNames)));
                    $createdL2++;
                }

                // Mapuj PIM names → Magento L2 ID
                if ($l2Id) {
                    foreach ($pimNames as $pimName) {
                        $pimToMagentoId[$pimName] = (int) $l2Id;
                    }
                }

                $l2Position += 10;
            }

            $l1Position += 10;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Wynik: L1 created=%d skipped=%d | L2 created=%d skipped=%d</info>',
            $createdL1,
            $skippedL1,
            $createdL2,
            $skippedL2
        ));

        // Zapisz mapowanie do categories.json jeśli podano ścieżkę
        if ($jsonPath && !$dryRun && !empty($pimToMagentoId)) {
            $this->updateCategoriesJson($jsonPath, $pimToMagentoId, $output);
        }

        return Command::SUCCESS;
    }

    /**
     * Zbuduj indeks: "parentId/name" → categoryId
     */
    private function buildExistingIndex(): array
    {
        $collection = $this->collectionFactory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('is_active');

        $index = [];
        foreach ($collection as $cat) {
            $key = $cat->getParentId() . '/' . $cat->getName();
            $index[$key] = (int) $cat->getId();
        }
        return $index;
    }

    /**
     * Utwórz kategorię w Magento.
     */
    private function createCategory(string $name, int $parentId, int $position, bool $isActive): int
    {
        $category = $this->categoryFactory->create();
        $category->setName($name);
        $category->setIsActive($isActive);
        $category->setParentId($parentId);
        $category->setPosition($position);
        $category->setIncludeInMenu(true);

        // URL key — slug z nazwy
        $urlKey = $this->generateUrlKey($name);
        $category->setUrlKey($urlKey);

        // Store
        $category->setStoreId(0);

        // Path — Magento wymaga prawidłowej ścieżki
        $parentCategory = $this->categoryRepository->get($parentId);
        $category->setPath($parentCategory->getPath());

        $saved = $this->categoryRepository->save($category);
        return (int) $saved->getId();
    }

    /**
     * Generuj URL key z polskiej nazwy.
     */
    private function generateUrlKey(string $name): string
    {
        $slug = mb_strtolower($name);

        // Polskie znaki
        $pl = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'];
        $en = ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'];
        $slug = str_replace($pl, $en, $slug);

        // Tylko litery, cyfry, myślniki
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Wpisz Magento ID do categories.json (pole mappings.magento).
     */
    private function updateCategoriesJson(string $path, array $pimToMagentoId, OutputInterface $output): void
    {
        if (!file_exists($path)) {
            $output->writeln(sprintf('<error>Plik nie istnieje: %s</error>', $path));
            return;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!isset($data['master'])) {
            $output->writeln('<error>Brak klucza "master" w JSON</error>');
            return;
        }

        $updated = 0;
        foreach ($data['master'] as &$cat) {
            $name = $cat['name'] ?? '';
            if (isset($pimToMagentoId[$name])) {
                $cat['mappings']['magento'] = [
                    'id' => $pimToMagentoId[$name],
                    'label' => $name,
                ];
                $updated++;
            }
        }
        unset($cat);

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        $output->writeln(sprintf(
            '<info>Zaktualizowano categories.json: %d/%d kategorii z magento ID</info>',
            $updated,
            count($pimToMagentoId)
        ));
    }
}
