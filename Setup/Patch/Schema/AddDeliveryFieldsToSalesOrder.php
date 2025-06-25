<?php

namespace Madar\StockAvailability\Setup\Patch\Schema;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\DB\Ddl\Table;

class AddDeliveryFieldsToSalesOrder implements SchemaPatchInterface
{
    private $schemaSetup;

    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    public function apply()
    {
        $setup = $this->schemaSetup;
        $setup->startSetup();

        // Add fields to sales_order table
        $orderTable = $setup->getTable('sales_order');
        if ($setup->getConnection()->isTableExists($orderTable)) {
            $connection = $setup->getConnection();
            
            if (!$connection->tableColumnExists($orderTable, 'delivery_source_code')) {
                $connection->addColumn(
                    $orderTable,
                    'delivery_source_code',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 64,
                        'nullable' => true,
                        'comment' => 'Delivery Source Code'
                    ]
                );
            }

            if (!$connection->tableColumnExists($orderTable, 'delivery_branch_name')) {
                $connection->addColumn(
                    $orderTable,
                    'delivery_branch_name',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 255,
                        'nullable' => true,
                        'comment' => 'Delivery Branch Name'
                    ]
                );
            }

            if (!$connection->tableColumnExists($orderTable, 'delivery_branch_phone')) {
                $connection->addColumn(
                    $orderTable,
                    'delivery_branch_phone',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 50,
                        'nullable' => true,
                        'comment' => 'Delivery Branch Phone'
                    ]
                );
            }
        }

        // Add fields to sales_order_address table
        $addressTable = $setup->getTable('sales_order_address');
        if ($setup->getConnection()->isTableExists($addressTable)) {
            $connection = $setup->getConnection();
            
            if (!$connection->tableColumnExists($addressTable, 'delivery_source_code')) {
                $connection->addColumn(
                    $addressTable,
                    'delivery_source_code',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 64,
                        'nullable' => true,
                        'comment' => 'Delivery Source Code'
                    ]
                );
            }

            if (!$connection->tableColumnExists($addressTable, 'delivery_branch_name')) {
                $connection->addColumn(
                    $addressTable,
                    'delivery_branch_name',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 255,
                        'nullable' => true,
                        'comment' => 'Delivery Branch Name'
                    ]
                );
            }

            if (!$connection->tableColumnExists($addressTable, 'delivery_branch_phone')) {
                $connection->addColumn(
                    $addressTable,
                    'delivery_branch_phone',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 50,
                        'nullable' => true,
                        'comment' => 'Delivery Branch Phone'
                    ]
                );
            }
        }

        $setup->endSetup();
    }

    public function getAliases()
    {
        return [];
    }

    public static function getDependencies()
    {
        return [];
    }
}