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
 * InPost Paczkomaty — carrier Magento.
 *
 * Oferuje dwie metody:
 *   - inpost_locker   — Paczkomat (wymaga wybrania punktu przez Geowidget)
 *   - inpost_courier  — Kurier InPost (door-to-door)
 *
 * Limity gabarytowe Paczkomat:
 *   - Gabaryt A: max 8kg, 64x38x8 cm
 *   - Gabaryt B: max 25kg, 64x38x19 cm
 *   - Gabaryt C: max 25kg, 64x38x41 cm
 */
class InPost extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'techtor_inpost';
    protected $_isFixed = false;

    private const MAX_LOCKER_WEIGHT = 25.0; // kg

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

        // Paczkomat — tylko jesli waga <= 25kg
        if ($packageWeight <= self::MAX_LOCKER_WEIGHT) {
            $lockerPrice = $this->calculateLockerPrice($packageWeight);

            if ($freeThreshold > 0 && $packageValue >= $freeThreshold) {
                $lockerPrice = 0.0;
            }

            $locker = $this->rateMethodFactory->create();
            $locker->setCarrier($this->_code);
            $locker->setCarrierTitle($this->getConfigData('title'));
            $locker->setMethod('locker');
            $locker->setMethodTitle('Paczkomat InPost');
            $locker->setPrice($lockerPrice);
            $locker->setCost($lockerPrice);
            $result->append($locker);
        }

        // Kurier InPost — bez limitu wagi
        $courierPrice = $this->calculateCourierPrice($packageWeight);

        if ($freeThreshold > 0 && $packageValue >= $freeThreshold) {
            $courierPrice = 0.0;
        }

        $courier = $this->rateMethodFactory->create();
        $courier->setCarrier($this->_code);
        $courier->setCarrierTitle($this->getConfigData('title'));
        $courier->setMethod('courier');
        $courier->setMethodTitle('Kurier InPost');
        $courier->setPrice($courierPrice);
        $courier->setCost($courierPrice);
        $result->append($courier);

        return $result;
    }

    public function getAllowedMethods(): array
    {
        return [
            'locker' => 'Paczkomat InPost',
            'courier' => 'Kurier InPost',
        ];
    }

    /**
     * Cena paczkomatu wg gabarytu (wagi).
     */
    private function calculateLockerPrice(float $weight): float
    {
        $priceA = (float) $this->getConfigData('price_locker_a');
        $priceB = (float) $this->getConfigData('price_locker_b');
        $priceC = (float) $this->getConfigData('price_locker_c');

        if ($weight <= 8.0) {
            return $priceA ?: 12.99;
        }
        if ($weight <= 19.0) {
            return $priceB ?: 13.99;
        }
        return $priceC ?: 15.99;
    }

    /**
     * Cena kuriera wg wagi.
     */
    private function calculateCourierPrice(float $weight): float
    {
        $basePrice = (float) ($this->getConfigData('price_courier') ?: 18.99);
        $heavyThreshold = (float) ($this->getConfigData('heavy_threshold') ?: 30.0);
        $heavySurcharge = (float) ($this->getConfigData('heavy_surcharge') ?: 10.0);

        if ($weight > $heavyThreshold) {
            return $basePrice + $heavySurcharge;
        }

        return $basePrice;
    }
}
