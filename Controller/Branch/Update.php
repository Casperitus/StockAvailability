<?php

namespace Madar\StockAvailability\Controller\Branch;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Madar\StockAvailability\Logger\Location as LocationLogger;
use Madar\StockAvailability\Model\LocationManager;

class Update extends Action
{
    protected $resultJsonFactory;
    protected LocationManager $locationManager;
    protected LocationLogger $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        LocationManager $locationManager,
        LocationLogger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->locationManager = $locationManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $data = json_decode($this->getRequest()->getContent(), true);

        if (!is_array($data)) {
            return $result->setData([
                'success' => false,
                'message' => 'Invalid JSON payload.'
            ]);
        }
        try {
            $this->logger->debug('Incoming branch update payload', ['payload' => $data]);
            $response = $this->locationManager->persistLocation($data);
            $branchData = $response['branch'] ?? $this->locationManager->getBranchData();

            $payload = [
                'success' => true,
                'message' => 'Branch details updated successfully',
                'branch' => $branchData,
                'shipping_address' => $response['shipping_address'] ?? [],
                'prefill' => $response['prefill'] ?? [],
            ];

            $this->logger->debug('Branch update response payload', ['response' => $payload]);

            return $result->setData($payload);
        } catch (\Exception $exception) {
            $this->logger->error(
                'Unable to persist location: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $result->setData([
                'success' => false,
                'message' => __('Unable to save your location at this time. Please try again.'),
            ]);
        }
    }
}
