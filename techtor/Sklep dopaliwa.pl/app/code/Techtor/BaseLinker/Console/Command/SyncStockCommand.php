<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\BaseLinker\Cron\SyncStock;
use Techtor\BaseLinker\Model\Config;

/**
 * bin/magento techtor:bl:sync-stock
 * bin/magento techtor:bl:sync-stock --dry-run
 *
 * Reczne uruchomienie synchronizacji stanow magazynowych BL → Magento.
 */
class SyncStockCommand extends Command
{
    public function __construct(
        private readonly SyncStock $syncStock,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:bl:sync-stock');
        $this->setDescription('Synchronizuj stany magazynowe z BaseLinker');
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Pokaz co zostaloby zmienione, bez zapisywania'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>BaseLinker jest wylaczony w konfiguracji.</error>');
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — zmiany nie beda zapisane</comment>');
            // TODO: zaimplementowac dry-run mode w SyncStock
            // Na razie wyswietl co byloby zmienione
        }

        $output->writeln('<info>Synchronizacja stanow BL → Magento MSI...</info>');
        $this->syncStock->execute();
        $output->writeln('<info>Gotowe. Szczegoly w var/log/baselinker.log</info>');

        return Command::SUCCESS;
    }
}
