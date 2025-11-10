<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Setup\Patch\Data;

use Madar\StockAvailability\Model\DeliverabilityAttribute;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

class EnsureIsDeliverableAttribute implements DataPatchInterface, PatchVersionInterface
{
    private const ATTRIBUTE_CODE = DeliverabilityAttribute::ATTRIBUTE_CODE;

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
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $entityTypeId = (int) $eavSetup->getEntityTypeId(Product::ENTITY);

            $attribute = $this->eavConfig->getAttribute($entityTypeId, self::ATTRIBUTE_CODE);

            if (!$attribute || !$attribute->getId()) {
                $this->createAttribute($eavSetup);
                $attribute = $this->eavConfig->getAttribute($entityTypeId, self::ATTRIBUTE_CODE);
            }

            if ($attribute && $attribute->getId()) {
                $this->synchroniseAttributeConfiguration($eavSetup, $entityTypeId, (int) $attribute->getId());
            }
        } finally {
            $connection->endSetup();
        }
    }

    public static function getDependencies(): array
    {
        return [
            DisableIsDeliverableLayeredNavigation::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getVersion(): string
    {
        return '1.0.3';
    }

    private function createAttribute(EavSetup $eavSetup): void
    {
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
                'is_filterable_in_grid' => 0,
                'visible_in_advanced_search' => 1,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'option' => [
                    'values' => [
                        DeliverabilityAttribute::LABEL_REQUESTABLE,
                        DeliverabilityAttribute::LABEL_DELIVERABLE,
                    ],
                ],
                'position' => 210,
                'group' => 'General',
            ]
        );
    }

    private function synchroniseAttributeConfiguration(EavSetup $eavSetup, int $entityTypeId, int $attributeId): void
    {
        $fieldsToUpdate = [
            'frontend_label' => 'Is Deliverable',
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
            'default' => 0,
        ];

        foreach ($fieldsToUpdate as $field => $value) {
            $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, $field, $value);
        }

        $attribute = $this->eavConfig->getAttribute($entityTypeId, self::ATTRIBUTE_CODE);
        if ($attribute && $attribute->getId()) {
            $this->ensureAttributeOptions($eavSetup, (int) $attribute->getId(), $attribute->getOptions());
        }

        $this->assignToAllAttributeSets($eavSetup, $entityTypeId, $attributeId);
    }

    private function ensureAttributeOptions(EavSetup $eavSetup, int $attributeId, array $existingOptions): void
    {
        $existingLabels = [];
        foreach ($existingOptions as $option) {
            $label = $option->getLabel();
            if (is_string($label) && $label !== '') {
                $existingLabels[] = strtolower($label);
            }
        }

        $missingLabels = [];
        foreach ([DeliverabilityAttribute::LABEL_REQUESTABLE, DeliverabilityAttribute::LABEL_DELIVERABLE] as $label) {
            if (!in_array(strtolower($label), $existingLabels, true)) {
                $missingLabels[] = $label;
            }
        }

        if (!$missingLabels) {
            return;
        }

        $values = [];
        foreach ($missingLabels as $missingLabel) {
            $values[] = $missingLabel;
        }

        $eavSetup->addAttributeOption([
            'attribute_id' => $attributeId,
            'values' => $values,
        ]);
    }

    private function assignToAllAttributeSets(EavSetup $eavSetup, int $entityTypeId, int $attributeId): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $attributeSetTable = $connection->getTableName('eav_attribute_set');
        $attributeSetIds = $connection->fetchCol(
            $connection->select()
                ->from($attributeSetTable, 'attribute_set_id')
                ->where('entity_type_id = ?', $entityTypeId)
        );

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
    }
}

