<?php

declare(strict_types=1);

namespace Techtor\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddMissingAttributes implements DataPatchInterface
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
        $attributeSetId = $eavSetup->getAttributeSetId($entityTypeId, 'Sprzet paliwowy');

        $attributes = [
            'ean' => [
                'label' => 'EAN / GTIN',
                'input' => 'text',
                'type' => 'varchar',
                'sort_order' => 5,
                'searchable' => true,
                'visible_on_front' => true,
                'note' => 'Kod kreskowy EAN-8/EAN-13/GTIN-14',
            ],
            'delivery_time' => [
                'label' => 'Czas dostawy',
                'input' => 'select',
                'type' => 'int',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'option' => [
                    'values' => [
                        'Wysyłka w 24h',
                        'Wysyłka w 48h',
                        'Na zamówienie (3-7 dni)',
                        'Niedostępny',
                    ],
                ],
                'sort_order' => 6,
                'filterable' => true,
                'visible_on_front' => true,
                'used_in_product_listing' => true,
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
                    'is_filterable_in_grid' => $config['filterable'] ?? false ? 1 : 0,
                    'visible' => true,
                    'apply_to' => '',
                    'is_searchable' => $config['searchable'] ?? false ? 1 : 0,
                    'is_filterable' => $config['filterable'] ?? false ? 1 : 0,
                    'is_filterable_in_search' => $config['filterable'] ?? false ? 1 : 0,
                    'is_visible_on_front' => $config['visible_on_front'] ?? false ? 1 : 0,
                    'used_in_product_listing' => $config['used_in_product_listing'] ?? 0 ? 1 : 0,
                ], $config)
            );

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
        return [AddFuelAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
