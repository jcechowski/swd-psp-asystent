<?php

declare(strict_types=1);

namespace Techtor\StockSync\Model;

/**
 * DTO reprezentujacy produkt z scrapera Tarnawa.
 */
class TarnawaProduct
{
    public function __construct(
        public readonly string $sku,
        public readonly float $quantity,
        public readonly string $status,
        public readonly float $priceNetto,
        public readonly string $name,
        public readonly string $lastUpdated
    ) {
    }

    public function isInStock(): bool
    {
        return $this->status === 'in-stock' && $this->quantity > 0;
    }

    public function isOnBackorder(): bool
    {
        return $this->status === 'on-backorder';
    }

    public function isOutOfStock(): bool
    {
        return $this->status === 'out-of-stock';
    }
}
