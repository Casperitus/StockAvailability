<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Setup\Patch\Data;

use Magento\Customer\Model\Indexer\Address\AttributeProvider;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\Set;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class AddLatitudeLongitudeCustomerAddressAttributes implements DataPatchInterface, PatchRevertableInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private CustomerSetupFactory $customerSetupFactory;
    private SetFactory $attributeSetFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CustomerSetupFactory $customerSetupFactory,
        SetFactory $attributeSetFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->customerSetupFactory = $customerSetupFactory;
        $this->attributeSetFactory = $attributeSetFactory;
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $customerEntity = $customerSetup->getEavConfig()->getEntityType(AttributeProvider::ENTITY);
        $attributeSetId = $customerEntity->getDefaultAttributeSetId();

        /** @var $attributeSet Set */
        $attributeSet = $this->attributeSetFactory->create();
        $attributeGroupId = $attributeSet->getDefaultGroupId($attributeSetId);

        // Add latitude attribute as varchar to simplify storage
        $customerSetup->addAttribute(
            AttributeProvider::ENTITY,
            'latitude',
            [
                'type' => 'varchar', // Using varchar for simplicity
                'label' => 'Latitude',
                'input' => 'text',
                'backend' => '',
                'required' => false,
                'visible' => true,
                'system' => false,
                'position' => 340,
            ]
        );

        // Add longitude attribute as varchar to simplify storage
        $customerSetup->addAttribute(
            AttributeProvider::ENTITY,
            'longitude',
            [
                'type' => 'varchar', // Using varchar for simplicity
                'label' => 'Longitude',
                'input' => 'text',
                'backend' => '',
                'required' => false,
                'visible' => true,
                'system' => false,
                'position' => 341,
            ]
        );

        // Configure forms to use the attributes
        foreach (['latitude', 'longitude'] as $attributeCode) {
            $attribute = $customerSetup->getEavConfig()->getAttribute(AttributeProvider::ENTITY, $attributeCode);
            $attribute->addData([
                'used_in_forms' => [
                    'adminhtml_customer_address',
                    'customer_address_edit',
                    'customer_register_address'
                ],
                'attribute_set_id' => $attributeSetId,
                'attribute_group_id' => $attributeGroupId
            ]);
            $attribute->save();
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $customerSetup->removeAttribute(AttributeProvider::ENTITY, 'latitude');
        $customerSetup->removeAttribute(AttributeProvider::ENTITY, 'longitude');

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
