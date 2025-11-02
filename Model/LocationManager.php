<?php

namespace Madar\StockAvailability\Model;

use Madar\StockAvailability\Helper\StockHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class LocationManager
{
    private Session $session;

    private CustomerSession $customerSession;

    private CheckoutSession $checkoutSession;

    private CartRepositoryInterface $cartRepository;

    private AddressRepositoryInterface $addressRepository;

    private AddressInterfaceFactory $addressFactory;

    private StockHelper $stockHelper;

    private LoggerInterface $logger;

    public function __construct(
        Session $session,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        AddressRepositoryInterface $addressRepository,
        AddressInterfaceFactory $addressFactory,
        StockHelper $stockHelper,
        LoggerInterface $logger
    ) {
        $this->session = $session;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->addressRepository = $addressRepository;
        $this->addressFactory = $addressFactory;
        $this->stockHelper = $stockHelper;
        $this->logger = $logger;
    }

    /**
     * Persist the selected location into session, customer address book and the active quote.
     *
     * @param array $payload
     * @return array
     */
    public function persistLocation(array $payload): array
    {
        $latitude = $payload['customer_latitude'] ?? $payload['latitude'] ?? null;
        $longitude = $payload['customer_longitude'] ?? $payload['longitude'] ?? null;

        if ($latitude !== null) {
            $this->session->setData('customer_latitude', (string)$latitude);
        }
        if ($longitude !== null) {
            $this->session->setData('customer_longitude', (string)$longitude);
        }

        $branchData = $this->resolveBranchData($payload, $latitude, $longitude);

        $addressData = $this->normalizeAddressData($payload['address'] ?? []);
        if ($addressData && $latitude !== null && $longitude !== null) {
            $addressData['latitude'] = $latitude;
            $addressData['longitude'] = $longitude;
        }

        if (!empty($payload['save_address']) && $addressData) {
            $this->persistCustomerAddress($addressData);
        }

        $this->session->setData('location_shipping_address', $addressData ?: []);

        $this->updateQuote($addressData, $branchData);

        $response = [
            'branch' => $branchData,
            'shipping_address' => $addressData,
            'prefill' => $this->getCheckoutPrefillData(),
        ];

        return $response;
    }

    /**
     * Applies the stored location information to the order being saved.
     */
    public function applyToOrder(OrderInterface $order): void
    {
        try {
            $shippingAddress = $order->getShippingAddress();

            $shippingLat = $shippingAddress ? $shippingAddress->getData('latitude') : null;
            $shippingLng = $shippingAddress ? $shippingAddress->getData('longitude') : null;

            $sessionLat = $this->session->getData('customer_latitude');
            $sessionLng = $this->session->getData('customer_longitude');

            $shippingHasCoords = $this->hasCoordinates($shippingLat, $shippingLng);
            $sessionHasCoords = $this->hasCoordinates($sessionLat, $sessionLng);

            $finalLatValue = null;
            $finalLngValue = null;
            $finalLatFloat = null;
            $finalLngFloat = null;
            $shouldRecalculateBranch = false;

            if ($shippingHasCoords) {
                $finalLatValue = $shippingLat;
                $finalLngValue = $shippingLng;
                $finalLatFloat = (float)$shippingLat;
                $finalLngFloat = (float)$shippingLng;
                $shouldRecalculateBranch = !$sessionHasCoords
                    || !$this->coordinatesEqual($shippingLat, $sessionLat)
                    || !$this->coordinatesEqual($shippingLng, $sessionLng);
            } elseif ($sessionHasCoords) {
                $finalLatValue = $sessionLat;
                $finalLngValue = $sessionLng;
                $finalLatFloat = (float)$sessionLat;
                $finalLngFloat = (float)$sessionLng;
                $shouldRecalculateBranch = true;

                if ($shippingAddress) {
                    $shippingAddress->setData('latitude', $finalLatValue);
                    $shippingAddress->setData('longitude', $finalLngValue);
                }
            }

            if ($shippingAddress && $finalLatValue !== null && $finalLngValue !== null) {
                $shippingAddress->setData('latitude', $finalLatValue);
                $shippingAddress->setData('longitude', $finalLngValue);
            }

            $branchData = [
                'selected_source_code' => $this->session->getData('selected_source_code'),
                'selected_branch_name' => $this->session->getData('selected_branch_name'),
                'selected_branch_phone' => $this->session->getData('selected_branch_phone'),
            ];

            if ($finalLatFloat !== null && $finalLngFloat !== null && ($shouldRecalculateBranch || !$branchData['selected_source_code'])) {
                $nearestSource = $this->stockHelper->findNearestSourceCode($finalLatFloat, $finalLngFloat);

                if ($nearestSource) {
                    if ($branchData['selected_source_code'] !== $nearestSource) {
                        $branchData['selected_branch_name'] = null;
                        $branchData['selected_branch_phone'] = null;
                    }
                    $branchData['selected_source_code'] = $nearestSource;
                } else {
                    $branchData['selected_source_code'] = null;
                    $branchData['selected_branch_name'] = null;
                    $branchData['selected_branch_phone'] = null;
                }
            }

            if ($finalLatValue !== null && $finalLngValue !== null) {
                $this->session->setData('customer_latitude', $finalLatValue);
                $this->session->setData('customer_longitude', $finalLngValue);
            }

            $this->session->setData('selected_source_code', $branchData['selected_source_code']);
            $this->session->setData('selected_branch_name', $branchData['selected_branch_name']);
            $this->session->setData('selected_branch_phone', $branchData['selected_branch_phone']);

            $order->setData('delivery_source_code', $branchData['selected_source_code']);
            $order->setData('delivery_branch_name', $branchData['selected_branch_name']);
            $order->setData('delivery_branch_phone', $branchData['selected_branch_phone']);

            if ($shippingAddress) {
                $shippingAddress->setData('delivery_source_code', $branchData['selected_source_code']);
                $shippingAddress->setData('delivery_branch_name', $branchData['selected_branch_name']);
                $shippingAddress->setData('delivery_branch_phone', $branchData['selected_branch_phone']);
            }
        } catch (\Exception $exception) {
            $this->logger->error('Error applying location data to order: ' . $exception->getMessage());
        }
    }

    /**
     * Returns the branch data stored in the session.
     */
    public function getBranchData(): array
    {
        return [
            'selected_source_code' => $this->session->getData('selected_source_code'),
            'selected_branch_name' => $this->session->getData('selected_branch_name'),
            'selected_branch_phone' => $this->session->getData('selected_branch_phone'),
            'customer_latitude' => $this->session->getData('customer_latitude'),
            'customer_longitude' => $this->session->getData('customer_longitude'),
        ];
    }

    /**
     * Data exposed to Hyvä checkout prefill mechanisms.
     */
    public function getCheckoutPrefillData(): array
    {
        $addressData = $this->session->getData('location_shipping_address');

        if (!is_array($addressData) || empty($addressData)) {
            return [];
        }

        return [
            'shipping_address' => [
                'firstname' => $addressData['firstname'] ?? '',
                'lastname' => $addressData['lastname'] ?? '',
                'telephone' => $addressData['telephone'] ?? '',
                'street' => $addressData['street'] ?? [],
                'city' => $addressData['city'] ?? '',
                'postcode' => $addressData['postcode'] ?? '',
                'country_id' => $addressData['country_id'] ?? '',
                'region' => $addressData['region'] ?? '',
                'latitude' => $addressData['latitude'] ?? $this->session->getData('customer_latitude'),
                'longitude' => $addressData['longitude'] ?? $this->session->getData('customer_longitude'),
            ],
            'branch' => $this->getBranchData(),
        ];
    }

    private function resolveBranchData(array $payload, $latitude, $longitude): array
    {
        $branchData = [
            'selected_source_code' => $payload['selected_source_code'] ?? null,
            'selected_branch_name' => $payload['selected_branch_name'] ?? null,
            'selected_branch_phone' => $payload['selected_branch_phone'] ?? null,
        ];

        if (!$branchData['selected_source_code'] && $latitude !== null && $longitude !== null) {
            try {
                $nearestSource = $this->stockHelper->findNearestSourceCode((float)$latitude, (float)$longitude);
                if ($nearestSource) {
                    $branchData['selected_source_code'] = $nearestSource;
                }
            } catch (\Exception $exception) {
                $this->logger->error('Unable to resolve nearest branch: ' . $exception->getMessage());
            }
        }

        $this->session->setData('selected_source_code', $branchData['selected_source_code']);
        $this->session->setData('selected_branch_name', $branchData['selected_branch_name']);
        $this->session->setData('selected_branch_phone', $branchData['selected_branch_phone']);

        return array_merge(
            $branchData,
            [
                'customer_latitude' => $this->session->getData('customer_latitude'),
                'customer_longitude' => $this->session->getData('customer_longitude'),
            ]
        );
    }

    private function normalizeAddressData($address): array
    {
        if (!is_array($address) || empty($address)) {
            return [];
        }

        if (isset($address['street']) && !is_array($address['street'])) {
            $address['street'] = [$address['street']];
        }

        return $address;
    }

    private function persistCustomerAddress(array $addressData): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        try {
            $customerId = (int)$this->customerSession->getCustomerId();
            $addressId = isset($addressData['address_id']) ? (int)$addressData['address_id'] : null;

            if ($addressId) {
                $address = $this->addressRepository->getById($addressId);
                if ((int)$address->getCustomerId() !== $customerId) {
                    throw new LocalizedException(__('Address does not belong to this customer.'));
                }
            } else {
                $address = $this->addressFactory->create();
                $address->setCustomerId($customerId);
            }

            $address->setFirstname($addressData['firstname'] ?? '')
                ->setLastname($addressData['lastname'] ?? '')
                ->setTelephone($addressData['telephone'] ?? '')
                ->setCity($addressData['city'] ?? '')
                ->setPostcode($addressData['postcode'] ?? '')
                ->setCountryId($addressData['country_id'] ?? '')
                ->setStreet($addressData['street'] ?? []);

            if (isset($addressData['region'])) {
                if (is_array($addressData['region'])) {
                    if (!empty($addressData['region']['region_id'])) {
                        $address->setRegionId($addressData['region']['region_id']);
                    }
                    if (!empty($addressData['region']['region'])) {
                        $address->setRegion($addressData['region']['region']);
                    }
                } else {
                    $address->setRegion($addressData['region']);
                }
            }

            $address->setIsDefaultShipping(!empty($addressData['is_default_shipping']));
            $address->setIsDefaultBilling(!empty($addressData['is_default_billing']));

            if (isset($addressData['latitude'])) {
                $address->setCustomAttribute('latitude', $addressData['latitude']);
            }
            if (isset($addressData['longitude'])) {
                $address->setCustomAttribute('longitude', $addressData['longitude']);
            }

            $this->addressRepository->save($address);
        } catch (\Exception $exception) {
            $this->logger->error('Failed to persist customer address: ' . $exception->getMessage());
        }
    }

    private function updateQuote(array $addressData, array $branchData): void
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $exception) {
            $this->logger->error('Unable to retrieve quote: ' . $exception->getMessage());
            return;
        }

        if (!$quote || !$quote->getId()) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress) {
            return;
        }

        if (!empty($addressData)) {
            if (!empty($addressData['firstname'])) {
                $shippingAddress->setFirstname($addressData['firstname']);
            }
            if (!empty($addressData['lastname'])) {
                $shippingAddress->setLastname($addressData['lastname']);
            }
            if (isset($addressData['telephone'])) {
                $shippingAddress->setTelephone($addressData['telephone']);
            }
            if (!empty($addressData['street'])) {
                $shippingAddress->setStreet($addressData['street']);
            }
            if (isset($addressData['city'])) {
                $shippingAddress->setCity($addressData['city']);
            }
            if (isset($addressData['postcode'])) {
                $shippingAddress->setPostcode($addressData['postcode']);
            }
            if (isset($addressData['country_id'])) {
                $shippingAddress->setCountryId($addressData['country_id']);
            }
            if (!empty($addressData['region'])) {
                if (is_array($addressData['region'])) {
                    if (!empty($addressData['region']['region_id'])) {
                        $shippingAddress->setRegionId($addressData['region']['region_id']);
                    }
                    if (!empty($addressData['region']['region'])) {
                        $shippingAddress->setRegion($addressData['region']['region']);
                    }
                } else {
                    $shippingAddress->setRegion($addressData['region']);
                }
            }
        }

        if (!empty($branchData['customer_latitude'])) {
            $shippingAddress->setData('latitude', $branchData['customer_latitude']);
        }
        if (!empty($branchData['customer_longitude'])) {
            $shippingAddress->setData('longitude', $branchData['customer_longitude']);
        }

        $shippingAddress->setData('delivery_source_code', $branchData['selected_source_code'] ?? null);
        $shippingAddress->setData('delivery_branch_name', $branchData['selected_branch_name'] ?? null);
        $shippingAddress->setData('delivery_branch_phone', $branchData['selected_branch_phone'] ?? null);

        try {
            $this->cartRepository->save($quote);
        } catch (\Exception $exception) {
            $this->logger->error('Unable to persist quote with new location data: ' . $exception->getMessage());
        }
    }

    private function hasCoordinates($lat, $lng): bool
    {
        return $lat !== null && $lat !== '' && $lng !== null && $lng !== '';
    }

    private function coordinatesEqual($first, $second): bool
    {
        if ($first === null || $first === '' || $second === null || $second === '') {
            return false;
        }

        return abs((float)$first - (float)$second) < 0.000001;
    }
}
