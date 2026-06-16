<?php

declare(strict_types=1);

namespace Techtor\Import\Model;

/**
 * Mapuje dane produktu PIM (produkty-configs.json + BEVO) → atrybuty Magento.
 *
 * Źródła danych (priorytet):
 *   1. produkty-configs.json — ujednolicone dane z PIM (ceny, opisy, SEO, stock)
 *   2. output-bevo/{SKU}/product.json — surowe dane ze scrapera (specyfikacje, opisy)
 *   3. CategoryMap — mapowanie kategorii PIM → Magento
 */
class ProductMapper
{
    /**
     * Mapuj produkt PIM na dane gotowe do zapisu w Magento.
     *
     * @param array<string, mixed> $pimConfig Konfiguracja z produkty-configs.json
     * @param array<string, mixed>|null $bevoData Surowe dane BEVO (opcjonalne)
     * @param int|null $categoryId Magento category ID
     * @return array<string, mixed>
     */
    public function mapToMagento(array $pimConfig, ?array $bevoData, ?int $categoryId): array
    {
        $sku = $pimConfig['code'] ?? '';
        $name = $pimConfig['name'] ?? $bevoData['name'] ?? $sku;

        // Cena — brutto (z VAT 23%)
        $priceGross = $this->resolvePrice($pimConfig, $bevoData);

        // Cena zakupu netto
        $costNetto = (float) ($pimConfig['purchasePricePLN'] ?? 0);

        // Waga
        $weight = (float) ($pimConfig['weight'] ?? $bevoData['weight'] ?? 0);

        // EAN — walidacja format 8-14 cyfr
        $ean = $this->validateEan($pimConfig['ean'] ?? $bevoData['specifications']['EAN'] ?? '');

        // Opisy
        $description = $this->resolveDescription($pimConfig, $bevoData);
        $shortDescription = $pimConfig['descriptionShort'] ?? $bevoData['description_short'] ?? '';

        // URL key
        $urlKey = $this->resolveUrlKey($pimConfig, $name);

        // Producent
        $manufacturer = $pimConfig['manufacturer'] ?? $bevoData['brand'] ?? '';

        $data = [
            'sku' => $sku,
            'name' => $name,
            'price' => $priceGross,
            'cost' => $costNetto,
            'weight' => $weight,
            'status' => 1, // enabled
            'visibility' => 4, // catalog + search
            'type_id' => 'simple',
            'tax_class_id' => 2, // Taxable Goods (23% VAT)
            'attribute_set_id' => 'Sprzet paliwowy', // resolved later to ID

            // Opisy
            'description' => $description,
            'short_description' => $shortDescription,

            // SEO
            'url_key' => $urlKey,
            'meta_title' => $pimConfig['seoTitle'] ?? '',
            'meta_description' => $pimConfig['seoDescription'] ?? '',
            'meta_keyword' => $pimConfig['seoKeywords'] ?? '',

            // Custom atrybuty Techtor
            'ean' => $ean,
            'manufacturer_code' => $pimConfig['manufacturerCode'] ?? $bevoData['sku'] ?? '',

            // Kategoria
            'category_ids' => $categoryId ? [$categoryId] : [],

            // Stock (do osobnego przetworzenia)
            '_stock_qty' => $this->resolveStock($pimConfig),
            '_stock_status' => $this->resolveStockStatus($pimConfig),

            // Meta — nie zapisywane w Magento, pomocnicze
            '_pim_manufacturer' => $manufacturer,
            '_pim_category_name' => $pimConfig['category'] ?? '',
            '_pim_master_category_id' => $pimConfig['masterCategoryId'] ?? '',
            '_bevo_url' => $pimConfig['urlBevo'] ?? $bevoData['url'] ?? '',
            '_bevo_specs' => $bevoData['specifications'] ?? [],
        ];

        return $data;
    }

    /**
     * Ustal cenę brutto (z VAT).
     * Priorytet: salePriceGross z PIM > oblicz z netto > cena BEVO brutto.
     */
    private function resolvePrice(array $pimConfig, ?array $bevoData): float
    {
        // PIM ma cenę brutto
        if (!empty($pimConfig['salePriceGross']) && $pimConfig['salePriceGross'] > 0) {
            return round((float) $pimConfig['salePriceGross'], 2);
        }

        // PIM ma cenę netto — oblicz brutto
        if (!empty($pimConfig['salePriceNet']) && $pimConfig['salePriceNet'] > 0) {
            return round((float) $pimConfig['salePriceNet'] * 1.23, 2);
        }

        // BEVO brutto
        if ($bevoData && !empty($bevoData['priceBrutto'])) {
            return round((float) $bevoData['priceBrutto'], 2);
        }

        // BEVO netto
        if ($bevoData && !empty($bevoData['priceNetto'])) {
            return round((float) $bevoData['priceNetto'] * 1.23, 2);
        }

        return 0.0;
    }

    /**
     * Ustal opis produktu.
     * PIM descriptionLong > BEVO description > wygeneruj z specyfikacji.
     */
    private function resolveDescription(array $pimConfig, ?array $bevoData): string
    {
        if (!empty($pimConfig['descriptionLong'])) {
            return $pimConfig['descriptionLong'];
        }

        if ($bevoData && !empty($bevoData['description'])) {
            return $bevoData['description'];
        }

        // Wygeneruj z specyfikacji BEVO
        if ($bevoData && !empty($bevoData['specifications'])) {
            return $this->specsToHtml($bevoData['specifications']);
        }

        return '';
    }

    /**
     * Zamień specyfikacje na tabelkę HTML.
     */
    private function specsToHtml(array $specs): string
    {
        if (empty($specs)) {
            return '';
        }

        $html = '<table class="data-table techtor-specs"><tbody>';
        foreach ($specs as $key => $value) {
            if ($key === 'EAN') {
                continue; // EAN osobno
            }
            $html .= sprintf(
                '<tr><th>%s</th><td>%s</td></tr>',
                htmlspecialchars((string) $key),
                htmlspecialchars((string) $value)
            );
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Ustal URL key.
     * PIM seoUrl > generuj z nazwy.
     */
    private function resolveUrlKey(array $pimConfig, string $name): string
    {
        if (!empty($pimConfig['seoUrl'])) {
            return $pimConfig['seoUrl'];
        }

        return $this->slugify($name);
    }

    /**
     * Generuj slug z polskiego tekstu.
     */
    private function slugify(string $text): string
    {
        $slug = mb_strtolower($text);

        $pl = ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'];
        $en = ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'];
        $slug = str_replace($pl, $en, $slug);

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Max 250 znaków (Magento limit)
        if (mb_strlen($slug) > 250) {
            $slug = mb_substr($slug, 0, 250);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    /**
     * Waliduj EAN — musi być 8-14 cyfr.
     */
    private function validateEan(string $ean): string
    {
        $ean = trim($ean);
        if (preg_match('/^\d{8,14}$/', $ean)) {
            return $ean;
        }
        return '';
    }

    /**
     * Ustal ilość stocku.
     */
    private function resolveStock(array $pimConfig): float
    {
        $firmao = (float) ($pimConfig['stockFirmao'] ?? 0);
        $tarnawa = (float) ($pimConfig['stockTarnawa'] ?? 0);
        return $firmao + $tarnawa;
    }

    /**
     * Ustal status stocku.
     */
    private function resolveStockStatus(array $pimConfig): bool
    {
        return $this->resolveStock($pimConfig) > 0;
    }
}
