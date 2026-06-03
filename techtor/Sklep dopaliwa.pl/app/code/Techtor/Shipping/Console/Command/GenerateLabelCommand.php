<?php

declare(strict_types=1);

namespace Techtor\Shipping\Console\Command;

use Magento\Sales\Api\OrderRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Shipping\Model\LabelService;

/**
 * bin/magento techtor:shipping:label <order_id>
 *
 * Recznie generuje etykiete wysylkowa dla zamowienia.
 */
class GenerateLabelCommand extends Command
{
    public function __construct(
        private readonly LabelService $labelService,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:shipping:label');
        $this->setDescription('Wygeneruj etykiete wysylkowa dla zamowienia');
        $this->addArgument('order_id', InputArgument::REQUIRED, 'ID zamowienia (entity_id lub increment_id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = $input->getArgument('order_id');

        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Zamowienie %s nie znalezione: %s</error>', $orderId, $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Zamowienie: %s | Shipping: %s</info>',
            $order->getIncrementId(),
            $order->getShippingDescription()
        ));

        $shippingMethod = $order->getShippingMethod() ?? '';
        if (!str_starts_with($shippingMethod, 'techtor_')) {
            $output->writeln('<error>Zamowienie nie uzywa carriera Techtor.</error>');
            return Command::FAILURE;
        }

        try {
            $result = $this->labelService->generateForOrder($order);

            $output->writeln('<info>Etykieta wygenerowana!</info>');
            $output->writeln(sprintf('  Carrier:  %s', $result->getCarrierCode()));
            $output->writeln(sprintf('  Tracking: %s', $result->getTrackingNumber()));
            $output->writeln(sprintf('  PDF size: %d bytes', strlen(base64_decode($result->getLabelPdf()))));

            // Zapisz PDF
            $pdfPath = sprintf('/tmp/label_%s.pdf', $order->getIncrementId());
            file_put_contents($pdfPath, base64_decode($result->getLabelPdf()));
            $output->writeln(sprintf('  Zapisano: %s', $pdfPath));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>BLAD: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
