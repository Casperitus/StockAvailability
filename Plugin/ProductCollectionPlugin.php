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

    private function resolveSourceCode(): ?string
    {
        $sourceCode = $this->customerSession->getData('selected_source_code');
        if (is_string($sourceCode) && $sourceCode !== '') {
            return $sourceCode;
        }

        $latitude = $this->customerSession->getData('customer_latitude');
        $longitude = $this->customerSession->getData('customer_longitude');

        if ($latitude !== null && $longitude !== null && is_numeric($latitude) && is_numeric($longitude)) {
            $resolved = $this->stockHelper->findNearestSourceCode((float) $latitude, (float) $longitude);

            if ($resolved) {
                $this->customerSession->setData('selected_source_code', $resolved);
                $this->logger->debug('Resolved source code from stored coordinates.', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'resolved_source' => $resolved,
                ]);

                return $resolved;
            }

            $this->logger->debug('Unable to resolve source code from stored coordinates.', [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return null;
    }

    public function afterLoad(Collection $subject, Collection $result): Collection
    {
        if ($result->getFlag('stock_availability_processing')) {
            return $result;
        }

        $result->setFlag('stock_availability_processing', true);

        try {
            $items = $result->getItems();
            if (!$items) {
                $this->logger->debug('Product collection loaded without items; skipping deliverability evaluation.');
                return $result;
            }

            $sourceCode = $this->resolveSourceCode();

            $this->logger->debug('Evaluating deliverability for product collection.', [
                'selected_source_code' => $sourceCode,
                'item_count' => count($items),
            ]);

            foreach ($items as $product) {
                $sku = (string) $product->getSku();
                $hasSource = $sourceCode !== null && $sourceCode !== '';
                $isDeliverable = $hasSource
                    ? $this->stockHelper->isProductDeliverable($sku, $sourceCode)
                    : false;

                if (!$hasSource) {
                    $this->logger->debug('No source selected; marking product as requestable in collection.', [
                        'sku' => $sku,
                    ]);
                }

                $this->deliverabilityAttribute->apply($product, $isDeliverable);

                if ($isDeliverable) {
                    $this->logger->debug('Product marked as deliverable in collection.', [
                        'sku' => $sku,
                        'source_code' => $sourceCode,
                    ]);
                } elseif ($hasSource) {
                    $product->setIsSalable(false);
                    $this->logger->debug(sprintf('Product %s marked as requestable in collection.', $sku));
                }
            }

            return $result;
        } finally {
            $result->setFlag('stock_availability_processing', false);
        }
    }
}
