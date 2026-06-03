<?php

declare(strict_types=1);

namespace Techtor\Firmao\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Firmao\Cron\SyncStock;
use Techtor\Firmao\Model\Config;

/**
 * bin/magento techtor:firmao:sync-stock
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
        $this->setName('techtor:firmao:sync-stock');
        $this->setDescription('Synchronizuj stany magazynowe z Firmao');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isStockSyncEnabled()) {
            $output->writeln('<error>Synchronizacja stanow Firmao jest wylaczona.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Synchronizacja stanow Firmao → Magento MSI...</info>');
        $this->syncStock->execute();
        $output->writeln('<info>Gotowe. Szczegoly w var/log/firmao.log</info>');

        return Command::SUCCESS;
    }
}
