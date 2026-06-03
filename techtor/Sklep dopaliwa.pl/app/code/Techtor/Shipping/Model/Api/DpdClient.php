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
 * DPD Web Services API — generowanie etykiet i tracking.
 *
 * DPD API v2: SOAP (generateSpedLabels) lub REST.
 * Tu: uproszczona wersja REST-owa.
 */
class DpdClient implements LabelGeneratorInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $carrierCode): bool
    {
        return $carrierCode === 'techtor_dpd';
    }

    public function generate(array $shipmentData): LabelResult
    {
        $apiUrl = $this->getConfig('api_url');
        $login = $this->getConfig('login');
        $password = $this->getApiPassword();
        $masterfid = $this->getConfig('master_fid');

        if (empty($apiUrl) || empty($login) || empty($password)) {
            throw new LocalizedException(__('DPD API: brak konfiguracji (url/login/haslo)'));
        }

        // Buduj payload DPD
        $payload = [
            'authData' => [
                'login' => $login,
                'password' => $password,
                'masterFid' => $masterfid,
            ],
            'shipmentData' => [
                'sender' => [
                    'name' => $this->getConfig('sender_name') ?: 'TECHTOR',
                    'address' => $this->getConfig('sender_address') ?: '',
                    'city' => $this->getConfig('sender_city') ?: '',
                    'postalCode' => $this->getConfig('sender_postcode') ?: '',
                    'countryCode' => 'PL',
                    'phone' => $this->getConfig('sender_phone') ?: '',
                    'email' => $this->getConfig('sender_email') ?: 'biuro@techtor.pl',
                ],
                'receiver' => [
                    'name' => $shipmentData['receiver_name'] ?? '',
                    'address' => $shipmentData['street'] ?? '',
                    'city' => $shipmentData['city'] ?? '',
                    'postalCode' => $shipmentData['postcode'] ?? '',
                    'countryCode' => $shipmentData['country'] ?? 'PL',
                    'phone' => $shipmentData['receiver_phone'] ?? '',
                    'email' => $shipmentData['receiver_email'] ?? '',
                ],
                'parcels' => [
                    [
                        'weight' => (float) ($shipmentData['weight'] ?? 1),
                        'sizeX' => (int) ($shipmentData['length'] ?? 30),
                        'sizeY' => (int) ($shipmentData['width'] ?? 20),
                        'sizeZ' => (int) ($shipmentData['height'] ?? 15),
                    ],
                ],
                'reference' => $shipmentData['reference'] ?? '',
                'cod' => isset($shipmentData['cod_amount'])
                    ? ['amount' => (float) $shipmentData['cod_amount'], 'currency' => 'PLN']
                    : null,
            ],
        ];

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(30);

        try {
            $this->curl->post($apiUrl . '/shipments', json_encode($payload));
        } catch (\Exception $e) {
            throw new LocalizedException(__('DPD API error: %1', $e->getMessage()));
        }

        $response = json_decode($this->curl->getBody(), true);
        if (!is_array($response) || !isset($response['trackingNumber'])) {
            $this->logger->error('DPD API: nieprawidlowa odpowiedz', ['body' => $this->curl->getBody()]);
            throw new LocalizedException(__('DPD API: blad generowania etykiety'));
        }

        // Pobierz PDF
        $labelPdf = $response['labelPdf'] ?? '';
        $trackingNumber = $response['trackingNumber'];

        $this->logger->info(sprintf('DPD: etykieta wygenerowana, tracking=%s', $trackingNumber));

        return new LabelResult($trackingNumber, $labelPdf, 'techtor_dpd');
    }

    public function getTracking(string $trackingNumber): array
    {
        $apiUrl = $this->getConfig('api_url');

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(15);

        try {
            $this->curl->get($apiUrl . '/tracking/' . urlencode($trackingNumber));
        } catch (\Exception $e) {
            $this->logger->error("DPD tracking error: {$e->getMessage()}");
            return ['status' => 'error', 'events' => []];
        }

        $response = json_decode($this->curl->getBody(), true) ?? [];

        return [
            'status' => $response['status'] ?? 'unknown',
            'events' => $response['events'] ?? [],
        ];
    }

    private function getConfig(string $field): string
    {
        return (string) $this->scopeConfig->getValue("carriers/techtor_dpd/{$field}");
    }

    private function getApiPassword(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue('carriers/techtor_dpd/api_password');
        return $this->encryptor->decrypt($encrypted);
    }
}
