<?php

declare(strict_types=1);

namespace Techtor\Shipping\Api;

/**
 * Interfejs generowania etykiet wysylkowych.
 *
 * Kazdy carrier (InPost, DPD, DHL) implementuje ten interfejs.
 */
interface LabelGeneratorInterface
{
    /**
     * Wygeneruj etykiete wysylkowa.
     *
     * @param array<string, mixed> $shipmentData Dane przesylki
     * @return LabelResult
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generate(array $shipmentData): LabelResult;

    /**
     * Pobierz status przesylki (tracking).
     *
     * @param string $trackingNumber
     * @return array<string, mixed> {status: string, events: array}
     */
    public function getTracking(string $trackingNumber): array;

    /**
     * Czy ten generator obsluguje dany carrier code.
     */
    public function supports(string $carrierCode): bool;
}
