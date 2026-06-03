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
 * DHL eCommerce API — generowanie etykiet i tracking.
 *
 * DHL API: https://api-eu.dhl.com
 */
class DhlClient implements LabelGeneratorInterface
{
    private const API_URL = 'https://api-eu.dhl.com';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $carrierCode): bool
    {
        return $carrierCode === 'techtor_dhl';
    }

    public function generate(array $shipmentData): LabelResult
    {
        $apiKey = $this->getApiKey();
        $accountNumber = $this->getConfig('account_number');

        if (empty($apiKey) || empty($accountNumber)) {
            throw new LocalizedException(__('DHL API: brak konfiguracji (API key/account)'));
        }

        $payload = [
            'productCode' => 'N', // DHL Parcel Connect (krajowy)
            'accounts' => [
                ['typeCode' => 'shipper', 'number' => $accountNumber],
            ],
            'outputImageProperties' => [
                'imageOptions' => [
                    ['typeCode' => 'label', 'templateName' => 'ECOM26_84_A4_001'],
                ],
            ],
            'customerDetails' => [
                'shipperDetails' => [
                    'postalAddress' => [
                        'postalCode' => $this->getConfig('sender_postcode') ?: '',
                        'cityName' => $this->getConfig('sender_city') ?: '',
                        'addressLine1' => $this->getConfig('sender_address') ?: '',
                        'countryCode' => 'PL',
                    ],
                    'contactInformation' => [
                        'companyName' => $this->getConfig('sender_name') ?: 'TECHTOR',
                        'phone' => $this->getConfig('sender_phone') ?: '',
                        'email' => 'biuro@techtor.pl',
                    ],
                ],
                'receiverDetails' => [
                    'postalAddress' => [
                        'postalCode' => $shipmentData['postcode'] ?? '',
                        'cityName' => $shipmentData['city'] ?? '',
                        'addressLine1' => $shipmentData['street'] ?? '',
                        'countryCode' => $shipmentData['country'] ?? 'PL',
                    ],
                    'contactInformation' => [
                        'fullName' => $shipmentData['receiver_name'] ?? '',
                        'phone' => $shipmentData['receiver_phone'] ?? '',
                        'email' => $shipmentData['receiver_email'] ?? '',
                    ],
                ],
            ],
            'content' => [
                'packages' => [
                    [
                        'weight' => (float) ($shipmentData['weight'] ?? 1),
                        'dimensions' => [
                            'length' => (float) ($shipmentData['length'] ?? 30),
                            'width' => (float) ($shipmentData['width'] ?? 20),
                            'height' => (float) ($shipmentData['height'] ?? 15),
                        ],
                    ],
                ],
                'description' => $shipmentData['reference'] ?? 'Zamowienie dopaliwa.pl',
            ],
            'shipmentNotification' => [
                ['typeCode' => 'email', 'receiverId' => $shipmentData['receiver_email'] ?? ''],
            ],
        ];

        $this->curl->addHeader('Authorization', "DHL-API-Key {$apiKey}");
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(30);

        try {
            $this->curl->post(self::API_URL . '/shipments', json_encode($payload));
        } catch (\Exception $e) {
            throw new LocalizedException(__('DHL API error: %1', $e->getMessage()));
        }

        $status = $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->error(sprintf('DHL API HTTP %d: %s', $status, $this->curl->getBody()));
            throw new LocalizedException(__('DHL API HTTP %1', $status));
        }

        $response = json_decode($this->curl->getBody(), true) ?? [];
        $trackingNumber = $response['shipmentTrackingNumber'] ?? '';
        $labelPdf = $response['documents'][0]['content'] ?? '';

        $this->logger->info(sprintf('DHL: etykieta wygenerowana, tracking=%s', $trackingNumber));

        return new LabelResult($trackingNumber, $labelPdf, 'techtor_dhl');
    }

    public function getTracking(string $trackingNumber): array
    {
        $apiKey = $this->getApiKey();

        $this->curl->addHeader('Authorization', "DHL-API-Key {$apiKey}");
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->setTimeout(15);

        try {
            $this->curl->get(self::API_URL . '/track/shipments?trackingNumber=' . urlencode($trackingNumber));
        } catch (\Exception $e) {
            $this->logger->error("DHL tracking error: {$e->getMessage()}");
            return ['status' => 'error', 'events' => []];
        }

        $response = json_decode($this->curl->getBody(), true) ?? [];
        $shipments = $response['shipments'] ?? [];

        if (empty($shipments)) {
            return ['status' => 'not_found', 'events' => []];
        }

        $shipment = $shipments[0];
        return [
            'status' => $shipment['status']['statusCode'] ?? 'unknown',
            'events' => $shipment['events'] ?? [],
        ];
    }

    private function getApiKey(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue('carriers/techtor_dhl/api_key');
        return $this->encryptor->decrypt($encrypted);
    }

    private function getConfig(string $field): string
    {
        return (string) $this->scopeConfig->getValue("carriers/techtor_dhl/{$field}");
    }
}
