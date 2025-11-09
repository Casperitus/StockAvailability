<?php

namespace Madar\StockAvailability\Plugin\Hyva\Checkout;

use Hyva\Checkout\Model\Form\EntityFormService;
use Madar\StockAvailability\Model\LocationManager;

class EntityFormServicePlugin
{
    public const SESSION_ADDRESS_ID = 'madar-session-shipping-address';

    private LocationManager $locationManager;

    public function __construct(LocationManager $locationManager)
    {
        $this->locationManager = $locationManager;
    }

    /**
     * @param EntityFormService $subject
     * @param array $result
     * @param string $formCode
     * @param int|null $quoteId
     * @return array
     */
    public function afterGetFormData(EntityFormService $subject, array $result, string $formCode, $quoteId = null): array
    {
        if ($formCode !== 'shipping-address-form') {
            return $result;
        }

        $prefill = $this->locationManager->getCheckoutPrefillData();
        if (empty($prefill['shipping_address']) || !is_array($prefill['shipping_address'])) {
            return $result;
        }

        $shippingPrefill = $prefill['shipping_address'];

        if (!isset($result['entity']) || !is_array($result['entity'])) {
            $result['entity'] = [];
        }

        $result['entity'] = array_replace_recursive($result['entity'], $this->filterEmptyValues($shippingPrefill));

        if (!isset($result['entity']['custom_attributes']) || !is_array($result['entity']['custom_attributes'])) {
            $result['entity']['custom_attributes'] = [];
        }

        foreach (['latitude', 'longitude'] as $coordinate) {
            if (isset($shippingPrefill[$coordinate]) && $shippingPrefill[$coordinate] !== null) {
                $result['entity']['custom_attributes'][$coordinate] = $shippingPrefill[$coordinate];
            }
        }

        $addressesKey = $this->resolveAddressesKey($result);
        $result[$addressesKey] = $this->injectSessionAddress(
            $result[$addressesKey] ?? [],
            $shippingPrefill,
            $result['entity']['custom_attributes']
        );

        $result['selectedAddressId'] = self::SESSION_ADDRESS_ID;

        return $result;
    }

    private function filterEmptyValues(array $data): array
    {
        return array_filter(
            $data,
            static function ($value, $key) {
                if ($key === 'country_id') {
                    return true;
                }

                if (is_array($value)) {
                    return count(array_filter($value, static fn($item) => $item !== null && $item !== '')) > 0;
                }

                return $value !== null && $value !== '';
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function resolveAddressesKey(array $result): string
    {
        foreach (['addresses', 'addressList', 'items'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return $key;
            }
        }

        return 'addresses';
    }

    private function injectSessionAddress(array $addresses, array $shippingPrefill, array $customAttributes): array
    {
        $addressItem = $this->filterEmptyValues($shippingPrefill);
        $addressItem['id'] = self::SESSION_ADDRESS_ID;
        $addressItem['entity_id'] = self::SESSION_ADDRESS_ID;
        $addressItem['customer_address_id'] = null;

        $addressItem['custom_attributes'] = [];
        foreach (['latitude', 'longitude'] as $coordinate) {
            if (isset($customAttributes[$coordinate])) {
                $addressItem['custom_attributes'][$coordinate] = $customAttributes[$coordinate];
            }
        }

        $filtered = [];
        foreach ($addresses as $existingAddress) {
            if (!is_array($existingAddress)) {
                continue;
            }

            $existingId = $existingAddress['id'] ?? $existingAddress['entity_id'] ?? null;
            if ($existingId === self::SESSION_ADDRESS_ID) {
                continue;
            }

            $filtered[] = $existingAddress;
        }

        $filtered[] = $addressItem;

        return $filtered;
    }
}
