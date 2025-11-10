<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

class UpdateIsDeliverableAttribute implements DataPatchInterface, PatchVersionInterface
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
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);
            $attribute = $this->eavConfig->getAttribute($entityTypeId, self::ATTRIBUTE_CODE);

            if (!$attribute || !$attribute->getId()) {
                return;
            }

            $fieldsToUpdate = [
                'is_filterable' => 0,
                'is_filterable_in_search' => 0,
                'is_filterable_in_grid' => 0,
                'is_visible_on_front' => 1,
                'visible_on_front' => 1,
                'is_used_in_grid' => 1,
                'is_visible_in_grid' => 1,
                'used_in_product_listing' => 1,
                'searchable' => 1,
                'visible' => 1,
            ];

            foreach ($fieldsToUpdate as $field => $value) {
                $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, $field, $value);
            }

            $attributeSetTable = $connection->getTableName('eav_attribute_set');
            $attributeSetIds = $connection->fetchCol(
                $connection->select()
                    ->from($attributeSetTable, 'attribute_set_id')
                    ->where('entity_type_id = ?', $entityTypeId)
            );

            $attributeId = (int) $attribute->getId();

            foreach ($attributeSetIds as $attributeSetId) {
                $attributeGroupId = $eavSetup->getDefaultAttributeGroupId(Product::ENTITY, (int) $attributeSetId);
                if ($attributeGroupId) {
                    $eavSetup->addAttributeToSet(
                        Product::ENTITY,
                        (int) $attributeSetId,
                        $attributeGroupId,
                        $attributeId,
                        210
                    );
                }
            }
        } finally {
            $connection->endSetup();
        }
    }

    public static function getDependencies(): array
    {
        return [AddIsDeliverableAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getVersion(): string
    {
        return '1.0.1';
    }
}
