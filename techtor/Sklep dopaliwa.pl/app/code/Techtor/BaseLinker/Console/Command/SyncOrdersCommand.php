<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\BaseLinker\Cron\SyncOrders;
use Techtor\BaseLinker\Cron\SyncStatuses;
use Techtor\BaseLinker\Model\Config;

/**
 * bin/magento techtor:bl:sync-orders
 * bin/magento techtor:bl:sync-orders --statuses
 *
 * Reczne uruchomienie synchronizacji zamowien lub statusow.
 */
class SyncOrdersCommand extends Command
{
    public function __construct(
        private readonly SyncOrders $syncOrders,
        private readonly SyncStatuses $syncStatuses,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:bl:sync-orders');
        $this->setDescription('Synchronizuj zamowienia z BaseLinker');
        $this->addOption(
            'statuses',
            's',
            InputOption::VALUE_NONE,
            'Synchronizuj statusy zamowien zamiast nowych zamowien'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isOrderSyncEnabled()) {
            $output->writeln('<error>Synchronizacja zamowien jest wylaczona.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('statuses')) {
            $output->writeln('<info>Synchronizacja statusow zamowien BL → Magento...</info>');
            $this->syncStatuses->execute();
            $output->writeln('<info>Gotowe. Szczegoly w var/log/baselinker.log</info>');
        } else {
            $output->writeln('<info>Synchronizacja niezsynchronizowanych zamowien → BL...</info>');
            $this->syncOrders->execute();
            $output->writeln('<info>Gotowe. Szczegoly w var/log/baselinker.log</info>');
        }

        return Command::SUCCESS;
    }
}
