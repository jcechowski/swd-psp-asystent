<?php

declare(strict_types=1);

namespace Techtor\Import\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogUrlRewrite\Model\Product\GetProductUrlRewriteDataByStore;

/**
 * Zabezpiecza GetProductUrlRewriteDataByStore przed crashem
 * gdy produkt jest właśnie tworzony (EAV attributes nie są jeszcze w bazie).
 *
 * Bug Magento 2.4.7: przy nowym produkcie, afterSave event odpala
 * URL rewrite generation zanim EAV wartości (url_key, visibility)
 * trafią do bazy, co powoduje TypeError na null return.
 */
class SafeUrlRewriteData
{
    public function aroundExecute(
        GetProductUrlRewriteDataByStore $subject,
        callable $proceed,
        ProductInterface $product,
        int $storeId
    ): array {
        try {
            return $proceed($product, $storeId);
        } catch (\TypeError|\Error $e) {
            // Fallback: zwróć dane z obiektu produktu (w pamięci)
            return [
                'visibility' => (int) ($product->getVisibility() ?? 4),
                'url_key' => $product->getUrlKey() ?? $product->getSku(),
            ];
        }
    }
}
