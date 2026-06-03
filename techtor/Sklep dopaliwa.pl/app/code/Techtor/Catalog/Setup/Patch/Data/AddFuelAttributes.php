<?php

declare(strict_types=1);

namespace Techtor\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddFuelAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);

        // --- Attribute Set: Sprzet paliwowy ---
        $attributeSetName = 'Sprzet paliwowy';
        $defaultSetId = $eavSetup->getDefaultAttributeSetId($entityTypeId);
        $eavSetup->addAttributeSet($entityTypeId, $attributeSetName);
        $attributeSetId = $eavSetup->getAttributeSetId($entityTypeId, $attributeSetName);

        // Kopiuj grupy z Default
        $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, 'Parametry techniczne', 15);

        // --- Atrybuty ---
        $attributes = [
            'product_type_techtor' => [
                'label' => 'Typ produktu',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => [
                        'Pompa',
                        'Przeplywomierz',
                        'Waz',
                        'Armatura',
                        'Zbiornik',
                        'Pistolet wydawczy',
                        'Filtr',
                        'Zestaw',
                        'Akcesoria',
                    ],
                ],
                'filterable' => true,
                'searchable' => true,
                'comparable' => true,
                'visible_on_front' => true,
            ],
            'fuel_type' => [
                'label' => 'Typ paliwa',
                'input' => 'multiselect',
                'type' => 'text',
                'backend' => \Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend::class,
                'option' => [
                    'values' => ['Diesel', 'Benzyna', 'AdBlue', 'Olej', 'LPG', 'Biopaliwo'],
                ],
                'filterable' => true,
                'searchable' => true,
                'visible_on_front' => true,
            ],
            'flow_rate' => [
                'label' => 'Wydajnosc (l/min)',
                'input' => 'text',
                'type' => 'varchar',
                'filterable' => true,
                'searchable' => true,
                'visible_on_front' => true,
            ],
            'connection_type' => [
                'label' => 'Typ przylacza',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => [
                        'BSP 1"',
                        'BSP 3/4"',
                        'BSP 1/2"',
                        'BSP 2"',
                        'M14x1.5',
                        'NPT 1"',
                        'Camlock',
                    ],
                ],
                'filterable' => true,
                'visible_on_front' => true,
            ],
            'voltage' => [
                'label' => 'Napiecie',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => ['12V', '24V', '230V', 'Reczna'],
                ],
                'filterable' => true,
                'searchable' => true,
                'visible_on_front' => true,
            ],
            'certification' => [
                'label' => 'Certyfikaty',
                'input' => 'multiselect',
                'type' => 'text',
                'backend' => \Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend::class,
                'option' => [
                    'values' => ['ATEX', 'CE', 'ADR', 'ISO 9001', 'UL'],
                ],
                'filterable' => true,
                'visible_on_front' => true,
            ],
            'hose_diameter' => [
                'label' => 'Srednica weza',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => ['DN13', 'DN16', 'DN19', 'DN25', 'DN32', 'DN38', 'DN50'],
                ],
                'filterable' => true,
                'visible_on_front' => true,
            ],
            'hose_length' => [
                'label' => 'Dlugosc weza',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => ['1m', '2m', '3m', '4m', '5m', '6m', '8m', '10m'],
                ],
                'filterable' => true,
                'visible_on_front' => true,
            ],
            'manufacturer_code' => [
                'label' => 'Kod producenta',
                'input' => 'text',
                'type' => 'varchar',
                'filterable' => false,
                'searchable' => true,
                'visible_on_front' => true,
            ],
            'supplier_code_tarnawa' => [
                'label' => 'Kod Tarnawa',
                'input' => 'text',
                'type' => 'varchar',
                'filterable' => false,
                'searchable' => true,
                'visible_on_front' => false,
            ],
        ];

        foreach ($attributes as $code => $config) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                $code,
                array_merge([
                    'group' => 'Parametry techniczne',
                    'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'required' => false,
                    'user_defined' => true,
                    'is_used_in_grid' => true,
                    'is_visible_in_grid' => false,
                    'is_filterable_in_grid' => $config['filterable'] ?? false,
                    'visible' => true,
                    'sort_order' => 10,
                    'apply_to' => '',
                    'is_searchable' => $config['searchable'] ?? false ? 1 : 0,
                    'is_filterable' => $config['filterable'] ?? false ? 1 : 0,
                    'is_filterable_in_search' => $config['filterable'] ?? false ? 1 : 0,
                    'is_comparable' => $config['comparable'] ?? false ? 1 : 0,
                    'is_visible_on_front' => $config['visible_on_front'] ?? false ? 1 : 0,
                    'used_in_product_listing' => 1,
                ], $config)
            );

            // Dodaj atrybut do naszego Attribute Set
            $attributeId = $eavSetup->getAttributeId($entityTypeId, $code);
            $eavSetup->addAttributeToSet(
                $entityTypeId,
                $attributeSetId,
                'Parametry techniczne',
                $attributeId
            );
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
