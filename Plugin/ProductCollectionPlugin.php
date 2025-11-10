<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\DeliverabilityAttribute;
use Madar\StockAvailability\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class ProductCollectionPlugin
{
    private StockHelper $stockHelper;
    private CustomerSession $customerSession;
    private DeliverabilityAttribute $deliverabilityAttribute;
    private LoggerInterface $logger;

    public function __construct(
        StockHelper $stockHelper,
        CustomerSession $customerSession,
        DeliverabilityAttribute $deliverabilityAttribute,
        LoggerInterface $logger
    ) {
        $this->stockHelper = $stockHelper;
        $this->customerSession = $customerSession;
        $this->deliverabilityAttribute = $deliverabilityAttribute;
        $this->logger = $logger;
    }

    public function afterLoad(Collection $subject, Collection $result): Collection
    {
        $items = $result->getItems();
        if (!$items) {
            return $result;
        }

        $sourceCode = $this->customerSession->getData('selected_source_code');
        $sourceCode = $sourceCode ? (string) $sourceCode : null;

        foreach ($items as $product) {
            $sku = (string) $product->getSku();
            $isDeliverable = true;

            if ($sourceCode) {
                $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $sourceCode);
            }

            $this->deliverabilityAttribute->apply($product, $isDeliverable);

            if (!$isDeliverable) {
                $product->setIsSalable(false);
                $this->logger->debug(sprintf('Product %s marked as requestable in collection.', $sku));
            }
        }

        return $result;
    }
}
