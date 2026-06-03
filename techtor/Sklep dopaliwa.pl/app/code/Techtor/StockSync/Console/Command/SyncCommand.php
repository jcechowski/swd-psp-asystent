<?php

declare(strict_types=1);

namespace Techtor\StockSync\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\StockSync\Cron\MainSync;
use Techtor\StockSync\Model\Config;

/**
 * bin/magento techtor:stock:sync
 * bin/magento techtor:stock:sync --verbose
 *
 * Reczne uruchomienie glownej synchronizacji stanow.
 */
class SyncCommand extends Command
{
    public function __construct(
        private readonly MainSync $mainSync,
        private readonly Config $config,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:stock:sync');
        $this->setDescription('Uruchom synchronizacje stanow magazynowych (Firmao + Tarnawa → Magento)');
        $this->addOption('summary', 's', InputOption::VALUE_NONE, 'Pokaz podsumowanie po zakonczeniu');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>StockSync jest wylaczony w konfiguracji.</error>');
            $output->writeln('Wlacz w: Stores > Configuration > Techtor > Stock Sync');
            return Command::FAILURE;
        }

        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception) {
        }

        $output->writeln('<info>StockSync: Firmao + Tarnawa → Magento MSI...</info>');
        $output->writeln(sprintf('  Tarnawa scraper: %s', $this->config->isTarnawaEnabled() ? 'TAK' : 'NIE'));
        $output->writeln(sprintf('  Export JSON:     %s', $this->config->isExportEnabled() ? 'TAK' : 'NIE'));
        $output->writeln('');

        $this->mainSync->execute();

        if ($input->getOption('summary')) {
            $this->printSummary($output);
        }

        $output->writeln('<info>Gotowe. Szczegoly w var/log/stocksync.log</info>');
        return Command::SUCCESS;
    }

    private function printSummary(OutputInterface $output): void
    {
        $results = $this->mainSync->getResults();

        if (empty($results)) {
            $output->writeln('<comment>Brak wynikow (brak produktow do synca)</comment>');
            return;
        }

        $counts = ['24h' => 0, '48h' => 0, 'na-zamowienie' => 0, 'niedostepny' => 0];
        foreach ($results as $r) {
            $counts[$r['delivery']] = ($counts[$r['delivery']] ?? 0) + 1;
        }

        $output->writeln(sprintf('Zsynchronizowano: <info>%d</info> produktow', count($results)));
        $output->writeln(sprintf('  Wysylka 24h:        <info>%d</info>', $counts['24h']));
        $output->writeln(sprintf('  Wysylka 48h:        <comment>%d</comment>', $counts['48h']));
        $output->writeln(sprintf('  Na zamowienie:      <comment>%d</comment>', $counts['na-zamowienie']));
        $output->writeln(sprintf('  Niedostepny:        <error>%d</error>', $counts['niedostepny']));
    }
}
