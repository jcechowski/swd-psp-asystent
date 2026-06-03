<?php

declare(strict_types=1);

namespace Techtor\Firmao\Api;

interface FirmaoClientInterface
{
    /**
     * Pobierz produkty z Firmao (z paginacja).
     *
     * @param int $page Offset (start)
     * @param int $pageSize Limit (max 100)
     * @return array<string, mixed> {data: Product[], totalSize: int}
     */
    public function getProducts(int $page = 0, int $pageSize = 100): array;

    /**
     * Pobierz wszystkie produkty (iteruje po stronach).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllProducts(): array;

    /**
     * Pobierz stan magazynowy produktu (currentStoreState).
     *
     * @param string $productCode SKU produktu w Firmao
     * @return float
     */
    public function getProductStock(string $productCode): float;

    /**
     * Pobierz produkt po kodzie (SKU).
     *
     * @param string $productCode
     * @return array<string, mixed>|null Null jesli nie znaleziono
     */
    public function getProductByCode(string $productCode): ?array;

    /**
     * Utworz dokument magazynowy PZ (przyjecie zewnetrzne).
     *
     * @param array<string, mixed> $pzData
     * @return int ID dokumentu PZ w Firmao
     */
    public function createStorageDoc(array $pzData): int;

    /**
     * Dodaj pozycje do dokumentu PZ.
     *
     * @param int $pzId ID dokumentu PZ
     * @param array<string, mixed> $entryData
     * @return int ID pozycji
     */
    public function addTransactionEntry(int $pzId, array $entryData): int;

    /**
     * Aktualizuj pozycje w dokumencie PZ.
     *
     * @param int $entryId
     * @param array<string, mixed> $entryData
     * @return array<string, mixed>
     */
    public function updateTransactionEntry(int $entryId, array $entryData): array;

    /**
     * Pobierz dokumenty magazynowe (PZ).
     *
     * @param string $type Typ: OUTSIDE_INCOME, OUTSIDE_OUTCOME itp.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getStorageDocs(string $type = 'OUTSIDE_INCOME', int $limit = 100): array;

    /**
     * Wykonaj dowolny request GET na API Firmao.
     *
     * @param string $endpoint Sciezka po base URL, np. "/products"
     * @param array<string, string> $queryParams
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $queryParams = []): array;

    /**
     * Wykonaj dowolny request POST na API Firmao.
     *
     * @param string $endpoint
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $body): array;
}
