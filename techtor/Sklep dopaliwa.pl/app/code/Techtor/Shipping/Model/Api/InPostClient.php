<?php

declare(strict_types=1);

namespace Techtor\Shipping\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Techtor\Shipping\Api\LabelGeneratorInterface;
use Techtor\Shipping\Api\LabelResult;

/**
 * InPost ShipX API v2 — generowanie etykiet i tracking.
 *
 * Dokumentacja: https://docs.inpost24.com/display/PL/ShipX+API+v2
 * Sandbox: https://sandbox-api-shipx-pl.easypack24.net
 * Produkcja: https://api-shipx-pl.easypack24.net
 */
class InPostClient implements LabelGeneratorInterface
{
    private const SANDBOX_URL = 'https://sandbox-api-shipx-pl.easypack24.net';
    private const PRODUCTION_URL = 'https://api-shipx-pl.easypack24.net';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $carrierCode): bool
    {
        return str_starts_with($carrierCode, 'techtor_inpost');
    }

    public function generate(array $shipmentData): LabelResult
    {
        // 1. Utworz przesylke
        $shipment = $this->createShipment($shipmentData);
        $shipmentId = (int) ($shipment['id'] ?? 0);

        if ($shipmentId === 0) {
            throw new LocalizedException(__('InPost: nie udalo sie utworzyc przesylki'));
        }

        // 2. Pobierz etykiete PDF
        $labelPdf = $this->getLabel($shipmentId);

        $trackingNumber = $shipment['tracking_number'] ?? '';

        $this->logger->info(sprintf(
            'InPost: etykieta wygenerowana, tracking=%s, shipment=%d',
            $trackingNumber,
            $shipmentId
        ));

        return new LabelResult(
            $trackingNumber,
            $labelPdf,
            'techtor_inpost',
            [
                'shipment_id' => $shipmentId,
                'service' => $shipmentData['service'] ?? 'inpost_locker_standard',
            ]
        );
    }

    public function getTracking(string $trackingNumber): array
    {
        $response = $this->request('GET', "/v1/tracking/{$trackingNumber}");

        return [
            'status' => $response['status'] ?? 'unknown',
            'events' => $response['tracking_details'] ?? [],
        ];
    }

    /**
     * Utworz przesylke InPost.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function createShipment(array $data): array
    {
        $organizationId = $this->getConfig('organization_id');

        // Ustaw serwis na podstawie metody wysylki
        $service = $data['service'] ?? 'inpost_locker_standard';
        if (str_contains($data['method'] ?? '', 'courier')) {
            $service = 'inpost_courier_standard';
        }

        $payload = [
            'receiver' => [
                'name' => $data['receiver_name'] ?? '',
                'email' => $data['receiver_email'] ?? '',
                'phone' => $data['receiver_phone'] ?? '',
                'address' => null,
            ],
            'sender' => [
                'name' => $this->getConfig('sender_name') ?: 'TECHTOR dopaliwa.pl',
                'email' => $this->getConfig('sender_email') ?: 'biuro@techtor.pl',
                'phone' => $this->getConfig('sender_phone') ?: '',
            ],
            'parcels' => [
                [
                    'dimensions' => [
                        'length' => (float) ($data['length'] ?? 30),
                        'width' => (float) ($data['width'] ?? 20),
                        'height' => (float) ($data['height'] ?? 15),
                    ],
                    'weight' => [
                        'amount' => (float) ($data['weight'] ?? 1),
                    ],
                ],
            ],
            'service' => $service,
            'reference' => $data['reference'] ?? '',
        ];

        // Paczkomat — dodaj target_point
        if ($service === 'inpost_locker_standard' && !empty($data['locker_id'])) {
            $payload['custom_attributes'] = [
                'target_point' => $data['locker_id'],
            ];
        }

        // Kurier — dodaj adres
        if (str_contains($service, 'courier')) {
            $payload['receiver']['address'] = [
                'street' => $data['street'] ?? '',
                'building_number' => $data['building_number'] ?? '',
                'city' => $data['city'] ?? '',
                'post_code' => $data['postcode'] ?? '',
                'country_code' => $data['country'] ?? 'PL',
            ];
        }

        return $this->request(
            'POST',
            "/v1/organizations/{$organizationId}/shipments",
            $payload
        );
    }

    /**
     * Pobierz PDF etykiety.
     */
    private function getLabel(int $shipmentId): string
    {
        $url = $this->getBaseUrl() . "/v1/shipments/{$shipmentId}/label";

        $this->setupCurl();
        $this->curl->addHeader('Accept', 'application/pdf');
        $this->curl->get($url);

        if ($this->curl->getStatus() !== 200) {
            throw new LocalizedException(__('InPost: blad pobierania etykiety'));
        }

        return base64_encode($this->curl->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->getBaseUrl() . $endpoint;
        $this->setupCurl();

        try {
            if ($method === 'GET') {
                $this->curl->get($url);
            } elseif ($method === 'POST') {
                $this->curl->addHeader('Content-Type', 'application/json');
                $this->curl->post($url, json_encode($body));
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('InPost API error: %1', $e->getMessage()));
        }

        $status = $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->error(sprintf('InPost API HTTP %d: %s', $status, $this->curl->getBody()));
            throw new LocalizedException(__('InPost API HTTP %1', $status));
        }

        return json_decode($this->curl->getBody(), true) ?? [];
    }

    private function setupCurl(): void
    {
        $token = $this->getApiToken();
        $this->curl->addHeader('Authorization', "Bearer {$token}");
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->setTimeout(30);
    }

    private function getBaseUrl(): string
    {
        $sandbox = $this->scopeConfig->isSetFlag('carriers/techtor_inpost/sandbox');
        return $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    private function getApiToken(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue('carriers/techtor_inpost/api_token');
        return $this->encryptor->decrypt($encrypted);
    }

    private function getConfig(string $field): string
    {
        return (string) $this->scopeConfig->getValue("carriers/techtor_inpost/{$field}");
    }
}
