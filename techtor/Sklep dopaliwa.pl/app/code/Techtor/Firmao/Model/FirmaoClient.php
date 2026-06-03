<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;

/**
 * Klient API Firmao.
 *
 * Auth: Basic Auth (email:token w Base64).
 * Base URL: https://system.firmao.pl/{company}/svc/v1
 * Rate limit: 1 sek miedzy requestami (Firmao nie dokumentuje limitu,
 *             ale przy szybkich requestach zwraca 429).
 */
class FirmaoClient implements FirmaoClientInterface
{
    private const MIN_REQUEST_INTERVAL_US = 1_000_000; // 1 sek

    private float $lastRequestTime = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getProducts(int $page = 0, int $pageSize = 100): array
    {
        return $this->get('/products', [
            'start' => (string) $page,
            'limit' => (string) $pageSize,
            'sort' => 'productCode',
            'dir' => 'ASC',
        ]);
    }

    public function getAllProducts(): array
    {
        $allProducts = [];
        $start = 0;
        $pageSize = 100;

        // Pierwszy request — pobierz totalSize
        $response = $this->getProducts(0, 1);
        $totalSize = (int) ($response['totalSize'] ?? 0);

        if ($totalSize === 0) {
            return [];
        }

        $this->logger->info(sprintf('Firmao: lacznie %d produktow do pobrania', $totalSize));

        while ($start < $totalSize) {
            $response = $this->getProducts($start, $pageSize);
            $items = $response['data'] ?? [];

            if (empty($items)) {
                break;
            }

            $allProducts = array_merge($allProducts, $items);
            $start += count($items);

            $this->logger->debug(sprintf(
                'Firmao: pobrano %d/%d produktow',
                count($allProducts),
                $totalSize
            ));
        }

        return $allProducts;
    }

    public function getProductStock(string $productCode): float
    {
        $product = $this->getProductByCode($productCode);
        if ($product === null) {
            return 0.0;
        }
        return (float) ($product['currentStoreState'] ?? 0);
    }

    public function getProductByCode(string $productCode): ?array
    {
        $response = $this->get('/products', [
            'filters' => sprintf('(productCode="%s")', $productCode),
            'limit' => '1',
        ]);

        $items = $response['data'] ?? [];
        return !empty($items) ? $items[0] : null;
    }

    public function createStorageDoc(array $pzData): int
    {
        $defaults = [
            'type' => 'OUTSIDE_INCOME',
            'warehouse' => ['id' => $this->config->getWarehouseId()],
            'invoicePatternId' => 1,
            'paymentType' => 'TRANSFER',
            'currency' => 'PLN',
            'calculateFromGross' => false,
        ];

        $payload = array_merge($defaults, $pzData);
        $response = $this->post('/storagedocs', $payload);

        $pzId = (int) ($response['id'] ?? 0);
        if ($pzId === 0) {
            throw new LocalizedException(__('Firmao: nie udalo sie utworzyc dokumentu PZ'));
        }

        $this->logger->info(sprintf(
            'Firmao: utworzono PZ #%d (%s)',
            $pzId,
            $pzData['storageDocNumber'] ?? '?'
        ));

        return $pzId;
    }

    public function addTransactionEntry(int $pzId, array $entryData): int
    {
        $entryData['storageDoc'] = ['id' => $pzId];

        if (!isset($entryData['mode'])) {
            $entryData['mode'] = 'PURCHASE';
        }

        // Oblicz VAT jesli nie podano
        if (!isset($entryData['vatPercent'])) {
            $entryData['vatPercent'] = $this->config->getDefaultVatRate();
        }

        // Oblicz brutto jesli tylko netto
        if (isset($entryData['unitNettoPrice']) && !isset($entryData['unitBruttoPrice'])) {
            $vat = (float) $entryData['vatPercent'];
            $netto = (float) $entryData['unitNettoPrice'];
            $entryData['unitBruttoPrice'] = round($netto * (1 + $vat / 100), 2);
        }

        // Oblicz sumy
        if (isset($entryData['quantity'], $entryData['unitNettoPrice'])) {
            $qty = (float) $entryData['quantity'];
            $entryData['nettoPrice'] = round($qty * (float) $entryData['unitNettoPrice'], 2);
            $entryData['bruttoPrice'] = round($qty * (float) $entryData['unitBruttoPrice'], 2);
        }

        $response = $this->post('/transactionentries', $entryData);

        $entryId = (int) ($response['id'] ?? 0);
        $this->logger->debug(sprintf(
            'Firmao: dodano pozycje #%d do PZ #%d',
            $entryId,
            $pzId
        ));

        return $entryId;
    }

    public function updateTransactionEntry(int $entryId, array $entryData): array
    {
        return $this->request('PUT', "/transactionentries/{$entryId}", $entryData);
    }

    public function getStorageDocs(string $type = 'OUTSIDE_INCOME', int $limit = 100): array
    {
        $response = $this->get('/storagedocs', [
            'filters' => sprintf('(type="%s")', $type),
            'sort' => 'storageDocNumber',
            'dir' => 'DESC',
            'limit' => (string) $limit,
        ]);

        return $response['data'] ?? [];
    }

    public function get(string $endpoint, array $queryParams = []): array
    {
        $url = $this->config->getApiUrl() . $endpoint;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $this->request('GET', $url);
    }

    public function post(string $endpoint, array $body): array
    {
        $url = $this->config->getApiUrl() . $endpoint;
        return $this->request('POST', $url, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $login = $this->config->getLogin();
        $password = $this->config->getPassword();

        if (empty($login) || empty($password)) {
            throw new LocalizedException(__('Firmao API: login/haslo nie skonfigurowane.'));
        }

        $this->rateLimit();

        // Firmao uzywa Basic Auth: email:token
        $this->curl->setCredentials($login, $password);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->setTimeout(30);

        $this->logger->debug(sprintf('Firmao API %s: %s', $method, $url));

        try {
            switch ($method) {
                case 'GET':
                    $this->curl->get($url);
                    break;
                case 'POST':
                    $this->curl->post($url, json_encode($body));
                    break;
                case 'PUT':
                    // Magento Curl nie ma PUT — uzywamy curl_setopt
                    $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
                    $this->curl->post($url, json_encode($body));
                    break;
            }
            $this->lastRequestTime = microtime(true);
        } catch (\Exception $e) {
            $this->logger->error("Firmao API error: {$e->getMessage()}");
            throw new LocalizedException(__('Blad polaczenia z Firmao: %1', $e->getMessage()));
        }

        $statusCode = $this->curl->getStatus();

        // Rate limit retry
        if ($statusCode === 429) {
            $this->logger->warning('Firmao API: rate limit (429), czekam 5s...');
            sleep(5);
            return $this->request($method, $url, $body);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = $this->curl->getBody();
            $this->logger->error("Firmao API HTTP {$statusCode}: {$responseBody}");
            throw new LocalizedException(__('Firmao API HTTP %1', $statusCode));
        }

        $response = json_decode($this->curl->getBody(), true);

        if (!is_array($response)) {
            throw new LocalizedException(__('Nieprawidlowa odpowiedz z Firmao API.'));
        }

        return $response;
    }

    private function rateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1_000_000;
            $waitTime = self::MIN_REQUEST_INTERVAL_US - $elapsed;
            if ($waitTime > 0) {
                usleep((int) $waitTime);
            }
        }
    }
}
