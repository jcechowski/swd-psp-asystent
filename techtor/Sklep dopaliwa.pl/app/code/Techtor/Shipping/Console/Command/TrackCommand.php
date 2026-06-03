<?php

declare(strict_types=1);

namespace Techtor\Shipping\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Techtor\Shipping\Model\LabelService;

/**
 * bin/magento techtor:shipping:track <tracking_number> --carrier=techtor_inpost
 *
 * Sprawdz status przesylki.
 */
class TrackCommand extends Command
{
    public function __construct(
        private readonly LabelService $labelService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('techtor:shipping:track');
        $this->setDescription('Sprawdz status przesylki');
        $this->addArgument('tracking', InputArgument::REQUIRED, 'Numer przesylki');
        $this->addOption('carrier', 'c', InputOption::VALUE_REQUIRED, 'Carrier code (techtor_inpost, techtor_dpd, techtor_dhl)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trackingNumber = $input->getArgument('tracking');
        $carrier = $input->getOption('carrier') ?? 'techtor_inpost';

        $output->writeln(sprintf('<info>Tracking: %s (%s)</info>', $trackingNumber, $carrier));

        try {
            $result = $this->labelService->getTracking($trackingNumber, $carrier);

            $output->writeln(sprintf('Status: <comment>%s</comment>', $result['status']));

            $events = $result['events'] ?? [];
            if (!empty($events)) {
                $output->writeln('');
                $output->writeln('Historia:');
                foreach ($events as $event) {
                    $date = $event['datetime'] ?? $event['timestamp'] ?? '?';
                    $desc = $event['description'] ?? $event['status'] ?? '?';
                    $output->writeln(sprintf('  [%s] %s', $date, $desc));
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>BLAD: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
