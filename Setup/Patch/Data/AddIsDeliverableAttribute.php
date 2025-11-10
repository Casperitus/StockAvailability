<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

class AddIsDeliverableAttribute implements DataPatchInterface, PatchVersionInterface
{
    private const ATTRIBUTE_CODE = 'is_deliverable';

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;
    private EavConfig $eavConfig;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory,
        EavConfig $eavConfig
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->eavConfig = $eavConfig;
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
            $attribute = $this->eavConfig->getAttribute($entityTypeId, self::ATTRIBUTE_CODE);

            if (!$attribute || !$attribute->getId()) {
                $eavSetup->addAttribute(
                    Product::ENTITY,
                    self::ATTRIBUTE_CODE,
                    [
                        'type' => 'int',
                        'label' => 'Is Deliverable',
                        'input' => 'select',
                        'backend' => '',
                        'frontend' => '',
                        'required' => false,
                        'visible' => true,
                        'user_defined' => true,
                        'default' => 0,
                        'searchable' => 1,
                        'filterable' => 0,
                        'filterable_in_search' => 0,
                        'comparable' => 0,
                        'visible_on_front' => 1,
                        'used_in_product_listing' => 1,
                        'unique' => 0,
                        'is_configurable' => 0,
                        'is_used_in_grid' => 1,
                        'is_visible_in_grid' => 1,
                        'is_filterable_in_grid' => 1,
                        'visible_in_advanced_search' => 1,
                        'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                        'option' => [
                            'values' => [
                                'Requestable',
                                'Deliverable',
                            ],
                        ],
                        'position' => 210,
                        'group' => 'General',
                    ]
                );

                $attributeSetId = $eavSetup->getDefaultAttributeSetId(Product::ENTITY);
                $attributeGroupId = $eavSetup->getDefaultAttributeGroupId(Product::ENTITY, $attributeSetId);
                $eavSetup->addAttributeToSet(
                    Product::ENTITY,
                    $attributeSetId,
                    $attributeGroupId,
                    self::ATTRIBUTE_CODE,
                    210
                );
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getVersion(): string
    {
        return '1.0.0';
    }
}
