<?php

namespace Madar\StockAvailability\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Madar\StockAvailability\Model\Session;

class DeliveryBranch implements SectionSourceInterface
{
    protected $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }
    public function getSectionData()
    {
        $data = [
            'selected_source_code' => $this->session->getData('selected_source_code'),
            'selected_branch_name' => $this->session->getData('selected_branch_name'),
            'selected_branch_phone' => $this->session->getData('selected_branch_phone'),
            'customer_latitude' => $this->session->getData('customer_latitude'),
            'customer_longitude' => $this->session->getData('customer_longitude'),
        ];

        return $data;
    }
}
