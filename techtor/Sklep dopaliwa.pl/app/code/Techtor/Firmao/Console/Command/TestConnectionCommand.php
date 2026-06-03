<?php

declare(strict_types=1);

namespace Techtor\Firmao\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;
use Techtor\Firmao\Model\Config;

/**
 * bin/magento techtor:firmao:test
 */
class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly FirmaoClientInterface $client,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:firmao:test');
        $this->setDescription('Testuj polaczenie z Firmao API');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Firmao jest wylaczone w konfiguracji.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Laczenie z Firmao: %s</info>', $this->config->getApiUrl()));

        try {
            // Pobierz 1 produkt zeby sprawdzic polaczenie
            $response = $this->client->getProducts(0, 1);
            $total = $response['totalSize'] ?? 0;
            $output->writeln(sprintf('<info>Polaczenie OK! Produktow w Firmao: %d</info>', $total));

            // Pokaz pierwszy produkt jako przyklad
            $products = $response['data'] ?? [];
            if (!empty($products)) {
                $p = $products[0];
                $output->writeln('');
                $output->writeln('<comment>Przykladowy produkt:</comment>');
                $output->writeln(sprintf('  SKU:   %s', $p['productCode'] ?? '?'));
                $output->writeln(sprintf('  Nazwa: %s', $p['name'] ?? '?'));
                $output->writeln(sprintf('  Stan:  %.0f szt', $p['currentStoreState'] ?? 0));

                $purchaseNet = $p['purchasePriceGroup']['netPrice'] ?? 0;
                $output->writeln(sprintf('  Cena zakupu netto: %.2f PLN', $purchaseNet));
            }

            // Test magazynu
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Warehouse ID: %d</info>',
                $this->config->getWarehouseId()
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>BLAD: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
