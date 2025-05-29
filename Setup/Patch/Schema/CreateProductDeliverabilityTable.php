<?php

namespace Madar\StockAvailability\Setup\Patch\Schema;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\DB\Ddl\Table;

class CreateProductDeliverabilityTable implements SchemaPatchInterface
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

        $tableName = $setup->getTable('madar_product_deliverability');

        if (!$setup->getConnection()->isTableExists($tableName)) {
            // Create the table
            $table = $setup->getConnection()->newTable($tableName)
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    null,
                    [
                        'identity' => true,
                        'nullable' => false,
                        'primary' => true,
                        'unsigned' => true,
                    ],
                    'ID'
                )
                ->addColumn(
                    'product_sku',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'Product SKU'
                )
                ->addColumn(
                    'source_code',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'Inventory Source Code'
                )
                ->addColumn(
                    'deliverable',
                    Table::TYPE_BOOLEAN,
                    null,
                    ['nullable' => false, 'default' => '0'],
                    'Is Deliverable'
                )
                ->addColumn(
                    'last_updated',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
                    'Last Updated Time'
                )
                ->addIndex(
                    $setup->getIdxName('madar_product_deliverability', ['product_sku', 'source_code'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                    ['product_sku', 'source_code'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->setComment('Madar Product Deliverability');

            $setup->getConnection()->createTable($table);
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
        return [
            // Ensure that required schema patches run before this one
            AddDeliveryRangeKmToInventorySource::class,
            AddAssociatedHubsToInventorySource::class,
            AddIsHubToInventorySource::class,
        ];
    }
}
