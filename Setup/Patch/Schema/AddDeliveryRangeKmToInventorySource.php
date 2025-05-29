<?php

namespace Madar\StockAvailability\Setup\Patch\Schema;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\DB\Ddl\Table;

class AddDeliveryRangeKmToInventorySource implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    /**
     * Constructor
     *
     * @param SchemaSetupInterface $schemaSetup
     */
    public function __construct(
        SchemaSetupInterface $schemaSetup
    ) {
        $this->schemaSetup = $schemaSetup;
    }

    /**
     * Apply the patch
     */
    public function apply()
    {
        $setup = $this->schemaSetup;
        $setup->startSetup();

        $tableName = $setup->getTable('inventory_source');

        if ($setup->getConnection()->isTableExists($tableName)) {
            // Check if the column already exists
            if (!$setup->getConnection()->tableColumnExists($tableName, 'delivery_range_km')) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'delivery_range_km',
                    [
                        'type' => Table::TYPE_DECIMAL,
                        'length' => '12,4',
                        'nullable' => false,
                        'default' => '0.0000',
                        'comment' => 'Delivery Range in KM'
                    ]
                );
            }
        }

        $setup->endSetup();
    }

    /**
     * Get Aliases
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * Get Dependencies
     */
    public static function getDependencies()
    {
        return [];
    }
}
