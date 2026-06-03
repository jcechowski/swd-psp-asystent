<?php

declare(strict_types=1);

namespace Techtor\BaseLinker\Model;

/**
 * Mapowanie statusow zamowien BaseLinker ↔ Magento.
 *
 * Statusy BL sa konfigurowane per konto — te domyslne ID to punkt startowy.
 * Docelowo: pobrac statusy z BL API (getOrderStatusList) i mapowac w adminie.
 *
 * Typowe statusy BL:
 *   0  = Nowe zamowienie
 *   1  = W realizacji
 *   2  = Wyslane
 *   3  = Dostarczone
 *   4  = Anulowane
 *   5  = Zwrot
 *
 * Statusy Magento (domyslne):
 *   pending          = Oczekujace
 *   processing       = W realizacji
 *   complete         = Zrealizowane
 *   canceled         = Anulowane
 *   closed           = Zamkniete (zwrot)
 *   holded           = Wstrzymane
 */
class StatusMapper
{
    /**
     * BL status ID → Magento status code.
     * Null = nie mapuj (ignoruj zmiane).
     *
     * @var array<int, string|null>
     */
    private const BL_TO_MAGENTO = [
        0 => 'pending',
        1 => 'processing',
        2 => 'complete',
        3 => 'complete',
        4 => 'canceled',
        5 => 'closed',
    ];

    /**
     * Magento status → BL status ID.
     *
     * @var array<string, int>
     */
    private const MAGENTO_TO_BL = [
        'pending'    => 0,
        'processing' => 1,
        'complete'   => 2,
        'canceled'   => 4,
        'closed'     => 5,
        'holded'     => 0, // wstrzymane → traktuj jako nowe w BL
    ];

    /**
     * Mapuj status BL na status Magento.
     */
    public function blToMagento(int $blStatusId): ?string
    {
        return self::BL_TO_MAGENTO[$blStatusId] ?? null;
    }

    /**
     * Mapuj status Magento na status BL.
     */
    public function magentoToBl(string $magentoStatus): ?int
    {
        return self::MAGENTO_TO_BL[$magentoStatus] ?? null;
    }

    /**
     * Pobierz cale mapowanie BL → Magento.
     *
     * @return array<int, string|null>
     */
    public function getAllBlToMagento(): array
    {
        return self::BL_TO_MAGENTO;
    }

    /**
     * Pobierz cale mapowanie Magento → BL.
     *
     * @return array<string, int>
     */
    public function getAllMagentoToBl(): array
    {
        return self::MAGENTO_TO_BL;
    }
}
