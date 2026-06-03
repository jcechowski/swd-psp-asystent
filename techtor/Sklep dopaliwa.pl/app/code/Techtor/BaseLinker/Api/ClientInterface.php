<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Api;

interface ClientInterface
{
    /**
     * Wywolaj metode API BaseLinker.
     *
     * @param string $method Nazwa metody BL (np. getOrders, addOrder)
     * @param array<string, mixed> $params Parametry requestu
     * @return array<string, mixed> Odpowiedz API
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function call(string $method, array $params = []): array;

    /**
     * Pobierz produkty z magazynu BL.
     *
     * @param int $inventoryId
     * @return array<string, mixed>
     */
    public function getInventoryProducts(int $inventoryId): array;

    /**
     * Utworz zamowienie w BaseLinker.
     *
     * @param array<string, mixed> $orderData
     * @return int ID zamowienia w BL
     */
    public function createOrder(array $orderData): int;
}
