<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model;

/**
 * Mapuje pola produktu Firmao → atrybuty Magento.
 *
 * Firmao zwraca produkty z polami:
 *   id, productCode, name, type.label, currentStoreState,
 *   purchasePriceGroup{netPrice, unit}, salePriceGroups{A,B,C...},
 *   weight, ean, description, ...
 *
 * Magento potrzebuje:
 *   sku, name, price, weight, status, visibility, attribute_set_id,
 *   + custom atrybuty (manufacturer_code, ean, fuel_type, voltage...)
 */
class ProductMapper
{
    /**
     * Mapuj produkt Firmao na dane gotowe do upsert w Magento.
     *
     * @param array<string, mixed> $firmaoProduct
     * @param string $priceGroup Grupa cenowa sprzedazy (A, B, C)
     * @return array<string, mixed> Dane do ProductRepositoryInterface::save()
     */
    public function mapToMagento(array $firmaoProduct, string $priceGroup = 'A'): array
    {
        $sku = $firmaoProduct['productCode'] ?? '';
        $name = $firmaoProduct['name'] ?? $sku;

        // Cena sprzedazy z wybranej grupy cenowej
        $salePrice = $this->extractSalePrice($firmaoProduct, $priceGroup);

        // Cena zakupu (netto)
        $purchasePrice = (float) ($firmaoProduct['purchasePriceGroup']['netPrice'] ?? 0);

        // Jednostka
        $unit = $firmaoProduct['purchasePriceGroup']['unit'] ?? 'szt';

        // Waga (Firmao zwraca w kg)
        $weight = (float) ($firmaoProduct['weight'] ?? 0);

        // EAN
        $ean = $firmaoProduct['ean'] ?? '';

        // Opis
        $description = $firmaoProduct['description'] ?? '';
        $shortDescription = $firmaoProduct['shortDescription'] ?? '';

        return [
            'sku' => $sku,
            'name' => $name,
            'price' => $salePrice,
            'cost' => $purchasePrice,
            'weight' => $weight,
            'status' => 1, // enabled
            'visibility' => 4, // catalog + search
            'type_id' => 'simple',
            'tax_class_id' => 2, // Taxable Goods

            // Custom atrybuty Techtor
            'ean' => $ean,
            'manufacturer_code' => $firmaoProduct['manufacturerCode'] ?? '',

            // Meta
            '_firmao_id' => (int) ($firmaoProduct['id'] ?? 0),
            '_firmao_unit' => $unit,
            '_firmao_purchase_netto' => $purchasePrice,
            '_firmao_stock' => (float) ($firmaoProduct['currentStoreState'] ?? 0),
            '_firmao_raw' => $firmaoProduct, // caly obiekt do referencji

            // Opis
            'description' => $description,
            'short_description' => $shortDescription,
        ];
    }

    /**
     * Wyciagnij cene sprzedazy z grupy cenowej Firmao.
     *
     * Firmao ma grupy: A (bazowa), B (hurt 1), C (hurt 2), itd.
     * Kazda grupa ma netPrice i grossPrice.
     */
    private function extractSalePrice(array $product, string $group): float
    {
        // salePriceGroups to obiekt z kluczami A, B, C...
        $groups = $product['salePriceGroups'] ?? [];

        if (isset($groups[$group]['grossPrice'])) {
            return (float) $groups[$group]['grossPrice'];
        }

        if (isset($groups[$group]['netPrice'])) {
            // Oblicz brutto (23% VAT)
            return round((float) $groups[$group]['netPrice'] * 1.23, 2);
        }

        // Fallback: grupa A
        if ($group !== 'A' && isset($groups['A']['grossPrice'])) {
            return (float) $groups['A']['grossPrice'];
        }

        return 0.0;
    }

    /**
     * Mapuj typ produktu Firmao na atrybut product_type_techtor.
     *
     * @param string $firmaoType type.label z Firmao
     * @return string|null Wartosc atrybutu Magento, null jesli nie mapuje sie
     */
    public function mapProductType(string $firmaoType): ?string
    {
        $firmaoType = mb_strtolower(trim($firmaoType));

        return match (true) {
            str_contains($firmaoType, 'pomp')           => 'Pompa',
            str_contains($firmaoType, 'przeplywomierz') => 'Przeplywomierz',
            str_contains($firmaoType, 'waz')
                || str_contains($firmaoType, 'wąż')     => 'Waz',
            str_contains($firmaoType, 'armatur')
                || str_contains($firmaoType, 'zlacz')    => 'Armatura',
            str_contains($firmaoType, 'zbiornik')       => 'Zbiornik',
            str_contains($firmaoType, 'pistolet')       => 'Pistolet wydawczy',
            str_contains($firmaoType, 'filtr')          => 'Filtr',
            str_contains($firmaoType, 'zestaw')         => 'Zestaw',
            default                                     => 'Akcesoria',
        };
    }
}
