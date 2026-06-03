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

        $this->curl->addHeader('X-BLToken', $token);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->setTimeout(30);

        $postData = http_build_query([
            'method' => $method,
            'parameters' => json_encode($params),
        ]);

        $this->logger->info("BaseLinker API call: {$method}", ['params' => $params]);

        try {
            $this->curl->post(self::API_URL, $postData);
        } catch (\Exception $e) {
            $this->logger->error("BaseLinker API error: {$e->getMessage()}");
            throw new LocalizedException(__('Blad polaczenia z BaseLinker: %1', $e->getMessage()));
        }

        $response = json_decode($this->curl->getBody(), true);

        if (!is_array($response)) {
            throw new LocalizedException(__('Nieprawidlowa odpowiedz z BaseLinker API.'));
        }

        if (isset($response['status']) && $response['status'] === 'ERROR') {
            $errorMsg = $response['error_message'] ?? 'Nieznany blad';
            $this->logger->error("BaseLinker API error: {$errorMsg}", ['method' => $method]);
            throw new LocalizedException(__('BaseLinker API: %1', $errorMsg));
        }

        return $response;
    }

    public function getInventoryProducts(int $inventoryId): array
    {
        return $this->call('getInventoryProductsList', [
            'inventory_id' => $inventoryId,
        ]);
    }

    public function createOrder(array $orderData): int
    {
        $response = $this->call('addOrder', $orderData);
        return (int) ($response['order_id'] ?? 0);
    }
}
