<?php

namespace Madar\StockAvailability\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class MigrateDeliveryData extends Command
{
    protected $appState;
    protected $orderCollectionFactory;
    protected $addressRepository;

    public function __construct(
        State $appState,
        OrderCollectionFactory $orderCollectionFactory,
        AddressRepositoryInterface $addressRepository
    ) {
        $this->appState = $appState;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->addressRepository = $addressRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('madar:migrate:delivery-data')
            ->setDescription('Migrate delivery data from customer addresses to order addresses');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $output->writeln('Starting delivery data migration...');

        $orders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', ['notnull' => true])
            ->addFieldToSelect(['entity_id', 'customer_id', 'increment_id']);

        $migrated = 0;
        $total = $orders->count();

        foreach ($orders as $order) {
            try {
                $shippingAddress = $order->getShippingAddress();
                if (!$shippingAddress) {
                    continue;
                }

                // Skip if already has coordinates
                if ($shippingAddress->getData('latitude') && $shippingAddress->getData('longitude')) {
                    continue;
                }

                // Get customer's default shipping address
                $customerId = $order->getCustomerId();
                if (!$customerId) {
                    continue;
                }

                // Find customer address that matches order address
                $customer = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
                    ->getById($customerId);

                $addresses = $customer->getAddresses();
                $matchingAddress = null;

                foreach ($addresses as $address) {
                    // Try to match by street and city
                    $orderStreet = implode(' ', $shippingAddress->getStreet());
                    $addressStreet = implode(' ', $address->getStreet());
                    
                    if (stripos($orderStreet, $addressStreet) !== false && 
                        $shippingAddress->getCity() === $address->getCity()) {
                        $matchingAddress = $address;
                        break;
                    }
                }

                // If no match, use default shipping address
                if (!$matchingAddress) {
                    foreach ($addresses as $address) {
                        if ($address->isDefaultShipping()) {
                            $matchingAddress = $address;
                            break;
                        }
                    }
                }

                if ($matchingAddress) {
                    $latitude = $matchingAddress->getCustomAttribute('latitude');
                    $longitude = $matchingAddress->getCustomAttribute('longitude');

                    if ($latitude && $longitude) {
                        $shippingAddress->setData('latitude', $latitude->getValue());
                        $shippingAddress->setData('longitude', $longitude->getValue());
                        $shippingAddress->save();
                        $migrated++;
                        
                        $output->writeln("Migrated order #{$order->getIncrementId()}");
                    }
                }

            } catch (\Exception $e) {
                $output->writeln("Error processing order #{$order->getIncrementId()}: " . $e->getMessage());
            }
        }

        $output->writeln("Migration completed. Migrated {$migrated} out of {$total} orders.");
        return 0;
    }
}