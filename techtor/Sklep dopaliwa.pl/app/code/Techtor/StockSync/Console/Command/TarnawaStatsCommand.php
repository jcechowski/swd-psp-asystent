<?php

declare(strict_types=1);

namespace Techtor\StockSync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\StockSync\Model\Config;
use Techtor\StockSync\Model\TarnawaReader;

/**
 * bin/magento techtor:stock:tarnawa
 *
 * Pokaz statystyki ostatniego scrape'a Tarnawa.
 */
class TarnawaStatsCommand extends Command
{
    public function __construct(
        private readonly TarnawaReader $tarnawaReader,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:stock:tarnawa');
        $this->setDescription('Pokaz statystyki scrapera Tarnawa');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isTarnawaEnabled()) {
            $output->writeln('<error>Tarnawa jest wylaczona w konfiguracji.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Katalog scrapera: %s</info>', $this->config->getTarnawaDir()));
        $output->writeln('');

        // Statystyki z stock_updates.json
        $stats = $this->tarnawaReader->getLastScrapeStats();
        if ($stats !== null) {
            $output->writeln('<comment>Ostatni scrape:</comment>');
            $output->writeln(sprintf('  Start:      %s', $stats['start'] ?? '?'));
            $output->writeln(sprintf('  Czas:       %.1f min', $stats['durationMinutes'] ?? 0));
            $output->writeln(sprintf('  Produktow:  %d', $stats['productsChecked'] ?? 0));
            $output->writeln(sprintf('  Bledow:     %d', $stats['errors'] ?? 0));

            $statuses = $stats['statuses'] ?? [];
            if (!empty($statuses)) {
                $output->writeln('');
                $output->writeln('<comment>Statusy:</comment>');
                $output->writeln(sprintf('  <info>in-stock</info>:      %d', $statuses['in-stock'] ?? 0));
                $output->writeln(sprintf('  <comment>on-backorder</comment>:  %d', $statuses['on-backorder'] ?? 0));
                $output->writeln(sprintf('  <error>out-of-stock</error>:  %d', $statuses['out-of-stock'] ?? 0));
            }
        } else {
            $output->writeln('<comment>Brak pliku stock_updates.json — scraper jeszcze nie uruchomiony?</comment>');
        }

        // Szybki odczyt aktualnych danych
        $output->writeln('');
        $products = $this->tarnawaReader->readAll();
        $output->writeln(sprintf('Produktow w katalogu: <info>%d</info>', count($products)));

        if (!empty($products)) {
            $inStock = 0;
            $backorder = 0;
            $outOfStock = 0;
            foreach ($products as $p) {
                match ($p->status) {
                    'in-stock' => $inStock++,
                    'on-backorder' => $backorder++,
                    'out-of-stock' => $outOfStock++,
                    default => null,
                };
            }
            $output->writeln(sprintf('  in-stock: %d | on-backorder: %d | out-of-stock: %d', $inStock, $backorder, $outOfStock));
        }

        return Command::SUCCESS;
    }
}
