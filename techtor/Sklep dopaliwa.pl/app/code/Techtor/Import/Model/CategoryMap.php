<?php

declare(strict_types=1);

namespace Techtor\Import\Model;

/**
 * Mapowanie 87 master kategorii PIM → drzewo kategorii Magento.
 *
 * Struktura: L1 (kategoria nadrzędna) → L2[] (podkategorie).
 * Każda L2 zawiera listę nazw master kategorii PIM które do niej trafiają.
 *
 * Jeśli L2 ma jedną pozycję o tej samej nazwie co klucz — to bezpośrednie mapowanie 1:1.
 */
class CategoryMap
{
    /**
     * Drzewo kategorii Magento.
     * Klucz L1 = nazwa kategorii nadrzędnej.
     * Klucz L2 = nazwa podkategorii Magento.
     * Wartość = tablica nazw master kategorii PIM.
     *
     * @return array<string, array<string, string[]>>
     */
    public static function getTree(): array
    {
        return [
            'Pompy' => [
                'Pompy do ON'       => ['Pompa do ON'],
                'Pompy do benzyny'  => ['Pompa do Pb'],
                'Pompy do oleju'    => ['Pompa do oleju'],
                'Pompy do smaru'    => ['Pompa do smaru'],
                'Pompy do AdBlue'   => ['Pompa do Adblue'],
            ],

            'Dystrybutory' => [
                'Dystrybutory paliwa'             => ['Dystrybutor'],
                'Dystrybutory AdBlue na beczkę'   => ['Dystrybutor Adblue Beczka'],
                'Dystrybutory AdBlue na mauser'   => ['Dystrybutor Adblue Mauser'],
                'Dystrybutory benzyny'            => ['Dystrybutor Pb'],
                'Układy dystrybucyjne ON'         => ['Układ dystrybucyjny ON'],
            ],

            'Liczniki i przepływomierze' => [
                'Liczniki do ON'      => ['Licznik ON'],
                'Liczniki do benzyny' => ['Licznik Pb'],
                'Liczniki do oleju'   => ['Licznik olej'],
                'Liczniki do smaru'   => ['Licznik smaru'],
                'Liczniki do AdBlue'  => ['Licznik Adblue'],
            ],

            'Pistolety i końcówki' => [
                'Pistolety do ON'      => ['Pistolet do ON'],
                'Pistolety do benzyny' => ['Pistolet do Pb'],
                'Pistolety do oleju'   => ['Pistolet do oleju'],
                'Pistolety do AdBlue'  => ['Pistolet do Adblue'],
                'Końcówki obrotowe'    => ['Końcówka obrotowa'],
                'Końcówki proste'      => ['Końcówka prosta'],
                'Uchwyty do pistoletów' => ['Uchwyt do pistoletu'],
                'Rozpylacze'           => ['Rozpylacz'],
            ],

            'Węże' => [
                'Węże do ON'                  => ['Wąż do ON', 'M-FLEX PETROL'],
                'Węże do AdBlue'              => ['Wąż do AdBlue'],
                'Węże PVC'                    => ['Wąż PVC'],
                'Węże hydrauliczne'           => ['Wąż hydrauliczny'],
                'Węże do chłodziwa'           => ['Wąż do chłodziwa'],
                'Węże do powietrza i wody'    => ['Wąż do powietrza i wody'],
            ],

            'Zwijaki' => [
                'Zwijaki stalowe malowane'     => ['Zwijak - stal malowana'],
                'Zwijaki stalowe nierdzewne'   => ['Zwijak - stal nierdzewna'],
                'Zwijaki do AdBlue'            => ['Zwijak do AdBlue'],
                'Zwijaki do ON'                => ['Zwijak do ON'],
            ],

            'Armatura i złączki' => [
                'Armatura'            => ['Armatura'],
                'Armatura AdBlue'     => ['Armatura Adblue'],
                'Złączki Camlock'     => ['Camlock'],
                'Flansze'             => ['Flansza'],
                'Kolanka'             => ['Kolanko'],
                'Mufy'                => ['Mufa'],
                'Nypla'               => ['Nypel'],
                'Nypla redukcyjne'    => ['Nypel redukcyjny'],
                'Redukcje'            => ['Redukcja'],
                'Szybkozłącza'        => ['Szybkozłącze'],
                'Trójniki'            => ['Trójnik'],
                'Zawory kulowe'       => ['Zawór kulowy'],
                'Zawory zwrotne'      => ['Zawór zwrotny'],
                'Śrubunki'            => ['Śrubunek'],
            ],

            'Filtry i oczyszczanie' => [
                'Filtry do ON'        => ['Filtr'],
                'Filtry do AdBlue'    => ['Filtr Adblue'],
                'Oczyszczarki'        => ['Oczyszczarka'],
                'Myjki do części'     => ['Myjka do części'],
                'Wysysarki'           => ['Wysysarka'],
                'Ściekarki'           => ['Ściekarka'],
            ],

            'Zbiorniki i osprzęt' => [
                'Barki do dystrybucji'  => ['Barek'],
                'Zbiorniki Tankwagen'   => ['Tankwagen'],
                'Wanny kanałowe'        => ['Wanna kanałowa'],
                'Wózki pod beczkę'      => ['Wózek pod beczkę'],
            ],

            'Części zamienne i akcesoria' => [
                'Części zamienne'          => ['Części zamienne'],
                'Zestawy olejowe'          => ['Zestaw olejowy'],
                'Zestawy smarowe'          => ['Zestaw smarowy'],
                'Biodiesel'                => ['Biodiesel'],
                'By-pass'                  => ['By-pass'],
                'Dozowniki oleju'          => ['Dozownik oleju'],
                'Dławiki EX'               => ['Dławik EX'],
                'Głowice'                  => ['Głowica'],
                'Hydranty'                 => ['Hydranty'],
                'Kosze ssawne'             => ['Kosz ssawny'],
                'Membrany'                 => ['Membrana'],
                'Obejmy'                   => ['Obejma'],
                'Oringi'                   => ['Oring'],
                'Osłony'                   => ['Osłona wiatraka'],
                'Podkładki'               => ['Podkładka metalowo-gumowa'],
                'Pokrywy bębna'           => ['Pokrywa bębna'],
                'Przewody zasilające'      => ['Przewód zasilający'],
                'Przyłącza do beczki'      => ['Przyłącze do beczki'],
                'Puszki elektryczne'       => ['Puszka elektryczna'],
                'Płyny niskozamarzające'   => ['Płyny niskozamarzające'],
                'Płyty dociskowe'          => ['Płyta dociskowa'],
                'Płyty montażowe'          => ['Płyta montażowa'],
                'Rury ssawne'              => ['Rura ssawna'],
                'Szczotki'                 => ['Szczotki'],
                'Urządzenia do hamulców'   => ['Urządzenie do hamulców'],
                'Uszczelnienia'            => ['Uszczelnienie'],
                'Usługi'                   => ['Usługa'],
                'Łopatki'                  => ['Łopatki'],
                'Mocowanie BP3000'         => ['Mocowanie BP3000'],
            ],
        ];
    }

    /**
     * Zwróć odwrotne mapowanie: nazwa PIM → [L1, L2].
     *
     * @return array<string, array{l1: string, l2: string}>
     */
    public static function getReverseLookup(): array
    {
        $lookup = [];
        foreach (self::getTree() as $l1 => $l2s) {
            foreach ($l2s as $l2Name => $pimNames) {
                foreach ($pimNames as $pimName) {
                    $lookup[$pimName] = ['l1' => $l1, 'l2' => $l2Name];
                }
            }
        }
        return $lookup;
    }

    /**
     * Ile kategorii L1 i L2 jest zdefiniowanych.
     *
     * @return array{l1: int, l2: int}
     */
    public static function stats(): array
    {
        $tree = self::getTree();
        $l2Count = 0;
        foreach ($tree as $l2s) {
            $l2Count += count($l2s);
        }
        return ['l1' => count($tree), 'l2' => $l2Count];
    }
}
