<?php

declare(strict_types=1);

namespace Techtor\Firmao\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Firmao\Cron\SyncPrices;
use Techtor\Firmao\Cron\SyncProducts;
use Techtor\Firmao\Model\Config;

/**
 * bin/magento techtor:firmao:sync-products
 * bin/magento techtor:firmao:sync-products --prices
 */
class SyncProductsCommand extends Command
{
    public function __construct(
        private readonly SyncProducts $syncProducts,
        private readonly SyncPrices $syncPrices,
        private readonly Config $config,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:firmao:sync-products');
        $this->setDescription('Synchronizuj produkty z Firmao');
        $this->addOption(
            'prices',
            'p',
            InputOption::VALUE_NONE,
            'Synchronizuj tylko ceny (szybsze)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Firmao jest wylaczone w konfiguracji.</error>');
            return Command::FAILURE;
        }

        // Magento wymaga ustawienia area dla operacji katalogowych
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception) {
            // area juz ustawiona
        }

        if ($input->getOption('prices')) {
            $output->writeln('<info>Synchronizacja cen Firmao → Magento...</info>');
            $this->syncPrices->execute();
        } else {
            $output->writeln('<info>Synchronizacja produktow Firmao → Magento...</info>');
            $this->syncProducts->execute();
        }

        $output->writeln('<info>Gotowe. Szczegoly w var/log/firmao.log</info>');
        return Command::SUCCESS;
    }
}
