<?php

declare(strict_types=1);

namespace Techtor\Import\Model;

/**
 * Czyta dane produktowe z plików PIM (produkty-configs.json, output-bevo, categories.json).
 *
 * Oczekiwana struktura katalogu importu:
 *   var/import/pim/
 *     produkty-configs.json   — konfiguracje produktów z PIM (SKU → config)
 *     categories.json         — master kategorie z mappingami
 *     catalog-product-categories.json — mapowanie bevo:SKU → master category ID
 *     output-bevo/            — katalog z output scrapera BEVO (opcjonalny)
 *       {SKU}/product.json
 */
class PimReader
{
    private const IMPORT_DIR = BP . '/var/import/pim';

    /**
     * Czytaj produkty-configs.json — główne źródło danych produktów.
     * Zwraca tablicę indeksowaną kodem produktu.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readProductConfigs(): array
    {
        $path = self::IMPORT_DIR . '/produkty-configs.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Brak pliku: $path — skopiuj z PIM (Docker volume wezy_data)");
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Nieprawidłowy format produkty-configs.json");
        }

        // Indeksuj po kodzie produktu
        $indexed = [];
        foreach ($data as $item) {
            $code = $item['code'] ?? null;
            if ($code) {
                $indexed[$code] = $item;
            }
        }

        return $indexed;
    }

    /**
     * Czytaj categories.json — master kategorie z mappingami.
     * Zwraca mapę: master category ID → magento category ID.
     *
     * @return array<string, int>
     */
    public function readCategoryMappings(): array
    {
        $path = self::IMPORT_DIR . '/categories.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Brak pliku: $path");
        }

        $data = json_decode(file_get_contents($path), true);
        $mappings = [];

        foreach ($data['master'] ?? [] as $cat) {
            $magentoId = $cat['mappings']['magento']['id'] ?? null;
            if ($magentoId !== null) {
                $mappings[$cat['id']] = (int) $magentoId;
                // Też po nazwie — backup lookup
                $mappings[$cat['name']] = (int) $magentoId;
            }
        }

        return $mappings;
    }

    /**
     * Czytaj catalog-product-categories.json — mapowanie bevo:SKU → master category ID.
     *
     * @return array<string, string> code → master category ID
     */
    public function readProductCategoryAssignments(): array
    {
        $path = self::IMPORT_DIR . '/catalog-product-categories.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        // Klucze to "bevo:SKU" — normalizuj do czystego SKU
        $assignments = [];
        foreach ($data as $key => $catId) {
            $sku = preg_replace('/^bevo:/', '', $key);
            $assignments[$sku] = $catId;
        }

        return $assignments;
    }

    /**
     * Czytaj dane BEVO dla konkretnego SKU (output-bevo/{SKU}/product.json).
     *
     * @return array<string, mixed>|null
     */
    public function readBevoProduct(string $sku): ?array
    {
        $path = self::IMPORT_DIR . '/output-bevo/' . $sku . '/product.json';
        if (!file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true);
    }

    /**
     * Zwróć ścieżkę do katalogu importu.
     */
    public function getImportDir(): string
    {
        return self::IMPORT_DIR;
    }

    /**
     * Sprawdź czy katalog importu istnieje i ma wymagane pliki.
     *
     * @return string[] Lista brakujących plików
     */
    public function validateImportDir(): array
    {
        $missing = [];
        $required = ['produkty-configs.json', 'categories.json'];

        foreach ($required as $file) {
            if (!file_exists(self::IMPORT_DIR . '/' . $file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }
}
