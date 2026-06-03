<?php

declare(strict_types=1);

namespace Techtor\Shipping\Api;

/**
 * Wynik generowania etykiety.
 */
class LabelResult
{
    public function __construct(
        private readonly string $trackingNumber,
        private readonly string $labelPdf,
        private readonly string $carrierCode,
        private readonly array $metadata = []
    ) {
    }

    public function getTrackingNumber(): string
    {
        return $this->trackingNumber;
    }

    /**
     * Zawartosc PDF etykiety (base64 lub raw).
     */
    public function getLabelPdf(): string
    {
        return $this->labelPdf;
    }

    public function getCarrierCode(): string
    {
        return $this->carrierCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
