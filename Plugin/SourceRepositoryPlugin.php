<?php

namespace Madar\StockAvailability\Plugin;

use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\DataObject;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceSearchResultsInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Psr\Log\LoggerInterface;

class SourceRepositoryPlugin
{
    protected ExtensionAttributesFactory $extensionAttributesFactory;
    protected LoggerInterface $logger;

    public function __construct(
        ExtensionAttributesFactory $extensionAttributesFactory,
        LoggerInterface $logger
    ) {
        $this->extensionAttributesFactory = $extensionAttributesFactory;
        $this->logger = $logger;
    }

    public function beforeSave(SourceRepositoryInterface $subject, SourceInterface $source): array
    {
        if ($source instanceof DataObject) {
            $extensionAttributes = $source->getExtensionAttributes();
            if ($extensionAttributes !== null) {
                // Set delivery_range_km from extension attributes to source data
                $source->setData('delivery_range_km', $extensionAttributes->getDeliveryRangeKm());

                // Set associated_hubs from extension attributes to source data
                $associatedHubs = $extensionAttributes->getAssociatedHubs();
                if ($associatedHubs !== null) {
                    $this->logger->info(sprintf('SourceRepositoryPlugin: BeforeSave - Setting associated hubs: %s', json_encode($associatedHubs)));
                    // Save associated_hubs as a comma-separated string
                    $source->setData('associated_hubs', implode(',', $associatedHubs));
                }

                // Set is_hub from extension attributes to source data
                $isHub = $extensionAttributes->getIsHub();
                if ($isHub !== null) {
                    $source->setData('is_hub', $isHub);
                }
            }
        }

        return [$source];
    }

    public function afterGet(SourceRepositoryInterface $subject, SourceInterface $source): SourceInterface
    {
        $this->extendSource($source);
        return $source;
    }

    public function afterGetList(SourceRepositoryInterface $subject, SourceSearchResultsInterface $sourceSearchResults): SourceSearchResultsInterface
    {
        $items = $sourceSearchResults->getItems();
        array_walk($items, [$this, 'extendSource']);
        return $sourceSearchResults;
    }

    private function extendSource(SourceInterface $source): void
    {
        if (!$source instanceof DataObject) {
            return;
        }

        $extensionAttributes = $source->getExtensionAttributes();

        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionAttributesFactory->create(SourceInterface::class);
            $source->setExtensionAttributes($extensionAttributes);
        }

        // Get delivery_range_km
        $deliveryRangeKm = $source->getData('delivery_range_km') ?: 0.0;
        $extensionAttributes->setDeliveryRangeKm($deliveryRangeKm);

        // Get associated_hubs
        $associatedHubs = $source->getData('associated_hubs');
        $associatedHubsArray = [];
        if ($associatedHubs !== null && $associatedHubs !== '') {
            $associatedHubsArray = explode(',', $associatedHubs);
        }
        $extensionAttributes->setAssociatedHubs($associatedHubsArray);

        // Get is_hub
        $isHub = (bool) $source->getData('is_hub');
        $extensionAttributes->setIsHub($isHub);
    }
}
