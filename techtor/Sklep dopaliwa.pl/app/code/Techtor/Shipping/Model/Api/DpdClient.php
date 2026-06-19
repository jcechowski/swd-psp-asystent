<?php

declare(strict_types=1);

namespace Techtor\Shipping\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Techtor\Shipping\Api\LabelGeneratorInterface;
use Techtor\Shipping\Api\LabelResult;

/**
 * DPD Polska Web Services API — SOAP.
 *
 * Endpoint: https://dpdservices.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices
 * Sandbox:  https://dpdservicesdemo.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices
 *
 * Metody: generateSpedLabelsV4, generateProtocolV2, getParcelTrackingV1
 */
class DpdClient implements LabelGeneratorInterface
{
    private const WSDL_PRODUCTION = 'https://dpdservices.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices?wsdl';
    private const WSDL_SANDBOX = 'https://dpdservicesdemo.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices?wsdl';

    private const TRACKING_WSDL_PRODUCTION = 'https://dpdinfox.dpd.com.pl/DPDInfoServicesObjEventsService/DPDInfoServicesObjEvents?wsdl';
    private const TRACKING_WSDL_SANDBOX = 'https://dpdinfoxdemo.dpd.com.pl/DPDInfoServicesObjEventsService/DPDInfoServicesObjEvents?wsdl';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $carrierCode): bool
    {
        return $carrierCode === 'techtor_dpd';
    }

    public function generate(array $shipmentData): LabelResult
    {
        $login = $this->getConfig('login');
        $password = $this->getApiPassword();
        $masterFid = $this->getConfig('master_fid');

        if (empty($login) || empty($password) || empty($masterFid)) {
            throw new LocalizedException(__('DPD API: brak konfiguracji (login/haslo/master_fid)'));
        }

        $authData = [
            'login' => $login,
            'password' => $password,
            'masterFid' => (int) $masterFid,
        ];

        $parcel = [
            'content' => $shipmentData['reference'] ?? 'Przesylka',
            'custRef' => $shipmentData['reference'] ?? '',
            'weight' => (float) ($shipmentData['weight'] ?? 1),
            'sizeX' => (int) ($shipmentData['length'] ?? 30),
            'sizeY' => (int) ($shipmentData['width'] ?? 20),
            'sizeZ' => (int) ($shipmentData['height'] ?? 15),
        ];

        $receiver = [
            'address' => $shipmentData['street'] ?? '',
            'city' => $shipmentData['city'] ?? '',
            'company' => $shipmentData['company'] ?? $shipmentData['receiver_name'] ?? '',
            'countryCode' => $shipmentData['country'] ?? 'PL',
            'email' => $shipmentData['receiver_email'] ?? '',
            'fid' => null,
            'name' => $shipmentData['receiver_name'] ?? '',
            'phone' => $shipmentData['receiver_phone'] ?? '',
            'postalCode' => str_replace('-', '', $shipmentData['postcode'] ?? ''),
        ];

        $sender = [
            'address' => $this->getConfig('sender_address') ?: '',
            'city' => $this->getConfig('sender_city') ?: '',
            'company' => $this->getConfig('sender_name') ?: 'TECHTOR',
            'countryCode' => 'PL',
            'email' => $this->getConfig('sender_email') ?: 'biuro@techtor.pl',
            'fid' => (int) $masterFid,
            'name' => $this->getConfig('sender_name') ?: 'TECHTOR',
            'phone' => $this->getConfig('sender_phone') ?: '',
            'postalCode' => str_replace('-', '', $this->getConfig('sender_postcode') ?: ''),
        ];

        $services = [];

        // COD (pobranie)
        if (!empty($shipmentData['cod_amount'])) {
            $services['cod'] = [
                'amount' => (float) $shipmentData['cod_amount'],
                'currency' => 'PLN',
            ];
        }

        $packageParams = [
            'authDataV1' => $authData,
            'pkgAddMethodV1' => 'DOMESTIC',
            'parcelsV1' => [
                'parcels' => [$parcel],
                'receiver' => $receiver,
                'ref1' => $shipmentData['reference'] ?? '',
                'sender' => $sender,
                'services' => $services ?: null,
            ],
        ];

        try {
            $client = $this->createSoapClient($this->getWsdl());
            $result = $client->__soapCall('generateSpedLabelsV4', [$packageParams]);
        } catch (\SoapFault $e) {
            $this->logger->error('DPD SOAP error: ' . $e->getMessage(), [
                'code' => $e->getCode(),
                'detail' => $e->detail ?? null,
            ]);
            throw new LocalizedException(__('DPD API: %1', $e->getMessage()));
        }

        // Parse response
        $session = $result->return ?? null;
        if (!$session) {
            throw new LocalizedException(__('DPD API: pusta odpowiedz'));
        }

        $statusInfo = $session->statusInfo ?? null;
        if ($statusInfo && ($statusInfo->status ?? '') === 'DISALLOWED') {
            $errorMsg = $statusInfo->description ?? 'Operacja niedozwolona';
            $this->logger->error('DPD API DISALLOWED: ' . $errorMsg);
            throw new LocalizedException(__('DPD API: %1', $errorMsg));
        }

        // Extract parcels from packageV1 response
        $packages = $session->packages ?? $session->parcels ?? null;
        if (!$packages) {
            throw new LocalizedException(__('DPD API: brak danych paczki w odpowiedzi'));
        }

        // Normalize to array
        if (!is_array($packages)) {
            $packages = [$packages];
        }

        $firstPackage = $packages[0];
        $parcels = $firstPackage->parcels ?? [];
        if (!is_array($parcels)) {
            $parcels = [$parcels];
        }

        $waybill = '';
        foreach ($parcels as $p) {
            $waybill = $p->waybill ?? '';
            if ($waybill) {
                break;
            }
        }

        if (empty($waybill)) {
            $this->logger->error('DPD API: brak waybill w odpowiedzi', ['response' => print_r($result, true)]);
            throw new LocalizedException(__('DPD API: brak numeru listu przewozowego'));
        }

        // Generate label PDF
        $labelPdf = $this->generateLabel($authData, $waybill);

        $this->logger->info(sprintf('DPD: etykieta wygenerowana, tracking=%s', $waybill));

        return new LabelResult($waybill, $labelPdf, 'techtor_dpd');
    }

    /**
     * Pobranie etykiety PDF z DPD (generateSpedLabelsV4 zwraca PDF od razu,
     * ale na wypadek potrzeby osobnego wywolania).
     */
    private function generateLabel(array $authData, string $waybill): string
    {
        try {
            $client = $this->createSoapClient($this->getWsdl());
            $result = $client->__soapCall('generateSpedLabelsV4', [[
                'authDataV1' => $authData,
                'outputDocFormatV1' => 'PDF',
                'outputDocPageFormatV1' => 'A4',
                'outputLabelType' => 'BIC3',
                'packageWaybills' => [$waybill],
            ]]);

            $label = $result->return->documentData ?? '';
            if ($label) {
                return base64_encode($label);
            }
        } catch (\SoapFault $e) {
            $this->logger->warning('DPD: nie udalo sie pobrac etykiety PDF: ' . $e->getMessage());
        }

        return '';
    }

    public function getTracking(string $trackingNumber): array
    {
        $login = $this->getConfig('login');
        $password = $this->getApiPassword();

        if (empty($login) || empty($password)) {
            return ['status' => 'error', 'events' => []];
        }

        try {
            $client = $this->createSoapClient($this->getTrackingWsdl());
            $result = $client->__soapCall('getEventsForWaybillV1', [[
                'authDataV1' => [
                    'login' => $login,
                    'password' => $password,
                    'channel' => 'dpd_pl',
                ],
                'waybill' => $trackingNumber,
                'language' => 'PL',
            ]]);
        } catch (\SoapFault $e) {
            $this->logger->error("DPD tracking SOAP error: {$e->getMessage()}");
            return ['status' => 'error', 'events' => []];
        }

        $eventsList = $result->return->eventsList ?? [];
        if (!is_array($eventsList)) {
            $eventsList = [$eventsList];
        }

        $events = [];
        $lastStatus = 'unknown';

        foreach ($eventsList as $event) {
            $events[] = [
                'date' => $event->eventTime ?? '',
                'description' => $event->description ?? '',
                'depot' => $event->depotName ?? '',
            ];
            $lastStatus = $event->description ?? $lastStatus;
        }

        return [
            'status' => $lastStatus,
            'events' => $events,
        ];
    }

    private function createSoapClient(string $wsdl): \SoapClient
    {
        return new \SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 15,
            'default_socket_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'soap_version' => SOAP_1_1,
        ]);
    }

    private function getWsdl(): string
    {
        $apiUrl = $this->getConfig('api_url');
        if ($apiUrl) {
            return rtrim($apiUrl, '?') . '?wsdl';
        }

        $sandbox = (bool) $this->getConfig('sandbox');
        return $sandbox ? self::WSDL_SANDBOX : self::WSDL_PRODUCTION;
    }

    private function getTrackingWsdl(): string
    {
        $sandbox = (bool) $this->getConfig('sandbox');
        return $sandbox ? self::TRACKING_WSDL_SANDBOX : self::TRACKING_WSDL_PRODUCTION;
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
