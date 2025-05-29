<?php

namespace Madar\StockAvailability\Setup\Patch\Schema;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\DB\Ddl\Table;

class AddIsHubToInventorySource implements SchemaPatchInterface
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
            if (!$setup->getConnection()->tableColumnExists($tableName, 'is_hub')) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'is_hub',
                    [
                        'type' => Table::TYPE_BOOLEAN,
                        'nullable' => false,
                        'default' => '0',
                        'comment' => 'Is Hub - Toggle to Enable/Disable'
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
