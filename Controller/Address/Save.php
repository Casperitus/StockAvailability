<?php

namespace Madar\StockAvailability\Controller\Address;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Directory\Model\RegionFactory;
use Psr\Log\LoggerInterface;

class Save extends Action
{
    protected $jsonFactory;
    protected $customerSession;
    protected $addressRepository;
    protected $addressFactory;
    protected $regionFactory;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        CustomerSession $customerSession,
        AddressRepositoryInterface $addressRepository,
        AddressInterfaceFactory $addressFactory,
        RegionFactory $regionFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->customerSession = $customerSession;
        $this->addressRepository = $addressRepository;
        $this->addressFactory = $addressFactory;
        $this->regionFactory = $regionFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            $this->logger->info('User not logged in.');
            return $result->setData([
                'error' => true,
                'message' => __('User not logged in')
            ]);
        }

        $data = json_decode($this->getRequest()->getContent(), true);
        $this->logger->info('Received data:', $data);

        if (empty($data)) {
            $this->logger->error('No data received in the request.');
            return $result->setData([
                'error' => true,
                'message' => __('No data received.')
            ]);
        }

        $customerId = $this->customerSession->getCustomerId();

        try {
            // 1) Load or create address
            if (!empty($data['address_id'])) {
                $this->logger->info('Loading existing address with ID ' . $data['address_id']);
                $address = $this->addressRepository->getById($data['address_id']);
                if ($address->getCustomerId() != $customerId) {
                    throw new LocalizedException(__('Address does not belong to this customer.'));
                }
            } else {
                $this->logger->info('Creating a new address for customer ID ' . $customerId);
                $address = $this->addressFactory->create();
                $address->setCustomerId($customerId);
            }

            // 2) Basic fields:
            //    - If user didn't pass "firstname" or "lastname", fallback to the actual customer's data or "Default"
            $firstname = $data['firstname']
                ?? $this->customerSession->getCustomer()->getFirstname()
                ?? 'Default Firstname';

            $lastname = $data['lastname']
                ?? $this->customerSession->getCustomer()->getLastname()
                ?? 'Default Lastname';

            $telephone = $data['telephone'] ?? '0000000000';

            // Street can be multi-line if we want
            $streetRaw = $data['street'] ?? 'Default Street';
            // If streetRaw is a string, wrap it in array. If array, use as is.
            $streetLines = is_array($streetRaw) ? $streetRaw : [$streetRaw];

            $address->setFirstname($firstname)
                ->setLastname($lastname)
                ->setStreet($streetLines)
                ->setCity($data['city'] ?? 'Default City')
                ->setCountryId($data['country_id'] ?? 'SA')
                ->setPostcode($data['postcode'] ?? '00000')
                ->setTelephone($telephone);

            $this->logger->info('Set main address fields.');

            // 3) Region (Uncommenting your code):
            /* if (!empty($data['region'])) {
                $regionName = $data['region'];
                $countryId = $data['country_id'] ?? 'SA';
                $region = $this->regionFactory->create()->loadByName($regionName, $countryId);
                if ($region && $region->getId()) {
                    $address->setRegionId($region->getId());
                    $this->logger->info('Set region ID for ' . $regionName);
                } else {
                    $address->setRegion($regionName);
                    $this->logger->info('Set region as text: ' . $regionName);
                }
            } */

            // 4) Custom attributes for lat/lng
            if (!empty($data['latitude'])) {
                try {
                    $address->setCustomAttribute('latitude', $data['latitude']);
                    $this->logger->info('Set latitude: ' . $data['latitude']);
                } catch (\Exception $e) {
                    $this->logger->error('Error setting latitude: ' . $e->getMessage());
                }
            }
            if (!empty($data['longitude'])) {
                try {
                    $address->setCustomAttribute('longitude', $data['longitude']);
                    $this->logger->info('Set longitude: ' . $data['longitude']);
                } catch (\Exception $e) {
                    $this->logger->error('Error setting longitude: ' . $e->getMessage());
                }
            }

            // 5) If you want default shipping/billing:
            if (!empty($data['is_default_shipping'])) {
                $address->setIsDefaultShipping(true);
            }
            if (!empty($data['is_default_billing'])) {
                $address->setIsDefaultBilling(false);
            }

            // 6) Save
            try {
                $this->addressRepository->save($address);
                $this->logger->info('Address saved successfully with ID ' . $address->getId());
                $this->customerSession->setData('customer_latitude', $data['latitude']);
                $this->customerSession->setData('customer_longitude', $data['longitude']);
                return $result->setData(['success' => true, 'message' => __('Address saved successfully.')]);
            } catch (\Exception $e) {
                $this->logger->error('Error saving address: ' . $e->getMessage());
                $this->logger->error($e->getTraceAsString());
                return $result->setData([
                    'error' => true,
                    'message' => __('An error occurred while saving the address.')
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in address processing: ' . $e->getMessage());
            return $result->setData(['error' => true, 'message' => $e->getMessage()]);
        }
    }
}
