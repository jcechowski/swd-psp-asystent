<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Techtor\Firmao\Api\FirmaoClientInterface;

class FirmaoClient implements FirmaoClientInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getProducts(int $page = 0, int $pageSize = 100): array
    {
        $url = sprintf(
            '%s/api/v1/company/products?pageSize=%d&page=%d&sort=code',
            $this->config->getApiUrl(),
            $pageSize,
            $page
        );

        return $this->request('GET', $url);
    }

    public function getProductStock(string $productCode): float
    {
        $url = sprintf(
            '%s/api/v1/company/products?filters=(code="%s")&fields=code,currentStoreState',
            $this->config->getApiUrl(),
            urlencode($productCode)
        );

        $response = $this->request('GET', $url);
        $items = $response['data'] ?? [];

        if (empty($items)) {
            return 0.0;
        }

        return (float) ($items[0]['currentStoreState'] ?? 0);
    }

    public function getAllProducts(): array
    {
        $allProducts = [];
        $page = 0;
        $pageSize = 100;

        do {
            $response = $this->getProducts($page, $pageSize);
            $items = $response['data'] ?? [];
            $allProducts = array_merge($allProducts, $items);
            $page++;

            $this->logger->info(sprintf(
                'Firmao: pobrano strone %d (%d produktow)',
                $page,
                count($items)
            ));

            // Rate limiting — 1 sek miedzy requestami
            if (count($items) === $pageSize) {
                usleep(1_000_000);
            }
        } while (count($items) === $pageSize);

        return $allProducts;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url): array
    {
        $login = $this->config->getLogin();
        $password = $this->config->getPassword();

        if (empty($login) || empty($password)) {
            throw new LocalizedException(__('Firmao API: login/haslo nie skonfigurowane.'));
        }

        $this->curl->setCredentials($login, $password);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->setTimeout(30);

        try {
            if ($method === 'GET') {
                $this->curl->get($url);
            }
        } catch (\Exception $e) {
            $this->logger->error("Firmao API error: {$e->getMessage()}");
            throw new LocalizedException(__('Blad polaczenia z Firmao: %1', $e->getMessage()));
        }

        $statusCode = $this->curl->getStatus();
        if ($statusCode !== 200) {
            $this->logger->error("Firmao API HTTP {$statusCode}: {$this->curl->getBody()}");
            throw new LocalizedException(__('Firmao API zwrocilo HTTP %1', $statusCode));
        }

        $response = json_decode($this->curl->getBody(), true);

        if (!is_array($response)) {
            throw new LocalizedException(__('Nieprawidlowa odpowiedz z Firmao API.'));
        }

        return $response;
    }
}
