<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\BaseLinker\Api\ClientInterface;
use Techtor\BaseLinker\Model\Config;

/**
 * bin/magento techtor:bl:test
 *
 * Testuje polaczenie z API BaseLinker — pobiera liste statusow zamowien.
 */
class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:bl:test');
        $this->setDescription('Testuj polaczenie z BaseLinker API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>BaseLinker jest wylaczony w konfiguracji.</error>');
            $output->writeln('Wlacz w: Stores > Configuration > Techtor > BaseLinker');
            return Command::FAILURE;
        }

        $output->writeln('<info>Testowanie polaczenia z BaseLinker...</info>');

        try {
            $statuses = $this->client->getOrderStatusList();
            $output->writeln(sprintf('<info>Polaczenie OK! Znaleziono %d statusow:</info>', count($statuses)));

            foreach ($statuses as $status) {
                $output->writeln(sprintf(
                    '  [%d] %s (klient widzi: %s)',
                    $status['id'] ?? 0,
                    $status['name'] ?? '?',
                    $status['name_for_customer'] ?? '?'
                ));
            }

            // Test inventory
            $inventoryId = $this->config->getInventoryId();
            $output->writeln('');
            $output->writeln(sprintf('<info>Testowanie magazynu (ID: %d)...</info>', $inventoryId));

            $products = $this->client->getInventoryProducts($inventoryId);
            $count = count($products['products'] ?? []);
            $output->writeln(sprintf('<info>Magazyn OK! Znaleziono %d produktow.</info>', $count));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>BLAD: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
