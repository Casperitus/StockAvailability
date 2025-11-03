<?php

namespace Madar\StockAvailability\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Location extends Base
{
    /**
     * Log everything so we can trace the full workflow.
     *
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * @var string
     */
    protected $fileName = '/var/log/madar_location.log';
}

