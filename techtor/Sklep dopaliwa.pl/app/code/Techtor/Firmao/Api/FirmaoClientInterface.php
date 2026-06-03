<?php

declare(strict_types=1);

namespace Techtor\Firmao\Api;

interface FirmaoClientInterface
{
    /**
     * Pobierz produkty z Firmao (z paginacja).
     *
     * @param int $page
     * @param int $pageSize
     * @return array<string, mixed>
     */
    public function getProducts(int $page = 0, int $pageSize = 100): array;

    /**
     * Pobierz stan magazynowy produktu (currentStoreState).
     *
     * @param string $productCode SKU produktu w Firmao
     * @return float
     */
    public function getProductStock(string $productCode): float;

    /**
     * Pobierz wszystkie produkty (iteruje po stronach).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProducts(): array;
}
