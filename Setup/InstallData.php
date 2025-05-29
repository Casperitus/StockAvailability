<?php

namespace Madar\StockAvailability\Setup;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Customer\Setup\CustomerSetupFactory;

class InstallData implements InstallDataInterface
{
    private $eavSetupFactory;
    private $customerSetupFactory;

    public function __construct(
        EavSetupFactory $eavSetupFactory,
        CustomerSetupFactory $customerSetupFactory
    ) {
        $this->eavSetupFactory = $eavSetupFactory;
        $this->customerSetupFactory = $customerSetupFactory;
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

        // Add product attribute
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'is_global_shipping',
            [
                'type' => 'int',
                'backend' => '',
                'frontend' => '',
                'label' => 'Global Shipping',
                'input' => 'boolean',
                'source' => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => true,
                'default' => '0',
                'searchable' => true,
                'filterable' => true,
                'comparable' => true,
                'visible_on_front' => false,
                'used_in_product_listing' => true,
                'unique' => false,
                'apply_to' => ''
            ]
        );

        // Initialize Customer Setup
        $customerSetup = $this->customerSetupFactory->create(['setup' => $setup]);

        // Add latitude and longitude attributes to customer addresses
        $attributes = [
            'latitude' => [
                'type' => 'decimal',
                'label' => 'Latitude',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'position' => 999,
                'system' => false,
            ],
            'longitude' => [
                'type' => 'decimal',
                'label' => 'Longitude',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'position' => 1000,
                'system' => false,
            ],
        ];

        foreach ($attributes as $code => $data) {
            $customerSetup->addAttribute('customer_address', $code, $data);

            $attribute = $customerSetup->getEavConfig()->getAttribute('customer_address', $code)
                ->addData([
                    'used_in_forms' => [
                        'adminhtml_customer_address',
                        'customer_address_edit',
                        'customer_register_address'
                    ]
                ]);

            $attribute->save();
        }
    }
}
