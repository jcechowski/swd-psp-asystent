<?php

declare(strict_types=1);

namespace Techtor\StockSync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\StockSync\Model\Config;
use Techtor\StockSync\Model\TarnawaReader;

/**
 * bin/magento techtor:stock:status <SKU>
 *
 * Pokaz status jednego produktu ze wszystkich zrodel.
 */
class StatusCommand extends Command
{
    public function __construct(
        private readonly FirmaoClientInterface $firmaoClient,
        private readonly TarnawaReader $tarnawaReader,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:stock:status');
        $this->setDescription('Pokaz stan magazynowy produktu z Firmao + Tarnawa');
        $this->addArgument('sku', InputArgument::REQUIRED, 'SKU produktu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sku = $input->getArgument('sku');

        $output->writeln(sprintf('<info>Status produktu: %s</info>', $sku));
        $output->writeln('');

        // Firmao
        $output->writeln('<comment>Firmao (magazyn wlasny):</comment>');
        try {
            $firmaoProduct = $this->firmaoClient->getProductByCode($sku);
            if ($firmaoProduct !== null) {
                $firmaoStock = (float) ($firmaoProduct['currentStoreState'] ?? 0);
                $name = $firmaoProduct['name'] ?? '?';
                $output->writeln(sprintf('  Nazwa:  %s', $name));
                $output->writeln(sprintf('  Stan:   <info>%.0f szt</info>', $firmaoStock));
                $purchaseNet = $firmaoProduct['purchasePriceGroup']['netPrice'] ?? 0;
                $output->writeln(sprintf('  Zakup:  %.2f PLN netto', $purchaseNet));
            } else {
                $output->writeln('  <error>Nie znaleziony w Firmao</error>');
                $firmaoStock = 0;
            }
        } catch (\Exception $e) {
            $output->writeln(sprintf('  <error>Blad Firmao: %s</error>', $e->getMessage()));
            $firmaoStock = 0;
        }

        // Tarnawa
        $output->writeln('');
        $output->writeln('<comment>Tarnawa (dostawca):</comment>');
        if ($this->config->isTarnawaEnabled()) {
            $tarnawa = $this->tarnawaReader->readProduct($sku);
            if ($tarnawa !== null) {
                $output->writeln(sprintf('  Stan:   %.0f szt', $tarnawa->quantity));
                $output->writeln(sprintf('  Status: %s', $this->formatStatus($tarnawa->status)));
                $output->writeln(sprintf('  Cena:   %.2f PLN netto', $tarnawa->priceNetto));
                $output->writeln(sprintf('  Update: %s', $tarnawa->lastUpdated));
                $tarnawaStock = $tarnawa->quantity;
                $tarnawaStatus = $tarnawa->status;
            } else {
                $output->writeln('  <comment>Nie znaleziony w scraperze Tarnawa</comment>');
                $tarnawaStock = 0;
                $tarnawaStatus = 'unknown';
            }
        } else {
            $output->writeln('  <comment>Tarnawa wylaczona w konfiguracji</comment>');
            $tarnawaStock = 0;
            $tarnawaStatus = 'unknown';
        }

        // Podsumowanie
        $totalStock = $firmaoStock + $tarnawaStock;
        $output->writeln('');
        $output->writeln('<comment>Wynik StockSync:</comment>');
        $output->writeln(sprintf('  Total:    %.0f szt (Firmao: %.0f + Tarnawa: %.0f)', $totalStock, $firmaoStock, $tarnawaStock));

        if ($firmaoStock > 0) {
            $output->writeln('  Dostawa:  <info>Wysylka 24h</info> (z wlasnego magazynu)');
        } elseif ($tarnawaStock > 0) {
            $output->writeln('  Dostawa:  <comment>Wysylka 48h</comment> (od dostawcy)');
        } elseif ($tarnawaStatus === 'on-backorder') {
            $output->writeln('  Dostawa:  <comment>Na zamowienie</comment> (backorder u dostawcy)');
        } else {
            $output->writeln('  Dostawa:  <error>Niedostepny</error>');
        }

        return Command::SUCCESS;
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'in-stock' => '<info>in-stock</info>',
            'on-backorder' => '<comment>on-backorder</comment>',
            'out-of-stock' => '<error>out-of-stock</error>',
            default => $status,
        };
    }
}
