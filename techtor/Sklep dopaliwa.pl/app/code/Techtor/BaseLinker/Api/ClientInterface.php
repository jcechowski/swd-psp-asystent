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
     * Aktualizuj stany magazynowe w BL.
     *
     * @param int $inventoryId
     * @param array<string, array{sku: string, stock: float}> $products SKU => qty
     * @return array<string, mixed>
     */
    public function updateInventoryStock(int $inventoryId, array $products): array;

    /**
     * Utworz zamowienie w BaseLinker.
     *
     * @param array<string, mixed> $orderData
     * @return int ID zamowienia w BL
     */
    public function createOrder(array $orderData): int;

    /**
     * Pobierz zamowienia z BL (po dacie lub ID).
     *
     * @param int $dateFrom Unix timestamp
     * @param int $idFrom Pobierz zamowienia z ID wiekszym niz podany
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(int $dateFrom = 0, int $idFrom = 0): array;

    /**
     * Pobierz liste statusow zamowien z BL.
     *
     * @return array<int, array{id: int, name: string, name_for_customer: string}>
     */
    public function getOrderStatusList(): array;

    /**
     * Zmien status zamowienia w BL.
     *
     * @param int $orderId BL order ID
     * @param int $statusId BL status ID
     * @return array<string, mixed>
     */
    public function setOrderStatus(int $orderId, int $statusId): array;

    /**
     * Pobierz stany magazynowe z BL dla produktow po SKU.
     *
     * @param int $inventoryId
     * @param array<int, string> $skus Lista SKU
     * @return array<string, float> SKU => qty
     */
    public function getInventoryStockBySkus(int $inventoryId, array $skus): array;
}
