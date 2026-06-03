<?php

declare(strict_types=1);

namespace Techtor\StockSync\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Eksportuje stock-data.json — plik JSON uzywany przez frontend
 * do wyswietlania dynamicznego czasu dostawy.
 *
 * Format wyjsciowy (kompatybilny z techtor.pl):
 * {
 *   "SKU": 5,                    // stock Firmao (wlasny magazyn)
 *   "SKU__total": 15,            // Firmao + Tarnawa
 *   "SKU__status": "on-backorder", // status Tarnawa (jesli total=0)
 *   "SKU__delivery": "24h"       // czas dostawy
 * }
 */
class StockDataExporter
{
    public function __construct(
        private readonly Config $config,
        private readonly FileDriver $fileDriver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Eksportuj stock-data.json.
     *
     * @param array<string, array{qty: float, delivery: string, firmao: float, tarnawa: float, tarnawa_status: string}> $syncResults
     */
    public function export(array $syncResults): void
    {
        $exportPath = $this->config->getExportPath();

        $data = [];
        foreach ($syncResults as $sku => $result) {
            $firmao = $result['firmao'];
            $tarnawa = $result['tarnawa'];
            $total = $firmao + $tarnawa;
            $delivery = $result['delivery'];
            $tarnawaStatus = $result['tarnawa_status'];

            // Stock Firmao (wlasny magazyn)
            $data[$sku] = (int) $firmao;

            // Total (Firmao + Tarnawa)
            $data[$sku . '__total'] = (int) $total;

            // Delivery time
            $data[$sku . '__delivery'] = $delivery;

            // Status Tarnawa (tylko jesli total=0 i jest status)
            if ($total == 0 && $tarnawaStatus !== 'unknown') {
                $data[$sku . '__status'] = $tarnawaStatus;
            }
        }

        try {
            // Upewnij sie ze katalog istnieje
            $dir = dirname($exportPath);
            if (!$this->fileDriver->isDirectory($dir)) {
                $this->fileDriver->createDirectory($dir, 0755);
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $this->fileDriver->filePutContents($exportPath, $json);

            $this->logger->info(sprintf(
                'StockSync export: %d kluczy → %s (%.1f KB)',
                count($data),
                $exportPath,
                strlen($json) / 1024
            ));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('StockSync export error: %s', $e->getMessage()));
        }
    }
}
