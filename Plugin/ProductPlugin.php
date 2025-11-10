<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Plugin;

use Magento\Catalog\Model\Product;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\DeliverabilityAttribute;
use Madar\StockAvailability\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class ProductPlugin
{
    private StockHelper $stockHelper;
    private LoggerInterface $logger;
    private CustomerSession $customerSession;
    private DeliverabilityAttribute $deliverabilityAttribute;

    /** @var array<string,bool> */
    private array $validatedSkus = [];

    public function __construct(
        StockHelper $stockHelper,
        LoggerInterface $logger,
        CustomerSession $customerSession,
        DeliverabilityAttribute $deliverabilityAttribute
    ) {
        $this->stockHelper = $stockHelper;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
        $this->deliverabilityAttribute = $deliverabilityAttribute;
    }

    public function afterIsSaleable(Product $subject, bool $result): bool
    {
        $sku = (string) $subject->getSku();

        if (!$result) {
            $this->deliverabilityAttribute->apply($subject, false);
            return false;
        }

        if (array_key_exists($sku, $this->validatedSkus)) {
            $isDeliverable = $this->validatedSkus[$sku];
            $this->deliverabilityAttribute->apply($subject, $isDeliverable);
            return $result && $isDeliverable;
        }

        $sourceCode = $this->customerSession->getData('selected_source_code');
        if (!$sourceCode) {
            $this->logger->debug(sprintf('No source selected for SKU %s, defaulting to deliverable.', $sku));
            $this->validatedSkus[$sku] = true;
            $this->deliverabilityAttribute->apply($subject, true);
            return $result;
        }

        $isDeliverable = $this->stockHelper->isProductDeliverable($sku, (string) $sourceCode);
        $this->validatedSkus[$sku] = $isDeliverable;
        $this->deliverabilityAttribute->apply($subject, $isDeliverable);

        if (!$isDeliverable) {
            $this->logger->info(sprintf('SKU %s marked as requestable for source %s.', $sku, $sourceCode));
        }

        return $result && $isDeliverable;
    }
}
