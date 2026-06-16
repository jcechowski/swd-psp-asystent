<?php

declare(strict_types=1);

namespace Techtor\Import\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Import\Model\PimReader;

/**
 * Synchronizacja stanów magazynowych z PIM do Magento.
 *
 * Czyta produkty-configs.json (stockFirmao + stockTarnawa) i aktualizuje
 * CatalogInventory w Magento. Gotowy pod CRON.
 *
 * Użycie:
 *   bin/magento techtor:import:stock [--dry-run] [--limit=N] [--sku=XXX]
 */
class ImportStock extends Command
{
    private PimReader $pimReader;
    private ProductRepositoryInterface $productRepository;
    private StockRegistryInterface $stockRegistry;
    private State $appState;

    public function __construct(
        PimReader $pimReader,
        ProductRepositoryInterface $productRepository,
        StockRegistryInterface $stockRegistry,
        State $appState
    ) {
        $this->pimReader = $pimReader;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:import:stock')
            ->setDescription('Synchronizacja stanów magazynowych z PIM (Firmao + Tarnawa) do Magento')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tylko wyświetl zmiany')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max N produktów')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Tylko konkretny SKU');
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

        // Walidacja
        $missing = $this->pimReader->validateImportDir();
        if (!empty($missing)) {
            $output->writeln('<error>Brakujące pliki w var/import/pim/:</error>');
            foreach ($missing as $f) {
                $output->writeln("  - $f");
            }
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN]</comment>');
        }

        $configs = $this->pimReader->readProductConfigs();
        $output->writeln(sprintf('Produkty w PIM: %d', count($configs)));

        if ($onlySku) {
            if (isset($configs[$onlySku])) {
                $configs = [$onlySku => $configs[$onlySku]];
            } else {
                $output->writeln(sprintf('<error>SKU "%s" nie znaleziony</error>', $onlySku));
                return Command::FAILURE;
            }
        }

        $stats = ['updated' => 0, 'not_in_magento' => 0, 'unchanged' => 0, 'errors' => 0];
        $processed = 0;

        foreach ($configs as $code => $pimConfig) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $sku = $pimConfig['code'] ?? $code;

            // Pomijaj węże (FlexGen)
            if (preg_match('/^W[A-Z]\d{3}\d{3}/', $sku)) {
                continue;
            }

            // Sprawdź czy produkt istnieje w Magento
            try {
                $this->productRepository->get($sku);
            } catch (NoSuchEntityException $e) {
                $stats['not_in_magento']++;
                continue;
            }

            $processed++;

            // Oblicz stock
            $stockFirmao = (float) ($pimConfig['stockFirmao'] ?? 0);
            $stockTarnawa = (float) ($pimConfig['stockTarnawa'] ?? 0);
            $totalStock = $stockFirmao + $stockTarnawa;
            $isInStock = $totalStock > 0;

            try {
                $stockItem = $this->stockRegistry->getStockItemBySku($sku);
                $currentQty = (float) $stockItem->getQty();
                $currentInStock = (bool) $stockItem->getIsInStock();

                // Sprawdź czy coś się zmieniło
                if (abs($currentQty - $totalStock) < 0.01 && $currentInStock === $isInStock) {
                    $stats['unchanged']++;
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  [STOCK] %s: %.0f → %.0f (Firmao=%.0f, Tarnawa=%.0f) %s',
                        $sku,
                        $currentQty,
                        $totalStock,
                        $stockFirmao,
                        $stockTarnawa,
                        $isInStock ? 'IN_STOCK' : 'OUT_OF_STOCK'
                    ));
                    $stats['updated']++;
                    continue;
                }

                $stockItem->setQty($totalStock);
                $stockItem->setIsInStock($isInStock);
                $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
                $stats['updated']++;

                if ($processed % 100 === 0) {
                    $output->writeln(sprintf('  ... przetworzono %d', $processed));
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $output->writeln(sprintf('  <error>[ERROR] %s: %s</error>', $sku, $e->getMessage()));
            }
        }

        $output->writeln('');
        $output->writeln('<info>===== STOCK SYNC =====</info>');
        $output->writeln(sprintf('  Sprawdzono:      %d', $processed));
        $output->writeln(sprintf('  Zaktualizowano:  %d', $stats['updated']));
        $output->writeln(sprintf('  Bez zmian:       %d', $stats['unchanged']));
        $output->writeln(sprintf('  Nie w Magento:   %d', $stats['not_in_magento']));
        $output->writeln(sprintf('  Błędy:           %d', $stats['errors']));

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
