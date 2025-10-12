<?php

namespace Magento\Framework\Session;

class SessionManager
{
    public function setData($key, $value = null)
    {
        return $this;
    }

    public function getData($key = '')
    {
        return null;
    }
}
