<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Techtor\BaseLinker\Api\ClientInterface;

class Client implements ClientInterface
{
    private const API_URL = 'https://api.baselinker.com/connector.php';

    /**
     * Rate limiting: minimalny odstep miedzy requestami (mikrosekundy).
     * BL limit: 100 req/min = ~600ms miedzy requestami. Ustawiamy 700ms dla bezpieczenstwa.
     */
    private const MIN_REQUEST_INTERVAL_US = 700_000;

    private float $lastRequestTime = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function call(string $method, array $params = []): array
    {
        $token = $this->config->getApiToken();
        if (empty($token)) {
            throw new LocalizedException(__('BaseLinker API token nie jest skonfigurowany.'));
        }

        $this->rateLimit();

        $this->curl->addHeader('X-BLToken', $token);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->setTimeout(30);

        $postData = http_build_query([
            'method' => $method,
            'parameters' => json_encode($params),
        ]);

        $this->logger->info("BL API: {$method}", ['params' => array_keys($params)]);

        try {
            $this->curl->post(self::API_URL, $postData);
            $this->lastRequestTime = microtime(true);
        } catch (\Exception $e) {
            $this->logger->error("BL API connection error: {$e->getMessage()}");
            throw new LocalizedException(__('Blad polaczenia z BaseLinker: %1', $e->getMessage()));
        }

        $body = $this->curl->getBody();
        $response = json_decode($body, true);

        if (!is_array($response)) {
            $this->logger->error('BL API: nieprawidlowa odpowiedz', ['body' => substr($body, 0, 500)]);
            throw new LocalizedException(__('Nieprawidlowa odpowiedz z BaseLinker API.'));
        }

        if (isset($response['status']) && $response['status'] === 'ERROR') {
            $code = $response['error_code'] ?? 'UNKNOWN';
            $msg = $response['error_message'] ?? 'Nieznany blad';
            $this->logger->error("BL API error [{$code}]: {$msg}", ['method' => $method]);

            // Jesli rate limit — poczekaj i ponow
            if ($code === 'ERROR_TOO_MANY_REQUESTS') {
                $this->logger->warning('BL API: rate limit, czekam 60s...');
                sleep(60);
                return $this->call($method, $params);
            }

            throw new LocalizedException(__('BaseLinker API [%1]: %2', $code, $msg));
        }

        return $response;
    }

    public function getInventoryProducts(int $inventoryId): array
    {
        $allProducts = [];
        $page = 1;

        do {
            $response = $this->call('getInventoryProductsList', [
                'inventory_id' => $inventoryId,
                'page' => $page,
            ]);

            $products = $response['products'] ?? [];
            $allProducts = array_merge($allProducts, $products);
            $page++;
        } while (!empty($products) && count($products) >= 100);

        return ['products' => $allProducts];
    }

    public function updateInventoryStock(int $inventoryId, array $products): array
    {
        // BL akceptuje max 1000 produktow na raz
        $chunks = array_chunk($products, 1000, true);
        $results = [];

        foreach ($chunks as $chunk) {
            $stockData = [];
            foreach ($chunk as $productData) {
                // Format BL: product_id => {warehouse_id: qty}
                // Uzywamy SKU jako klucza w inventory
                $stockData[$productData['sku']] = [
                    'bl_1' => $productData['stock'],
                ];
            }

            $response = $this->call('updateInventoryProductsStock', [
                'inventory_id' => $inventoryId,
                'products' => $stockData,
            ]);

            $results[] = $response;
        }

        return ['batches' => count($chunks), 'results' => $results];
    }

    public function createOrder(array $orderData): int
    {
        $response = $this->call('addOrder', $orderData);
        return (int) ($response['order_id'] ?? 0);
    }

    public function getOrders(int $dateFrom = 0, int $idFrom = 0): array
    {
        $params = [];
        if ($dateFrom > 0) {
            $params['date_from'] = $dateFrom;
        }
        if ($idFrom > 0) {
            $params['id_from'] = $idFrom;
        }

        $response = $this->call('getOrders', $params);
        return $response['orders'] ?? [];
    }

    public function getOrderStatusList(): array
    {
        $response = $this->call('getOrderStatusList');
        return $response['statuses'] ?? [];
    }

    public function setOrderStatus(int $orderId, int $statusId): array
    {
        return $this->call('setOrderStatus', [
            'order_id' => $orderId,
            'status_id' => $statusId,
        ]);
    }

    public function getInventoryStockBySkus(int $inventoryId, array $skus): array
    {
        $allProducts = $this->getInventoryProducts($inventoryId);
        $stockMap = [];

        foreach ($allProducts['products'] ?? [] as $product) {
            $sku = $product['sku'] ?? '';
            if (in_array($sku, $skus, true)) {
                // Sumuj stany ze wszystkich magazynow BL
                $qty = 0;
                foreach ($product['stock'] ?? [] as $warehouseQty) {
                    $qty += (float) $warehouseQty;
                }
                $stockMap[$sku] = $qty;
            }
        }

        return $stockMap;
    }

    /**
     * Rate limiting — czekaj jesli ostatni request byl za szybko.
     */
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
