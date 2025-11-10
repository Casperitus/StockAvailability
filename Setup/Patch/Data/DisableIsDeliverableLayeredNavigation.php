<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

class DisableIsDeliverableLayeredNavigation implements DataPatchInterface, PatchVersionInterface
{
    private const ATTRIBUTE_CODE = 'is_deliverable';

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

            $fieldsToUpdate = [
                'is_filterable' => 0,
                'is_filterable_in_search' => 0,
                'is_filterable_in_grid' => 0,
            ];

            foreach ($fieldsToUpdate as $field => $value) {
                $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, $field, $value);
            }
        } finally {
            $connection->endSetup();
        }
    }

    public static function getDependencies(): array
    {
        return [
            UpdateIsDeliverableAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getVersion(): string
    {
        return '1.0.2';
    }
}
