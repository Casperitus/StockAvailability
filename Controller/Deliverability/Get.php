<?php

namespace Madar\StockAvailability\Controller\Deliverability;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Logger\Location as LocationLogger;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;

class Get extends Action
{
    protected $stockHelper;
    protected $resultJsonFactory;
    protected $request;
    protected LocationLogger $logger;

    public function __construct(
        Context $context,
        StockHelper $stockHelper,
        JsonFactory $resultJsonFactory,
        Http $request,
        LocationLogger $logger
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
        $sourceCodes = (array) $this->request->getParam('source_codes', []); // Add this line

        $this->logger->debug('Deliverability request payload', [
            'skus' => $skus,
            'source_code' => $sourceCode,
            'source_codes' => $sourceCodes,
        ]);

        if (empty($skus)) {
            return $result->setData([
                'success' => false,
                'message' => 'No SKUs were provided.'
            ]);
        }

        try {
            $deliverabilityData = [];

            // Handle multiple source codes for store availability check
            if (!empty($sourceCodes)) {
                foreach ($skus as $sku) {
                    foreach ($sourceCodes as $code) {
                        $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $code);
                        $deliverabilityData[] = [
                            'sku' => $sku,
                            'source_code' => $code,
                            'deliverable' => $isDeliverable ? 'Yes' : 'No'
                        ];
                    }
                }
            } else {
                // Original logic for single source
                foreach ($skus as $sku) {
                    $isDeliverable = true;
                    if ($sourceCode) {
                        $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $sourceCode);
                    }
                    $deliverabilityData[] = [
                        'sku' => $sku,
                        'deliverable' => $isDeliverable ? 'Yes' : 'No'
                    ];
                }
            }

            $response = [
                'success' => true,
                'data' => $deliverabilityData
            ];
            $this->logger->debug('Deliverability response payload', ['response' => $response]);

            return $result->setData($response);
        } catch (\Exception $e) {
            $this->logger->error('Unable to fetch deliverability data: ' . $e->getMessage(), ['exception' => $e]);

            return $result->setData([
                'success' => false,
                'message' => 'Unable to fetch deliverability data.'
            ]);
        }
    }
}
