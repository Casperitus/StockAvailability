<?php

namespace Madar\StockAvailability\Controller\Deliverability;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Madar\StockAvailability\Helper\StockHelper;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Request\Http;
use Psr\Log\LoggerInterface;

class Get extends Action
{
    protected $stockHelper;
    protected $resultJsonFactory;
    protected $request;
    protected $logger;

    public function __construct(
        Context $context,
        StockHelper $stockHelper,
        JsonFactory $resultJsonFactory,
        Http $request,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->stockHelper = $stockHelper;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $skus = (array) $this->request->getParam('skus', []);
        $sourceCode = $this->request->getParam('source_code', null);

        if (empty($skus)) {
            return $result->setData([
                'success' => false,
                'message' => 'No SKUs were provided.'
            ]);
        }

        try {
            $deliverabilityData = [];
            foreach ($skus as $sku) {
                // 1) If no source code is provided, default deliverable = true (Yes)
                $isDeliverable = true;
                if ($sourceCode) {
                    $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $sourceCode);
                }
                $deliverabilityData[] = [
                    'sku'         => $sku,
                    'deliverable' => $isDeliverable ? 'Yes' : 'No'
                ];
            }

            return $result->setData([
                'success' => true,
                'data'    => $deliverabilityData
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Unable to fetch deliverability data.'
            ]);
        }
    }
}
