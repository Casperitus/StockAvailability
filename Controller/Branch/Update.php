<?php

namespace Madar\StockAvailability\Controller\Branch;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Madar\StockAvailability\Model\Session;
use Madar\StockAvailability\Helper\StockHelper;
use Psr\Log\LoggerInterface;

class Update extends Action
{
    protected $resultJsonFactory;
    protected $session;
    protected $stockHelper;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Session $session,
        StockHelper $stockHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->session = $session;
        $this->stockHelper = $stockHelper;
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

        // Update lat/long in session
        if (isset($data['customer_latitude'])) {
            $this->session->setData('customer_latitude', $data['customer_latitude']);
        }

        if (isset($data['customer_longitude'])) {
            $this->session->setData('customer_longitude', $data['customer_longitude']);
        }

        // If the payload explicitly provided a source code/branch info, store it
        // (this is how your mapComponent might send the source code)
        if (isset($data['selected_source_code'])) {
            $this->session->setData('selected_source_code', $data['selected_source_code']);
        }

        if (isset($data['selected_branch_name'])) {
            $this->session->setData('selected_branch_name', $data['selected_branch_name']);
        }

        if (isset($data['selected_branch_phone'])) {
            $this->session->setData('selected_branch_phone', $data['selected_branch_phone']);
        }

        // Now, if no selected_source_code was provided, automatically find the nearest source
        $existingSource = $this->session->getData('selected_source_code');
        if (!$existingSource) {
            $lat = $this->session->getData('customer_latitude');
            $lng = $this->session->getData('customer_longitude');
            if ($lat && $lng) {
                $nearest = $this->stockHelper->findNearestSourceCode((float)$lat, (float)$lng);
                if ($nearest) {
                    $this->session->setData('selected_source_code', $nearest);
                } else {
                    // If no branch is within range, optionally set a fallback:
                    // $this->session->setData('selected_source_code', 'LOGISTICS');
                    // or do nothing, so that the plugin throws an exception at checkout
                }
            }
        }

        // Log session data for debugging
        $this->logger->info('Branch Update Session Data: ' . json_encode($this->session->getData()));

        // Return saved session data for confirmation
        $response = [
            'success' => true,
            'message' => 'Branch details updated successfully',
            'branch_details' => [
                'source_code' => $this->session->getData('selected_source_code'),
                'branch_name' => $this->session->getData('selected_branch_name'),
                'branch_phone' => $this->session->getData('selected_branch_phone'),
                'latitude' => $this->session->getData('customer_latitude'),
                'longitude' => $this->session->getData('customer_longitude'),
            ],
        ];

        return $result->setData($response);
    }
}
