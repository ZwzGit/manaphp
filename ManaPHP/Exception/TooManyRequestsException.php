<?php
namespace ManaPHP\Exception;

use ManaPHP\Exception;

class TooManyRequestsException extends Exception
{
    public function getStatusCode()
    {
        /**
         * https://tools.ietf.org/html/rfc6585#section-4
         */
        return 429;
    }
}