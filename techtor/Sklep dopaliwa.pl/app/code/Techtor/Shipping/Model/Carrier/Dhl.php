<?php

declare(strict_types=1);

namespace Techtor\Shipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * DHL Kurier — carrier Magento.
 *
 * Kalkulacja ceny wysylki na podstawie tabeli wag.
 * Progi konfigurowane w adminie.
 */
class Dhl extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'techtor_dhl';
    protected $_isFixed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request): \Magento\Shipping\Model\Rate\Result|bool
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $result = $this->rateResultFactory->create();
        $packageWeight = (float) $request->getPackageWeight();
        $packageValue = (float) $request->getPackageValue();
        $freeThreshold = (float) $this->getConfigData('free_shipping_threshold');

        $shippingPrice = $this->calculatePrice($packageWeight);

        if ($freeThreshold > 0 && $packageValue >= $freeThreshold) {
            $shippingPrice = 0.0;
        }

        // Sprawdz max wage
        $maxWeight = (float) ($this->getConfigData('max_weight') ?: 31.5);
        if ($packageWeight > $maxWeight) {
            return false; // Za ciezkie — nie oferuj DHL
        }

        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));
        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);

        $result->append($method);

        return $result;
    }

    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * Tabela wag → cena. Konfigurowana w adminie jako JSON lub progi.
     */
    private function calculatePrice(float $weight): float
    {
        $weightTable = $this->getWeightTable();

        foreach ($weightTable as $maxW => $price) {
            if ($weight <= (float) $maxW) {
                return (float) $price;
            }
        }

        // Powyzej najwyzszego progu — najwyzsza cena + doplata
        $prices = array_values($weightTable);
        return end($prices) ?: (float) $this->getConfigData('price');
    }

    /**
     * Parsuj tabele wag z konfiguracji.
     *
     * Format w adminie: "1:12.99,5:14.99,10:18.99,20:22.99,31.5:28.99"
     *
     * @return array<string, float> maxWeight => price
     */
    private function getWeightTable(): array
    {
        $raw = (string) $this->getConfigData('weight_table');

        if (empty($raw)) {
            // Domyslna tabela
            return [
                '1' => 12.99,
                '5' => 14.99,
                '10' => 18.99,
                '20' => 22.99,
                '31.5' => 28.99,
            ];
        }

        $table = [];
        $entries = explode(',', $raw);
        foreach ($entries as $entry) {
            $parts = explode(':', trim($entry));
            if (count($parts) === 2) {
                $table[trim($parts[0])] = (float) trim($parts[1]);
            }
        }

        ksort($table, SORT_NUMERIC);
        return $table;
    }
}
